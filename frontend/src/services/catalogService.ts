import { httpClient } from './httpClient'
import type {
  Genre,
  Tag,
  Banner,
  ComicListItem,
  ComicDetail,
  ChapterSummary,
  ChapterReader,
  ListComicsParams,
  CollectionResponse,
  SingleResponse,
  PaginatedResponse,
} from '@/types/catalog'

export class CatalogService {
  /**
   * Fetch all active genres ordered alphabetically.
   * GET /api/genres
   */
  static async getGenres(): Promise<CollectionResponse<Genre>> {
    const response = await httpClient.get<CollectionResponse<Genre>>('/genres')
    return response.data
  }

  /**
   * Fetch all tags ordered alphabetically.
   * GET /api/tags
   */
  static async getTags(): Promise<CollectionResponse<Tag>> {
    const response = await httpClient.get<CollectionResponse<Tag>>('/tags')
    return response.data
  }

  /**
   * Fetch all active banners of approved comics ordered by sort_order.
   * GET /api/banners
   */
  static async getBanners(): Promise<CollectionResponse<Banner>> {
    const response = await httpClient.get<CollectionResponse<Banner>>('/banners')
    return response.data
  }

  /**
   * Fetch paginated list of approved comics with optional filters, search and sorting.
   * GET /api/comics
   */
  static async getComics(params?: ListComicsParams): Promise<PaginatedResponse<ComicListItem>> {
    const response = await httpClient.get<PaginatedResponse<ComicListItem>>('/comics', {
      params,
    })
    return response.data
  }

  /**
   * Fetch full details of an approved comic by slug.
   * GET /api/comics/{slug}
   */
  static async getComicDetail(slug: string): Promise<SingleResponse<ComicDetail>> {
    const encodedSlug = encodeURIComponent(slug)
    const response = await httpClient.get<SingleResponse<ComicDetail>>(`/comics/${encodedSlug}`)
    return response.data
  }

  /**
   * Fetch published chapters of an approved comic by slug.
   * GET /api/comics/{slug}/chapters
   */
  static async getChapters(slug: string): Promise<CollectionResponse<ChapterSummary>> {
    const encodedSlug = encodeURIComponent(slug)
    const response = await httpClient.get<CollectionResponse<ChapterSummary>>(
      `/comics/${encodedSlug}/chapters`
    )
    return response.data
  }

  /**
   * Fetch reader view for a published chapter by comic slug and chapter number.
   * GET /api/comics/{slug}/chapters/{chapterNumber}
   */
  static async getChapterReader(
    slug: string,
    chapterNumber: string | number
  ): Promise<SingleResponse<ChapterReader>> {
    const encodedSlug = encodeURIComponent(slug)
    const encodedChapter = encodeURIComponent(String(chapterNumber))
    const response = await httpClient.get<SingleResponse<ChapterReader>>(
      `/comics/${encodedSlug}/chapters/${encodedChapter}`
    )
    return response.data
  }
}
