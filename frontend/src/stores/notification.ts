import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { NotificationService } from '@/services/notificationService'
import { getErrorMessage } from '@/services/httpClient'
import type {
  NotificationItem,
  NotificationCreatedPayload,
} from '@/types/notification'

export const useNotificationStore = defineStore('notification', () => {
  // State
  const items = ref<NotificationItem[]>([])
  const unreadCount = ref<number>(0)
  const isLoading = ref<boolean>(false)
  const isLoadingMore = ref<boolean>(false)
  const isMarkingAll = ref<boolean>(false)
  const error = ref<string | null>(null)
  const currentPage = ref<number>(1)
  const lastPage = ref<number>(1)
  const total = ref<number>(0)
  const hasLoadedOnce = ref<boolean>(false)

  // Concurrency guard for fetches
  let fetchToken = 0
  let isFetchingUnread = false

  // Computed
  const hasMore = computed(() => currentPage.value < lastPage.value)
  const badgeLabel = computed(() => {
    if (unreadCount.value <= 0) return ''
    return unreadCount.value > 99 ? '99+' : String(unreadCount.value)
  })

  /**
   * Fetch exact unread notification count from backend.
   */
  async function fetchUnreadCount(): Promise<void> {
    if (isFetchingUnread) return
    isFetchingUnread = true
    try {
      const count = await NotificationService.getUnreadCount()
      unreadCount.value = Math.max(0, count)
    } catch {
      // Keep previous count silently on background sync failure
    } finally {
      isFetchingUnread = false
    }
  }

  /**
   * Fetch paginated notifications (page 1 replaces list; page > 1 appends).
   */
  async function fetchNotifications(page = 1, perPage = 15): Promise<void> {
    const token = ++fetchToken
    const isAppend = page > 1

    if (isAppend) {
      if (isLoadingMore.value) return
      isLoadingMore.value = true
    } else {
      isLoading.value = true
    }
    error.value = null

    try {
      const response = await NotificationService.getNotifications(page, perPage)

      // Discard stale out-of-order response
      if (token !== fetchToken) return

      if (isAppend) {
        const existingIds = new Set(items.value.map((item) => item.id))
        const newItems = response.data.filter((item) => !existingIds.has(item.id))
        items.value = [...items.value, ...newItems]
      } else {
        items.value = response.data
      }

      currentPage.value = response.meta.current_page
      lastPage.value = response.meta.last_page
      total.value = response.meta.total
      unreadCount.value = Math.max(0, response.meta.unread_count)
      hasLoadedOnce.value = true
    } catch (err: unknown) {
      if (token !== fetchToken) return
      error.value = getErrorMessage(err, 'Không thể tải danh sách thông báo.')
    } finally {
      if (token === fetchToken) {
        isLoading.value = false
        isLoadingMore.value = false
      }
    }
  }

  /**
   * Load the next page of notifications.
   */
  async function loadMore(perPage = 15): Promise<void> {
    if (!hasMore.value || isLoadingMore.value || isLoading.value) {
      return
    }
    await fetchNotifications(currentPage.value + 1, perPage)
  }

  /**
   * Mark a single notification as read with optimistic update and strict rollback on failure.
   */
  async function markAsRead(notificationId: string): Promise<boolean> {
    const target = items.value.find((n) => n.id === notificationId)

    // Snapshot previous state for rollback
    const prevIsRead = target?.is_read ?? false
    const prevReadAt = target?.read_at ?? null
    const prevUnreadCount = unreadCount.value

    if (target && !target.is_read) {
      target.is_read = true
      target.read_at = new Date().toISOString()
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }

    try {
      const result = await NotificationService.markAsRead(notificationId)

      if (target) {
        target.is_read = result.data.is_read
        target.read_at = result.data.read_at
      }
      unreadCount.value = Math.max(0, result.meta.unread_count)
      return true
    } catch (err: unknown) {
      // Strictly rollback optimistic update on API failure
      if (target) {
        target.is_read = prevIsRead
        target.read_at = prevReadAt
      }
      unreadCount.value = prevUnreadCount
      error.value = getErrorMessage(err, 'Không thể đánh dấu thông báo đã đọc.')
      return false
    }
  }

  /**
   * Mark all unread notifications as read with optimistic update and strict rollback on failure.
   */
  async function markAllAsRead(): Promise<boolean> {
    if (isMarkingAll.value || unreadCount.value === 0) {
      return true
    }

    isMarkingAll.value = true
    error.value = null

    // Snapshot previous state for rollback
    const previousStates = items.value.map((item) => ({
      id: item.id,
      is_read: item.is_read,
      read_at: item.read_at,
    }))
    const prevUnreadCount = unreadCount.value

    // Optimistic update
    const nowIso = new Date().toISOString()
    items.value.forEach((item) => {
      if (!item.is_read) {
        item.is_read = true
        item.read_at = nowIso
      }
    })
    unreadCount.value = 0

    try {
      const result = await NotificationService.markAllAsRead()
      unreadCount.value = Math.max(0, result.unread_count)
      return true
    } catch (err: unknown) {
      // Strictly rollback optimistic update on API failure
      const snapshotMap = new Map(previousStates.map((s) => [s.id, s]))
      items.value.forEach((item) => {
        const snap = snapshotMap.get(item.id)
        if (snap) {
          item.is_read = snap.is_read
          item.read_at = snap.read_at
        }
      })
      unreadCount.value = prevUnreadCount
      error.value = getErrorMessage(err, 'Không thể đánh dấu tất cả thông báo đã đọc.')
      return false
    } finally {
      isMarkingAll.value = false
    }
  }

  /**
   * Handle incoming realtime .NotificationCreated broadcast event.
   * Immediately updates unread count and prepends incoming notification item.
   * Deduplicates strictly by notification ID so API + Echo never render duplicate cards or increment counts twice.
   */
  function handleRealtimeNotification(payload: NotificationCreatedPayload): void {
    if (!payload?.notification?.id) return

    const incoming = payload.notification

    // Strictly isolate to bell notification types (Post Likes and Comments only - CAI-MSG-04)
    const bellTypes = ['post_liked', 'post_commented', 'comic_commented', 'chapter_commented']
    if (incoming.type && !bellTypes.includes(incoming.type)) {
      return
    }

    const existingIndex = items.value.findIndex((item) => item.id === incoming.id)

    if (existingIndex !== -1) {
      // Deduplicate: update existing item with latest data
      items.value[existingIndex] = incoming
    } else {
      // Always prepend new notification item immediately so dropdown updates in real-time
      items.value.unshift(incoming)
      total.value += 1
    }

    // Authoritative unread count from backend broadcast payload
    if (typeof payload.unread_count === 'number' && payload.unread_count >= 0) {
      unreadCount.value = payload.unread_count
    } else if (existingIndex === -1 && !incoming.is_read) {
      // Fallback increment only for new unread items
      unreadCount.value = Math.max(0, unreadCount.value + 1)
    }
  }

  /**
   * Clear all store state (called on logout).
   */
  function $reset(): void {
    fetchToken++
    isFetchingUnread = false
    items.value = []
    unreadCount.value = 0
    isLoading.value = false
    isLoadingMore.value = false
    isMarkingAll.value = false
    error.value = null
    currentPage.value = 1
    lastPage.value = 1
    total.value = 0
    hasLoadedOnce.value = false
  }

  return {
    // State
    items,
    unreadCount,
    isLoading,
    isLoadingMore,
    isMarkingAll,
    error,
    currentPage,
    lastPage,
    total,
    hasLoadedOnce,

    // Computed
    hasMore,
    badgeLabel,

    // Actions
    fetchUnreadCount,
    fetchNotifications,
    loadMore,
    markAsRead,
    markAllAsRead,
    handleRealtimeNotification,
    $reset,
  }
})
