import { httpClient } from './httpClient'
import type {
  FollowResponse,
  BookmarkResponse,
  LibraryQueryParams,
  LibraryComicListResponse,
} from '@/types/userLibrary'

export class UserLibraryService {
  /**
   * Toggle follow/unfollow an approved comic.
   * Atomically updates follows_count on the comic.
   * POST /api/comics/{id}/follow
   */
  static async toggleFollow(comicId: number | string): Promise<FollowResponse> {
    const encodedId = encodeURIComponent(String(comicId))
    const response = await httpClient.post<FollowResponse>(`/comics/${encodedId}/follow`)
    return response.data
  }

  /**
   * Fetch paginated list of approved comics followed by the authenticated user.
   * GET /api/user/follows
   */
  static async getFollows(params?: LibraryQueryParams): Promise<LibraryComicListResponse> {
    const response = await httpClient.get<LibraryComicListResponse>('/user/follows', {
      params,
    })
    return response.data
  }

  /**
   * Toggle bookmark/unbookmark an approved comic in personal library.
   * POST /api/comics/{id}/bookmark
   */
  static async toggleBookmark(comicId: number | string): Promise<BookmarkResponse> {
    const encodedId = encodeURIComponent(String(comicId))
    const response = await httpClient.post<BookmarkResponse>(`/comics/${encodedId}/bookmark`)
    return response.data
  }

  /**
   * Fetch paginated list of approved comics bookmarked by the authenticated user.
   * GET /api/user/bookmarks
   */
  static async getBookmarks(params?: LibraryQueryParams): Promise<LibraryComicListResponse> {
    const response = await httpClient.get<LibraryComicListResponse>('/user/bookmarks', {
      params,
    })
    return response.data
  }
}
