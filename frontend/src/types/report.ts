/**
 * Violation reporting types for ComicAI (BlueToon).
 * Matches backend ReportResource, ReportController, and StoreReportRequest.
 */

export type ReportableType = 'post' | 'comment' | 'comic' | 'chapter' | 'user'

export type ReportStatus = 'pending' | 'resolved' | 'dismissed'

export interface ReportUserSummary {
  id: number
  name: string
  avatar_url: string | null
  role: string
}

export interface ReportTarget {
  id: number
  type: ReportableType
  exists: boolean
  summary?: string
  // Target: post / comment
  content?: string
  user_id?: number
  has_image?: boolean
  parent_id?: number | null
  // Target: comic / chapter
  title?: string
  slug?: string
  approval_status?: string
  comic_id?: number
  chapter_number?: string
  status?: string
  // Target: user
  name?: string
  role?: string
  avatar_url?: string | null
}

export interface Report {
  id: number
  reporter: ReportUserSummary | null
  reportable_type: ReportableType
  reportable_id: number
  target: ReportTarget | null
  reason: string
  description: string | null
  status: ReportStatus
  resolver: ReportUserSummary | null
  resolved_at: string | null
  admin_notes: string | null
  created_at: string | null
  updated_at: string | null
}

export interface StoreReportPayload {
  reportable_type: ReportableType
  reportable_id: number
  reason: string
  description?: string | null
}

export interface UpdateReportPayload {
  status: 'resolved' | 'dismissed'
  admin_notes?: string | null
}

export interface ListReportsParams {
  status?: ReportStatus
  reason?: string
  reportable_type?: ReportableType
  per_page?: number
  page?: number
}

export interface ReportActionResponse {
  status: 'success'
  message: string
  data: Report
}
