import { httpClient } from './httpClient'
import type { UserProfileResponse } from '@/types/userProfile'
import type { ComicListItem, PaginatedResponse } from '@/types/catalog'
import type { Post } from '@/types/community'

export const UserProfileService = {
  /**
   * Fetch public profile details and statistics for a given user.
   */
  async getUserProfile(userId: number): Promise<UserProfileResponse> {
    const response = await httpClient.get<UserProfileResponse>(`/users/${userId}`)
    return response.data
  },

  /**
   * Fetch public approved comics created by a given creator.
   */
  async getUserComics(userId: number, page = 1, perPage = 12): Promise<PaginatedResponse<ComicListItem>> {
    const response = await httpClient.get<PaginatedResponse<ComicListItem>>(`/users/${userId}/comics`, {
      params: {
        page,
        per_page: perPage,
      },
    })
    return response.data
  },

  /**
   * Fetch public community posts authored by a given user.
   */
  async getUserPosts(userId: number, page = 1, perPage = 10): Promise<PaginatedResponse<Post>> {
    const response = await httpClient.get<PaginatedResponse<Post>>(`/users/${userId}/posts`, {
      params: {
        page,
        per_page: perPage,
      },
    })
    return response.data
  },
}
