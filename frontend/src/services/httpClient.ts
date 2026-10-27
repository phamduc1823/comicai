import axios, { type AxiosError } from 'axios'
import type { ApiValidationErrorResponse } from '@/types/auth'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'

/**
 * Unified Axios instance configured for Laravel Sanctum SPA authentication.
 * Uses withCredentials: true to send and receive SPA session cookies.
 * Configured with XSRF tokens supported in Axios ^1.21.0.
 */
export const httpClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
})

// Request interceptor to handle root endpoints (like /sanctum/csrf-cookie)
httpClient.interceptors.request.use((config) => {
  // If requesting /sanctum, point directly to root to bypass /api baseURL
  if (config.url?.startsWith('/sanctum')) {
    config.baseURL = ''
  } else if (config.baseURL && config.url?.startsWith(config.baseURL)) {
    // If URL already includes baseURL, strip it to avoid duplication (/api/api/...)
    config.url = config.url.slice(config.baseURL.length) || '/'
  }
  return config
})

function isCsrfCookieRequest(config?: { url?: string; baseURL?: string }): boolean {
  if (!config?.url) return false

  // Strip query string and hash fragment
  const cleanUrl = config.url.split('?')[0].split('#')[0]

  // Extract pathname if URL is absolute (contains scheme ://)
  let urlPath = cleanUrl
  const schemeIndex = cleanUrl.indexOf('://')
  if (schemeIndex !== -1) {
    const pathIndex = cleanUrl.indexOf('/', schemeIndex + 3)
    urlPath = pathIndex !== -1 ? cleanUrl.slice(pathIndex) : '/'
  }

  // Normalize urlPath to always have a single leading slash and no trailing slash
  const normalizedUrlPath = '/' + urlPath.replace(/^\/+/, '').replace(/\/+$/, '')

  if (normalizedUrlPath === '/sanctum/csrf-cookie') {
    return true
  }

  // If relative URL and baseURL is provided, check combined full path
  if (!cleanUrl.includes('://') && config.baseURL) {
    const cleanBase = config.baseURL.split('?')[0].split('#')[0]
    const combined = `${cleanBase}/${urlPath}`
    const normalizedFullPath = '/' + combined.replace(/^\/+/, '').replace(/\/+$/, '').replace(/\/+/g, '/')
    if (normalizedFullPath === '/sanctum/csrf-cookie') {
      return true
    }
  }

  return false
}

// Response interceptor to handle CSRF expiration with a single retry
httpClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const originalRequest = error.config as (typeof error.config & { _retry?: boolean }) | undefined

    // On 419 (CSRF token mismatch), retry at most once and never retry the CSRF handshake itself
    if (
      error.response?.status === 419 &&
      originalRequest &&
      !originalRequest._retry &&
      !isCsrfCookieRequest(originalRequest)
    ) {
      originalRequest._retry = true
      try {
        await httpClient.get('/sanctum/csrf-cookie', { baseURL: '' })
        return httpClient(originalRequest)
      } catch (csrfError) {
        return Promise.reject(csrfError)
      }
    }

    return Promise.reject(error)
  }
)

/**
 * Type guard for Axios errors.
 */
export function isAxiosError<T = any>(error: unknown): error is AxiosError<T> {
  return axios.isAxiosError(error)
}

/**
 * Check if error is HTTP 401 Unauthorized (session expired / unauthenticated).
 */
export function isUnauthorizedError(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 401
}

/**
 * Check if error is HTTP 403 Forbidden (e.g. deactivated account).
 */
export function isForbiddenError(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 403
}

/**
 * Check if error is HTTP 422 Unprocessable Content (validation failure).
 */
export function isValidationError(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 422
}

/**
 * Check if error is HTTP 429 Too Many Requests (rate limit exceeded).
 */
export function isRateLimitError(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 429
}

/**
 * Check if error is HTTP 400 Bad Request (e.g. guest.api blocking already logged-in user).
 */
export function isBadRequestError(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 400
}

/**
 * Extract human-readable error message from unknown error.
 */
export function getErrorMessage(error: unknown, fallback = 'Đã có lỗi xảy ra. Vui lòng thử lại.'): string {
  if (isAxiosError<{ message?: string; status?: string }>(error)) {
    return error.response?.data?.message || error.message || fallback
  }
  if (error instanceof Error) {
    return error.message
  }
  return fallback
}

/**
 * Extract field-specific validation errors from an HTTP 422 error.
 */
export function getValidationErrors(error: unknown): Record<string, string[]> {
  if (isAxiosError<ApiValidationErrorResponse>(error) && error.response?.status === 422) {
    return error.response.data?.errors || {}
  }
  return {}
}

/**
 * Extract Retry-After header in seconds from an HTTP 429 error.
 */
export function getRetryAfterSeconds(error: unknown): number | null {
  if (isAxiosError(error) && error.response?.status === 429) {
    const retryHeader = error.response.headers?.['retry-after']
    if (retryHeader) {
      const parsed = parseInt(String(retryHeader), 10)
      if (!isNaN(parsed)) {
        return parsed
      }
    }
  }
  return null
}
