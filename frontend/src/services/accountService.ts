import { httpClient } from './httpClient'
import type { User, UpdateProfilePayload, UpdatePasswordPayload, AvatarUploadResponse } from '@/types/auth'

export class AccountService {
  /**
   * Get authenticated user profile and account details.
   * GET /api/user/profile
   */
  static async getProfile(): Promise<{ status: string; user: User }> {
    const response = await httpClient.get<{ status: string; user: User }>('/user/profile')
    return response.data
  }

  /**
   * Update name and optionally email.
   * PUT /api/user/profile
   */
  static async updateProfile(
    payload: UpdateProfilePayload
  ): Promise<{ status: string; message: string; user: User }> {
    const response = await httpClient.put<{ status: string; message: string; user: User }>(
      '/user/profile',
      payload
    )
    return response.data
  }

  /**
   * Upload and set a new user avatar.
   * POST /api/user/avatar
   */
  static async uploadAvatar(file: File): Promise<AvatarUploadResponse> {
    const formData = new FormData()
    formData.append('avatar', file)

    const response = await httpClient.post<AvatarUploadResponse>('/user/avatar', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data
  }

  /**
   * Delete custom avatar and restore default fallback.
   * DELETE /api/user/avatar
   */
  static async deleteAvatar(): Promise<{ status: string; message: string; user: User }> {
    const response = await httpClient.delete<{ status: string; message: string; user: User }>(
      '/user/avatar'
    )
    return response.data
  }

  /**
   * Update account password.
   * PUT /api/user/password
   */
  static async updatePassword(
    payload: UpdatePasswordPayload
  ): Promise<{ status: string; message: string }> {
    const response = await httpClient.put<{ status: string; message: string }>(
      '/user/password',
      payload
    )
    return response.data
  }
}
