/**
 * Type definitions matching backend Reading History APIs.
 * Derived from ReadingHistoryController, ReadingHistoryResource, and ComicListResource.
 */

import type { ComicListItem, PaginatedResponse } from './catalog'

/**
 * Payload sent to record reading history.
 * POST /api/comics/{comicId}/reading-history
 */
export interface RecordReadingHistoryPayload {
  chapter_id: number
}

/**
 * Data payload returned upon successfully recording reading history.
 */
export interface RecordReadingHistoryData {
  comic_id: number
  chapter_id: number
  chapter_number: string
  updated_at: string
}

/**
 * Response returned from recording reading history.
 */
export interface RecordReadingHistoryResponse {
  status: 'success'
  message: string
  data: RecordReadingHistoryData
}

/**
 * Summary of the last read chapter embedded in reading history item.
 */
export interface LastReadChapter {
  id: number
  chapter_number: string
  title: string | null
  slug: string
}

/**
 * A single reading history entry returned in the user's reading history list.
 */
export interface ReadingHistoryItem {
  id: number
  comic: ComicListItem
  last_read_chapter: LastReadChapter | null
  updated_at: string
}

/**
 * Query parameters for fetching paginated reading history.
 */
export interface ReadingHistoryQueryParams {
  page?: number
  per_page?: number
}

/**
 * Paginated response containing reading history items.
 * GET /api/user/reading-history
 */
export type ReadingHistoryListResponse = PaginatedResponse<ReadingHistoryItem>

/**
 * Generic success response returned by deletion operations.
 * DELETE /api/comics/{comicId}/reading-history
 * DELETE /api/user/reading-history
 */
export interface ReadingHistoryActionResponse {
  status: 'success'
  message: string
}

/**
 * Reading history details for a single comic.
 * GET /api/comics/{comicId}/reading-history
 */
export interface ComicReadingHistoryData {
  comic_id: number
  chapter_id: number
  chapter_number: string
  title: string | null
  slug: string
  updated_at: string
}

/**
 * Response returned from querying reading history of a specific comic.
 */
export interface ComicReadingHistoryResponse {
  status: 'success'
  data: ComicReadingHistoryData | null
}
