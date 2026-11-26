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

/**
 * Admin Banner entity matching backend AdminBannerResource.
 */
export interface AdminBanner {
  id: number
  comic_id: number
  title_override: string | null
  desktop_banner_path: string
  desktop_banner_url: string | null
  mobile_banner_path: string | null
  mobile_banner_url: string | null
  sort_order: number
  is_active: boolean
  created_at: string | null
  updated_at: string | null
  comic?: {
    id: number
    title: string
    slug: string
    approval_status: string
    cover_path: string | null
    cover_url: string | null
  }
}

export interface ReorderBannersPayload {
  orders: {
    id: number
    sort_order: number
  }[]
}

// -------------------------------------------------------------
// Module A: Genres & Tags
// -------------------------------------------------------------
export interface AdminGenre {
  id: number
  name: string
  slug: string
  description: string | null
  is_active: boolean
  comics_count?: number
  created_at: string | null
  updated_at: string | null
}

export interface CreateGenrePayload {
  name: string
  slug?: string
  description?: string
  is_active?: boolean
}

export interface UpdateGenrePayload {
  name: string
  slug?: string
  description?: string
  is_active?: boolean
}

export interface AdminTag {
  id: number
  name: string
  slug: string
  description: string | null
  comics_count?: number
  created_at: string | null
  updated_at: string | null
}

export interface CreateTagPayload {
  name: string
  slug?: string
  description?: string
}

export interface UpdateTagPayload {
  name: string
  slug?: string
  description?: string
}

// -------------------------------------------------------------
// Module B: Users Management
// -------------------------------------------------------------
export interface AdminUser {
  id: number
  name: string
  email: string
  role: 'reader' | 'creator' | 'admin'
  is_active: boolean
  avatar_path: string | null
  email_verified_at: string | null
  created_at: string | null
  comics_count?: number
  comic_purchases_count?: number
  wallet?: {
    id: number
    user_id: number
    balance: number
  } | null
  creator_profile?: {
    id: number
    user_id: number
    pen_name: string
    status: string
  } | null
}

export interface UpdateUserRolePayload {
  role: 'reader' | 'creator' | 'admin'
}

// -------------------------------------------------------------
// Module C: Admin Dashboard Stats
// -------------------------------------------------------------
export interface AdminDashboardStats {
  users: {
    total: number
    new_7_days: number
    new_30_days: number
    creators: number
    active: number
    locked: number
  }
  queue: {
    pending_comics: number
    pending_chapters: number
    pending_creator_applications: number
    pending_reports: number
    total_pending: number
  }
  catalog: {
    total_comics: number
    approved_comics: number
    draft_comics: number
    rejected_comics: number
    vip_comics: number
    total_chapters: number
    published_chapters: number
  }
  finance: {
    coins_in_circulation: number
    total_vip_purchases: number
    active_vip_purchases: number
    revoked_vip_purchases: number
    vip_revenue_coins: number
  }
  recent: {
    pending_comics: {
      id: number
      title: string
      slug: string
      cover_path: string | null
      user?: { id: number; name: string; email: string }
      created_at: string
      updated_at: string
    }[]
    pending_reports: any[]
    vip_purchases: any[]
  }
}

// -------------------------------------------------------------
// Module D & E: Catalog Management (All Comics & Chapters)
// -------------------------------------------------------------
export interface AdminComicCatalogItem {
  id: number
  title: string
  slug: string
  author_name: string
  cover_path: string | null
  approval_status: 'draft' | 'pending' | 'approved' | 'rejected'
  status: 'ongoing' | 'completed' | 'hiatus'
  is_vip: boolean
  price: number
  is_featured: boolean
  views_count: number
  follows_count: number
  chapters_count: number
  user?: {
    id: number
    name: string
    email: string
  }
  genres?: { id: number; name: string; slug: string }[]
  created_at: string
  updated_at: string
}

export interface AdminComicDetail {
  id: number
  title: string
  slug: string
  alternative_titles?: string | null
  author_name: string
  description?: string | null
  cover_path?: string | null
  banner_path?: string | null
  age_rating: string
  status: 'ongoing' | 'completed' | 'hiatus'
  approval_status: 'draft' | 'pending' | 'approved' | 'rejected'
  rejection_reason?: string | null
  is_vip: boolean
  price: number
  is_featured: boolean
  views_count: number
  follows_count: number
  chapters_count: number
  purchases_count?: number
  user?: {
    id: number
    name: string
    email: string
    role?: string
    is_active?: boolean
    created_at?: string
  }
  approver?: {
    id: number
    name: string
    email: string
  }
  genres?: { id: number; name: string; slug: string }[]
  tags?: { id: number; name: string; slug: string }[]
  created_at: string
  updated_at: string
}

export interface AdminComicChapterItem {
  id: number
  comic_id: number
  chapter_number: string
  title: string | null
  slug: string
  status: 'draft' | 'pending' | 'published' | 'rejected'
  rejection_reason?: string | null
  views_count: number
  images_count: number
  created_at: string
  updated_at?: string
}

export interface AdminComicChapterDetail extends AdminComicChapterItem {
  approved_by?: number | null
  approved_at?: string | null
  approver?: {
    id: number
    name: string
    email: string
  } | null
  images?: {
    id: number
    chapter_id: number
    image_path: string
    page_number: number
    image_url?: string
  }[]
}

export interface ListAdminComicChaptersParams {
  q?: string
  status?: string
  sort_by?: string
  sort_dir?: string
  page?: number
  per_page?: number
}

export interface ListAdminComicsCatalogParams {
  q?: string
  approval_status?: string
  status?: string
  is_vip?: string | boolean
  genre_id?: number | string
  creator?: string
  user_id?: number | string
  sort_by?: string
  sort_dir?: string
  page?: number
  per_page?: number
}

export interface AdminChapterCatalogItem {
  id: number
  comic_id: number
  chapter_number: string
  title: string | null
  slug: string
  status: 'draft' | 'pending' | 'published' | 'rejected'
  rejection_reason?: string | null
  views_count: number
  images_count: number
  created_at: string
  comic?: {
    id: number
    title: string
    slug: string
    cover_path: string | null
    user_id: number
    user?: { id: number; name: string; email: string }
  }
}

// -------------------------------------------------------------
// Module F & G: Wallet, Ledger & VIP Purchases
// -------------------------------------------------------------
export interface AdminWalletItem {
  id: number
  user_id: number
  balance: number
  currency: string
  created_at: string
  updated_at: string
  user?: {
    id: number
    name: string
    email: string
    role: string
    is_active: boolean
  }
}

export interface AdminCoinTransactionItem {
  id: number
  wallet_id: number
  user_id: number
  type: string
  amount: number
  balance_before: number
  balance_after: number
  reference_type: string | null
  reference_id: number | null
  description: string | null
  created_at: string
  user?: {
    id: number
    name: string
    email: string
  }
}

export interface AdminComicPurchaseItem {
  id: number
  user_id: number
  comic_id: number
  status: 'active' | 'revoked'
  price_paid: number
  transaction_id: number
  refund_transaction_id: number | null
  purchased_at: string
  revoked_at: string | null
  user?: {
    id: number
    name: string
    email: string
  }
  comic?: {
    id: number
    title: string
    slug: string
    cover_path: string | null
    price: number
  }
  transaction?: {
    id: number
    amount: number
    created_at: string
  }
}

export interface AdminRefundPayload {
  transaction_id: number
  reason: string
  idempotency_key?: string
}

// -------------------------------------------------------------
// Module H: System Broadcast Notifications
// -------------------------------------------------------------
export interface AdminBroadcastPayload {
  title: string
  message: string
  broadcast_type?: 'announcement' | 'maintenance' | 'promotion' | 'update'
  target_audience: 'all' | 'readers' | 'creators'
  action_url?: string
}

export interface AdminBroadcastHistoryItem {
  id: string
  type: string
  data: {
    kind: string
    title: string
    body: string
    broadcast_type: string
    is_broadcast: boolean
    target?: { url: string }
    actor?: { name: string }
  }
  created_at: string
}


