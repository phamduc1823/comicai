/**
 * Type definitions matching backend User Library APIs (Follows and Bookmarks).
 * Derived from UserLibraryController, Follow, Bookmark, and ComicListResource.
 */

import type { ComicListItem, PaginatedResponse } from './catalog'

export interface FollowData {
  comic_id: number
  is_followed: boolean
  follows_count: number
}

export interface FollowResponse {
  status: 'success'
  message: string
  data: FollowData
}

export interface BookmarkData {
  comic_id: number
  is_bookmarked: boolean
}

export interface BookmarkResponse {
  status: 'success'
  message: string
  data: BookmarkData
}

export interface LibraryQueryParams {
  page?: number
  per_page?: number
}

export type LibraryComicListResponse = PaginatedResponse<ComicListItem>
