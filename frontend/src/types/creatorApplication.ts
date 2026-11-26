/**
 * Creator Application status.
 */
export type CreatorApplicationStatus = 'pending' | 'approved' | 'rejected' | 'canceled'

/**
 * Action types recorded in application histories.
 */
export type CreatorApplicationActionType =
  | 'submitted'
  | 'resubmitted'
  | 'rejected'
  | 'approved'
  | 'canceled'

/**
 * Historical snapshot entity of a creator application version.
 */
export interface CreatorApplicationHistory {
  id: number
  application_id: number
  version: number
  pen_name: string
  bio: string
  reason: string
  sample_works: string[] | null
  status: CreatorApplicationStatus
  rejection_reason: string | null
  action_type: CreatorApplicationActionType
  action_by: number | null
  action_at: string
  snapshot_data?: Record<string, unknown> | null
  created_at: string
}

/**
 * Creator Application entity matching backend CreatorApplicationResource.
 */
export interface CreatorApplication {
  id: number
  application_code: string
  user_id: number
  pen_name: string
  bio: string
  reason: string
  sample_works: string[] | null
  status: CreatorApplicationStatus
  rejection_reason: string | null
  canceled_at: string | null
  version: number
  reviewed_at: string | null
  reviewed_by: number | null
  reviewer?: {
    id: number
    name: string
    email?: string
  } | null
  user?: {
    id: number
    name: string
    email?: string
    avatar_path: string | null
  } | null
  histories?: CreatorApplicationHistory[]
  created_at: string
  updated_at: string
}

/**
 * Creator Profile info matching backend CreatorProfileResource.
 */
export interface CreatorProfileInfo {
  id: number
  user_id: number
  creator_code: string
  pen_name: string
  bio: string | null
  status: 'active' | 'revoked'
  approved_at: string | null
  revoked_at: string | null
  can_reapply: boolean
  reapply_granted_at: string | null
  reapply_grant_reason: string | null
  reapplied_at: string | null
  user?: {
    id: number
    name: string
    email?: string
    avatar_path: string | null
  } | null
  comics_count?: number
  approved_comics_count?: number
  pending_comics_count?: number
  rejected_comics_count?: number
  chapters_count?: number
  created_at?: string
  updated_at?: string
}

/**
 * Response returned by GET /api/creator-applications/my.
 */
export interface MyApplicationResponse {
  application: CreatorApplication | null
  profile: CreatorProfileInfo | null
}

/**
 * Payload sent to POST /api/creator-applications and PUT /api/creator-applications/{id}/resubmit.
 */
export interface SubmitApplicationPayload {
  pen_name: string
  bio: string
  reason: string
  sample_works?: string[]
}

/**
 * Successful response from store, cancel, and resubmit application actions.
 */
export interface ApplicationActionResponse {
  message: string
  data: CreatorApplication
}
