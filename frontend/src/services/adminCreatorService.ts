import { httpClient } from './httpClient'
import type {
  ListAdminCreatorApplicationsParams,
  AdminCreatorApplicationListResponse,
  ListAdminCreatorsParams,
  AdminCreatorListResponse,
  AdminCreatorDetailResponse,
  RejectCreatorApplicationPayload,
  RevokeCreatorPayload,
  ReapplyReasonPayload,
} from '@/types/adminCreator'
import type {
  CreatorApplication,
  CreatorProfileInfo,
  ApplicationActionResponse,
} from '@/types/creatorApplication'

export class AdminCreatorService {
  /**
   * Fetch paginated list of creator applications with search and filters.
   * GET /api/admin/creator-applications
   */
  static async getApplications(
    params?: ListAdminCreatorApplicationsParams
  ): Promise<AdminCreatorApplicationListResponse> {
    const response = await httpClient.get<AdminCreatorApplicationListResponse>(
      '/admin/creator-applications',
      { params }
    )
    return response.data
  }

  /**
   * Fetch detail of a single creator application with loaded histories.
   * GET /api/admin/creator-applications/{id}
   */
  static async getApplication(id: number | string): Promise<{ data: CreatorApplication }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.get<{ data: CreatorApplication }>(
      `/admin/creator-applications/${encodedId}`
    )
    return response.data
  }

  /**
   * Approve a pending creator application.
   * POST /api/admin/creator-applications/{id}/approve
   */
  static async approveApplication(id: number | string): Promise<ApplicationActionResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<ApplicationActionResponse>(
      `/admin/creator-applications/${encodedId}/approve`
    )
    return response.data
  }

  /**
   * Reject a pending creator application with mandatory reason.
   * POST /api/admin/creator-applications/{id}/reject
   */
  static async rejectApplication(
    id: number | string,
    payload: RejectCreatorApplicationPayload
  ): Promise<ApplicationActionResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<ApplicationActionResponse>(
      `/admin/creator-applications/${encodedId}/reject`,
      payload
    )
    return response.data
  }

  /**
   * Trigger browser download of filtered applications in Microsoft Excel (.xlsx) format.
   * GET /api/admin/creator-applications/export
   */
  static async exportApplications(params?: { status?: string; q?: string }): Promise<void> {
    const response = await httpClient.get('/admin/creator-applications/export', {
      params,
      responseType: 'blob',
    })

    const blob = new Blob([response.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `danh_sach_ho_so_creator_${new Date().toISOString().slice(0, 10)}.xlsx`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
  }

  /**
   * Fetch paginated list of creators with search and status filters.
   * GET /api/admin/creators
   */
  static async getCreators(params?: ListAdminCreatorsParams): Promise<AdminCreatorListResponse> {
    const response = await httpClient.get<AdminCreatorListResponse>('/admin/creators', {
      params,
    })
    return response.data
  }

  /**
   * Fetch detail of a creator with comic counts, pen name history, and audit logs.
   * GET /api/admin/creators/{id}
   */
  static async getCreator(id: number | string): Promise<AdminCreatorDetailResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.get<AdminCreatorDetailResponse>(
      `/admin/creators/${encodedId}`
    )
    return response.data
  }

  /**
   * Revoke creator privileges with mandatory reason.
   * POST /api/admin/creators/{id}/revoke
   */
  static async revokeCreator(
    id: number | string,
    payload: RevokeCreatorPayload
  ): Promise<{ message: string; data: CreatorProfileInfo }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<{ message: string; data: CreatorProfileInfo }>(
      `/admin/creators/${encodedId}/revoke`,
      payload
    )
    return response.data
  }

  /**
   * Grant 1-time reapply permission to a revoked creator with mandatory reason.
   * POST /api/admin/creators/{id}/grant-reapply
   */
  static async grantReapply(
    id: number | string,
    payload: ReapplyReasonPayload
  ): Promise<{ message: string; data: CreatorProfileInfo }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<{ message: string; data: CreatorProfileInfo }>(
      `/admin/creators/${encodedId}/grant-reapply`,
      payload
    )
    return response.data
  }

  /**
   * Revoke 1-time reapply permission before creator submits with mandatory reason.
   * POST /api/admin/creators/{id}/revoke-reapply
   */
  static async revokeReapply(
    id: number | string,
    payload: ReapplyReasonPayload
  ): Promise<{ message: string; data: CreatorProfileInfo }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<{ message: string; data: CreatorProfileInfo }>(
      `/admin/creators/${encodedId}/revoke-reapply`,
      payload
    )
    return response.data
  }

  /**
   * Trigger browser download of filtered creators in Microsoft Excel (.xlsx) format.
   * GET /api/admin/creators/export
   */
  static async exportCreators(params?: { status?: string; q?: string }): Promise<void> {
    const response = await httpClient.get('/admin/creators/export', {
      params,
      responseType: 'blob',
    })

    const blob = new Blob([response.data], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `danh_sach_creator_${new Date().toISOString().slice(0, 10)}.xlsx`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
  }
}
