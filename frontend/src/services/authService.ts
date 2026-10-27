import { httpClient } from './httpClient'
import type {
  User,
  LoginCredentials,
  RegisterData,
  AuthSuccessResponse,
  UserProfileResponse,
  LogoutResponse,
} from '@/types/auth'

export class AuthService {
  private static csrfHandshakePromise: Promise<void> | null = null

  /**
   * Request CSRF cookie from Laravel Sanctum SPA endpoint (/sanctum/csrf-cookie).
   * Caches in-flight promise to avoid multiple simultaneous handshakes.
   */
  static async csrfCookie(): Promise<void> {
    if (!this.csrfHandshakePromise) {
      this.csrfHandshakePromise = httpClient
        .get('/sanctum/csrf-cookie', { baseURL: '' })
        .then(() => {
          this.csrfHandshakePromise = null
        })
        .catch((error) => {
          this.csrfHandshakePromise = null
          throw error
        })
    }
    return this.csrfHandshakePromise
  }

  /**
   * Register a new user account.
   * Performs CSRF handshake before POST /api/register.
   */
  static async register(data: RegisterData): Promise<AuthSuccessResponse> {
    await this.csrfCookie()
    const response = await httpClient.post<AuthSuccessResponse>('/register', data)
    return response.data
  }

  /**
   * Authenticate user with email and password credentials.
   * Performs CSRF handshake before POST /api/login.
   */
  static async login(credentials: LoginCredentials): Promise<AuthSuccessResponse> {
    await this.csrfCookie()
    const response = await httpClient.post<AuthSuccessResponse>('/login', credentials)
    return response.data
  }

  /**
   * Log out authenticated user, destroying the server session.
   */
  static async logout(): Promise<LogoutResponse> {
    const response = await httpClient.post<LogoutResponse>('/logout')
    return response.data
  }

  /**
   * Fetch currently authenticated user profile.
   */
  static async getUser(): Promise<User> {
    const response = await httpClient.get<UserProfileResponse>('/user')
    return response.data.user
  }
}
