/**
 * Types for Notification System matching Laravel Backend NotificationResource
 * Implemented in CAI-NOTIF-01
 */

export type NotificationKind =
  | 'new_message'
  | 'post_liked'
  | 'post_commented'
  | 'creator_application_approved'
  | 'creator_application_rejected'
  | 'comic_submitted_for_review'
  | 'comic_review_result'
  | 'chapter_batch_review_result'
  | string

export interface NotificationActor {
  id: number | null
  name: string
  avatar_path: string | null
  avatar_url: string | null
  role: string
}

export interface NotificationTarget {
  type: 'conversation' | 'post' | 'creator_application' | 'comic_moderation' | 'creator_comic' | string
  id: number | null
  message_id?: number | null
  url: string
}

export interface NotificationItem {
  id: string
  type: NotificationKind
  title: string
  body: string
  actor: NotificationActor | null
  target: NotificationTarget | null
  is_read: boolean
  read_at: string | null
  created_at: string | null
}

export interface NotificationsPaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  unread_count: number
}

export interface NotificationsListResponse {
  data: NotificationItem[]
  meta: NotificationsPaginationMeta
}

export interface NotificationCreatedPayload {
  notification: NotificationItem
  unread_count: number
}
