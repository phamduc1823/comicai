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
  AdminBanner,
  ReorderBannersPayload,
} from '@/types/admin'
import type {
  Report,
  UpdateReportPayload,
  ListReportsParams,
  ReportActionResponse,
} from '@/types/report'
import { ReportService } from './reportService'

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
   * CAI-ADMIN-03: Supports optionally approving selected chapter IDs alongside the comic.
   * POST /api/admin/moderation/comics/{id}/approve
   */
  static async approveComic(
    id: number | string,
    chapterIds?: number[]
  ): Promise<ModerationActionResponse<AdminModerationComic>> {
    const encodedId = encodeURIComponent(String(id))
    const payload: { chapter_ids?: number[] } = {}
    if (chapterIds && chapterIds.length > 0) {
      payload.chapter_ids = chapterIds
    }
    const response = await httpClient.post<ModerationActionResponse<AdminModerationComic>>(
      `/admin/moderation/comics/${encodedId}/approve`,
      payload
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

  /**
   * Fetch all banners for admin management.
   * GET /api/admin/banners
   */
  static async getBanners(): Promise<{ data: AdminBanner[] }> {
    const response = await httpClient.get<{ data: AdminBanner[] }>('/admin/banners')
    return response.data
  }

  /**
   * Create a new homepage slider banner.
   * POST /api/admin/banners
   */
  static async createBanner(
    formData: FormData
  ): Promise<{ status: string; message: string; data: AdminBanner }> {
    const response = await httpClient.post<{ status: string; message: string; data: AdminBanner }>(
      '/admin/banners',
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
      }
    )
    return response.data
  }

  /**
   * Update an existing banner (supports multipart upload via POST /api/admin/banners/{id}).
   */
  static async updateBanner(
    id: number | string,
    formData: FormData
  ): Promise<{ status: string; message: string; data: AdminBanner }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<{ status: string; message: string; data: AdminBanner }>(
      `/admin/banners/${encodedId}`,
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
      }
    )
    return response.data
  }

  /**
   * Delete a banner.
   * DELETE /api/admin/banners/{id}
   */
  static async deleteBanner(id: number | string): Promise<{ status: string; message: string }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.delete<{ status: string; message: string }>(
      `/admin/banners/${encodedId}`
    )
    return response.data
  }

  /**
   * Toggle or update active status of a banner.
   * PATCH /api/admin/banners/{id}/status
   */
  static async toggleBannerStatus(
    id: number | string,
    isActive?: boolean
  ): Promise<{ status: string; message: string; data: AdminBanner }> {
    const encodedId = encodeURIComponent(String(id))
    const payload = isActive !== undefined ? { is_active: isActive } : {}
    const response = await httpClient.patch<{ status: string; message: string; data: AdminBanner }>(
      `/admin/banners/${encodedId}/status`,
      payload
    )
    return response.data
  }

  /**
   * Batch update banner sort orders.
   * PATCH /api/admin/banners/reorder
   */
  static async reorderBanners(
    payload: ReorderBannersPayload
  ): Promise<{ status: string; message: string }> {
    const response = await httpClient.patch<{ status: string; message: string }>(
      '/admin/banners/reorder',
      payload
    )
    return response.data
  }

  /**
   * Fetch paginated list of violation reports for moderation.
   * GET /api/admin/reports
   */
  static async getReports(params?: ListReportsParams): Promise<PaginatedResponse<Report>> {
    return ReportService.getReports(params)
  }

  /**
   * Fetch single violation report details.
   * GET /api/admin/reports/{id}
   */
  static async getReport(id: number | string): Promise<{ status: string; data: Report }> {
    return ReportService.getReport(id)
  }

  /**
   * Resolve or dismiss a violation report.
   * PATCH /api/admin/reports/{id}
   */
  static async updateReport(
    id: number | string,
    payload: UpdateReportPayload
  ): Promise<ReportActionResponse> {
    return ReportService.updateReport(id, payload)
  }

  // =========================================================
  // Module C: Admin Dashboard
  // =========================================================
  static async getDashboardStats(): Promise<{ status: string; data: import('@/types/admin').AdminDashboardStats }> {
    const response = await httpClient.get<{ status: string; data: import('@/types/admin').AdminDashboardStats }>(
      '/admin/dashboard/stats'
    )
    return response.data
  }

  // =========================================================
  // Module B: Users Management
  // =========================================================
  static async getUsers(params?: {
    q?: string
    role?: string
    is_active?: string | boolean
    email_verified?: string
    sort_by?: string
    sort_dir?: string
    page?: number
    per_page?: number
  }): Promise<PaginatedResponse<import('@/types/admin').AdminUser>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminUser>>(
      '/admin/users',
      { params }
    )
    return response.data
  }

  static async getUser(id: number | string): Promise<{ status: string; data: any }> {
    const response = await httpClient.get<{ status: string; data: any }>(`/admin/users/${id}`)
    return response.data
  }

  static async toggleUserActive(id: number | string): Promise<{ status: string; message: string; data: import('@/types/admin').AdminUser }> {
    const response = await httpClient.patch<{ status: string; message: string; data: import('@/types/admin').AdminUser }>(
      `/admin/users/${id}/toggle-active`
    )
    return response.data
  }

  static async updateUserRole(
    id: number | string,
    role: 'reader' | 'creator' | 'admin'
  ): Promise<{ status: string; message: string; data: import('@/types/admin').AdminUser }> {
    const response = await httpClient.patch<{ status: string; message: string; data: import('@/types/admin').AdminUser }>(
      `/admin/users/${id}/role`,
      { role }
    )
    return response.data
  }

  // =========================================================
  // Module A: Genres & Tags Management
  // =========================================================
  static async getGenres(params?: {
    q?: string
    is_active?: string | boolean
    sort_by?: string
    sort_dir?: string
    page?: number
    per_page?: number | string
  }): Promise<PaginatedResponse<import('@/types/admin').AdminGenre>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminGenre>>(
      '/admin/genres',
      { params }
    )
    return response.data
  }

  static async createGenre(payload: import('@/types/admin').CreateGenrePayload): Promise<{ status: string; message: string; data: import('@/types/admin').AdminGenre }> {
    const response = await httpClient.post<{ status: string; message: string; data: import('@/types/admin').AdminGenre }>(
      '/admin/genres',
      payload
    )
    return response.data
  }

  static async updateGenre(
    id: number | string,
    payload: import('@/types/admin').UpdateGenrePayload
  ): Promise<{ status: string; message: string; data: import('@/types/admin').AdminGenre }> {
    const response = await httpClient.put<{ status: string; message: string; data: import('@/types/admin').AdminGenre }>(
      `/admin/genres/${id}`,
      payload
    )
    return response.data
  }

  static async toggleGenreStatus(id: number | string): Promise<{ status: string; message: string; data: import('@/types/admin').AdminGenre }> {
    const response = await httpClient.patch<{ status: string; message: string; data: import('@/types/admin').AdminGenre }>(
      `/admin/genres/${id}/toggle-status`
    )
    return response.data
  }

  static async deleteGenre(id: number | string): Promise<{ status: string; message: string }> {
    const response = await httpClient.delete<{ status: string; message: string }>(`/admin/genres/${id}`)
    return response.data
  }

  static async getTags(params?: {
    q?: string
    sort_by?: string
    sort_dir?: string
    page?: number
    per_page?: number | string
  }): Promise<PaginatedResponse<import('@/types/admin').AdminTag>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminTag>>(
      '/admin/tags',
      { params }
    )
    return response.data
  }

  static async createTag(payload: import('@/types/admin').CreateTagPayload): Promise<{ status: string; message: string; data: import('@/types/admin').AdminTag }> {
    const response = await httpClient.post<{ status: string; message: string; data: import('@/types/admin').AdminTag }>(
      '/admin/tags',
      payload
    )
    return response.data
  }

  static async updateTag(
    id: number | string,
    payload: import('@/types/admin').UpdateTagPayload
  ): Promise<{ status: string; message: string; data: import('@/types/admin').AdminTag }> {
    const response = await httpClient.put<{ status: string; message: string; data: import('@/types/admin').AdminTag }>(
      `/admin/tags/${id}`,
      payload
    )
    return response.data
  }

  static async deleteTag(id: number | string): Promise<{ status: string; message: string }> {
    const response = await httpClient.delete<{ status: string; message: string }>(`/admin/tags/${id}`)
    return response.data
  }

  // =========================================================
  // Module D & E: Catalog Management (All Comics & Chapters)
  // =========================================================
  static async getComicsCatalog(
    params?: import('@/types/admin').ListAdminComicsCatalogParams
  ): Promise<PaginatedResponse<import('@/types/admin').AdminComicCatalogItem>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminComicCatalogItem>>(
      '/admin/comics',
      { params }
    )
    return response.data
  }

  static async getComicCatalogDetail(id: number | string): Promise<{ status: string; data: import('@/types/admin').AdminComicDetail }> {
    const response = await httpClient.get<{ status: string; data: import('@/types/admin').AdminComicDetail }>(`/admin/comics/${id}`)
    return response.data
  }

  static async getComicDetail(id: number | string): Promise<{ status: string; data: import('@/types/admin').AdminComicDetail }> {
    return this.getComicCatalogDetail(id)
  }

  static async getComicChapters(
    comicId: number | string,
    params?: import('@/types/admin').ListAdminComicChaptersParams
  ): Promise<PaginatedResponse<import('@/types/admin').AdminComicChapterItem> & { comic?: { id: number; title: string; slug: string } }> {
    const response = await httpClient.get<
      PaginatedResponse<import('@/types/admin').AdminComicChapterItem> & { comic?: { id: number; title: string; slug: string } }
    >(`/admin/comics/${comicId}/chapters`, { params })
    return response.data
  }

  static async getComicChapterDetail(
    comicId: number | string,
    chapterId: number | string
  ): Promise<{ status: string; comic?: { id: number; title: string; slug: string; user_id: number }; data: import('@/types/admin').AdminComicChapterDetail }> {
    const response = await httpClient.get<{
      status: string
      comic?: { id: number; title: string; slug: string; user_id: number }
      data: import('@/types/admin').AdminComicChapterDetail
    }>(`/admin/comics/${comicId}/chapters/${chapterId}`)
    return response.data
  }

  static async updateComicChapterStatus(
    comicId: number | string,
    chapterId: number | string,
    payload: { status: string; rejection_reason?: string }
  ): Promise<{ status: string; message: string; data: import('@/types/admin').AdminComicChapterItem }> {
    const response = await httpClient.patch<{ status: string; message: string; data: import('@/types/admin').AdminComicChapterItem }>(
      `/admin/comics/${comicId}/chapters/${chapterId}/status`,
      payload
    )
    return response.data
  }

  static async updateComicStatus(
    id: number | string,
    payload: { status?: string; is_featured?: boolean }
  ): Promise<{ status: string; message: string; data: any }> {
    const response = await httpClient.patch<{ status: string; message: string; data: any }>(
      `/admin/comics/${id}/status`,
      payload
    )
    return response.data
  }

  static async updateComicVip(
    id: number | string,
    payload: { is_vip: boolean; price: number }
  ): Promise<{ status: string; message: string; data: any }> {
    const response = await httpClient.patch<{ status: string; message: string; data: any }>(
      `/admin/comics/${id}/vip`,
      payload
    )
    return response.data
  }

  static async updateComicApprovalStatus(
    id: number | string,
    payload: { approval_status: string; rejection_reason?: string; chapter_ids?: number[] }
  ): Promise<{ status: string; message: string; data: any }> {
    const response = await httpClient.patch<{ status: string; message: string; data: any }>(
      `/admin/comics/${id}/approval-status`,
      payload
    )
    return response.data
  }

  static async getChaptersCatalog(params?: {
    comic_id?: number | string
    status?: string
    q?: string
    sort_by?: string
    sort_dir?: string
    page?: number
    per_page?: number
  }): Promise<PaginatedResponse<import('@/types/admin').AdminChapterCatalogItem>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminChapterCatalogItem>>(
      '/admin/chapters',
      { params }
    )
    return response.data
  }

  static async getChapterCatalogDetail(id: number | string): Promise<{ status: string; data: any }> {
    const response = await httpClient.get<{ status: string; data: any }>(`/admin/chapters/${id}`)
    return response.data
  }

  static async updateChapterCatalogStatus(
    id: number | string,
    payload: { status: string; rejection_reason?: string }
  ): Promise<{ status: string; message: string; data: any }> {
    const response = await httpClient.patch<{ status: string; message: string; data: any }>(
      `/admin/chapters/${id}/status`,
      payload
    )
    return response.data
  }

  // =========================================================
  // Module F & G: Wallet Ledger & VIP Purchases
  // =========================================================
  static async getWallets(params?: {
    q?: string
    sort_by?: string
    sort_dir?: string
    page?: number
    per_page?: number
  }): Promise<PaginatedResponse<import('@/types/admin').AdminWalletItem>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminWalletItem>>(
      '/admin/wallets',
      { params }
    )
    return response.data
  }

  static async getCoinTransactions(params?: {
    user_id?: number | string
    type?: string
    q?: string
    page?: number
    per_page?: number
  }): Promise<PaginatedResponse<import('@/types/admin').AdminCoinTransactionItem>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminCoinTransactionItem>>(
      '/admin/coin-transactions',
      { params }
    )
    return response.data
  }

  static async getComicPurchases(params?: {
    status?: string
    user_id?: number | string
    comic_id?: number | string
    q?: string
    page?: number
    per_page?: number
  }): Promise<PaginatedResponse<import('@/types/admin').AdminComicPurchaseItem>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminComicPurchaseItem>>(
      '/admin/comic-purchases',
      { params }
    )
    return response.data
  }

  static async refundPurchase(
    payload: import('@/types/admin').AdminRefundPayload
  ): Promise<{ status: string; message: string; data: any }> {
    const response = await httpClient.post<{ status: string; message: string; data: any }>(
      '/admin/comic-purchases/refund',
      payload
    )
    return response.data
  }

  // =========================================================
  // Module H: System Broadcast Notifications
  // =========================================================
  static async broadcastNotification(
    payload: import('@/types/admin').AdminBroadcastPayload
  ): Promise<{ status: string; message: string; data: any }> {
    const response = await httpClient.post<{ status: string; message: string; data: any }>(
      '/admin/notifications/broadcast',
      payload
    )
    return response.data
  }

  static async getBroadcastsHistory(params?: {
    page?: number
    per_page?: number
  }): Promise<PaginatedResponse<import('@/types/admin').AdminBroadcastHistoryItem>> {
    const response = await httpClient.get<PaginatedResponse<import('@/types/admin').AdminBroadcastHistoryItem>>(
      '/admin/notifications/broadcasts',
      { params }
    )
    return response.data
  }
}
