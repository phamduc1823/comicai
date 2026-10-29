import { httpClient } from './httpClient'
import type { PaginatedResponse } from '@/types/catalog'
import type {
  AdminModerationComic,
  AdminModerationChapter,
  ListAdminModerationComicsParams,
  ListAdminModerationChaptersParams,
  RejectComicPayload,
  RejectComicChangesPayload,
  RejectChapterPayload,
  ModerationActionResponse,
} from '@/types/admin'

export class AdminService {
  /**
   * Fetch paginated list of comics in the moderation queue.
   * GET /api/admin/moderation/comics
   * Supports filtering by type ('all' | 'new' | 'changes'), search term 'q', and pagination.
   */
  static async getModerationComics(
    params?: ListAdminModerationComicsParams
  ): Promise<PaginatedResponse<AdminModerationComic>> {
    const response = await httpClient.get<PaginatedResponse<AdminModerationComic>>(
      '/admin/moderation/comics',
      { params }
    )
    return response.data
  }

  /**
   * Approve a pending new comic.
   * POST /api/admin/moderation/comics/{id}/approve
   */
  static async approveComic(
    id: number | string
  ): Promise<ModerationActionResponse<AdminModerationComic>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<ModerationActionResponse<AdminModerationComic>>(
      `/admin/moderation/comics/${encodedId}/approve`
    )
    return response.data
  }

  /**
   * Reject a pending new comic with reason.
   * POST /api/admin/moderation/comics/{id}/reject
   * Rejection reason must be between 5 and 1000 characters.
   */
  static async rejectComic(
    id: number | string,
    payload: RejectComicPayload
  ): Promise<ModerationActionResponse<AdminModerationComic>> {
    const encodedId = encodeURIComponent(String(id))
    const trimmedReason = payload.rejection_reason.trim()
    const response = await httpClient.post<ModerationActionResponse<AdminModerationComic>>(
      `/admin/moderation/comics/${encodedId}/reject`,
      { rejection_reason: trimmedReason }
    )
    return response.data
  }

  /**
   * Approve proposed pending changes for an already approved comic.
   * POST /api/admin/moderation/comics/{id}/approve-changes
   */
  static async approveComicChanges(
    id: number | string
  ): Promise<ModerationActionResponse<AdminModerationComic>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<ModerationActionResponse<AdminModerationComic>>(
      `/admin/moderation/comics/${encodedId}/approve-changes`
    )
    return response.data
  }

  /**
   * Reject proposed pending changes for an already approved comic.
   * POST /api/admin/moderation/comics/{id}/reject-changes
   * Rejection reason must be between 5 and 1000 characters.
   */
  static async rejectComicChanges(
    id: number | string,
    payload: RejectComicChangesPayload
  ): Promise<ModerationActionResponse<AdminModerationComic>> {
    const encodedId = encodeURIComponent(String(id))
    const trimmedReason = payload.rejection_reason.trim()
    const response = await httpClient.post<ModerationActionResponse<AdminModerationComic>>(
      `/admin/moderation/comics/${encodedId}/reject-changes`,
      { rejection_reason: trimmedReason }
    )
    return response.data
  }

  /**
   * Fetch paginated list of chapters in the moderation queue (status=pending).
   * GET /api/admin/moderation/chapters
   * Supports filtering by comic_id and pagination.
   */
  static async getModerationChapters(
    params?: ListAdminModerationChaptersParams
  ): Promise<PaginatedResponse<AdminModerationChapter>> {
    const response = await httpClient.get<PaginatedResponse<AdminModerationChapter>>(
      '/admin/moderation/chapters',
      { params }
    )
    return response.data
  }

  /**
   * Approve and publish a pending chapter.
   * POST /api/admin/moderation/chapters/{id}/approve
   * Note: Parent comic must be approved; backend returns 422 if comic is not approved.
   */
  static async approveChapter(
    id: number | string
  ): Promise<ModerationActionResponse<AdminModerationChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<ModerationActionResponse<AdminModerationChapter>>(
      `/admin/moderation/chapters/${encodedId}/approve`
    )
    return response.data
  }

  /**
   * Reject a pending chapter with reason.
   * POST /api/admin/moderation/chapters/{id}/reject
   * Rejection reason must be between 5 and 500 characters.
   */
  static async rejectChapter(
    id: number | string,
    payload: RejectChapterPayload
  ): Promise<ModerationActionResponse<AdminModerationChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const trimmedReason = payload.rejection_reason.trim()
    const response = await httpClient.post<ModerationActionResponse<AdminModerationChapter>>(
      `/admin/moderation/chapters/${encodedId}/reject`,
      { rejection_reason: trimmedReason }
    )
    return response.data
  }
}
