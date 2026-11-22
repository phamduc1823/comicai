import { httpClient } from './httpClient'
import type {
  RecordReadingHistoryPayload,
  RecordReadingHistoryResponse,
  ReadingHistoryQueryParams,
  ReadingHistoryListResponse,
  ReadingHistoryActionResponse,
  ComicReadingHistoryResponse,
} from '@/types/readingHistory'

export class ReadingHistoryService {
  /**
   * Retrieve reading history for a specific comic for the authenticated user.
   * GET /api/comics/{comicId}/reading-history
   */
  static async getComicReadingHistory(
    comicId: number | string
  ): Promise<ComicReadingHistoryResponse> {
    const encodedId = encodeURIComponent(String(comicId))
    const response = await httpClient.get<ComicReadingHistoryResponse>(
      `/comics/${encodedId}/reading-history`
    )
    return response.data
  }

  /**
   * Record or update reading history for a comic upon opening a chapter.
   * POST /api/comics/{comicId}/reading-history
   */
  static async recordReadingHistory(
    comicId: number | string,
    payload: RecordReadingHistoryPayload
  ): Promise<RecordReadingHistoryResponse> {
    const encodedId = encodeURIComponent(String(comicId))
    const response = await httpClient.post<RecordReadingHistoryResponse>(
      `/comics/${encodedId}/reading-history`,
      payload
    )
    return response.data
  }

  /**
   * Retrieve paginated reading history for the authenticated user.
   * GET /api/user/reading-history
   */
  static async getReadingHistory(
    params?: ReadingHistoryQueryParams
  ): Promise<ReadingHistoryListResponse> {
    const response = await httpClient.get<ReadingHistoryListResponse>(
      '/user/reading-history',
      {
        params,
      }
    )
    return response.data
  }

  /**
   * Remove reading history of a specific comic.
   * DELETE /api/comics/{comicId}/reading-history
   */
  static async deleteReadingHistory(
    comicId: number | string
  ): Promise<ReadingHistoryActionResponse> {
    const encodedId = encodeURIComponent(String(comicId))
    const response = await httpClient.delete<ReadingHistoryActionResponse>(
      `/comics/${encodedId}/reading-history`
    )
    return response.data
  }

  /**
   * Clear all reading history for the authenticated user.
   * DELETE /api/user/reading-history
   */
  static async clearReadingHistory(): Promise<ReadingHistoryActionResponse> {
    const response = await httpClient.delete<ReadingHistoryActionResponse>(
      '/user/reading-history'
    )
    return response.data
  }
}
