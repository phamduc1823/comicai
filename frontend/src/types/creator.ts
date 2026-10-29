import type { Genre, Tag, ChapterImage } from './catalog'

/**
 * Moderation approval status for comics in Creator Studio.
 */
export type ApprovalStatus = 'draft' | 'pending' | 'approved' | 'rejected'

/**
 * Comic ongoing/completed progress status.
 */
export type ComicProgressStatus = 'ongoing' | 'completed'

/**
 * Content age rating values accepted by backend validation.
 */
export type AgeRating = 'all' | '13+' | '16+' | '18+'

/**
 * Pending staged changes awaiting Admin moderation for an already approved comic.
 */
export interface PendingChanges {
  title?: string
  slug?: string
  author_name?: string
  description?: string | null
  cover_path?: string
  cover_url?: string | null
  age_rating?: AgeRating
  tags?: number[]
  genres?: number[]
  submitted_at?: string
}

/**
 * Creator Comic entity serialized from backend CreatorComicResource.
 */
export interface CreatorComic {
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
  age_rating: AgeRating
  status: ComicProgressStatus
  approval_status: ApprovalStatus
  rejection_reason: string | null
  pending_changes: PendingChanges | null
  views_count: number
  follows_count: number
  is_featured: boolean
  ai_summary: string | null
  chapters_count: number
  published_chapters_count: number
  genres: Genre[]
  tags: Tag[]
  creator?: {
    id: number
    name: string
    email?: string
    avatar_path: string | null
  } | null
  approver?: {
    id: number
    name: string
  } | null
  approved_at: string | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Query filter parameters supported by GET /api/creator/comics.
 */
export interface ListCreatorComicsParams {
  page?: number
  per_page?: number
  approval_status?: ApprovalStatus
  status?: ComicProgressStatus
  q?: string
}

/**
 * Payload sent to POST /api/creator/comics.
 */
export interface CreateComicPayload {
  title: string
  author_name: string
  cover: File
  description?: string | null
  alternative_titles?: string | null
  age_rating?: AgeRating
  status?: ComicProgressStatus
  genres: number[]
  tags?: number[]
}

/**
 * Payload sent to POST /api/creator/comics/{id} with _method=PUT.
 */
export interface UpdateComicPayload {
  title?: string
  author_name?: string
  cover?: File | null
  description?: string | null
  alternative_titles?: string | null
  age_rating?: AgeRating
  status?: ComicProgressStatus
  genres?: number[]
  tags?: number[]
}

/**
 * Generic success message response from creator actions.
 */
export interface ActionSuccessResponse {
  status: 'success'
  message: string
  data?: CreatorComic
}

/**
 * Moderation and publication status for chapters in Creator Studio.
 * Values: 'draft' | 'pending' | 'published' | 'rejected'
 */
export type ChapterStatus = 'draft' | 'pending' | 'published' | 'rejected'

/**
 * Chapter entity serialized from backend CreatorChapterResource.
 */
export interface CreatorChapter {
  id: number
  comic_id: number
  chapter_number: string
  title: string | null
  slug: string
  status: ChapterStatus
  rejection_reason: string | null
  views_count: number
  images_count: number
  images?: ChapterImage[]
  comic?: {
    id: number
    title: string
    slug: string
    user_id: number
  } | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Query filter parameters supported by GET /api/creator/comics/{comicId}/chapters.
 */
export interface ListCreatorChaptersParams {
  page?: number
  per_page?: number
  status?: ChapterStatus
}

/**
 * Payload sent to POST /api/creator/comics/{comicId}/chapters.
 */
export interface CreateChapterPayload {
  chapter_number: number | string
  title?: string | null
}

/**
 * Payload sent to PUT/PATCH /api/creator/chapters/{id}.
 */
export interface UpdateChapterPayload {
  chapter_number?: number | string
  title?: string | null
}

/**
 * Payload sent to PUT/PATCH /api/creator/chapters/{id}/images/reorder.
 */
export interface ReorderImagesPayload {
  image_ids?: number[]
  orders?: { id: number; page_number: number }[]
}

/**
 * Response returned from POST /api/creator/chapters/{id}/images.
 */
export interface UploadImagesSuccessResponse {
  status: 'success'
  message: string
  data: ChapterImage[]
}

/**
 * Response returned from PUT/PATCH /api/creator/chapters/{id}/images/reorder.
 */
export interface ReorderImagesSuccessResponse {
  status: 'success'
  message: string
  data: ChapterImage[]
}
