import { watch, onUnmounted, type Ref } from 'vue'
import {
  subscribeUserNotifications,
  unsubscribeUserNotifications,
  onEchoReconnect,
  getEchoConnectionStatus,
} from '@/services/echoService'
import { useNotificationStore } from '@/stores/notification'
import type { User } from '@/types/auth'
import type { NotificationCreatedPayload } from '@/types/notification'

const FALLBACK_POLL_INTERVAL_MS = 15_000

/**
 * Composable managing realtime notification listeners and safe fallback polling.
 * - Reuses existing private user channel `chat.user.{userId}` (.NotificationCreated)
 * - Prevents duplicate listener registration on remount or user switch
 * - Automatically cleans up listeners, timers, and state on logout or unmount
 * - Uses gentle 15s visibility-aware polling ONLY when Echo WebSocket is not connected
 */
export function useNotificationRealtime(userRef: Ref<User | null>) {
  const notificationStore = useNotificationStore()

  let currentSubscribedUserId: number | null = null
  let unsubscribeReconnect: (() => void) | null = null
  let pollTimer: ReturnType<typeof setInterval> | null = null
  let currentHandler: ((payload: NotificationCreatedPayload) => void) | null = null

  function stopPolling(): void {
    if (pollTimer !== null) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  function startPolling(): void {
    stopPolling()
    pollTimer = setInterval(() => {
      if (!currentSubscribedUserId) return
      // Only poll when tab is visible and Echo is NOT connected
      if (typeof document !== 'undefined' && document.hidden) return
      if (getEchoConnectionStatus() === 'connected') return
      notificationStore.fetchUnreadCount()
    }, FALLBACK_POLL_INTERVAL_MS)
  }

  function teardownSubscription(): void {
    if (currentSubscribedUserId !== null) {
      if (currentHandler) {
        unsubscribeUserNotifications(currentSubscribedUserId, currentHandler)
        currentHandler = null
      } else {
        unsubscribeUserNotifications(currentSubscribedUserId)
      }
      currentSubscribedUserId = null
    }
    stopPolling()
  }

  function syncSubscription(userId: number | null | undefined): void {
    if (currentSubscribedUserId !== null && currentSubscribedUserId !== userId) {
      teardownSubscription()
      notificationStore.$reset()
    }

    if (userId && currentSubscribedUserId !== userId) {
      currentSubscribedUserId = userId
      notificationStore.fetchUnreadCount()

      currentHandler = (payload: NotificationCreatedPayload) => {
        // Strictly filter: Bell icon ONLY accepts Post Likes and Comments (CAI-MSG-04)
        const type = payload.notification?.type || ''
        const bellTypes = ['post_liked', 'post_commented', 'comic_commented', 'chapter_commented']
        if (bellTypes.includes(type)) {
          notificationStore.handleRealtimeNotification(payload)
        }
      }
      subscribeUserNotifications(userId, currentHandler)
      startPolling()
    } else if (!userId) {
      teardownSubscription()
      notificationStore.$reset()
    }
  }

  // Resync unread count upon WebSocket reconnect
  unsubscribeReconnect = onEchoReconnect(() => {
    if (currentSubscribedUserId !== null) {
      notificationStore.fetchUnreadCount()
      if (notificationStore.items.length > 0 || notificationStore.hasLoadedOnce) {
        notificationStore.fetchNotifications(1)
      }
    }
  })

  watch(
    () => userRef.value?.id,
    (newUserId) => {
      syncSubscription(newUserId)
    },
    { immediate: true }
  )

  onUnmounted(() => {
    teardownSubscription()
    if (unsubscribeReconnect) {
      unsubscribeReconnect()
      unsubscribeReconnect = null
    }
  })

  return {
    syncSubscription,
    teardownSubscription,
  }
}
