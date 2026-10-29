<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import CreatorLayout from '@/layouts/CreatorLayout.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import RejectionReasonModal from '@/components/common/RejectionReasonModal.vue'
import { CreatorService } from '@/services/creatorService'
import {
  getErrorMessage,
  getValidationErrors,
  isForbiddenError,
  isNotFoundError,
} from '@/services/httpClient'
import type {
  CreatorComic,
  CreatorChapter,
  ChapterStatus,
  CreateChapterPayload,
  UpdateChapterPayload,
} from '@/types/creator'
import type { PaginationMeta } from '@/types/catalog'

const route = useRoute()
const router = useRouter()

// Comic ID from route parameter
const comicId = computed(() => {
  const param = route.params.comicId
  return Array.isArray(param) ? param[0] : param
})

// Data state
const comic = ref<CreatorComic | null>(null)
const chapters = ref<CreatorChapter[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)

// Loading & error states
const isLoadingComic = ref(true)
const isLoadingChapters = ref(true)
const comicError = ref<string | null>(null)
const chaptersError = ref<string | null>(null)
const isForbidden = ref(false)
const isNotFound = ref(false)

// Request race condition prevention counters
let latestComicRequestId = 0
let latestChaptersRequestId = 0
let isComponentMounted = true

// Notification toasts
const successFeedback = ref<string | null>(null)
const actionError = ref<string | null>(null)
let feedbackTimer: ReturnType<typeof setTimeout> | null = null

function showSuccessToast(msg: string) {
  if (!isComponentMounted) return
  if (feedbackTimer) {
    clearTimeout(feedbackTimer)
    feedbackTimer = null
  }
  successFeedback.value = msg
  actionError.value = null
  feedbackTimer = setTimeout(() => {
    if (isComponentMounted) {
      successFeedback.value = null
    }
  }, 4500)
}

function showErrorToast(msg: string) {
  if (!isComponentMounted) return
  if (feedbackTimer) {
    clearTimeout(feedbackTimer)
    feedbackTimer = null
  }
  actionError.value = msg
  feedbackTimer = setTimeout(() => {
    if (isComponentMounted) {
      actionError.value = null
    }
  }, 6000)
}

function clearToasts() {
  if (feedbackTimer) {
    clearTimeout(feedbackTimer)
    feedbackTimer = null
  }
  successFeedback.value = null
  actionError.value = null
}

// Filters & pagination
const selectedStatus = ref<ChapterStatus | ''>('')
const currentPage = ref(1)

// Cover image fallback
const coverImageError = ref(false)
function handleCoverError() {
  coverImageError.value = true
}

// -------------------------------------------------------------
// Format Helpers
// -------------------------------------------------------------

/**
 * Format chapter number cleanly:
 * "1.00" -> "Chương 1"
 * "1.50" -> "Chương 1.5"
 * "2.25" -> "Chương 2.25"
 */
function formatChapterNumberDisplay(rawNum: string | number): string {
  const n = parseFloat(String(rawNum))
  if (isNaN(n)) return `Chương ${rawNum}`
  const formatted = Number.isInteger(n) ? n.toString() : parseFloat(n.toFixed(2)).toString()
  return `Chương ${formatted}`
}

/**
 * Extract clean string representation of chapter number for URLs and editing forms:
 * "1.00" -> "1"
 * "1.50" -> "1.5"
 * "2.25" -> "2.25"
 */
function getCleanChapterNumber(rawNum: string | number): string {
  const n = parseFloat(String(rawNum))
  if (isNaN(n)) return String(rawNum)
  return Number.isInteger(n) ? n.toString() : parseFloat(n.toFixed(2)).toString()
}

/**
 * Format ISO datetime string to VN format
 */
function formatDateTime(isoString: string | null | undefined): string {
  if (!isoString) return '—'
  try {
    const d = new Date(isoString)
    return d.toLocaleDateString('vi-VN', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return isoString
  }
}

// -------------------------------------------------------------
// Data Fetching
// -------------------------------------------------------------

/**
 * Load parent comic details to verify ownership and display header metadata.
 * Uses request sequence ID to strictly discard stale responses and isolates state on error.
 */
async function loadComicDetails() {
  const currentId = comicId.value
  if (!currentId) {
    comic.value = null
    chapters.value = []
    paginationMeta.value = null
    comicError.value = 'Mã bộ truyện không hợp lệ.'
    isLoadingComic.value = false
    return
  }

  const reqId = ++latestComicRequestId
  isLoadingComic.value = true
  comicError.value = null
  isForbidden.value = false
  isNotFound.value = false
  coverImageError.value = false

  // Clear previous comic and chapters state to avoid leaking old data across routes or on error
  comic.value = null
  chapters.value = []
  paginationMeta.value = null

  try {
    const res = await CreatorService.getComic(currentId)
    // Ignore out-of-order response if route changed while in-flight
    if (reqId !== latestComicRequestId) return
    comic.value = res.data
  } catch (err: unknown) {
    if (reqId !== latestComicRequestId) return
    comic.value = null
    chapters.value = []
    paginationMeta.value = null

    if (isForbiddenError(err)) {
      isForbidden.value = true
      comicError.value = 'Bạn không có quyền truy cập hoặc quản lý các chương của bộ truyện này.'
    } else if (isNotFoundError(err)) {
      isNotFound.value = true
      comicError.value = 'Bộ truyện không tồn tại hoặc đã bị xóa.'
    } else {
      comicError.value = getErrorMessage(
        err,
        'Không thể tải thông tin bộ truyện. Vui lòng thử lại sau.'
      )
    }
  } finally {
    if (reqId === latestComicRequestId) {
      isLoadingComic.value = false
    }
  }
}

/**
 * Load chapters list for this comic with current filter and pagination.
 * Discards stale responses from previous comic routes or cancelled requests.
 */
async function loadChapters(page = currentPage.value) {
  const currentComicId = comicId.value
  if (!currentComicId || isForbidden.value || isNotFound.value || comicError.value || !comic.value) {
    isLoadingChapters.value = false
    return
  }

  const reqId = ++latestChaptersRequestId
  isLoadingChapters.value = true
  chaptersError.value = null

  try {
    const res = await CreatorService.getChapters(currentComicId, {
      page,
      per_page: 20,
      status: selectedStatus.value || undefined,
    })

    if (reqId !== latestChaptersRequestId) return
    chapters.value = res.data || []
    paginationMeta.value = res.meta || null
    currentPage.value = page
  } catch (err: unknown) {
    if (reqId !== latestChaptersRequestId) return
    chapters.value = []
    paginationMeta.value = null
    chaptersError.value = getErrorMessage(
      err,
      'Không thể tải danh sách chương. Vui lòng thử lại.'
    )
  } finally {
    if (reqId === latestChaptersRequestId) {
      isLoadingChapters.value = false
    }
  }
}

/**
 * Full page refresh (both comic and chapters), resetting page to 1
 */
async function refreshAll() {
  clearToasts()
  currentPage.value = 1
  await loadComicDetails()
  if (!isForbidden.value && !isNotFound.value && !comicError.value && comic.value) {
    await loadChapters(1)
  }
}

function handleStatusFilterChange() {
  currentPage.value = 1
  loadChapters(1)
}

function handlePageChange(page: number) {
  if (page < 1 || (paginationMeta.value && page > paginationMeta.value.last_page)) return
  loadChapters(page)
  window.scrollTo({ top: 120, behavior: 'smooth' })
}

// -------------------------------------------------------------
// Create & Edit Chapter Modal State & Accessibility
// -------------------------------------------------------------

const formModalOpen = ref(false)
const formModalMode = ref<'create' | 'edit'>('create')
const editingChapter = ref<CreatorChapter | null>(null)
const isSubmittingForm = ref(false)
const formSubmitError = ref<string | null>(null)
let prevBodyOverflow = ''

// Form fields
const formValues = reactive({
  chapter_number: '',
  title: '',
})

// Field-specific validation errors (e.g. from 422 or frontend checks)
const formErrors = reactive<{
  chapter_number: string | null
  title: string | null
}>({
  chapter_number: null,
  title: null,
})

function handleChapterModalKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && formModalOpen.value && !isSubmittingForm.value) {
    closeFormModal()
  }
}

// Watch modal open state to handle Escape key accessibility and background scroll locking
watch(
  () => formModalOpen.value,
  (open) => {
    if (open) {
      window.addEventListener('keydown', handleChapterModalKeyDown)
      prevBodyOverflow = document.body.style.overflow
      document.body.style.overflow = 'hidden'
    } else {
      window.removeEventListener('keydown', handleChapterModalKeyDown)
      document.body.style.overflow = prevBodyOverflow
    }
  }
)

function resetForm() {
  formValues.chapter_number = ''
  formValues.title = ''
  formErrors.chapter_number = null
  formErrors.title = null
  formSubmitError.value = null
  editingChapter.value = null
  isSubmittingForm.value = false
}

function openCreateModal() {
  resetForm()
  formModalMode.value = 'create'
  formModalOpen.value = true
}

function openEditModal(chapter: CreatorChapter) {
  // Guard against editing pending or published chapters
  if (chapter.status === 'pending' || chapter.status === 'published') {
    showErrorToast(
      `Không thể chỉnh sửa chương đang ở trạng thái "${
        chapter.status === 'pending' ? 'Chờ duyệt' : 'Đã xuất bản'
      }".`
    )
    return
  }

  resetForm()
  formModalMode.value = 'edit'
  editingChapter.value = chapter
  formValues.chapter_number = getCleanChapterNumber(chapter.chapter_number)
  formValues.title = chapter.title || ''
  formModalOpen.value = true
}

function closeFormModal() {
  if (isSubmittingForm.value) return
  formModalOpen.value = false
  resetForm()
}

/**
 * Validate form inputs on client-side before sending to API
 */
function validateChapterForm(): boolean {
  let isValid = true
  formErrors.chapter_number = null
  formErrors.title = null
  formSubmitError.value = null

  const numTrimmed = formValues.chapter_number.trim()

  // chapter_number validation
  if (!numTrimmed) {
    formErrors.chapter_number = 'Số thứ tự chương là bắt buộc.'
    isValid = false
  } else {
    // Regex matches 1-4 digits integer or decimal with up to 2 decimal places
    const regex = /^[0-9]{1,4}(\.[0-9]{1,2})?$/
    if (!regex.test(numTrimmed)) {
      formErrors.chapter_number =
        'Số chương không hợp lệ. Phải là số từ 0 đến 9999.99 và tối đa 2 chữ số thập phân.'
      isValid = false
    } else {
      const parsed = parseFloat(numTrimmed)
      if (isNaN(parsed) || parsed < 0 || parsed > 9999.99) {
        formErrors.chapter_number = 'Số chương phải nằm trong khoảng từ 0 đến 9999.99.'
        isValid = false
      }
    }
  }

  // title validation (optional, max 150 chars)
  if (formValues.title && formValues.title.trim().length > 150) {
    formErrors.title = 'Tiêu đề chương không được vượt quá 150 ký tự.'
    isValid = false
  }

  return isValid
}

/**
 * Handle form submission for create or update
 */
async function handleSubmitChapterForm() {
  if (isSubmittingForm.value) return

  // Strict check if editing an unauthorized status
  if (
    formModalMode.value === 'edit' &&
    editingChapter.value &&
    editingChapter.value.status !== 'draft' &&
    editingChapter.value.status !== 'rejected'
  ) {
    formSubmitError.value =
      'Chương ở trạng thái này không thể chỉnh sửa. Vui lòng đóng cửa sổ.'
    return
  }

  if (!validateChapterForm()) return

  isSubmittingForm.value = true
  formSubmitError.value = null

  try {
    if (formModalMode.value === 'create') {
      const payload: CreateChapterPayload = {
        chapter_number: formValues.chapter_number.trim(),
        title: formValues.title.trim() || null,
      }
      const res = await CreatorService.createChapter(comicId.value, payload)
      formModalOpen.value = false
      showSuccessToast(`Đã tạo ${formatChapterNumberDisplay(res.data.chapter_number)} thành công.`)
      // Refresh comic metadata (to get updated chapter count) and chapter list
      await loadComicDetails()
      await loadChapters(1)
    } else if (formModalMode.value === 'edit' && editingChapter.value) {
      const payload: UpdateChapterPayload = {
        chapter_number: formValues.chapter_number.trim(),
        title: formValues.title.trim() || null,
      }
      const res = await CreatorService.updateChapter(editingChapter.value.id, payload)
      formModalOpen.value = false
      showSuccessToast(
        `Đã cập nhật ${formatChapterNumberDisplay(res.data.chapter_number)} thành công.`
      )
      await loadChapters(currentPage.value)
    }
  } catch (err: unknown) {
    const fieldValidationErrors = getValidationErrors(err)

    if (fieldValidationErrors.chapter_number && fieldValidationErrors.chapter_number.length > 0) {
      formErrors.chapter_number = fieldValidationErrors.chapter_number[0]
    }
    if (fieldValidationErrors.title && fieldValidationErrors.title.length > 0) {
      formErrors.title = fieldValidationErrors.title[0]
    }

    // General or fallback error message
    formSubmitError.value = getErrorMessage(
      err,
      formModalMode.value === 'create'
        ? 'Không thể tạo chương mới. Vui lòng kiểm tra lại thông tin.'
        : 'Không thể cập nhật chương. Vui lòng kiểm tra lại thông tin.'
    )
  } finally {
    isSubmittingForm.value = false
  }
}

// -------------------------------------------------------------
// Status Transition Actions & Modals
// -------------------------------------------------------------

const isActionLoading = ref(false)

// Confirm Modal state
const confirmModalOpen = ref(false)
const confirmModalTitle = ref('')
const confirmModalMessage = ref('')
const confirmModalType = ref<'primary' | 'danger' | 'warning'>('primary')
const confirmModalConfirmText = ref('Xác nhận')
const confirmModalAction = ref<(() => Promise<void>) | null>(null)

// Rejection Reason Modal state
const rejectionModalOpen = ref(false)
const rejectionModalChapter = ref<CreatorChapter | null>(null)

/**
 * Prompt Submit Review (draft/rejected -> pending)
 */
function promptSubmitReview(chapter: CreatorChapter) {
  if (chapter.images_count < 1) {
    showErrorToast(
      `${formatChapterNumberDisplay(
        chapter.chapter_number
      )} chưa có ảnh nào. Bạn cần tải lên ít nhất 1 ảnh trước khi gửi duyệt.`
    )
    return
  }

  const numLabel = formatChapterNumberDisplay(chapter.chapter_number)
  confirmModalTitle.value = `Gửi duyệt ${numLabel}`
  confirmModalMessage.value = `Bạn có chắc chắn muốn gửi ${numLabel} tới Quản trị viên để kiểm duyệt? Trong thời gian chờ duyệt, bạn sẽ không thể chỉnh sửa chương này.`
  confirmModalType.value = 'primary'
  confirmModalConfirmText.value = 'Gửi kiểm duyệt'

  confirmModalAction.value = async () => {
    if (isActionLoading.value) return
    isActionLoading.value = true
    try {
      await CreatorService.submitChapterReview(chapter.id)
      confirmModalOpen.value = false
      showSuccessToast(`Đã gửi duyệt ${numLabel} thành công.`)
      await loadChapters(currentPage.value)
    } catch (err: unknown) {
      confirmModalOpen.value = false
      showErrorToast(getErrorMessage(err, `Không thể gửi duyệt ${numLabel}.`))
      await loadChapters(currentPage.value)
    } finally {
      isActionLoading.value = false
    }
  }

  confirmModalOpen.value = true
}

/**
 * Prompt Cancel Review (pending -> draft)
 */
function promptCancelReview(chapter: CreatorChapter) {
  const numLabel = formatChapterNumberDisplay(chapter.chapter_number)
  confirmModalTitle.value = `Rút yêu cầu duyệt ${numLabel}`
  confirmModalMessage.value = `Bạn có chắc chắn muốn rút yêu cầu kiểm duyệt cho ${numLabel}? Chương sẽ quay về trạng thái "Bản nháp" để bạn có thể tiếp tục chỉnh sửa.`
  confirmModalType.value = 'warning'
  confirmModalConfirmText.value = 'Rút yêu cầu'

  confirmModalAction.value = async () => {
    if (isActionLoading.value) return
    isActionLoading.value = true
    try {
      await CreatorService.cancelChapterReview(chapter.id)
      confirmModalOpen.value = false
      showSuccessToast(`Đã rút yêu cầu duyệt ${numLabel}.`)
      await loadChapters(currentPage.value)
    } catch (err: unknown) {
      confirmModalOpen.value = false
      showErrorToast(getErrorMessage(err, `Không thể rút yêu cầu duyệt ${numLabel}.`))
      await loadChapters(currentPage.value)
    } finally {
      isActionLoading.value = false
    }
  }

  confirmModalOpen.value = true
}

/**
 * Prompt Recall Published Chapter (published -> draft)
 */
function promptRecallChapter(chapter: CreatorChapter) {
  const numLabel = formatChapterNumberDisplay(chapter.chapter_number)
  confirmModalTitle.value = `Thu hồi ${numLabel} đã xuất bản`
  confirmModalMessage.value = `Cảnh báo: Thu hồi chương sẽ gỡ bỏ ${numLabel} khỏi danh sách đọc của độc giả và chuyển về trạng thái "Bản nháp". Bạn có chắc chắn muốn thu hồi?`
  confirmModalType.value = 'danger'
  confirmModalConfirmText.value = 'Thu hồi về nháp'

  confirmModalAction.value = async () => {
    if (isActionLoading.value) return
    isActionLoading.value = true
    try {
      await CreatorService.recallChapter(chapter.id)
      confirmModalOpen.value = false
      showSuccessToast(`Đã thu hồi ${numLabel} về trạng thái Bản nháp.`)
      await loadComicDetails()
      await loadChapters(currentPage.value)
    } catch (err: unknown) {
      confirmModalOpen.value = false
      showErrorToast(getErrorMessage(err, `Không thể thu hồi ${numLabel}.`))
      await loadChapters(currentPage.value)
    } finally {
      isActionLoading.value = false
    }
  }

  confirmModalOpen.value = true
}

/**
 * Open Rejection Reason Modal
 */
function openRejectionModal(chapter: CreatorChapter) {
  rejectionModalChapter.value = chapter
  rejectionModalOpen.value = true
}

function handleRejectionModalEdit() {
  const target = rejectionModalChapter.value
  rejectionModalOpen.value = false
  if (target) {
    openEditModal(target)
  }
}

/**
 * Navigate to images management page
 */
function navigateToImages(chapterId: number) {
  router.push({
    name: 'creator-chapter-images',
    params: { id: String(chapterId) },
  })
}

// -------------------------------------------------------------
// Lifecycle
// -------------------------------------------------------------

onMounted(async () => {
  isComponentMounted = true
  await loadComicDetails()
  if (!isForbidden.value && !isNotFound.value && !comicError.value && comic.value) {
    await loadChapters(1)
  }
})

onUnmounted(() => {
  isComponentMounted = false
  if (feedbackTimer) {
    clearTimeout(feedbackTimer)
    feedbackTimer = null
  }
  window.removeEventListener('keydown', handleChapterModalKeyDown)
  document.body.style.overflow = prevBodyOverflow
})

// Re-fetch if comicId param in route changes, resetting page to 1
watch(
  () => route.params.comicId,
  async (newId, oldId) => {
    if (newId && newId !== oldId) {
      currentPage.value = 1
      selectedStatus.value = ''
      await refreshAll()
    }
  }
)
</script>

<template>
  <CreatorLayout>
    <div class="creator-chapters-page">
      <!-- Breadcrumb Navigation -->
      <nav class="breadcrumb-nav" aria-label="Đường dẫn điều hướng">
        <RouterLink to="/creator/comics" class="breadcrumb-back-link">
          <span class="back-arrow" aria-hidden="true">←</span>
          <span>Danh sách truyện của tôi</span>
        </RouterLink>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="breadcrumb-current">Quản lý chương</span>
      </nav>

      <!-- Global Feedback Banners -->
      <Transition name="fade">
        <div v-if="successFeedback" class="alert-banner alert-success" role="alert">
          <span class="alert-icon" aria-hidden="true">✓</span>
          <span class="alert-message">{{ successFeedback }}</span>
          <button
            type="button"
            class="alert-close-btn"
            aria-label="Đóng thông báo"
            @click="successFeedback = null"
          >
            ✕
          </button>
        </div>
      </Transition>

      <Transition name="fade">
        <div v-if="actionError" class="alert-banner alert-danger" role="alert">
          <span class="alert-icon" aria-hidden="true">⚠️</span>
          <span class="alert-message">{{ actionError }}</span>
          <button
            type="button"
            class="alert-close-btn"
            aria-label="Đóng thông báo lỗi"
            @click="actionError = null"
          >
            ✕
          </button>
        </div>
      </Transition>

      <!-- Access Denied (403) or Not Found (404) State -->
      <section v-if="isForbidden || isNotFound" class="error-panel" aria-live="assertive">
        <div class="error-panel-icon" aria-hidden="true">
          {{ isForbidden ? '🔒' : '🔍' }}
        </div>
        <h2 class="error-panel-title">
          {{ isForbidden ? 'Truy cập bị từ chối' : 'Không tìm thấy bộ truyện' }}
        </h2>
        <p class="error-panel-desc">{{ comicError }}</p>
        <div class="error-panel-actions">
          <RouterLink to="/creator/comics" class="btn btn-primary">
            Quay lại danh sách truyện
          </RouterLink>
        </div>
      </section>

      <!-- General Comic Load Error -->
      <section
        v-else-if="comicError && !comic"
        class="error-panel"
        aria-live="assertive"
      >
        <div class="error-panel-icon" aria-hidden="true">⚠️</div>
        <h2 class="error-panel-title">Đã xảy ra lỗi tải dữ liệu</h2>
        <p class="error-panel-desc">{{ comicError }}</p>
        <div class="error-panel-actions">
          <button type="button" class="btn btn-primary" @click="refreshAll">
            Thử lại
          </button>
          <RouterLink to="/creator/comics" class="btn btn-outline">
            Về danh sách truyện
          </RouterLink>
        </div>
      </section>

      <!-- Main Content Area: Rendered when comic is loaded or while initial loading -->
      <template v-else-if="comic || isLoadingComic">
        <!-- Parent Comic Information Header Card -->
        <header class="comic-header-card">
          <!-- Loading skeleton for comic header -->
          <div v-if="isLoadingComic && !comic" class="comic-header-skeleton">
            <div class="skeleton-cover"></div>
            <div class="skeleton-details">
              <div class="skeleton-line title"></div>
              <div class="skeleton-line author"></div>
              <div class="skeleton-line badges"></div>
            </div>
          </div>

          <!-- Comic Header Content -->
          <div v-else-if="comic" class="comic-header-inner">
            <div class="comic-cover-wrap">
              <img
                v-if="comic.cover_url && !coverImageError"
                :src="comic.cover_url"
                :alt="`Bìa truyện ${comic.title}`"
                class="comic-cover-img"
                @error="handleCoverError"
              />
              <div v-else class="comic-cover-placeholder" aria-hidden="true">
                <span class="cover-icon">📖</span>
                <span class="cover-text">BlueToon</span>
              </div>
            </div>

            <div class="comic-details">
              <div class="comic-title-row">
                <h1 class="comic-title">{{ comic.title }}</h1>
                <RouterLink
                  :to="`/creator/comics/${comic.id}/edit`"
                  class="btn-edit-comic"
                  title="Chỉnh sửa thông tin tác phẩm"
                >
                  <span aria-hidden="true">✏️</span> Sửa thông tin truyện
                </RouterLink>
              </div>

              <p class="comic-author">
                Tác giả: <span class="author-name">{{ comic.author_name }}</span>
              </p>

              <div class="comic-status-badges">
                <StatusBadge :status="comic.approval_status" />
                <StatusBadge :progress-status="comic.status" />
              </div>

              <!-- Quick stats computed by backend -->
              <div class="comic-stats-bar">
                <div class="stat-item">
                  <span class="stat-label">Tổng số chương:</span>
                  <span class="stat-value">
                    {{ comic.chapters_count ?? (paginationMeta?.total ?? '—') }}
                  </span>
                </div>
                <div class="stat-divider" aria-hidden="true">•</div>
                <div class="stat-item">
                  <span class="stat-label">Đã xuất bản:</span>
                  <span class="stat-value highlight-published">
                    {{ comic.published_chapters_count ?? '—' }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Header Action Button -->
            <div class="comic-header-actions">
              <button
                type="button"
                class="btn btn-primary btn-add-chapter"
                @click="openCreateModal"
              >
                <span class="btn-icon" aria-hidden="true">+</span>
                <span>Tạo chương mới</span>
              </button>
            </div>
          </div>
        </header>

        <!-- Chapter Filter and Action Toolbar -->
        <section class="chapters-toolbar" aria-label="Bộ lọc và thao tác danh sách chương">
          <div class="filter-group">
            <label for="chapter-status-filter" class="filter-label">Trạng thái:</label>
            <select
              id="chapter-status-filter"
              v-model="selectedStatus"
              class="filter-select"
              @change="handleStatusFilterChange"
            >
              <option value="">Tất cả trạng thái</option>
              <option value="draft">Bản nháp</option>
              <option value="pending">Chờ duyệt</option>
              <option value="published">Đã xuất bản</option>
              <option value="rejected">Bị từ chối</option>
            </select>
          </div>

          <div class="toolbar-stats">
            <span v-if="paginationMeta" class="toolbar-counter">
              Hiển thị {{ paginationMeta.from ?? 0 }} - {{ paginationMeta.to ?? 0 }}
              trên tổng số {{ paginationMeta.total }} chương
            </span>
          </div>

          <div class="toolbar-actions">
            <button
              type="button"
              class="btn-refresh"
              :disabled="isLoadingChapters"
              title="Tải lại danh sách"
              @click="loadChapters(currentPage)"
            >
              <span class="refresh-icon" :class="{ spinning: isLoadingChapters }">↻</span>
              <span class="refresh-label">Làm mới</span>
            </button>
          </div>
        </section>

        <!-- Chapters List Section -->
        <section class="chapters-table-section">
          <!-- Chapters Load Error -->
          <div v-if="chaptersError" class="chapters-error-box" role="alert">
            <span class="error-icon" aria-hidden="true">⚠️</span>
            <div class="error-content">
              <p class="error-text">{{ chaptersError }}</p>
              <button
                type="button"
                class="btn btn-sm btn-outline"
                @click="loadChapters(currentPage)"
              >
                Thử lại
              </button>
            </div>
          </div>

          <!-- Loading Skeleton -->
          <div v-else-if="isLoadingChapters" class="chapters-loading-container">
            <div v-for="i in 5" :key="i" class="chapter-row-skeleton">
              <div class="skeleton-cell num"></div>
              <div class="skeleton-cell title"></div>
              <div class="skeleton-cell count"></div>
              <div class="skeleton-cell badge"></div>
              <div class="skeleton-cell actions"></div>
            </div>
          </div>

          <!-- Empty State -->
          <div
            v-else-if="chapters.length === 0"
            class="chapters-empty-state"
          >
            <div class="empty-icon" aria-hidden="true">📚</div>
            <h3 class="empty-title">
              {{
                selectedStatus
                  ? 'Không tìm thấy chương nào phù hợp'
                  : 'Bộ truyện này chưa có chương nào'
              }}
            </h3>
            <p class="empty-desc">
              {{
                selectedStatus
                  ? 'Không có chương nào thuộc trạng thái đã chọn. Hãy thử chọn bộ lọc khác hoặc xem toàn bộ danh sách.'
                  : 'Hãy tạo chương đầu tiên để bắt đầu đăng tải nội dung trang truyện Webtoon của bạn!'
              }}
            </p>
            <div class="empty-actions">
              <button
                v-if="selectedStatus"
                type="button"
                class="btn btn-outline"
                @click="selectedStatus = ''; handleStatusFilterChange()"
              >
                Xem tất cả chương
              </button>
              <button
                v-else
                type="button"
                class="btn btn-primary"
                @click="openCreateModal"
              >
                <span aria-hidden="true">+</span> Tạo chương đầu tiên
              </button>
            </div>
          </div>

          <!-- Table Container (Desktop / Tablet) -->
          <div v-else class="table-responsive">
            <table class="chapters-table">
              <thead>
                <tr>
                  <th scope="col" class="th-number">Số chương</th>
                  <th scope="col" class="th-title">Tiêu đề chương</th>
                  <th scope="col" class="th-images">Số ảnh</th>
                  <th scope="col" class="th-views">Lượt xem</th>
                  <th scope="col" class="th-status">Trạng thái</th>
                  <th scope="col" class="th-date">Cập nhật</th>
                  <th scope="col" class="th-actions">Thao tác</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="chap in chapters"
                  :key="chap.id"
                  class="chapter-row"
                  :class="`row-status-${chap.status}`"
                >
                  <!-- Chapter Number -->
                  <td class="td-number">
                    <span class="chapter-num-badge">
                      {{ formatChapterNumberDisplay(chap.chapter_number) }}
                    </span>
                  </td>

                  <!-- Chapter Title -->
                  <td class="td-title">
                    <span v-if="chap.title" class="chapter-title-text">{{ chap.title }}</span>
                    <span v-else class="chapter-title-empty">(Chưa đặt tiêu đề)</span>
                  </td>

                  <!-- Images Count -->
                  <td class="td-images">
                    <span class="images-counter" :class="{ 'zero-images': chap.images_count === 0 }">
                      <span class="img-icon" aria-hidden="true">🖼️</span>
                      <span>{{ chap.images_count }} trang</span>
                    </span>
                  </td>

                  <!-- Views Count -->
                  <td class="td-views">
                    <span class="views-text">
                      {{ chap.views_count.toLocaleString('vi-VN') }}
                    </span>
                  </td>

                  <!-- Status Badge -->
                  <td class="td-status">
                    <StatusBadge :status="chap.status" />
                  </td>

                  <!-- Updated At -->
                  <td class="td-date">
                    <span class="date-text">{{ formatDateTime(chap.updated_at) }}</span>
                  </td>

                  <!-- State-dependent Actions -->
                  <td class="td-actions">
                    <div class="action-buttons-group">
                      <!-- 1. DRAFT STATUS -->
                      <template v-if="chap.status === 'draft'">
                        <!-- Manage Images Button -->
                        <button
                          type="button"
                          class="action-btn btn-images"
                          title="Quản lý và tải lên ảnh trang truyện"
                          @click="navigateToImages(chap.id)"
                        >
                          <span aria-hidden="true">🖼️</span>
                          <span class="btn-text">Ảnh trang</span>
                        </button>

                        <!-- Edit Chapter Details -->
                        <button
                          type="button"
                          class="action-btn btn-edit"
                          title="Sửa số chương và tiêu đề"
                          @click="openEditModal(chap)"
                        >
                          <span aria-hidden="true">✏️</span>
                          <span class="btn-text">Sửa</span>
                        </button>

                        <!-- Submit for Review -->
                        <button
                          type="button"
                          class="action-btn btn-submit"
                          :disabled="chap.images_count === 0"
                          :title="
                            chap.images_count === 0
                              ? 'Cần tải lên ít nhất 1 ảnh để gửi duyệt'
                              : 'Gửi chương tới Quản trị viên để kiểm duyệt'
                          "
                          @click="promptSubmitReview(chap)"
                        >
                          <span aria-hidden="true">🚀</span>
                          <span class="btn-text">Gửi duyệt</span>
                        </button>
                      </template>

                      <!-- 2. PENDING STATUS -->
                      <template v-else-if="chap.status === 'pending'">
                        <!-- View Images (Read-Only) -->
                        <button
                          type="button"
                          class="action-btn btn-images"
                          title="Xem danh sách ảnh (Chế độ chỉ đọc)"
                          @click="navigateToImages(chap.id)"
                        >
                          <span aria-hidden="true">👁️</span>
                          <span class="btn-text">Xem ảnh</span>
                        </button>

                        <!-- Cancel Review (Withdraw back to draft) -->
                        <button
                          type="button"
                          class="action-btn btn-cancel-review"
                          title="Rút lại yêu cầu duyệt để tiếp tục chỉnh sửa"
                          @click="promptCancelReview(chap)"
                        >
                          <span aria-hidden="true">↩️</span>
                          <span class="btn-text">Rút duyệt</span>
                        </button>
                      </template>

                      <!-- 3. PUBLISHED STATUS -->
                      <template v-else-if="chap.status === 'published'">
                        <!-- View Images (Read-Only) -->
                        <button
                          type="button"
                          class="action-btn btn-images"
                          title="Xem danh sách ảnh (Chế độ chỉ đọc)"
                          @click="navigateToImages(chap.id)"
                        >
                          <span aria-hidden="true">👁️</span>
                          <span class="btn-text">Xem ảnh</span>
                        </button>

                        <!-- Public Reader Link (using normalized chapter number) -->
                        <RouterLink
                          v-if="comic?.slug"
                          :to="`/comics/${comic.slug}/chapters/${getCleanChapterNumber(chap.chapter_number)}`"
                          target="_blank"
                          class="action-btn btn-read-public"
                          title="Mở trang đọc trực tiếp của độc giả (Tab mới)"
                        >
                          <span aria-hidden="true">📖</span>
                          <span class="btn-text">Đọc</span>
                        </RouterLink>

                        <!-- Recall to Draft -->
                        <button
                          type="button"
                          class="action-btn btn-recall"
                          title="Thu hồi chương về bản nháp (sẽ ẩn khỏi độc giả)"
                          @click="promptRecallChapter(chap)"
                        >
                          <span aria-hidden="true">📥</span>
                          <span class="btn-text">Thu hồi</span>
                        </button>
                      </template>

                      <!-- 4. REJECTED STATUS -->
                      <template v-else-if="chap.status === 'rejected'">
                        <!-- View Rejection Reason -->
                        <button
                          type="button"
                          class="action-btn btn-rejection"
                          title="Xem nhận xét từ Quản trị viên"
                          @click="openRejectionModal(chap)"
                        >
                          <span aria-hidden="true">⚠️</span>
                          <span class="btn-text">Lý do từ chối</span>
                        </button>

                        <!-- Manage Images -->
                        <button
                          type="button"
                          class="action-btn btn-images"
                          title="Quản lý và tải lên lại ảnh"
                          @click="navigateToImages(chap.id)"
                        >
                          <span aria-hidden="true">🖼️</span>
                          <span class="btn-text">Ảnh trang</span>
                        </button>

                        <!-- Edit Chapter Details -->
                        <button
                          type="button"
                          class="action-btn btn-edit"
                          title="Chỉnh sửa thông tin chương"
                          @click="openEditModal(chap)"
                        >
                          <span aria-hidden="true">✏️</span>
                          <span class="btn-text">Sửa</span>
                        </button>

                        <!-- Resubmit Review -->
                        <button
                          type="button"
                          class="action-btn btn-submit"
                          :disabled="chap.images_count === 0"
                          :title="
                            chap.images_count === 0
                              ? 'Cần tải lên ít nhất 1 ảnh để gửi duyệt lại'
                              : 'Gửi duyệt lại sau khi chỉnh sửa'
                          "
                          @click="promptSubmitReview(chap)"
                        >
                          <span aria-hidden="true">🚀</span>
                          <span class="btn-text">Gửi lại</span>
                        </button>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination Bar -->
          <footer
            v-if="paginationMeta && paginationMeta.last_page > 1"
            class="pagination-bar"
            aria-label="Phân trang chương"
          >
            <div class="pagination-info">
              Trang <span class="current-pg">{{ paginationMeta.current_page }}</span> /
              {{ paginationMeta.last_page }} (Tổng cộng {{ paginationMeta.total }} chương)
            </div>

            <div class="pagination-buttons">
              <button
                type="button"
                class="pg-btn prev-btn"
                :disabled="paginationMeta.current_page <= 1 || isLoadingChapters"
                @click="handlePageChange(paginationMeta.current_page - 1)"
              >
                ‹ Trang trước
              </button>

              <button
                type="button"
                class="pg-btn next-btn"
                :disabled="
                  paginationMeta.current_page >= paginationMeta.last_page || isLoadingChapters
                "
                @click="handlePageChange(paginationMeta.current_page + 1)"
              >
                Trang sau ›
              </button>
            </div>
          </footer>
        </section>
      </template>

      <!-- ======================================================= -->
      <!-- MODAL: Tạo / Chỉnh sửa chương (ChapterFormModal)        -->
      <!-- ======================================================= -->
      <Teleport to="body">
        <Transition name="modal-fade">
          <div
            v-if="formModalOpen"
            class="modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-labelledby="chapter-form-title"
            @click.self="!isSubmittingForm && closeFormModal()"
          >
            <div class="modal-card chapter-form-card">
              <!-- Header -->
              <div class="modal-header">
                <div class="modal-header-icon" aria-hidden="true">
                  {{ formModalMode === 'create' ? '✨' : '✏️' }}
                </div>
                <div class="modal-header-text">
                  <h3 id="chapter-form-title" class="modal-title">
                    {{
                      formModalMode === 'create'
                        ? 'Tạo chương mới'
                        : `Chỉnh sửa ${formatChapterNumberDisplay(
                            editingChapter?.chapter_number || ''
                          )}`
                    }}
                  </h3>
                  <p v-if="comic" class="modal-subtitle">Bộ truyện: {{ comic.title }}</p>
                </div>
                <button
                  v-if="!isSubmittingForm"
                  type="button"
                  class="modal-close-btn"
                  aria-label="Đóng cửa sổ"
                  @click="closeFormModal"
                >
                  ✕
                </button>
              </div>

              <!-- Form Body -->
              <form @submit.prevent="handleSubmitChapterForm">
                <div class="modal-body">
                  <!-- General Error Banner -->
                  <div v-if="formSubmitError" class="modal-alert-danger" role="alert">
                    <span class="alert-icon" aria-hidden="true">⚠️</span>
                    <span class="alert-text">{{ formSubmitError }}</span>
                  </div>

                  <!-- Safeguard Alert if chapter status is pending or published -->
                  <div
                    v-if="
                      formModalMode === 'edit' &&
                      editingChapter &&
                      editingChapter.status !== 'draft' &&
                      editingChapter.status !== 'rejected'
                    "
                    class="modal-alert-danger"
                    role="alert"
                  >
                    Chương này đang ở trạng thái không thể chỉnh sửa ({{
                      editingChapter.status
                    }}). Vui lòng đóng cửa sổ.
                  </div>

                  <!-- Field 1: Chapter Number -->
                  <div class="form-group" :class="{ 'has-error': !!formErrors.chapter_number }">
                    <div class="label-row">
                      <label for="input-chapter-number" class="field-label">
                        Số thứ tự chương <span class="required-mark">*</span>
                      </label>
                      <span class="field-hint">Số nguyên hoặc số thập phân</span>
                    </div>

                    <input
                      id="input-chapter-number"
                      v-model="formValues.chapter_number"
                      type="text"
                      class="field-input"
                      placeholder="Ví dụ: 1 hoặc 1.5 hoặc 2.25"
                      :disabled="
                        isSubmittingForm ||
                        (formModalMode === 'edit' &&
                          editingChapter !== null &&
                          editingChapter.status !== 'draft' &&
                          editingChapter.status !== 'rejected')
                      "
                      autocomplete="off"
                      @input="formErrors.chapter_number = null"
                    />

                    <!-- Help text -->
                    <p class="field-subtext">
                      Hỗ trợ số nguyên (1, 2, 3...) hoặc số thập phân tối đa hai chữ số (1.5, 2.1...
                      cho chương ngoại truyện, phụ bản).
                    </p>

                    <!-- Error text -->
                    <p
                      v-if="formErrors.chapter_number"
                      class="field-error-msg"
                      role="alert"
                    >
                      {{ formErrors.chapter_number }}
                    </p>
                  </div>

                  <!-- Field 2: Title (optional) -->
                  <div class="form-group" :class="{ 'has-error': !!formErrors.title }">
                    <div class="label-row">
                      <label for="input-chapter-title" class="field-label">
                        Tiêu đề chương
                      </label>
                      <span
                        class="char-counter"
                        :class="{ 'limit-reached': formValues.title.length > 150 }"
                      >
                        {{ formValues.title.length }}/150
                      </span>
                    </div>

                    <input
                      id="input-chapter-title"
                      v-model="formValues.title"
                      type="text"
                      maxlength="160"
                      class="field-input"
                      placeholder="Ví dụ: Khởi đầu mới (không bắt buộc)"
                      :disabled="
                        isSubmittingForm ||
                        (formModalMode === 'edit' &&
                          editingChapter !== null &&
                          editingChapter.status !== 'draft' &&
                          editingChapter.status !== 'rejected')
                      "
                      autocomplete="off"
                      @input="formErrors.title = null"
                    />

                    <!-- Error text -->
                    <p v-if="formErrors.title" class="field-error-msg" role="alert">
                      {{ formErrors.title }}
                    </p>
                  </div>
                </div>

                <!-- Footer Actions -->
                <div class="modal-footer">
                  <button
                    type="button"
                    class="btn btn-cancel"
                    :disabled="isSubmittingForm"
                    @click="closeFormModal"
                  >
                    Hủy bỏ
                  </button>

                  <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="
                      isSubmittingForm ||
                      (formModalMode === 'edit' &&
                        editingChapter !== null &&
                        editingChapter.status !== 'draft' &&
                        editingChapter.status !== 'rejected')
                    "
                  >
                    <span v-if="isSubmittingForm" class="btn-spinner" aria-hidden="true"></span>
                    <span>
                      {{
                        isSubmittingForm
                          ? 'Đang lưu...'
                          : formModalMode === 'create'
                          ? 'Tạo chương'
                          : 'Cập nhật'
                      }}
                    </span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </Transition>
      </Teleport>

      <!-- ======================================================= -->
      <!-- MODAL: Xác nhận thao tác (ConfirmModal)                 -->
      <!-- ======================================================= -->
      <ConfirmModal
        :is-open="confirmModalOpen"
        :title="confirmModalTitle"
        :message="confirmModalMessage"
        :type="confirmModalType"
        :confirm-text="confirmModalConfirmText"
        :is-loading="isActionLoading"
        @confirm="confirmModalAction && confirmModalAction()"
        @close="!isActionLoading && (confirmModalOpen = false)"
      />

      <!-- ======================================================= -->
      <!-- MODAL: Lý do từ chối (RejectionReasonModal)            -->
      <!-- ======================================================= -->
      <RejectionReasonModal
        :is-open="rejectionModalOpen"
        :title="`Lý do từ chối ${formatChapterNumberDisplay(
          rejectionModalChapter?.chapter_number || ''
        )}`"
        :comic-title="comic?.title"
        :reason="rejectionModalChapter?.rejection_reason || null"
        guide-text="Bạn có thể cập nhật lại thông tin và nội dung của chương truyện theo yêu cầu của Quản trị viên và gửi lại yêu cầu duyệt bất cứ lúc nào."
        @close="rejectionModalOpen = false"
        @edit="handleRejectionModalEdit"
      />
    </div>
  </CreatorLayout>
</template>

<style scoped>
.creator-chapters-page {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
  padding: var(--spacing-lg);
  max-width: var(--container-max-width);
  margin: 0 auto;
  width: 100%;
}

/* Breadcrumb Navigation */
.breadcrumb-nav {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

.breadcrumb-back-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--color-primary);
  text-decoration: none;
  font-weight: var(--font-weight-medium);
  transition: color var(--transition-fast);
}

.breadcrumb-back-link:hover {
  color: var(--color-primary-hover);
  text-decoration: underline;
}

.breadcrumb-separator {
  color: var(--color-border-subtle);
}

.breadcrumb-current {
  color: var(--color-text-secondary);
}

/* Feedback Banners */
.alert-banner {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-md) var(--spacing-lg);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  animation: fadeIn 0.2s ease;
}

.alert-success {
  background-color: var(--color-success-soft);
  border: 1px solid rgba(16, 185, 129, 0.35);
  color: #34d399;
}

.alert-danger {
  background-color: var(--color-danger-soft);
  border: 1px solid rgba(239, 68, 68, 0.35);
  color: #f87171;
}

.alert-icon {
  font-size: 16px;
  flex-shrink: 0;
}

.alert-message {
  flex: 1;
}

.alert-close-btn {
  background: none;
  border: none;
  color: inherit;
  font-size: 14px;
  cursor: pointer;
  padding: 4px;
  opacity: 0.8;
  border-radius: var(--radius-xs);
}

.alert-close-btn:hover {
  opacity: 1;
  background-color: rgba(255, 255, 255, 0.1);
}

/* Error Panel (403, 404, or Network) */
.error-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-xl);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  gap: var(--spacing-md);
}

.error-panel-icon {
  font-size: 48px;
}

.error-panel-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
}

.error-panel-desc {
  font-size: var(--font-size-base);
  color: var(--color-text-secondary);
  max-width: 500px;
  margin: 0;
}

.error-panel-actions {
  display: flex;
  gap: var(--spacing-md);
  margin-top: var(--spacing-sm);
}

/* Parent Comic Information Header Card */
.comic-header-card {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-lg);
  box-shadow: var(--shadow-md);
}

.comic-header-inner {
  display: flex;
  gap: var(--spacing-xl);
  align-items: center;
}

.comic-cover-wrap {
  width: 96px;
  height: 128px;
  flex-shrink: 0;
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-card);
}

.comic-cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.comic-cover-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: var(--color-text-muted);
}

.cover-icon {
  font-size: 28px;
}

.cover-text {
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.comic-details {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
  min-width: 0;
}

.comic-title-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.comic-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  line-height: var(--line-height-tight);
}

.btn-edit-comic {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-xs);
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border-subtle);
  color: var(--color-text-secondary);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-edit-comic:hover {
  background-color: var(--color-bg-card-hover);
  color: var(--color-primary);
  border-color: var(--color-border-accent);
}

.comic-author {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0;
}

.author-name {
  color: var(--color-text-secondary);
  font-weight: var(--font-weight-medium);
}

.comic-status-badges {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  margin-top: 2px;
}

.comic-stats-bar {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  margin-top: var(--spacing-xs);
  font-size: var(--font-size-sm);
}

.stat-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.stat-label {
  color: var(--color-text-muted);
}

.stat-value {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-semibold);
}

.highlight-published {
  color: #34d399;
}

.stat-divider {
  color: var(--color-border-subtle);
}

.comic-header-actions {
  flex-shrink: 0;
}

.btn-add-chapter {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
}

.btn-icon {
  font-size: 18px;
  line-height: 1;
}

/* Header Skeleton */
.comic-header-skeleton {
  display: flex;
  gap: var(--spacing-xl);
  align-items: center;
}

.skeleton-cover {
  width: 96px;
  height: 128px;
  border-radius: var(--radius-md);
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

.skeleton-details {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.skeleton-line {
  height: 16px;
  border-radius: var(--radius-xs);
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

.skeleton-line.title {
  width: 45%;
  height: 26px;
}

.skeleton-line.author {
  width: 25%;
}

.skeleton-line.badges {
  width: 35%;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* Toolbar & Filters */
.chapters-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
  padding: var(--spacing-sm) 0;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.filter-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
}

.filter-select {
  padding: 8px 14px;
  border-radius: var(--radius-md);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  outline: none;
  cursor: pointer;
  transition: border-color var(--transition-fast);
}

.filter-select:focus {
  border-color: var(--color-primary);
}

.toolbar-stats {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

.toolbar-actions {
  display: flex;
  align-items: center;
}

.btn-refresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  background-color: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-refresh:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-refresh:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.refresh-icon {
  font-size: 14px;
  display: inline-block;
}

.refresh-icon.spinning {
  animation: spin 0.8s linear infinite;
}

/* Table Section */
.chapters-table-section {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.chapters-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: var(--font-size-sm);
}

.chapters-table thead tr {
  background-color: rgba(255, 255, 255, 0.02);
  border-bottom: 1px solid var(--color-border);
}

.chapters-table th {
  padding: var(--spacing-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.chapters-table td {
  padding: var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
  color: var(--color-text-secondary);
}

.chapter-row {
  transition: background-color var(--transition-fast);
}

.chapter-row:hover {
  background-color: rgba(56, 189, 248, 0.03);
}

.chapter-row:last-child td {
  border-bottom: none;
}

/* Specific Columns */
.chapter-num-badge {
  display: inline-block;
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  white-space: nowrap;
}

.chapter-title-text {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
  word-break: break-word;
  max-width: 280px;
  display: inline-block;
}

.chapter-title-empty {
  color: var(--color-text-muted);
  font-style: italic;
}

.images-counter {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--color-text-secondary);
  white-space: nowrap;
}

.images-counter.zero-images {
  color: #f87171;
}

.views-text {
  font-family: var(--font-family-mono);
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.date-text {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  white-space: nowrap;
}

/* Actions in Table */
.action-buttons-group {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 10px;
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  border: 1px solid transparent;
  cursor: pointer;
  text-decoration: none;
  white-space: nowrap;
  transition: all var(--transition-fast);
}

.action-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.btn-images {
  background-color: var(--color-bg-elevated);
  border-color: var(--color-border);
  color: var(--color-text-primary);
}

.btn-images:hover:not(:disabled) {
  background-color: var(--color-bg-card-hover);
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.btn-edit {
  background-color: transparent;
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-edit:hover:not(:disabled) {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
}

.btn-submit {
  background-color: var(--color-primary-soft);
  border-color: rgba(56, 189, 248, 0.3);
  color: var(--color-primary);
}

.btn-submit:hover:not(:disabled) {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.btn-cancel-review {
  background-color: var(--color-warning-soft);
  border-color: rgba(245, 158, 11, 0.3);
  color: #fbbf24;
}

.btn-cancel-review:hover:not(:disabled) {
  background-color: var(--color-warning);
  color: var(--color-text-inverse);
}

.btn-recall {
  background-color: var(--color-danger-soft);
  border-color: rgba(239, 68, 68, 0.3);
  color: #f87171;
}

.btn-recall:hover:not(:disabled) {
  background-color: var(--color-danger);
  color: #fff;
}

.btn-read-public {
  background-color: rgba(6, 182, 212, 0.12);
  border-color: rgba(6, 182, 212, 0.3);
  color: #22d3ee;
}

.btn-read-public:hover {
  background-color: #06b6d4;
  color: #fff;
}

.btn-rejection {
  background-color: var(--color-danger-soft);
  border-color: rgba(239, 68, 68, 0.35);
  color: #f87171;
  font-weight: var(--font-weight-semibold);
}

.btn-rejection:hover {
  background-color: rgba(239, 68, 68, 0.25);
  color: #fca5a5;
}

/* Skeleton Loading for Table */
.chapters-loading-container {
  display: flex;
  flex-direction: column;
}

.chapter-row-skeleton {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
}

.skeleton-cell {
  height: 16px;
  border-radius: var(--radius-xs);
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

.skeleton-cell.num {
  width: 12%;
}

.skeleton-cell.title {
  width: 35%;
}

.skeleton-cell.count {
  width: 15%;
}

.skeleton-cell.badge {
  width: 15%;
}

.skeleton-cell.actions {
  width: 23%;
}

/* Empty State */
.chapters-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-xl);
  gap: var(--spacing-sm);
}

.empty-icon {
  font-size: 44px;
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  max-width: 440px;
  margin: 0;
}

.empty-actions {
  margin-top: var(--spacing-md);
}

/* Error Box in table */
.chapters-error-box {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-xl);
  background-color: var(--color-danger-soft);
  color: #f87171;
}

.chapters-error-box .error-icon {
  font-size: 24px;
}

.chapters-error-box .error-content {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.chapters-error-box .error-text {
  margin: 0;
  font-size: var(--font-size-sm);
}

/* Pagination Bar */
.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: rgba(255, 255, 255, 0.01);
  border-top: 1px solid var(--color-border);
  font-size: var(--font-size-sm);
}

.pagination-info {
  color: var(--color-text-muted);
}

.current-pg {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-semibold);
}

.pagination-buttons {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.pg-btn {
  padding: 6px 14px;
  border-radius: var(--radius-sm);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: var(--font-size-xs);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.pg-btn:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.pg-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

/* Generic Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border: none;
  cursor: pointer;
  transition: all var(--transition-fast);
  text-decoration: none;
}

.btn-primary {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.btn-primary:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-outline {
  background-color: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
}

.btn-outline:hover:not(:disabled) {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
}

.btn-sm {
  padding: 4px 10px;
  font-size: var(--font-size-xs);
}

/* ======================================================= */
/* MODAL STYLES                                            */
/* ======================================================= */

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background-color: rgba(5, 9, 18, 0.82);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
}

.chapter-form-card {
  width: 100%;
  max-width: 520px;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.6), 0 8px 10px -6px rgba(0, 0, 0, 0.6);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.modal-header {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-md) var(--spacing-lg);
  border-bottom: 1px solid var(--color-border);
}

.modal-header-icon {
  width: 36px;
  height: 36px;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  background-color: var(--color-primary-soft);
  flex-shrink: 0;
}

.modal-header-text {
  flex: 1;
}

.modal-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.modal-subtitle {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 2px 0 0 0;
}

.modal-close-btn {
  background: none;
  border: none;
  color: var(--color-text-muted);
  font-size: 14px;
  cursor: pointer;
  padding: 4px;
  border-radius: var(--radius-sm);
}

.modal-close-btn:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.modal-body {
  padding: var(--spacing-lg);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.modal-alert-danger {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) var(--spacing-md);
  border-radius: var(--radius-md);
  background-color: var(--color-danger-soft);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #f87171;
  font-size: var(--font-size-xs);
  line-height: var(--line-height-normal);
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.field-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.required-mark {
  color: var(--color-danger);
}

.field-hint,
.char-counter {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.char-counter.limit-reached {
  color: var(--color-danger);
}

.field-input {
  width: 100%;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  background-color: var(--color-bg);
  border: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  outline: none;
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.field-input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary-glow);
}

.field-input:disabled {
  background-color: var(--color-bg-elevated);
  opacity: 0.6;
  cursor: not-allowed;
}

.has-error .field-input {
  border-color: var(--color-danger);
}

.has-error .field-input:focus {
  box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
}

.field-subtext {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0;
  line-height: var(--line-height-normal);
}

.field-error-msg {
  font-size: var(--font-size-xs);
  color: #f87171;
  margin: 0;
  font-weight: var(--font-weight-medium);
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--spacing-sm);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: rgba(11, 15, 25, 0.5);
  border-top: 1px solid var(--color-border);
}

.btn-cancel {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  background-color: transparent;
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  cursor: pointer;
}

.btn-cancel:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  display: inline-block;
  margin-right: 6px;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

/* ======================================================= */
/* RESPONSIVE BREAKPOINTS                                  */
/* ======================================================= */

@media (max-width: 768px) {
  .creator-chapters-page {
    padding: var(--spacing-md);
    gap: var(--spacing-md);
  }

  .comic-header-inner {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--spacing-md);
  }

  .comic-cover-wrap {
    width: 80px;
    height: 106px;
  }

  .comic-header-actions {
    width: 100%;
  }

  .btn-add-chapter {
    width: 100%;
  }

  .chapters-toolbar {
    flex-direction: column;
    align-items: stretch;
    gap: var(--spacing-sm);
  }

  .filter-group {
    justify-content: space-between;
  }

  .filter-select {
    flex: 1;
  }

  .toolbar-actions {
    justify-content: flex-end;
  }

  .pagination-bar {
    flex-direction: column;
    gap: var(--spacing-sm);
    align-items: stretch;
    text-align: center;
  }

  .pagination-buttons {
    justify-content: center;
  }
}
</style>
