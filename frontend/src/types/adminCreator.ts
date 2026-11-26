import type { PaginationMeta } from '@/types/catalog'
import type {
  CreatorApplication,
  CreatorApplicationStatus,
  CreatorProfileInfo,
} from '@/types/creatorApplication'

/**
 * Statistics counters for creator applications queue.
 */
export interface AdminCreatorApplicationStats {
  all: number
  pending: number
  approved: number
  rejected: number
  canceled: number
}

/**
 * Statistics counters for creators lifecycle management.
 */
export interface AdminCreatorStats {
  all: number
  active: number
  revoked: number
}

/**
 * Filter and search query parameters for creator applications list.
 */
export interface ListAdminCreatorApplicationsParams {
  page?: number
  per_page?: number
  status?: 'all' | CreatorApplicationStatus
  q?: string
}

/**
 * Filter and search query parameters for creators list.
 */
export interface ListAdminCreatorsParams {
  page?: number
  per_page?: number
  status?: 'all' | 'active' | 'revoked'
  q?: string
}

/**
 * Paginated response from GET /api/admin/creator-applications.
 */
export interface AdminCreatorApplicationListResponse {
  stats: AdminCreatorApplicationStats
  data: CreatorApplication[]
  meta: PaginationMeta
}

/**
 * Paginated response from GET /api/admin/creators.
 */
export interface AdminCreatorListResponse {
  stats: AdminCreatorStats
  data: CreatorProfileInfo[]
  meta: PaginationMeta
}

/**
 * Audit log event record matching backend CreatorAuditLogResource.
 */
export interface CreatorAuditLog {
  id: number
  user_id: number
  actor_id: number | null
  actor?: {
    id: number
    name: string
    email: string
  } | null
  application_id?: number | null
  application_code?: string | null
  event_type:
    | 'approved'
    | 'application_rejected'
    | 'pen_name_changed'
    | 'revoked'
    | 'reapply_granted'
    | 'reapplied'
    | 'reapply_rejected'
    | 'reapply_revoked'
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
  reason: string | null
  created_at: string
}

/**
 * Comic brief summary belonging to a creator.
 */
export interface CreatorComicSummary {
  id: number
  title: string
  slug: string
  approval_status: string
  created_at: string
}

/**
 * Pen name change record matching backend PenNameHistory.
 */
export interface PenNameHistoryItem {
  id: number
  user_id: number
  old_pen_name: string
  new_pen_name: string
  created_at: string
}

/**
 * Response from GET /api/admin/creators/{id}.
 */
export interface AdminCreatorDetailResponse {
  data: CreatorProfileInfo
  comics: CreatorComicSummary[]
  pen_name_history: PenNameHistoryItem[]
  audit_logs: CreatorAuditLog[]
}

/**
 * Payload for rejecting an application.
 */
export interface RejectCreatorApplicationPayload {
  reason: string
}

/**
 * Payload for revoking creator privileges.
 */
export interface RevokeCreatorPayload {
  reason: string
}

/**
 * Payload for granting or revoking reapply permission.
 */
export interface ReapplyReasonPayload {
  reason: string
}
