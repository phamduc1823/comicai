/**
 * Role of an authenticated user matching backend specification.
 */
export type UserRole = 'reader' | 'creator' | 'admin'

/**
 * User entity representation matching backend UserResource serialization.
 */
export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  avatar_path: string | null
  is_active: boolean
  email_verified_at: string | null
  created_at: string
  updated_at: string
}

/**
 * Payload sent to POST /api/register
 */
export interface RegisterData {
  name: string
  email: string
  password: string
  password_confirmation: string
}

/**
 * Payload sent to POST /api/login
 */
export interface LoginCredentials {
  email: string
  password: string
  remember?: boolean
}

/**
 * Successful response from POST /api/register and POST /api/login
 */
export interface AuthSuccessResponse {
  status: 'success'
  message: string
  user: User
}

/**
 * Successful response from GET /api/user
 */
export interface UserProfileResponse {
  status: 'success'
  user: User
}

/**
 * Successful response from POST /api/logout
 */
export interface LogoutResponse {
  status: 'success'
  message: string
}

/**
 * Standard Laravel validation error response (HTTP 422)
 */
export interface ApiValidationErrorResponse {
  message: string
  errors: Record<string, string[]>
}

/**
 * Generic API error response (HTTP 400, 403, 500)
 */
export interface ApiErrorResponse {
  status?: string
  message: string
}

/**
 * Rate limit error response (HTTP 429)
 */
export interface ApiRateLimitResponse {
  message: string
}
