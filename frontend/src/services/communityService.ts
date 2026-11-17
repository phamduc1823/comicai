import { httpClient } from './httpClient'
import type {
  Post,
  CreatePostPayload,
  UpdatePostPayload,
  LikeResponse,
  ListPostsParams,
  PaginatedResponse,
  SingleResponse,
  ActionSuccessResponse,
} from '@/types/community'

export class CommunityService {
  /**
   * Fetch paginated list of community posts with optional comic/user filters and sorting.
   * Publicly accessible by guests and authenticated users.
   * GET /api/posts
   */
  static async getPosts(params?: ListPostsParams): Promise<PaginatedResponse<Post>> {
    const response = await httpClient.get<PaginatedResponse<Post>>('/posts', {
      params,
    })
    return response.data
  }

  /**
   * Fetch details of a specific community post.
   * Publicly accessible by guests and authenticated users.
   * GET /api/posts/{id}
   */
  static async getPost(id: number | string): Promise<SingleResponse<Post>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.get<SingleResponse<Post>>(`/posts/${encodedId}`)
    return response.data
  }

  /**
   * Create a new community post with optional comic tag and image.
   * Strictly Creator and Admin only.
   * POST /api/posts
   */
  static async createPost(payload: CreatePostPayload): Promise<SingleResponse<Post>> {
    const formData = new FormData()
    formData.append('content', payload.content.trim())

    if (payload.comic_id !== undefined && payload.comic_id !== null) {
      formData.append('comic_id', String(payload.comic_id))
    }

    if (payload.image) {
      formData.append('image', payload.image)
    }

    const response = await httpClient.post<SingleResponse<Post>>('/posts', formData)
    return response.data
  }

  /**
   * Update an existing post.
   * Uses POST with _method=PUT to guarantee reliable multipart file uploads in PHP/Laravel.
   * Strictly author-only.
   * POST /api/posts/{id}
   */
  static async updatePost(
    id: number | string,
    payload: UpdatePostPayload
  ): Promise<SingleResponse<Post>> {
    const encodedId = encodeURIComponent(String(id))
    const formData = new FormData()
    formData.append('_method', 'PUT')

    if (payload.content !== undefined) {
      formData.append('content', payload.content.trim())
    }

    if (payload.comic_id !== undefined) {
      if (payload.comic_id !== null) {
        formData.append('comic_id', String(payload.comic_id))
      } else {
        formData.append('comic_id', '')
      }
    }

    if (payload.image) {
      formData.append('image', payload.image)
    }

    if (payload.remove_image) {
      formData.append('remove_image', '1')
    }

    const response = await httpClient.post<SingleResponse<Post>>(`/posts/${encodedId}`, formData)
    return response.data
  }

  /**
   * Delete a community post.
   * Author or Admin only.
   * DELETE /api/posts/{id}
   */
  static async deletePost(id: number | string): Promise<ActionSuccessResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.delete<ActionSuccessResponse>(`/posts/${encodedId}`)
    return response.data
  }

  /**
   * Toggle like/unlike on a community post.
   * Accessible by any authenticated and active user.
   * POST /api/posts/{id}/like
   */
  static async togglePostLike(id: number | string): Promise<LikeResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<LikeResponse>(`/posts/${encodedId}/like`)
    return response.data
  }
}
