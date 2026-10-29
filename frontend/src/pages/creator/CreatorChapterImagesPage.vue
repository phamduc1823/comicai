<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import CreatorLayout from '@/layouts/CreatorLayout.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import { CreatorService } from '@/services/creatorService'
import {
  getErrorMessage,
  isForbiddenError,
  isNotFoundError,
} from '@/services/httpClient'
import type { CreatorChapter } from '@/types/creator'
import type { ChapterImage } from '@/types/catalog'

const route = useRoute()

// Chapter ID from route param :id
const chapterId = computed(() => {
  const param = route.params.id
  return Array.isArray(param) ? param[0] : param
})

// Runtime environment limits (PHP ini defaults: upload_max_filesize=2M, post_max_size=8M)
const RUNTIME_MAX_FILE_SIZE = 2 * 1024 * 1024 // 2MB
const BATCH_UPLOAD_SIZE = 3 // 3 files per request batch

// Component state
const chapter = ref<CreatorChapter | null>(null)
const savedImages = ref<ChapterImage[]>([])
const workingImages = ref<ChapterImage[]>([])
const failedImageLoads = ref<Record<number, boolean>>({})

// Loading & error states
const isLoading = ref(true)
const pageError = ref<string | null>(null)
const isForbidden = ref(false)
const isNotFound = ref(false)

// Request race condition counter & component lifecycle guard
let latestRequestId = 0
let isComponentMounted = true

// Toasts & feedback
const successFeedback = ref<string | null>(null)
const actionError = ref<string | null>(null)
let feedbackTimer: ReturnType<typeof setTimeout> | null = null

function showSuccessToast(msg: string) {
  if (!isComponentMounted) return
  if (feedbackTimer) clearTimeout(feedbackTimer)
  successFeedback.value = msg
  actionError.value = null
  feedbackTimer = setTimeout(() => {
    if (isComponentMounted) successFeedback.value = null
  }, 4500)
}

function showErrorToast(msg: string) {
  if (!isComponentMounted) return
  if (feedbackTimer) clearTimeout(feedbackTimer)
  actionError.value = msg
  feedbackTimer = setTimeout(() => {
    if (isComponentMounted) actionError.value = null
  }, 6500)
}

function clearToasts() {
  if (feedbackTimer) clearTimeout(feedbackTimer)
  successFeedback.value = null
  actionError.value = null
}

// -------------------------------------------------------------
// Format Helpers
// -------------------------------------------------------------

function formatChapterDisplay(rawNum: string | number | undefined): string {
  if (!rawNum) return 'Chương'
  const n = parseFloat(String(rawNum))
  if (isNaN(n)) return `Chương ${rawNum}`
  const formatted = Number.isInteger(n) ? n.toString() : parseFloat(n.toFixed(2)).toString()
  return `Chương ${formatted}`
}


// Check if chapter is editable (only draft or rejected)
const isEditable = computed(() => {
  return chapter.value?.status === 'draft' || chapter.value?.status === 'rejected'
})

// Check if the current working order differs from the saved order
const hasUnsavedOrder = computed(() => {
  if (workingImages.value.length !== savedImages.value.length) return false
  return workingImages.value.some((img, idx) => img.id !== savedImages.value[idx]?.id)
})

// -------------------------------------------------------------
// Data Fetching
// -------------------------------------------------------------

async function loadChapterDetails() {
  const currentId = chapterId.value
  if (!currentId) {
    chapter.value = null
    savedImages.value = []
    workingImages.value = []
    pageError.value = 'Mã chương truyện không hợp lệ.'
    isLoading.value = false
    return
  }

  const reqId = ++latestRequestId
  isLoading.value = true
  pageError.value = null
  isForbidden.value = false
  isNotFound.value = false
  failedImageLoads.value = {}

  // F-01 FIX: Reset chapter and images state immediately so previous chapter
  // information and images do not persist while loading the new chapter
  chapter.value = null
  savedImages.value = []
  workingImages.value = []

  try {
    const res = await CreatorService.getChapter(currentId)
    if (reqId !== latestRequestId) return

    chapter.value = res.data
    const imagesList = res.data.images || []
    savedImages.value = [...imagesList]
    workingImages.value = [...imagesList]
  } catch (err: unknown) {
    if (reqId !== latestRequestId) return
    chapter.value = null
    savedImages.value = []
    workingImages.value = []

    if (isForbiddenError(err)) {
      isForbidden.value = true
      pageError.value = 'Bạn không có quyền truy cập hoặc quản lý ảnh của chương truyện này.'
    } else if (isNotFoundError(err)) {
      isNotFound.value = true
      pageError.value = 'Chương truyện không tồn tại hoặc đã bị xóa.'
    } else {
      pageError.value = getErrorMessage(
        err,
        'Không thể tải thông tin chương và danh sách ảnh. Vui lòng thử lại.'
      )
    }
  } finally {
    if (reqId === latestRequestId) {
      isLoading.value = false
    }
  }
}

function handleImageError(id: number) {
  failedImageLoads.value[id] = true
}

// -------------------------------------------------------------
// Upload Images (Batching & Runtime Limit Verification)
// -------------------------------------------------------------

const fileInputRef = ref<HTMLInputElement | null>(null)
const isDraggingOverDropzone = ref(false)
const isUploading = ref(false)
const uploadProgressText = ref('')
const uploadErrors = ref<string[]>([])

function triggerFileInput() {
  if (!isEditable.value || isUploading.value || isReordering.value) return
  fileInputRef.value?.click()
}

function handleDropzoneDragOver(e: DragEvent) {
  e.preventDefault()
  if (!isEditable.value || isUploading.value) return
  isDraggingOverDropzone.value = true
}

function handleDropzoneDragLeave() {
  isDraggingOverDropzone.value = false
}

function handleDropzoneDrop(e: DragEvent) {
  e.preventDefault()
  isDraggingOverDropzone.value = false
  if (!isEditable.value || isUploading.value || isReordering.value) return
  const files = e.dataTransfer?.files
  if (files && files.length > 0) {
    processSelectedFiles(Array.from(files))
  }
}

function handleFileInputChange(e: Event) {
  const target = e.target as HTMLInputElement
  const files = target.files
  if (files && files.length > 0) {
    processSelectedFiles(Array.from(files))
  }
  // Reset input value so same files can be selected again if needed
  target.value = ''
}

/**
 * Validate files against supported types and the PHP runtime limit (2MB)
 */
function processSelectedFiles(files: File[]) {
  if (!isEditable.value || isUploading.value) return
  uploadErrors.value = []

  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
  const allowedExtensions = ['.jpg', '.jpeg', '.png', '.webp']

  const validFiles: File[] = []

  for (const file of files) {
    // F-04 FIX: Reject empty files (0 bytes) before uploading
    if (file.size === 0) {
      uploadErrors.value.push(
        `Tệp "${file.name}" rỗng (0 bytes) và không thể tải lên.`
      )
      continue
    }

    const ext = '.' + file.name.split('.').pop()?.toLowerCase()
    const isMimeValid = allowedTypes.includes(file.type)
    const isExtValid = allowedExtensions.includes(ext)

    if (!isMimeValid && !isExtValid) {
      uploadErrors.value.push(
        `Tệp "${file.name}" không hợp lệ. Chỉ chấp nhận định dạng JPG, PNG hoặc WebP.`
      )
      continue
    }

    // Check PHP runtime upload_max_filesize limit (2MB)
    if (file.size > RUNTIME_MAX_FILE_SIZE) {
      const sizeMB = (file.size / (1024 * 1024)).toFixed(2)
      uploadErrors.value.push(
        `Tệp "${file.name}" (${sizeMB} MB) vượt quá giới hạn 2MB của máy chủ (upload_max_filesize). Vui lòng nén ảnh hoặc chọn tệp nhỏ hơn 2MB.`
      )
      continue
    }

    validFiles.push(file)
  }

  if (validFiles.length > 0) {
    executeBatchUpload(validFiles)
  }
}

/**
 * Execute sequential batch upload to prevent hitting post_max_size (8MB)
 * and preserve partial successes if a later batch fails.
 */
async function executeBatchUpload(files: File[]) {
  if (isUploading.value || !chapter.value || !isComponentMounted) return
  isUploading.value = true
  clearToasts()

  // Chunk valid files into small batches
  const batches: File[][] = []
  for (let i = 0; i < files.length; i += BATCH_UPLOAD_SIZE) {
    batches.push(files.slice(i, i + BATCH_UPLOAD_SIZE))
  }

  let totalUploaded = 0
  const totalFiles = files.length
  let hasBatchError = false

  for (let bIndex = 0; bIndex < batches.length; bIndex++) {
    // F-02 FIX: Guard against component unmounting before starting next batch
    if (!isComponentMounted) break

    const batch = batches[bIndex]
    const batchNum = bIndex + 1
    uploadProgressText.value = `Đang tải lên lô ${batchNum}/${batches.length} (${totalUploaded}/${totalFiles} ảnh)...`

    try {
      const res = await CreatorService.uploadChapterImages(chapter.value.id, batch)

      // F-02 FIX: Guard against component unmounting while batch request was in flight
      if (!isComponentMounted) break

      const newlyCreated = res.data || []

      // Append newly created images to local state immediately
      savedImages.value.push(...newlyCreated)
      workingImages.value.push(...newlyCreated)
      totalUploaded += newlyCreated.length
    } catch (err: unknown) {
      // F-02 FIX: If component was unmounted during request, stop silently
      if (!isComponentMounted) break

      hasBatchError = true
      const errorMsg = getErrorMessage(err, `Lỗi khi tải lên lô ${batchNum}.`)
      uploadErrors.value.push(errorMsg)
      showErrorToast(
        `Tải ảnh bị gián đoạn ở lô ${batchNum}/${batches.length}. Đã tải thành công ${totalUploaded}/${totalFiles} ảnh.`
      )
      break
    }
  }

  // F-02 FIX: Do not update reactive state or toasts if component unmounted
  if (!isComponentMounted) return

  isUploading.value = false
  uploadProgressText.value = ''

  if (totalUploaded > 0 && !hasBatchError) {
    showSuccessToast(`Đã tải lên thành công toàn bộ ${totalUploaded} ảnh trang Webtoon.`)
  } else if (totalUploaded > 0 && hasBatchError) {
    showSuccessToast(`Đã lưu thành công ${totalUploaded} ảnh trước khi gặp lỗi.`)
  }
}

// -------------------------------------------------------------
// Reorder Images (HTML5 Drag-and-Drop & Move Up/Down Controls)
// -------------------------------------------------------------

const isReordering = ref(false)
const draggedIndex = ref<number | null>(null)
const dragOverIndex = ref<number | null>(null)

function handleDragStart(index: number, e: DragEvent) {
  if (!isEditable.value || isUploading.value || isDeleting.value || isReordering.value) {
    e.preventDefault()
    return
  }
  draggedIndex.value = index
  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', String(index))
  }
}

function handleDragOverCard(index: number, e: DragEvent) {
  if (!isEditable.value || draggedIndex.value === null) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dragOverIndex.value = index
}

function handleDragLeaveCard(index: number) {
  if (dragOverIndex.value === index) {
    dragOverIndex.value = null
  }
}

function handleDropOnCard(targetIndex: number, e: DragEvent) {
  e.preventDefault()
  if (draggedIndex.value === null || draggedIndex.value === targetIndex) {
    draggedIndex.value = null
    dragOverIndex.value = null
    return
  }

  const list = [...workingImages.value]
  const [removed] = list.splice(draggedIndex.value, 1)
  list.splice(targetIndex, 0, removed)

  workingImages.value = list
  draggedIndex.value = null
  dragOverIndex.value = null
}

function handleDragEnd() {
  draggedIndex.value = null
  dragOverIndex.value = null
}

function moveImageUp(index: number) {
  if (index <= 0 || !isEditable.value || isUploading.value || isReordering.value) return
  const list = [...workingImages.value]
  const temp = list[index - 1]
  list[index - 1] = list[index]
  list[index] = temp
  workingImages.value = list
}

function moveImageDown(index: number) {
  if (
    index >= workingImages.value.length - 1 ||
    !isEditable.value ||
    isUploading.value ||
    isReordering.value
  )
    return
  const list = [...workingImages.value]
  const temp = list[index + 1]
  list[index + 1] = list[index]
  list[index] = temp
  workingImages.value = list
}

function cancelReorder() {
  workingImages.value = [...savedImages.value]
}

async function saveReorderedImages() {
  if (!chapter.value || !hasUnsavedOrder.value || isReordering.value) return
  isReordering.value = true
  clearToasts()

  const payloadIds = workingImages.value.map((img) => img.id)

  try {
    const res = await CreatorService.reorderChapterImages(chapter.value.id, {
      image_ids: payloadIds,
    })

    const updatedList = res.data || []
    savedImages.value = [...updatedList]
    workingImages.value = [...updatedList]
    showSuccessToast('Đã lưu sắp xếp thứ tự các trang thành công.')
  } catch (err: unknown) {
    showErrorToast(
      getErrorMessage(
        err,
        'Không thể lưu thứ tự ảnh. Vui lòng kiểm tra kết nối mạng và thử lại.'
      )
    )
  } finally {
    isReordering.value = false
  }
}

// -------------------------------------------------------------
// Delete Single Image
// -------------------------------------------------------------

const isDeleting = ref(false)
const deleteModalOpen = ref(false)
const imageToDelete = ref<ChapterImage | null>(null)

function promptDeleteImage(image: ChapterImage) {
  if (!isEditable.value || isUploading.value || isReordering.value) return
  imageToDelete.value = image
  deleteModalOpen.value = true
}

async function handleConfirmDelete() {
  if (!chapter.value || !imageToDelete.value || isDeleting.value) return
  isDeleting.value = true
  const img = imageToDelete.value

  try {
    await CreatorService.deleteChapterImage(chapter.value.id, img.id)
    deleteModalOpen.value = false
    imageToDelete.value = null
    showSuccessToast(`Đã xóa ảnh trang ${img.page_number} thành công.`)

    // Reload chapter details to synchronize backend auto-reindexed page numbers
    await loadChapterDetails()
  } catch (err: unknown) {
    deleteModalOpen.value = false
    showErrorToast(getErrorMessage(err, `Không thể xóa ảnh trang ${img.page_number}.`))
  } finally {
    isDeleting.value = false
  }
}

// -------------------------------------------------------------
// Image Full Preview Modal
// -------------------------------------------------------------

const previewModalOpen = ref(false)
const previewImageIndex = ref(0)
let prevBodyOverflow = ''

const currentPreviewImage = computed(() => {
  return workingImages.value[previewImageIndex.value] || null
})

function openPreviewModal(index: number) {
  previewImageIndex.value = index
  previewModalOpen.value = true
}

function closePreviewModal() {
  previewModalOpen.value = false
}

function nextPreview() {
  if (previewImageIndex.value < workingImages.value.length - 1) {
    previewImageIndex.value++
  }
}

function prevPreview() {
  if (previewImageIndex.value > 0) {
    previewImageIndex.value--
  }
}

function handlePreviewKeyDown(e: KeyboardEvent) {
  if (!previewModalOpen.value) return
  if (e.key === 'Escape') closePreviewModal()
  if (e.key === 'ArrowRight') nextPreview()
  if (e.key === 'ArrowLeft') prevPreview()
}

watch(
  () => previewModalOpen.value,
  (open) => {
    if (open) {
      window.addEventListener('keydown', handlePreviewKeyDown)
      prevBodyOverflow = document.body.style.overflow
      document.body.style.overflow = 'hidden'
    } else {
      window.removeEventListener('keydown', handlePreviewKeyDown)
      document.body.style.overflow = prevBodyOverflow
    }
  }
)

// -------------------------------------------------------------
// Lifecycle
// -------------------------------------------------------------

onMounted(async () => {
  isComponentMounted = true
  await loadChapterDetails()
})

onUnmounted(() => {
  isComponentMounted = false
  if (feedbackTimer) {
    clearTimeout(feedbackTimer)
    feedbackTimer = null
  }
  window.removeEventListener('keydown', handlePreviewKeyDown)
  document.body.style.overflow = prevBodyOverflow
})

// Re-fetch if chapter id route param changes
watch(
  () => route.params.id,
  async (newId, oldId) => {
    if (newId && newId !== oldId) {
      clearToasts()
      await loadChapterDetails()
    }
  }
)
</script>

<template>
  <CreatorLayout>
    <div class="creator-chapter-images-page">
      <!-- Breadcrumb Navigation -->
      <nav class="breadcrumb-nav" aria-label="Đường dẫn điều hướng">
        <RouterLink to="/creator/comics" class="breadcrumb-link">
          Danh sách truyện
        </RouterLink>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <RouterLink
          v-if="chapter?.comic_id"
          :to="`/creator/comics/${chapter.comic_id}/chapters`"
          class="breadcrumb-link"
        >
          {{ chapter.comic?.title || 'Quản lý chương' }}
        </RouterLink>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="breadcrumb-current">
          Ảnh trang {{ formatChapterDisplay(chapter?.chapter_number) }}
        </span>
      </nav>

      <!-- Global Feedback Alerts -->
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

      <!-- Access Denied (403) or Not Found (404) Panel -->
      <section v-if="isForbidden || isNotFound" class="error-panel" aria-live="assertive">
        <div class="error-panel-icon" aria-hidden="true">
          {{ isForbidden ? '🔒' : '🔍' }}
        </div>
        <h2 class="error-panel-title">
          {{ isForbidden ? 'Truy cập bị từ chối' : 'Không tìm thấy chương truyện' }}
        </h2>
        <p class="error-panel-desc">{{ pageError }}</p>
        <div class="error-panel-actions">
          <RouterLink to="/creator/comics" class="btn btn-primary">
            Quay lại danh sách truyện
          </RouterLink>
        </div>
      </section>

      <!-- General Load Error Panel -->
      <section v-else-if="pageError && !chapter" class="error-panel" aria-live="assertive">
        <div class="error-panel-icon" aria-hidden="true">⚠️</div>
        <h2 class="error-panel-title">Đã xảy ra lỗi tải dữ liệu</h2>
        <p class="error-panel-desc">{{ pageError }}</p>
        <div class="error-panel-actions">
          <button type="button" class="btn btn-primary" @click="loadChapterDetails">
            Thử lại
          </button>
          <RouterLink to="/creator/comics" class="btn btn-outline">
            Về danh sách truyện
          </RouterLink>
        </div>
      </section>

      <!-- Main Content -->
      <template v-else-if="chapter || isLoading">
        <!-- Chapter Header Card -->
        <header class="chapter-header-card">
          <!-- Loading skeleton -->
          <div v-if="isLoading && !chapter" class="header-skeleton">
            <div class="skeleton-line title"></div>
            <div class="skeleton-line subtitle"></div>
          </div>

          <div v-else-if="chapter" class="chapter-header-inner">
            <div class="header-left">
              <RouterLink
                :to="`/creator/comics/${chapter.comic_id}/chapters`"
                class="btn-back-link"
                title="Quay lại danh sách chương"
              >
                <span aria-hidden="true">←</span> Danh sách chương
              </RouterLink>

              <div class="header-title-block">
                <h1 class="chapter-title">
                  {{ formatChapterDisplay(chapter.chapter_number) }}
                  <span v-if="chapter.title" class="title-sub">: {{ chapter.title }}</span>
                </h1>
                <p v-if="chapter.comic" class="comic-name">
                  Tác phẩm: <span class="highlight">{{ chapter.comic.title }}</span>
                </p>
              </div>

              <div class="header-badges">
                <StatusBadge :status="chapter.status" />
                <span class="count-badge">
                  📷 {{ workingImages.length }} ảnh trang
                </span>
              </div>
            </div>

            <!-- Read-Only Banner / Notice -->
            <div v-if="!isEditable" class="readonly-notice" role="status">
              <span class="notice-icon" aria-hidden="true">ℹ️</span>
              <div class="notice-text">
                <strong v-if="chapter.status === 'pending'">Chế độ chỉ đọc (Chờ duyệt):</strong>
                <strong v-else-if="chapter.status === 'published'">Chế độ chỉ đọc (Đã xuất bản):</strong>
                <p v-if="chapter.status === 'pending'">
                  Chương đang được Quản trị viên duyệt. Không thể thêm, xóa hoặc đổi thứ tự ảnh.
                </p>
                <p v-else-if="chapter.status === 'published'">
                  Chương đã xuất bản cho độc giả. Hãy thu hồi chương về Bản nháp tại trang quản lý chương nếu muốn cập nhật ảnh.
                </p>
              </div>
            </div>
          </div>
        </header>

        <!-- Unsaved Reorder Floating Toolbar -->
        <Transition name="slide-down">
          <section
            v-if="hasUnsavedOrder && isEditable"
            class="unsaved-order-bar"
            aria-label="Thao tác lưu thứ tự ảnh"
          >
            <div class="unsaved-info">
              <span class="unsaved-icon" aria-hidden="true">⚡</span>
              <span class="unsaved-text">
                Thứ tự các trang đã được thay đổi nhưng <strong>chưa được lưu</strong> vào máy chủ.
              </span>
            </div>
            <div class="unsaved-actions">
              <button
                type="button"
                class="btn btn-outline btn-sm"
                :disabled="isReordering || isUploading || isDeleting"
                @click="cancelReorder"
              >
                Hủy thay đổi
              </button>
              <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="isReordering || isUploading || isDeleting"
                @click="saveReorderedImages"
              >
                <span v-if="isReordering" class="btn-spinner" aria-hidden="true"></span>
                <span>{{ isReordering ? 'Đang lưu thứ tự...' : 'Lưu thứ tự mới' }}</span>
              </button>
            </div>
          </section>
        </Transition>

        <!-- Uploader Dropzone Section (Hidden in read-only mode) -->
        <section
          v-if="isEditable"
          class="uploader-section"
          aria-label="Khu vực tải lên ảnh trang Webtoon"
        >
          <div
            class="dropzone-box"
            :class="{
              'drag-over': isDraggingOverDropzone,
              'uploading': isUploading,
            }"
            @dragover="handleDropzoneDragOver"
            @dragleave="handleDropzoneDragLeave"
            @drop="handleDropzoneDrop"
            @click="triggerFileInput"
          >
            <input
              ref="fileInputRef"
              type="file"
              multiple
              accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
              class="hidden-file-input"
              @change="handleFileInputChange"
            />

            <div class="dropzone-content">
              <div class="dropzone-icon" aria-hidden="true">
                <span v-if="isUploading">⏳</span>
                <span v-else>📁</span>
              </div>

              <div class="dropzone-labels">
                <h3 class="dropzone-main-label">
                  {{
                    isUploading
                      ? uploadProgressText || 'Đang tải lên các ảnh...'
                      : 'Kéo thả các ảnh trang Webtoon vào đây hoặc nhấn để chọn tệp'
                  }}
                </h3>
                <p class="dropzone-sub-label">
                  Hỗ trợ JPG, PNG, WebP • Tối đa 2MB mỗi ảnh (theo giới hạn runtime PHP) • Tải theo lô an toàn
                </p>
              </div>

              <button
                type="button"
                class="btn btn-primary btn-select-files"
                :disabled="isUploading"
                @click.stop="triggerFileInput"
              >
                Chọn ảnh từ máy
              </button>
            </div>
          </div>

          <!-- Upload Errors Summary -->
          <div v-if="uploadErrors.length > 0" class="upload-errors-list" role="alert">
            <div class="error-header">
              <span aria-hidden="true">⚠️</span> Một số tệp không thể tải lên:
            </div>
            <ul>
              <li v-for="(errMsg, eIdx) in uploadErrors" :key="eIdx">{{ errMsg }}</li>
            </ul>
          </div>
        </section>

        <!-- Gallery / Working Images Section -->
        <section class="gallery-section" aria-label="Danh sách ảnh trang truyện">
          <!-- Section Header -->
          <div class="gallery-header">
            <h2 class="gallery-title">
              Trang truyện Webtoon ({{ workingImages.length }})
            </h2>
            <p v-if="isEditable && workingImages.length > 1" class="gallery-hint">
              Kéo thả thẻ ảnh hoặc dùng nút mũi tên (← / →) để sắp xếp thứ tự đọc.
            </p>
          </div>

          <!-- Loading State in Gallery (F-01: prevents empty state flash during chapter load) -->
          <div v-if="isLoading && !chapter" class="gallery-loading-state" aria-live="polite">
            <div class="gallery-loading-spinner" aria-hidden="true"></div>
            <p class="gallery-loading-text">Đang tải thông tin chương và danh sách ảnh...</p>
          </div>

          <!-- Empty State -->
          <div v-else-if="workingImages.length === 0 && !isLoading" class="gallery-empty-state">
            <div class="empty-icon" aria-hidden="true">🖼️</div>
            <h3 class="empty-title">Chương này chưa có ảnh nào</h3>
            <p class="empty-desc">
              {{
                isEditable
                  ? 'Hãy tải lên các trang ảnh truyện Webtoon để chuẩn bị nội dung kiểm duyệt và phát hành.'
                  : 'Chưa có ảnh nào được tải lên cho chương này.'
              }}
            </p>
            <button
              v-if="isEditable"
              type="button"
              class="btn btn-primary"
              :disabled="isUploading"
              @click="triggerFileInput"
            >
              Tải ảnh lên ngay
            </button>
          </div>

          <!-- Grid of Webtoon Page Cards -->
          <div v-else class="images-grid">
            <div
              v-for="(img, idx) in workingImages"
              :key="img.id"
              class="image-card"
              :class="{
                'dragged-item': draggedIndex === idx,
                'drag-over-item': dragOverIndex === idx,
                'is-reordered': img.id !== savedImages[idx]?.id,
              }"
              :draggable="isEditable && !isUploading && !isReordering && !isDeleting"
              @dragstart="handleDragStart(idx, $event)"
              @dragover="handleDragOverCard(idx, $event)"
              @dragleave="handleDragLeaveCard(idx)"
              @drop="handleDropOnCard(idx, $event)"
              @dragend="handleDragEnd"
            >
              <!-- Page Badge -->
              <div class="page-badge-wrap">
                <span class="page-badge">
                  Trang {{ idx + 1 }}
                </span>
                <span
                  v-if="img.id !== savedImages[idx]?.id"
                  class="badge-moved"
                  title="Thứ tự đã thay đổi, chưa lưu"
                >
                  (Gốc: {{ img.page_number }})
                </span>
              </div>

              <!-- Thumbnail Container -->
              <div
                class="thumbnail-wrap"
                role="button"
                tabindex="0"
                :title="`Nhấn để xem trước trang ${idx + 1}`"
                @click="openPreviewModal(idx)"
                @keydown.enter="openPreviewModal(idx)"
              >
                <img
                  v-if="img.image_url && !failedImageLoads[img.id]"
                  :src="img.image_url"
                  :alt="`Trang ${idx + 1}`"
                  class="page-image"
                  loading="lazy"
                  @error="handleImageError(img.id)"
                />
                <div v-else class="broken-image-placeholder">
                  <span class="broken-icon" aria-hidden="true">⚠️</span>
                  <span class="broken-text">Không tải được ảnh</span>
                </div>

                <!-- Hover overlay icon -->
                <div class="thumbnail-hover-overlay" aria-hidden="true">
                  <span class="zoom-icon">🔍 Phóng to</span>
                </div>
              </div>

              <!-- Card Details & Actions Footer -->
              <div class="card-footer">
                <div class="image-meta">
                  <span v-if="img.width && img.height" class="meta-dimensions">
                    {{ img.width }}×{{ img.height }}px
                  </span>
                  <span v-else class="meta-dimensions">—</span>
                </div>

                <!-- Action buttons (only in edit mode) -->
                <div v-if="isEditable" class="card-actions">
                  <!-- Move Previous / Up -->
                  <button
                    type="button"
                    class="btn-card-action"
                    :disabled="idx === 0 || isUploading || isReordering || isDeleting"
                    title="Chuyển lên trước"
                    @click="moveImageUp(idx)"
                  >
                    ‹
                  </button>

                  <!-- Move Next / Down -->
                  <button
                    type="button"
                    class="btn-card-action"
                    :disabled="idx === workingImages.length - 1 || isUploading || isReordering || isDeleting"
                    title="Chuyển về sau"
                    @click="moveImageDown(idx)"
                  >
                    ›
                  </button>

                  <!-- Delete Single Image -->
                  <button
                    type="button"
                    class="btn-card-action btn-card-delete"
                    :disabled="isUploading || isReordering || isDeleting"
                    title="Xóa trang này"
                    @click="promptDeleteImage(img)"
                  >
                    🗑️
                  </button>
                </div>
              </div>
            </div>
          </div>
        </section>
      </template>

      <!-- ======================================================= -->
      <!-- MODAL: Xác nhận xóa ảnh                                 -->
      <!-- ======================================================= -->
      <ConfirmModal
        :is-open="deleteModalOpen"
        :title="`Xóa ảnh trang ${imageToDelete?.page_number || ''}`"
        :message="`Bạn có chắc chắn muốn xóa ảnh trang ${
          imageToDelete?.page_number || ''
        }? Thao tác này sẽ xóa tệp vĩnh viễn khỏi máy chủ và tự động đánh số lại các trang còn lại.`"
        type="danger"
        confirm-text="Xóa ảnh"
        :is-loading="isDeleting"
        @confirm="handleConfirmDelete"
        @close="!isDeleting && (deleteModalOpen = false)"
      />

      <!-- ======================================================= -->
      <!-- MODAL: Xem trước ảnh phóng to (Preview Modal)           -->
      <!-- ======================================================= -->
      <Teleport to="body">
        <Transition name="modal-fade">
          <div
            v-if="previewModalOpen && currentPreviewImage"
            class="preview-modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-label="Xem trước ảnh trang truyện"
            @click.self="closePreviewModal"
          >
            <div class="preview-modal-content">
              <!-- Top Preview Bar -->
              <div class="preview-top-bar">
                <span class="preview-title">
                  Trang {{ previewImageIndex + 1 }} / {{ workingImages.length }}
                  <span v-if="currentPreviewImage.width && currentPreviewImage.height" class="preview-res">
                    ({{ currentPreviewImage.width }} × {{ currentPreviewImage.height }}px)
                  </span>
                </span>
                <button
                  type="button"
                  class="preview-close-btn"
                  aria-label="Đóng xem trước"
                  @click="closePreviewModal"
                >
                  ✕
                </button>
              </div>

              <!-- Main Preview Image Container -->
              <div class="preview-image-container">
                <img
                  v-if="currentPreviewImage.image_url"
                  :src="currentPreviewImage.image_url"
                  :alt="`Trang ${previewImageIndex + 1}`"
                  class="preview-full-img"
                />

                <!-- Prev/Next Navigation Controls -->
                <button
                  v-if="previewImageIndex > 0"
                  type="button"
                  class="preview-nav-btn prev"
                  aria-label="Trang trước"
                  @click="prevPreview"
                >
                  ‹
                </button>
                <button
                  v-if="previewImageIndex < workingImages.length - 1"
                  type="button"
                  class="preview-nav-btn next"
                  aria-label="Trang sau"
                  @click="nextPreview"
                >
                  ›
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </div>
  </CreatorLayout>
</template>

<style scoped>
.creator-chapter-images-page {
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
  flex-wrap: wrap;
}

.breadcrumb-link {
  color: var(--color-primary);
  text-decoration: none;
  font-weight: var(--font-weight-medium);
  transition: color var(--transition-fast);
}

.breadcrumb-link:hover {
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

/* Error Panel */
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

/* Chapter Header Card */
.chapter-header-card {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-lg);
  box-shadow: var(--shadow-sm);
}

.chapter-header-inner {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--spacing-lg);
  flex-wrap: wrap;
}

.header-left {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.btn-back-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  text-decoration: none;
  margin-bottom: 4px;
  transition: color var(--transition-fast);
}

.btn-back-link:hover {
  color: var(--color-primary);
}

.chapter-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  line-height: var(--line-height-tight);
}

.title-sub {
  color: var(--color-text-secondary);
  font-weight: var(--font-weight-normal);
}

.comic-name {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0;
}

.comic-name .highlight {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.header-badges {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  margin-top: 4px;
}

.count-badge {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  padding: 3px 8px;
  border-radius: var(--radius-sm);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

/* Read-only notice */
.readonly-notice {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-sm);
  padding: var(--spacing-md);
  background-color: rgba(6, 182, 212, 0.08);
  border: 1px solid rgba(6, 182, 212, 0.25);
  border-radius: var(--radius-md);
  color: #22d3ee;
  max-width: 440px;
}

.notice-icon {
  font-size: 16px;
  flex-shrink: 0;
  margin-top: 2px;
}

.notice-text {
  font-size: var(--font-size-xs);
  line-height: var(--line-height-normal);
}

.notice-text strong {
  display: block;
  margin-bottom: 2px;
}

.notice-text p {
  margin: 0;
  color: var(--color-text-secondary);
}

/* Header Skeleton */
.header-skeleton {
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
  width: 40%;
  height: 28px;
}

.skeleton-line.subtitle {
  width: 25%;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* Unsaved Order Floating Bar */
.unsaved-order-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-sm) var(--spacing-lg);
  background-color: rgba(245, 158, 11, 0.12);
  border: 1px solid rgba(245, 158, 11, 0.35);
  border-radius: var(--radius-md);
  color: #fbbf24;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  position: sticky;
  top: 16px;
  z-index: 100;
  backdrop-filter: blur(8px);
}

.unsaved-info {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: var(--font-size-sm);
}

.unsaved-icon {
  font-size: 18px;
}

.unsaved-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

/* Uploader Dropzone */
.uploader-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.dropzone-box {
  border: 2px dashed var(--color-border-subtle);
  border-radius: var(--radius-lg);
  background-color: rgba(21, 30, 50, 0.4);
  padding: var(--spacing-2xl) var(--spacing-lg);
  text-align: center;
  cursor: pointer;
  transition: all var(--transition-normal);
}

.dropzone-box:hover,
.dropzone-box.drag-over {
  border-color: var(--color-primary);
  background-color: rgba(56, 189, 248, 0.05);
  box-shadow: 0 0 16px rgba(56, 189, 248, 0.12);
}

.dropzone-box.uploading {
  opacity: 0.7;
  cursor: wait;
}

.hidden-file-input {
  display: none;
}

.dropzone-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--spacing-md);
}

.dropzone-icon {
  font-size: 40px;
}

.dropzone-labels {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.dropzone-main-label {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.dropzone-sub-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0;
}

.btn-select-files {
  margin-top: 4px;
}

.upload-errors-list {
  background-color: var(--color-danger-soft);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: var(--radius-md);
  padding: var(--spacing-md);
  color: #f87171;
  font-size: var(--font-size-xs);
}

.error-header {
  font-weight: var(--font-weight-semibold);
  margin-bottom: 6px;
}

.upload-errors-list ul {
  margin: 0;
  padding-left: 18px;
}

.upload-errors-list li {
  margin-bottom: 2px;
}

/* Gallery Section */
.gallery-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.gallery-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.gallery-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.gallery-hint {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0;
}

.gallery-loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-3xl) var(--spacing-xl);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  gap: var(--spacing-md);
}

.gallery-loading-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--color-border-subtle);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.gallery-loading-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0;
}

.gallery-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-xl);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  gap: var(--spacing-sm);
}

.gallery-empty-state .empty-icon {
  font-size: 44px;
}

.gallery-empty-state .empty-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.gallery-empty-state .empty-desc {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  max-width: 400px;
  margin: 0 0 var(--spacing-sm) 0;
}

/* Grid of Webtoon Pages */
.images-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--spacing-md);
}

.image-card {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: transform var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
  cursor: grab;
  user-select: none;
  position: relative;
}

.image-card:hover {
  border-color: var(--color-border-accent);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

.image-card.dragged-item {
  opacity: 0.4;
  transform: scale(0.96);
}

.image-card.drag-over-item {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary);
  transform: translateY(-2px);
}

.image-card.is-reordered {
  border-color: #f59e0b;
}

/* Page Badge */
.page-badge-wrap {
  position: absolute;
  top: 8px;
  left: 8px;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 4px;
}

.page-badge {
  background-color: rgba(5, 9, 18, 0.85);
  backdrop-filter: blur(4px);
  color: #fff;
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  padding: 2px 7px;
  border-radius: var(--radius-xs);
  border: 1px solid rgba(255, 255, 255, 0.15);
}

.badge-moved {
  background-color: rgba(245, 158, 11, 0.9);
  color: #0b0f19;
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  padding: 2px 5px;
  border-radius: var(--radius-xs);
}

/* Thumbnail */
.thumbnail-wrap {
  width: 100%;
  aspect-ratio: 2 / 3;
  background-color: var(--color-bg-card);
  position: relative;
  overflow: hidden;
  cursor: pointer;
}

.page-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.broken-image-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: #f87171;
  font-size: var(--font-size-xs);
  padding: var(--spacing-sm);
  text-align: center;
}

.broken-icon {
  font-size: 24px;
}

.thumbnail-hover-overlay {
  position: absolute;
  inset: 0;
  background-color: rgba(5, 9, 18, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity var(--transition-fast);
}

.thumbnail-wrap:hover .thumbnail-hover-overlay {
  opacity: 1;
}

.zoom-icon {
  background-color: rgba(56, 189, 248, 0.9);
  color: #0b0f19;
  font-size: 11px;
  font-weight: var(--font-weight-bold);
  padding: 4px 8px;
  border-radius: var(--radius-sm);
}

/* Card Footer */
.card-footer {
  padding: 8px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  border-top: 1px solid var(--color-border);
  background-color: rgba(255, 255, 255, 0.01);
}

.image-meta {
  font-size: 10px;
  color: var(--color-text-muted);
}

.card-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.btn-card-action {
  width: 24px;
  height: 24px;
  border-radius: var(--radius-xs);
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: 12px;
  cursor: pointer;
  transition: all var(--transition-fast);
  padding: 0;
}

.btn-card-action:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.btn-card-action:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.btn-card-delete:hover:not(:disabled) {
  border-color: var(--color-danger);
  background-color: var(--color-danger-soft);
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

.btn-spinner {
  width: 12px;
  height: 12px;
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

/* Preview Modal */
.preview-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background-color: rgba(5, 9, 18, 0.92);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
}

.preview-modal-content {
  width: 100%;
  max-width: 780px;
  height: 90vh;
  display: flex;
  flex-direction: column;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.8);
}

.preview-top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  background-color: var(--color-bg);
  border-bottom: 1px solid var(--color-border);
}

.preview-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.preview-res {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-normal);
  margin-left: 6px;
}

.preview-close-btn {
  background: none;
  border: none;
  color: var(--color-text-muted);
  font-size: 16px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: var(--radius-sm);
}

.preview-close-btn:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.preview-image-container {
  flex: 1;
  position: relative;
  overflow-y: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #030712;
  padding: var(--spacing-md);
}

.preview-full-img {
  max-width: 100%;
  height: auto;
  object-fit: contain;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
}

.preview-nav-btn {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 44px;
  height: 44px;
  border-radius: var(--radius-full);
  background-color: rgba(17, 24, 39, 0.8);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: #fff;
  font-size: 24px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all var(--transition-fast);
  z-index: 10;
}

.preview-nav-btn:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.preview-nav-btn.prev {
  left: 16px;
}

.preview-nav-btn.next {
  right: 16px;
}

/* Transitions */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: transform 0.25s ease, opacity 0.25s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
  transform: translateY(-16px);
  opacity: 0;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Responsive */
@media (max-width: 768px) {
  .creator-chapter-images-page {
    padding: var(--spacing-md);
    gap: var(--spacing-md);
  }

  .chapter-header-inner {
    flex-direction: column;
    align-items: stretch;
  }

  .readonly-notice {
    max-width: 100%;
  }

  .unsaved-order-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .unsaved-actions {
    justify-content: flex-end;
  }

  .images-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: var(--spacing-sm);
  }
}
</style>
