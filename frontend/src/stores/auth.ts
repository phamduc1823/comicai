import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { User, UserRole, LoginCredentials, RegisterData } from '@/types/auth'
import { AuthService } from '@/services/authService'
import {
  isUnauthorizedError,
  isForbiddenError,
  isAxiosError,
  getErrorMessage,
} from '@/services/httpClient'

export type SessionStatus = 'idle' | 'authenticated' | 'guest' | 'error'

/**
 * Check if a 403 Forbidden response specifically matches EnsureUserIsActive middleware.
 */
function isDeactivatedAccountError(error: unknown): boolean {
  if (isForbiddenError(error) && isAxiosError<{ message?: string; status?: string }>(error)) {
    const data = error.response?.data
    return data?.status === 'error' && data?.message === 'Tài khoản của bạn đã bị vô hiệu hóa.'
  }
  return false
}

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  const isInitialized = ref<boolean>(false)
  const isLoading = ref<boolean>(false)
  const error = ref<string | null>(null)
  const sessionStatus = ref<SessionStatus>('idle')

  // In-flight initialization promise to deduplicate concurrent initialize() calls
  let initializationPromise: Promise<void> | null = null

  // Computed
  const isAuthenticated = computed<boolean>(() => user.value !== null)
  const userRole = computed<UserRole | null>(() => user.value?.role ?? null)
  const isAdmin = computed<boolean>(() => user.value?.role === 'admin')
  const isCreator = computed<boolean>(() => user.value?.role === 'creator')
  const isReader = computed<boolean>(() => user.value?.role === 'reader')

  /**
   * Initialize authentication state from backend session cookie.
   * Caches in-flight promise to prevent concurrent duplicate API requests.
   */
  async function initialize(): Promise<void> {
    if (isInitialized.value) {
      return
    }
    if (initializationPromise) {
      return initializationPromise
    }

    isLoading.value = true
    error.value = null

    initializationPromise = (async () => {
      try {
        const userData = await AuthService.getUser()
        user.value = userData
        sessionStatus.value = 'authenticated'
      } catch (err: unknown) {
        if (isUnauthorizedError(err)) {
          // Standard guest state: unauthenticated
          user.value = null
          sessionStatus.value = 'guest'
        } else if (isDeactivatedAccountError(err)) {
          // Account was deactivated by admin: session invalidated by backend
          user.value = null
          sessionStatus.value = 'guest'
          error.value = 'Tài khoản của bạn đã bị vô hiệu hóa.'
        } else {
          // Network failure, timeout, or HTTP 5xx: preserve unverified status
          sessionStatus.value = 'error'
          error.value = getErrorMessage(err, 'Không thể kết nối đến máy chủ xác thực.')
        }
      } finally {
        isInitialized.value = true
        isLoading.value = false
        initializationPromise = null
      }
    })()

    return initializationPromise
  }

  /**
   * Fetch latest user profile from backend.
   */
  async function fetchUser(): Promise<User | null> {
    isLoading.value = true
    error.value = null
    try {
      const userData = await AuthService.getUser()
      user.value = userData
      sessionStatus.value = 'authenticated'
      return userData
    } catch (err: unknown) {
      if (isUnauthorizedError(err)) {
        user.value = null
        sessionStatus.value = 'guest'
        return null
      }
      if (isDeactivatedAccountError(err)) {
        user.value = null
        sessionStatus.value = 'guest'
        error.value = 'Tài khoản của bạn đã bị vô hiệu hóa.'
        return null
      }
      sessionStatus.value = 'error'
      error.value = getErrorMessage(err, 'Không thể tải thông tin người dùng.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Authenticate user with credentials and populate user state.
   */
  async function login(credentials: LoginCredentials): Promise<User> {
    isLoading.value = true
    error.value = null
    try {
      const response = await AuthService.login(credentials)
      user.value = response.user
      sessionStatus.value = 'authenticated'
      return response.user
    } catch (err: unknown) {
      error.value = getErrorMessage(err, 'Đăng nhập không thành công.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Register a new user account and automatically populate user state.
   */
  async function register(data: RegisterData): Promise<User> {
    isLoading.value = true
    error.value = null
    try {
      const response = await AuthService.register(data)
      user.value = response.user
      sessionStatus.value = 'authenticated'
      return response.user
    } catch (err: unknown) {
      error.value = getErrorMessage(err, 'Đăng ký không thành công.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Log out authenticated user, destroying backend session.
   * If server request fails, does not pretend user is logged out.
   */
  async function logout(): Promise<void> {
    isLoading.value = true
    error.value = null
    try {
      await AuthService.logout()
      user.value = null
      sessionStatus.value = 'guest'
    } catch (err: unknown) {
      error.value = getErrorMessage(err, 'Đăng xuất không thành công. Vui lòng thử lại.')
      throw err
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Clear current error message.
   */
  function clearError(): void {
    error.value = null
  }

  return {
    // State
    user,
    isInitialized,
    isLoading,
    error,
    sessionStatus,

    // Computed
    isAuthenticated,
    userRole,
    isAdmin,
    isCreator,
    isReader,

    // Actions
    initialize,
    fetchUser,
    login,
    register,
    logout,
    clearError,
  }
})
