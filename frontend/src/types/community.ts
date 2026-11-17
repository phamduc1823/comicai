/**
 * Type definitions matching backend Community Posts & Likes APIs.
 * Derived from PostResource, PostController, StorePostRequest, and UpdatePostRequest.
 */

import type { UserRole } from './auth'
import type {
  PaginationLinks,
  PaginationMetaLink,
  PaginationMeta,
  PaginatedResponse,
  SingleResponse,
} from './catalog'
import type { ActionSuccessResponse } from './creator'

export type {
  PaginationLinks,
  PaginationMetaLink,
  PaginationMeta,
  PaginatedResponse,
  SingleResponse,
  ActionSuccessResponse,
}

export interface PostAuthor {
  id: number
  name: string
  avatar_url: string | null
  role: UserRole
}

export interface PostComic {
  id: number
  title: string
  slug: string
  cover_url: string | null
}

export interface Post {
  id: number
  content: string
  image_url: string | null
  likes_count: number
  comments_count: number
  is_liked: boolean
  user: PostAuthor
  comic: PostComic | null
  created_at: string | null
  updated_at: string | null
}

export interface CreatePostPayload {
  content: string
  comic_id?: number | null
  image?: File | null
}

export interface UpdatePostPayload {
  content?: string
  comic_id?: number | null
  image?: File | null
  remove_image?: boolean
}

export interface LikeData {
  id: number
  liked: boolean
  likes_count: number
}

export interface LikeResponse {
  status: 'success'
  message: string
  data: LikeData
}

export type PostSortOption = 'latest' | 'popular'

export interface ListPostsParams {
  comic_id?: number
  user_id?: number
  sort?: PostSortOption
  per_page?: number
  page?: number
}
