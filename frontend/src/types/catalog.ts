/**
 * Type definitions matching backend Public Catalog & Reader API Resources.
 */

export interface Genre {
  id: number
  name: string
  slug: string
  description: string | null
}

export interface Tag {
  id: number
  name: string
  slug: string
  description: string | null
}

export interface CreatorSummary {
  id: number
  name: string
  avatar_path: string | null
  avatar_url: string | null
}

export type ComicStatus = 'ongoing' | 'completed' | 'hiatus'

export interface ComicListItem {
  id: number
  title: string
  slug: string
  alternative_titles: string | null
  author_name: string
  cover_path: string | null
  cover_url: string | null
  age_rating: string
  status: ComicStatus
  views_count: number
  follows_count: number
  is_featured: boolean
  published_chapters_count: number
  genres: Genre[]
  tags: Tag[]
  creator: CreatorSummary | null
  created_at: string | null
  updated_at: string | null
}

export interface ComicDetail extends ComicListItem {
  description: string | null
  banner_path: string | null
  banner_url: string | null
  ai_summary: string | null
  chapters: ChapterSummary[]
}

export interface BannerComicSummary {
  id: number
  title: string
  slug: string
  cover_path: string | null
  cover_url: string | null
}

export interface Banner {
  id: number
  title_override: string | null
  desktop_banner_path: string | null
  desktop_banner_url: string | null
  mobile_banner_path: string | null
  mobile_banner_url: string | null
  sort_order: number
  comic: BannerComicSummary
}

export interface ChapterSummary {
  id: number
  chapter_number: string
  title: string | null
  slug: string
  views_count: number
  created_at: string | null
}

export interface ChapterImage {
  id: number
  page_number: number
  image_path: string
  image_url: string | null
  width: number | null
  height: number | null
}

export interface ChapterNavigation {
  id: number
  chapter_number: string
  title: string | null
  slug: string
}

export interface ReaderComicSummary {
  id: number
  title: string
  slug: string
}

export interface ChapterReader {
  id: number
  chapter_number: string
  title: string | null
  slug: string
  views_count: number
  comic: ReaderComicSummary
  images: ChapterImage[]
  prev_chapter: ChapterNavigation | null
  next_chapter: ChapterNavigation | null
  created_at: string | null
}

export type ComicSortOption = 'latest' | 'views' | 'follows'

export interface ListComicsParams {
  page?: number
  per_page?: number
  genre?: string
  tag?: string
  status?: ComicStatus
  sort?: ComicSortOption
  q?: string
}

export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface PaginationMetaLink {
  url: string | null
  label: string
  active: boolean
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  links: PaginationMetaLink[]
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface SingleResponse<T> {
  data: T
}

export interface CollectionResponse<T> {
  data: T[]
}

export interface PaginatedResponse<T> {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}
