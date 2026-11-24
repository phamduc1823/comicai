import { httpClient } from './httpClient'
import type { PaginatedResponse } from '@/types/catalog'
import type {
  Report,
  StoreReportPayload,
  UpdateReportPayload,
  ListReportsParams,
  ReportActionResponse,
} from '@/types/report'

export class ReportService {
  /**
   * Submit a new violation report.
   * POST /api/reports
   * Accessible by authenticated users (reader, creator, admin).
   */
  static async submitReport(payload: StoreReportPayload): Promise<ReportActionResponse> {
    const response = await httpClient.post<ReportActionResponse>('/reports', {
      reportable_type: payload.reportable_type,
      reportable_id: payload.reportable_id,
      reason: payload.reason.trim(),
      description: payload.description?.trim() || null,
    })
    return response.data
  }

  /**
   * Get paginated list of reports for Admin moderation.
   * GET /api/admin/reports
   * Strictly Admin only.
   */
  static async getReports(params?: ListReportsParams): Promise<PaginatedResponse<Report>> {
    const response = await httpClient.get<PaginatedResponse<Report>>('/admin/reports', {
      params,
    })
    return response.data
  }

  /**
   * Get details of a single report.
   * GET /api/admin/reports/{id}
   * Strictly Admin only.
   */
  static async getReport(id: number | string): Promise<{ status: string; data: Report }> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.get<{ status: string; data: Report }>(
      `/admin/reports/${encodedId}`
    )
    return response.data
  }

  /**
   * Resolve or dismiss a report.
   * PATCH /api/admin/reports/{id}
   * Strictly Admin only.
   */
  static async updateReport(
    id: number | string,
    payload: UpdateReportPayload
  ): Promise<ReportActionResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.patch<ReportActionResponse>(
      `/admin/reports/${encodedId}`,
      {
        status: payload.status,
        admin_notes: payload.admin_notes?.trim() || null,
      }
    )
    return response.data
  }
}
