import type { UserRole } from './auth'

export interface UserProfileStats {
  posts_count: number
  comics_count: number
}

export interface UserProfile {
  id: number
  name: string
  role: UserRole
  avatar_path: string | null
  avatar_url: string | null
  created_at: string
  stats: UserProfileStats
}

export interface UserProfileResponse {
  data: UserProfile
}
