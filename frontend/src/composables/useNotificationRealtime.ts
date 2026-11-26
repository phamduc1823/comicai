import { watch, onUnmounted, type Ref } from 'vue'
import {
  subscribeUserNotifications,
  unsubscribeUserNotifications,
  onEchoReconnect,
} from '@/services/echoService'
import { useNotificationStore } from '@/stores/notification'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types/auth'
import type { NotificationCreatedPayload } from '@/types/notification'

// Periodic polling interval (10 seconds, within the 10-15s required range)
const POLL_INTERVAL_MS = 10_000

/**
 * Composable managing realtime notification listeners and safe visibility-aware polling.
 * - Reuses existing private user channel `chat.user.{userId}` (.NotificationCreated)
 * - Whitelists likes, comments, and creator application status events
 * - Prevents duplicate listener registration on remount or user switch
 * - Provides gentle 10s visibility-aware polling with concurrency guard
 * - Immediately syncs badge and notification list without full page reload
 * - Automatically cleans up listeners, intervals, and state on logout or unmount
 */
export function useNotificationRealtime(userRef: Ref<User | null>) {
  const notificationStore = useNotificationStore()

  let currentSubscribedUserId: number | null = null
  let unsubscribeReconnect: (() => void) | null = null
  let pollTimer: ReturnType<typeof setInterval> | null = null
  let currentHandler: ((payload: NotificationCreatedPayload) => void) | null = null
  let isPolling = false

  function stopPolling(): void {
    if (pollTimer !== null) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  async function executePoll(): Promise<void> {
    if (!currentSubscribedUserId || isPolling) return
    // Pause polling when browser tab is inactive / hidden
    if (typeof document !== 'undefined' && document.hidden) return

    isPolling = true
    try {
      const prevUnread = notificationStore.unreadCount
      await notificationStore.fetchUnreadCount()

      // When unread count changes or list has been opened/loaded, sync page 1
      if (notificationStore.unreadCount !== prevUnread || notificationStore.hasLoadedOnce) {
        await notificationStore.fetchNotifications(1)
      }
    } catch {
      // Keep previous count silently on background sync failure
    } finally {
      isPolling = false
    }
  }

  function startPolling(): void {
    stopPolling()
    pollTimer = setInterval(executePoll, POLL_INTERVAL_MS)
  }

  function handleVisibilityChange(): void {
    if (typeof document !== 'undefined' && !document.hidden && currentSubscribedUserId) {
      // Immediately sync when tab becomes active again
      executePoll()
    }
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
    if (typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
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
        // Bell icon accepts Likes, Comments, and Creator application approval/rejection
        const type = payload.notification?.type || ''
        const bellTypes = [
          'post_liked',
          'post_commented',
          'comic_commented',
          'chapter_commented',
          'creator_application_approved',
          'creator_application_rejected',
        ]
        if (bellTypes.includes(type)) {
          notificationStore.handleRealtimeNotification(payload)
          if (type === 'creator_application_approved') {
            useAuthStore().fetchUser()
          }
        }
      }
      subscribeUserNotifications(userId, currentHandler)

      if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', handleVisibilityChange)
      }
      startPolling()
    } else if (!userId) {
      teardownSubscription()
      notificationStore.$reset()
    }
  }

  // Resync unread count and list upon WebSocket reconnect
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
