import { httpClient } from './httpClient'
import type { PaginatedResponse, SingleResponse } from '@/types/catalog'
import type {
  CreatorComic,
  ListCreatorComicsParams,
  CreateComicPayload,
  UpdateComicPayload,
  ActionSuccessResponse,
  CreatorChapter,
  ListCreatorChaptersParams,
  CreateChapterPayload,
  UpdateChapterPayload,
  ReorderImagesPayload,
  UploadImagesSuccessResponse,
  ReorderImagesSuccessResponse,
} from '@/types/creator'

export class CreatorService {
  /**
   * Fetch paginated list of comics owned by authenticated Creator (or all for Admin).
   * GET /api/creator/comics
   */
  static async getComics(
    params?: ListCreatorComicsParams
  ): Promise<PaginatedResponse<CreatorComic>> {
    const response = await httpClient.get<PaginatedResponse<CreatorComic>>('/creator/comics', {
      params,
    })
    return response.data
  }

  /**
   * Fetch detail of a single comic in Creator Studio.
   * GET /api/creator/comics/{id}
   */
  static async getComic(id: number | string): Promise<SingleResponse<CreatorComic>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.get<SingleResponse<CreatorComic>>(
      `/creator/comics/${encodedId}`
    )
    return response.data
  }

  /**
   * Create a new comic in draft state with cover image upload.
   * POST /api/creator/comics
   */
  static async createComic(payload: CreateComicPayload): Promise<SingleResponse<CreatorComic>> {
    const formData = new FormData()
    formData.append('title', payload.title.trim())
    formData.append('author_name', payload.author_name.trim())
    formData.append('cover', payload.cover)

    if (payload.description) {
      formData.append('description', payload.description)
    }
    if (payload.alternative_titles) {
      formData.append('alternative_titles', payload.alternative_titles.trim())
    }
    if (payload.age_rating) {
      formData.append('age_rating', payload.age_rating)
    }
    if (payload.status) {
      formData.append('status', payload.status)
    }

    payload.genres.forEach((genreId) => {
      formData.append('genres[]', String(genreId))
    })

    if (payload.tags && payload.tags.length > 0) {
      payload.tags.forEach((tagId) => {
        formData.append('tags[]', String(tagId))
      })
    }

    const response = await httpClient.post<SingleResponse<CreatorComic>>(
      '/creator/comics',
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      }
    )
    return response.data
  }

  /**
   * Update an existing comic in Creator Studio.
   * Uses POST with _method=PUT to guarantee reliable multipart file uploads in PHP/Laravel.
   * POST /api/creator/comics/{id}
   */
  static async updateComic(
    id: number | string,
    payload: UpdateComicPayload
  ): Promise<SingleResponse<CreatorComic>> {
    const encodedId = encodeURIComponent(String(id))
    const formData = new FormData()
    formData.append('_method', 'PUT')

    if (payload.title !== undefined) {
      formData.append('title', payload.title.trim())
    }
    if (payload.author_name !== undefined) {
      formData.append('author_name', payload.author_name.trim())
    }
    if (payload.cover) {
      formData.append('cover', payload.cover)
    }
    if (payload.description !== undefined) {
      formData.append('description', payload.description ?? '')
    }
    if (payload.alternative_titles !== undefined) {
      formData.append('alternative_titles', payload.alternative_titles?.trim() ?? '')
    }
    if (payload.age_rating !== undefined) {
      formData.append('age_rating', payload.age_rating)
    }
    if (payload.status !== undefined) {
      formData.append('status', payload.status)
    }

    if (payload.genres && payload.genres.length > 0) {
      payload.genres.forEach((genreId) => {
        formData.append('genres[]', String(genreId))
      })
    }

    if (payload.tags !== undefined) {
      if (payload.tags.length === 0) {
        // Lưu ý F-01: Trong multipart/form-data, PHP/Laravel ConvertEmptyStringsToNull chuyển '' thành null.
        // Backend CreatorComicController hiện dùng `isset($validated['tags'])` (trả về false khi null),
        // nên việc xóa toàn bộ tag khi gửi kèm FormData phụ thuộc vào việc cập nhật backend sang `array_key_exists`.
        formData.append('tags', '')
      } else {
        payload.tags.forEach((tagId) => {
          formData.append('tags[]', String(tagId))
        })
      }
    }

    const response = await httpClient.post<SingleResponse<CreatorComic>>(
      `/creator/comics/${encodedId}`,
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      }
    )
    return response.data
  }

  /**
   * Submit a draft or rejected comic for Admin moderation.
   * POST /api/creator/comics/{id}/submit-review
   */
  static async submitReview(id: number | string): Promise<SingleResponse<CreatorComic>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<SingleResponse<CreatorComic>>(
      `/creator/comics/${encodedId}/submit-review`
    )
    return response.data
  }

  /**
   * Cancel/withdraw a pending review request, moving comic from pending back to draft.
   * POST /api/creator/comics/{id}/cancel-review
   */
  static async cancelReview(id: number | string): Promise<SingleResponse<CreatorComic>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<SingleResponse<CreatorComic>>(
      `/creator/comics/${encodedId}/cancel-review`
    )
    return response.data
  }

  /**
   * Delete a draft or rejected comic.
   * DELETE /api/creator/comics/{id}
   */
  static async deleteComic(id: number | string): Promise<ActionSuccessResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.delete<ActionSuccessResponse>(`/creator/comics/${encodedId}`)
    return response.data
  }

  // -------------------------------------------------------------
  // Quản lý Chương (Chapters) — UI-03
  // -------------------------------------------------------------

  /**
   * Fetch paginated list of chapters for a comic in Creator Studio.
   * GET /api/creator/comics/{comicId}/chapters
   */
  static async getChapters(
    comicId: number | string,
    params?: ListCreatorChaptersParams
  ): Promise<PaginatedResponse<CreatorChapter>> {
    const encodedComicId = encodeURIComponent(String(comicId))
    const response = await httpClient.get<PaginatedResponse<CreatorChapter>>(
      `/creator/comics/${encodedComicId}/chapters`,
      { params }
    )
    return response.data
  }

  /**
   * Create a new chapter in draft state for a comic.
   * POST /api/creator/comics/{comicId}/chapters
   */
  static async createChapter(
    comicId: number | string,
    payload: CreateChapterPayload
  ): Promise<SingleResponse<CreatorChapter>> {
    const encodedComicId = encodeURIComponent(String(comicId))
    const response = await httpClient.post<SingleResponse<CreatorChapter>>(
      `/creator/comics/${encodedComicId}/chapters`,
      {
        chapter_number: payload.chapter_number,
        title: payload.title?.trim() || null,
      }
    )
    return response.data
  }

  /**
   * Fetch details of a specific chapter with its images ordered by page number.
   * GET /api/creator/chapters/{id}
   */
  static async getChapter(id: number | string): Promise<SingleResponse<CreatorChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.get<SingleResponse<CreatorChapter>>(
      `/creator/chapters/${encodedId}`
    )
    return response.data
  }

  /**
   * Update chapter number and/or title in draft or rejected status.
   * PUT /api/creator/chapters/{id}
   */
  static async updateChapter(
    id: number | string,
    payload: UpdateChapterPayload
  ): Promise<SingleResponse<CreatorChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const body: Record<string, unknown> = {}
    if (payload.chapter_number !== undefined) {
      body.chapter_number = payload.chapter_number
    }
    if (payload.title !== undefined) {
      body.title = payload.title?.trim() || null
    }

    const response = await httpClient.put<SingleResponse<CreatorChapter>>(
      `/creator/chapters/${encodedId}`,
      body
    )
    return response.data
  }

  /**
   * Submit chapter for admin review (draft/rejected -> pending).
   * Must contain at least 1 image.
   * POST /api/creator/chapters/{id}/submit-review
   */
  static async submitChapterReview(id: number | string): Promise<SingleResponse<CreatorChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<SingleResponse<CreatorChapter>>(
      `/creator/chapters/${encodedId}/submit-review`
    )
    return response.data
  }

  /**
   * Cancel/withdraw review request (pending -> draft).
   * POST /api/creator/chapters/{id}/cancel-review
   */
  static async cancelChapterReview(id: number | string): Promise<SingleResponse<CreatorChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<SingleResponse<CreatorChapter>>(
      `/creator/chapters/${encodedId}/cancel-review`
    )
    return response.data
  }

  /**
   * Recall a published chapter back to draft (published -> draft).
   * POST /api/creator/chapters/{id}/recall
   */
  static async recallChapter(id: number | string): Promise<SingleResponse<CreatorChapter>> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<SingleResponse<CreatorChapter>>(
      `/creator/chapters/${encodedId}/recall`
    )
    return response.data
  }

  // -------------------------------------------------------------
  // Quản lý Ảnh Chương (Chapter Images) — UI-03
  // -------------------------------------------------------------

  /**
   * Upload one or multiple Webtoon page images to a draft or rejected chapter.
   * Uses multipart/form-data with 'images[]'.
   * POST /api/creator/chapters/{id}/images
   */
  static async uploadChapterImages(
    id: number | string,
    files: File[]
  ): Promise<UploadImagesSuccessResponse> {
    const encodedId = encodeURIComponent(String(id))
    const formData = new FormData()
    files.forEach((file) => {
      formData.append('images[]', file)
    })

    const response = await httpClient.post<UploadImagesSuccessResponse>(
      `/creator/chapters/${encodedId}/images`,
      formData
    )
    return response.data
  }

  /**
   * Reorder page images of a chapter.
   * Requires exact list of all image IDs of the chapter.
   * PUT /api/creator/chapters/{id}/images/reorder
   */
  static async reorderChapterImages(
    id: number | string,
    payload: ReorderImagesPayload
  ): Promise<ReorderImagesSuccessResponse> {
    const encodedId = encodeURIComponent(String(id))

    // Pre-validation: verify no duplicate image IDs
    if (payload.image_ids) {
      const uniqueIds = new Set(payload.image_ids)
      if (uniqueIds.size !== payload.image_ids.length) {
        throw new Error('Danh sách ID ảnh bị trùng lặp, không thể sắp xếp.')
      }
    }

    const response = await httpClient.put<ReorderImagesSuccessResponse>(
      `/creator/chapters/${encodedId}/images/reorder`,
      payload
    )
    return response.data
  }

  /**
   * Delete a single page image from the chapter.
   * Backend automatically re-indexes remaining images.
   * DELETE /api/creator/chapters/{chapterId}/images/{imageId}
   */
  static async deleteChapterImage(
    chapterId: number | string,
    imageId: number | string
  ): Promise<ActionSuccessResponse> {
    const encodedChapterId = encodeURIComponent(String(chapterId))
    const encodedImageId = encodeURIComponent(String(imageId))
    const response = await httpClient.delete<ActionSuccessResponse>(
      `/creator/chapters/${encodedChapterId}/images/${encodedImageId}`
    )
    return response.data
  }
}
