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
  CommentItemData,
  CommentListResponse,
  CreateCommentPayload,
  ReplyCommentPayload,
  ListCommentsParams,
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

  // ==========================================
  // Comments APIs
  // ==========================================

  /**
   * Fetch paginated list of root comments for a target entity (post, comic, chapter).
   * Eager-loads level-2 replies in chronological order.
   * Publicly accessible by guests and authenticated users.
   * GET /api/comments
   */
  static async getComments(params: ListCommentsParams): Promise<CommentListResponse> {
    const response = await httpClient.get<CommentListResponse>('/comments', {
      params,
    })
    return response.data
  }

  /**
   * Create a new root comment on a post, comic, or chapter.
   * Accessible by Reader, Creator, and Admin.
   * POST /api/comments
   */
  static async createComment(
    payload: CreateCommentPayload
  ): Promise<SingleResponse<CommentItemData>> {
    const response = await httpClient.post<SingleResponse<CommentItemData>>('/comments', {
      commentable_type: payload.commentable_type,
      commentable_id: payload.commentable_id,
      content: payload.content.trim(),
    })
    return response.data
  }

  /**
   * Reply to an existing root comment (level-1).
   * Backend strictly enforces a maximum of 2 comment levels.
   * Accessible by Reader, Creator, and Admin.
   * POST /api/comments/{id}/reply
   */
  static async replyComment(
    id: number | string,
    payload: ReplyCommentPayload
  ): Promise<SingleResponse<CommentItemData>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<SingleResponse<CommentItemData>>(
      `/comments/${encodedId}/reply`,
      {
        content: payload.content.trim(),
      }
    )
    return response.data
  }

  /**
   * Delete a comment or reply.
   * Strictly author or Admin only.
   * DELETE /api/comments/{id}
   */
  static async deleteComment(id: number | string): Promise<ActionSuccessResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.delete<ActionSuccessResponse>(`/comments/${encodedId}`)
    return response.data
  }

  /**
   * Toggle like/unlike on a comment.
   * Accessible by any authenticated and active user.
   * Note: POST /api/comments/{id}/like is a toggle action (likes if unliked, unlikes if liked).
   * POST /api/comments/{id}/like
   */
  static async toggleCommentLike(id: number | string): Promise<LikeResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<LikeResponse>(`/comments/${encodedId}/like`)
    return response.data
  }

  /**
   * Idempotently unlike a comment.
   * Ensures the comment is unliked without toggling it back to liked on repeat calls.
   * Accessible by any authenticated and active user.
   * DELETE /api/comments/{id}/like
   */
  static async unlikeComment(id: number | string): Promise<LikeResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.delete<LikeResponse>(`/comments/${encodedId}/like`)
    return response.data
  }
}
