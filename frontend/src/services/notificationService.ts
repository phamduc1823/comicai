import { httpClient } from '@/services/httpClient'
import type {
  NotificationItem,
  NotificationsListResponse,
} from '@/types/notification'

export class NotificationService {
  /**
   * Fetch paginated notifications for the authenticated user.
   */
  static async getNotifications(
    page = 1,
    perPage = 15,
    unreadOnly = false
  ): Promise<NotificationsListResponse> {
    const response = await httpClient.get<NotificationsListResponse>('/notifications', {
      params: {
        page,
        per_page: perPage,
        ...(unreadOnly ? { unread_only: 1 } : {}),
      },
    })
    return response.data
  }

  /**
   * Fetch exact unread notification count for the authenticated user.
   */
  static async getUnreadCount(): Promise<number> {
    const response = await httpClient.get<{ data: { unread_count: number } }>('/notifications/unread-count')
    return response.data.data.unread_count
  }

  /**
   * Mark a single notification as read.
   */
  static async markAsRead(
    notificationId: string
  ): Promise<{ data: NotificationItem; meta: { unread_count: number } }> {
    const response = await httpClient.patch<{
      status: string
      data: NotificationItem
      meta: { unread_count: number }
    }>(`/notifications/${notificationId}/read`)
    return {
      data: response.data.data,
      meta: response.data.meta,
    }
  }

  /**
   * Mark all unread notifications as read.
   */
  static async markAllAsRead(): Promise<{ updated_count: number; unread_count: number }> {
    const response = await httpClient.post<{
      status: string
      data: { updated_count: number; unread_count: number }
    }>('/notifications/read-all')
    return response.data.data
  }
}
