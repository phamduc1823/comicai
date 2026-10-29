import type { ChapterImage, Genre, Tag } from './catalog'

/**
 * Proposed metadata changes submitted by a creator for an approved comic.
 */
export interface AdminComicPendingChanges {
  title?: string
  slug?: string
  author_name?: string
  description?: string | null
  age_rating?: 'all' | '13+' | '16+' | '18+'
  status?: 'ongoing' | 'completed' | 'hiatus'
  cover_path?: string
  cover_url?: string
  genres?: number[]
  tags?: number[]
  [key: string]: unknown
}

/**
 * Comic entity serialized from backend AdminModerationComicResource.
 */
export interface AdminModerationComic {
  id: number
  title: string
  slug: string
  alternative_titles: string | null
  author_name: string
  description: string | null
  cover_path: string | null
  cover_url: string | null
  banner_path: string | null
  banner_url: string | null
  age_rating: 'all' | '13+' | '16+' | '18+'
  status: 'ongoing' | 'completed' | 'hiatus'
  approval_status: 'draft' | 'pending' | 'approved' | 'rejected'
  rejection_reason: string | null
  pending_changes: AdminComicPendingChanges | null
  has_pending_changes: boolean
  views_count: number
  follows_count: number
  is_featured: boolean
  genres: Genre[]
  tags: Tag[]
  creator: {
    id: number
    name: string
    avatar_path: string | null
  } | null
  approver: {
    id: number
    name: string
  } | null
  approved_at: string | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Comic summary nested inside AdminModerationChapterResource.
 */
export interface AdminModerationChapterComic {
  id: number
  title: string
  slug: string
  approval_status: 'draft' | 'pending' | 'approved' | 'rejected'
  status: 'ongoing' | 'completed' | 'hiatus'
  creator: {
    id: number
    name: string
    avatar_path: string | null
  } | null
}

/**
 * Chapter entity serialized from backend AdminModerationChapterResource.
 */
export interface AdminModerationChapter {
  id: number
  comic_id: number
  chapter_number: string
  title: string | null
  slug: string
  status: 'draft' | 'pending' | 'published' | 'rejected'
  rejection_reason: string | null
  approved_by: number | null
  approved_at: string | null
  views_count: number
  images_count: number
  images?: ChapterImage[]
  comic?: AdminModerationChapterComic | null
  approver?: {
    id: number
    name: string
  } | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Filter options for GET /api/admin/moderation/comics.
 */
export type ModerationComicFilterType = 'all' | 'new' | 'changes'

/**
 * Query parameters for GET /api/admin/moderation/comics.
 */
export interface ListAdminModerationComicsParams {
  type?: ModerationComicFilterType
  q?: string
  page?: number
  per_page?: number
}

/**
 * Query parameters for GET /api/admin/moderation/chapters.
 */
export interface ListAdminModerationChaptersParams {
  comic_id?: number | string
  page?: number
  per_page?: number
}

/**
 * Payload sent to POST /api/admin/moderation/comics/{id}/reject.
 * Rejection reason must be between 5 and 1000 characters.
 */
export interface RejectComicPayload {
  rejection_reason: string
}

/**
 * Payload sent to POST /api/admin/moderation/comics/{id}/reject-changes.
 * Rejection reason must be between 5 and 1000 characters.
 */
export interface RejectComicChangesPayload {
  rejection_reason: string
}

/**
 * Payload sent to POST /api/admin/moderation/chapters/{id}/reject.
 * Rejection reason must be between 5 and 500 characters.
 */
export interface RejectChapterPayload {
  rejection_reason: string
}

/**
 * Unified response envelope for admin moderation actions (approve, reject).
 */
export interface ModerationActionResponse<T> {
  status: 'success'
  message: string
  data: T
}
