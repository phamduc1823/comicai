<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink, onBeforeRouteLeave } from 'vue-router'
import CreatorLayout from '@/layouts/CreatorLayout.vue'
import { CreatorService } from '@/services/creatorService'
import { CatalogService } from '@/services/catalogService'
import {
  isValidationError,
  getValidationErrors,
  getErrorMessage,
  isForbiddenError,
  isNotFoundError,
} from '@/services/httpClient'
import type { CreatorComic, AgeRating, ComicProgressStatus } from '@/types/creator'
import type { Genre, Tag } from '@/types/catalog'

const route = useRoute()
const router = useRouter()

// Edit mode detection
const comicId = computed(() => {
  const idParam = route.params.id
  return idParam ? String(idParam) : null
})
const isEditMode = computed(() => !!comicId.value)

// Data states
const comic = ref<CreatorComic | null>(null)
const availableGenres = ref<Genre[]>([])
const availableTags = ref<Tag[]>([])

// Loading states
const isInitialLoading = ref(true)
const isSubmitting = ref(false)
const generalError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const successMessage = ref<string | null>(null)

// Form fields
const title = ref('')
const authorName = ref('')
const alternativeTitles = ref('')
const ageRating = ref<AgeRating>('13+')
const progressStatus = ref<ComicProgressStatus>('ongoing')
const description = ref('')
const selectedGenres = ref<number[]>([])
const selectedTags = ref<number[]>([])

// Cover file & preview
const coverFile = ref<File | null>(null)
const coverPreviewUrl = ref<string | null>(null)
const existingCoverUrl = ref<string | null>(null)

// Dirty state tracking
const isFormSubmitted = ref(false)
const isDirty = ref(false)

function markDirty() {
  if (!isInitialLoading.value) {
    isDirty.value = true
  }
}

// Watch fields for dirty tracking
watch(
  [title, authorName, alternativeTitles, ageRating, progressStatus, description, selectedGenres, selectedTags, coverFile],
  () => markDirty(),
  { deep: true }
)

// Computed pending changes status on approved comic
const isApprovedWithPendingChanges = computed(() => {
  return isEditMode.value && comic.value?.approval_status === 'approved' && comic.value?.pending_changes !== null
})

const isApprovedWithoutPendingChanges = computed(() => {
  return isEditMode.value && comic.value?.approval_status === 'approved' && comic.value?.pending_changes === null
})

const isComicPendingReview = computed(() => {
  return isEditMode.value && comic.value?.approval_status === 'pending'
})

// Max tags limit
const MAX_TAGS = 10
const isTagLimitReached = computed(() => selectedTags.value.length >= MAX_TAGS)

function toggleGenre(genreId: number) {
  if (isApprovedWithPendingChanges.value || isComicPendingReview.value) return
  const index = selectedGenres.value.indexOf(genreId)
  if (index === -1) {
    selectedGenres.value.push(genreId)
  } else {
    selectedGenres.value.splice(index, 1)
  }
  clearFieldError('genres')
}

function toggleTag(tagId: number) {
  if (isApprovedWithPendingChanges.value || isComicPendingReview.value) return
  const index = selectedTags.value.indexOf(tagId)
  if (index === -1) {
    if (selectedTags.value.length < MAX_TAGS) {
      selectedTags.value.push(tagId)
    }
  } else {
    selectedTags.value.splice(index, 1)
  }
  clearFieldError('tags')
}

function clearFieldError(field: string) {
  if (fieldErrors.value[field]) {
    delete fieldErrors.value[field]
  }
  if (generalError.value) {
    generalError.value = null
  }
}

// File handling
function handleCoverChange(event: Event) {
  const target = event.target as HTMLInputElement
  if (!target.files || target.files.length === 0) return

  const file = target.files[0]
  processSelectedCover(file)
}

function handleCoverDrop(event: DragEvent) {
  event.preventDefault()
  if (isApprovedWithPendingChanges.value || isComicPendingReview.value) return
  if (!event.dataTransfer?.files || event.dataTransfer.files.length === 0) return

  const file = event.dataTransfer.files[0]
  processSelectedCover(file)
}

function processSelectedCover(file: File) {
  clearFieldError('cover')

  // Client-side file type verification
  const validTypes = ['image/jpeg', 'image/png', 'image/webp']
  if (!validTypes.includes(file.type)) {
    fieldErrors.value.cover = ['Định dạng ảnh không hợp lệ. Vui lòng chọn tệp JPEG, PNG hoặc WebP.']
    return
  }

  // Max size: 5MB (5120 KB = 5242880 bytes)
  if (file.size > 5 * 1024 * 1024) {
    fieldErrors.value.cover = ['Kích thước ảnh bìa vượt quá 5MB. Vui lòng nén ảnh và thử lại.']
    return
  }

  // Cleanup old preview object URL if any
  if (coverPreviewUrl.value) {
    URL.revokeObjectURL(coverPreviewUrl.value)
  }

  coverFile.value = file
  coverPreviewUrl.value = URL.createObjectURL(file)
  markDirty()
}

function removeSelectedCover() {
  if (coverPreviewUrl.value) {
    URL.revokeObjectURL(coverPreviewUrl.value)
    coverPreviewUrl.value = null
  }
  coverFile.value = null
  markDirty()
}

/**
 * Load initial data: Genres, Tags, and Comic if in Edit mode
 */
async function initializePage() {
  isInitialLoading.value = true
  generalError.value = null
  fieldErrors.value = {}

  try {
    const [genresRes, tagsRes] = await Promise.all([
      CatalogService.getGenres(),
      CatalogService.getTags(),
    ])
    availableGenres.value = genresRes.data || []
    availableTags.value = tagsRes.data || []

    if (isEditMode.value && comicId.value) {
      const comicRes = await CreatorService.getComic(comicId.value)
      const data = comicRes.data
      comic.value = data

      // Populate form
      title.value = data.title || ''
      authorName.value = data.author_name || ''
      alternativeTitles.value = data.alternative_titles || ''
      ageRating.value = data.age_rating || '13+'
      progressStatus.value = data.status || 'ongoing'
      description.value = data.description || ''
      selectedGenres.value = data.genres ? data.genres.map((g) => g.id) : []
      selectedTags.value = data.tags ? data.tags.map((t) => t.id) : []
      existingCoverUrl.value = data.cover_url || null
    }
  } catch (err: unknown) {
    if (isForbiddenError(err)) {
      generalError.value = 'Bạn không có quyền chỉnh sửa bộ truyện này.'
    } else if (isNotFoundError(err)) {
      generalError.value = 'Bộ truyện không tồn tại hoặc đã bị xóa.'
    } else {
      generalError.value = getErrorMessage(err, 'Không thể tải thông tin bộ truyện.')
    }
  } finally {
    isInitialLoading.value = false
    isDirty.value = false
  }
}

/**
 * Handle form submission
 */
async function handleSubmit() {
  if (isSubmitting.value) return
  if (isApprovedWithPendingChanges.value || isComicPendingReview.value) return

  // Reset errors
  generalError.value = null
  fieldErrors.value = {}

  // Client-side quick validation
  const errors: Record<string, string[]> = {}
  if (!title.value.trim()) {
    errors.title = ['Vui lòng nhập tiêu đề truyện.']
  } else if (title.value.trim().length < 2) {
    errors.title = ['Tiêu đề truyện phải có ít nhất 2 ký tự.']
  }

  if (!authorName.value.trim()) {
    errors.author_name = ['Vui lòng nhập tên tác giả hoặc bút danh.']
  }

  if (selectedGenres.value.length === 0) {
    errors.genres = ['Vui lòng chọn ít nhất một thể loại cho bộ truyện.']
  }

  if (!isEditMode.value && !coverFile.value) {
    errors.cover = ['Vui lòng tải lên ảnh bìa cho bộ truyện.']
  }

  if (Object.keys(errors).length > 0) {
    fieldErrors.value = errors
    window.scrollTo({ top: 0, behavior: 'smooth' })
    return
  }

  isSubmitting.value = true

  try {
    if (isEditMode.value && comicId.value) {
      const response = await CreatorService.updateComic(comicId.value, {
        title: title.value.trim(),
        author_name: authorName.value.trim(),
        alternative_titles: alternativeTitles.value.trim() || null,
        age_rating: ageRating.value,
        status: progressStatus.value,
        description: description.value.trim() || null,
        genres: selectedGenres.value,
        tags: selectedTags.value,
        cover: coverFile.value || undefined,
      })

      isFormSubmitted.value = true
      isDirty.value = false
      successMessage.value = response.data?.approval_status === 'approved' && response.data?.pending_changes !== null
        ? 'Bản chỉnh sửa đã được gửi vào hàng đợi duyệt của Quản trị viên.'
        : 'Cập nhật bộ truyện thành công!'

      // Refresh comic state
      if (response.data) {
        comic.value = response.data
        existingCoverUrl.value = response.data.cover_url || null
        if (coverPreviewUrl.value) {
          URL.revokeObjectURL(coverPreviewUrl.value)
          coverPreviewUrl.value = null
        }
        coverFile.value = null
      }

      setTimeout(() => {
        router.push('/creator/comics')
      }, 1200)
    } else {
      await CreatorService.createComic({
        title: title.value.trim(),
        author_name: authorName.value.trim(),
        alternative_titles: alternativeTitles.value.trim() || null,
        age_rating: ageRating.value,
        status: progressStatus.value,
        description: description.value.trim() || null,
        genres: selectedGenres.value,
        tags: selectedTags.value,
        cover: coverFile.value!,
      })

      isFormSubmitted.value = true
      isDirty.value = false
      successMessage.value = 'Tạo bộ truyện mới thành công!'

      setTimeout(() => {
        router.push('/creator/comics')
      }, 1200)
    }
  } catch (err: unknown) {
    if (isValidationError(err)) {
      fieldErrors.value = getValidationErrors(err)
      generalError.value = 'Một số thông tin nhập chưa hợp lệ. Vui lòng kiểm tra lại.'
    } else {
      generalError.value = getErrorMessage(err, 'Đã có lỗi xảy ra khi lưu truyện. Vui lòng thử lại.')
    }
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    isSubmitting.value = false
  }
}

// Native browser warning on reload / close tab if form is dirty
function handleBeforeUnload(event: BeforeUnloadEvent) {
  if (isDirty.value && !isFormSubmitted.value) {
    event.preventDefault()
    event.returnValue = ''
    return ''
  }
}

// Warn on route leave if dirty
onBeforeRouteLeave((_to, _from, next) => {
  if (isDirty.value && !isFormSubmitted.value) {
    const confirmLeave = window.confirm(
      'Bạn có các thay đổi chưa được lưu trên biểu mẫu. Bạn có chắc chắn muốn rời khỏi trang?'
    )
    if (confirmLeave) {
      next()
    } else {
      next(false)
    }
  } else {
    next()
  }
})

onMounted(() => {
  window.addEventListener('beforeunload', handleBeforeUnload)
  initializePage()
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
  if (coverPreviewUrl.value) {
    URL.revokeObjectURL(coverPreviewUrl.value)
  }
})
</script>

<template>
  <CreatorLayout>
    <div class="creator-form-page">
      <!-- Page Navigation Header -->
      <div class="form-page-header">
        <RouterLink to="/creator/comics" class="btn-back">
          ← Quay lại danh sách
        </RouterLink>
        <h1 class="page-title">
          {{ isEditMode ? `Chỉnh sửa: ${comic?.title || 'Bộ truyện'}` : 'Đăng bộ truyện mới' }}
        </h1>
        <p class="page-subtitle">
          {{ isEditMode
            ? 'Cập nhật thông tin tác phẩm, ảnh bìa, thể loại và từ khóa tìm kiếm.'
            : 'Nhập thông tin tác phẩm mới. Truyện sẽ được lưu ở trạng thái bản nháp trước khi gửi duyệt.' }}
        </p>
      </div>

      <!-- Feedback notifications -->
      <div v-if="successMessage" class="feedback-banner banner-success" role="status">
        <span class="banner-icon" aria-hidden="true">✓</span>
        <span>{{ successMessage }}</span>
      </div>

      <div v-if="generalError" class="feedback-banner banner-error" role="alert">
        <span class="banner-icon" aria-hidden="true">⚠️</span>
        <span>{{ generalError }}</span>
      </div>

      <!-- Approved comic warning banner: Pending Changes exist -->
      <div
        v-if="isApprovedWithPendingChanges"
        class="feedback-banner banner-warning-pending"
        role="alert"
      >
        <span class="banner-icon" aria-hidden="true">🔒</span>
        <div class="banner-text">
          <strong>Bộ truyện đang có bản chỉnh sửa chờ Quản trị viên duyệt</strong>
          <p>
            Bạn không thể gửi thêm bản sửa đổi mới cho đến khi bản hiện tại được duyệt hoặc từ chối.
            Các trường thông tin quan trọng tạm thời bị khóa để bảo đảm tính nhất quán dữ liệu.
          </p>
        </div>
      </div>

      <!-- Approved comic info banner: Ready to stage changes -->
      <div
        v-else-if="isApprovedWithoutPendingChanges"
        class="feedback-banner banner-info-staged"
        role="note"
      >
        <span class="banner-icon" aria-hidden="true">ℹ️</span>
        <div class="banner-text">
          <strong>Bộ truyện đang xuất bản công khai</strong>
          <p>
            Mọi thay đổi về Tiêu đề, Tác giả, Ảnh bìa, Nhãn độ tuổi, Mô tả và Tags sẽ được lưu vào hàng đợi chờ duyệt lại.
            Độc giả trên website vẫn theo dõi phiên bản hiện tại cho đến khi bản sửa đổi được phê duyệt.
          </p>
        </div>
      </div>

      <!-- Loading skeleton -->
      <div v-if="isInitialLoading" class="form-skeleton" aria-busy="true">
        <div class="skeleton-block block-main"></div>
        <div class="skeleton-block block-side"></div>
      </div>

      <!-- Form Content -->
      <form v-else class="comic-form" @submit.prevent="handleSubmit">
        <div class="form-grid">
          <!-- Left Column: Core Metadata -->
          <div class="form-main-col">
            <!-- Title -->
            <div class="form-group" :class="{ 'has-error': fieldErrors.title }">
              <label for="comic-title" class="form-label">
                Tiêu đề truyện <span class="required-star">*</span>
              </label>
              <input
                id="comic-title"
                v-model="title"
                type="text"
                class="form-input"
                maxlength="200"
                placeholder="Nhập tên bộ truyện..."
                :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting"
                @input="clearFieldError('title')"
              />
              <div class="input-helper">
                <span v-if="fieldErrors.title" class="field-error" role="alert">
                  {{ fieldErrors.title[0] }}
                </span>
                <span class="char-counter">{{ title.length }} / 200</span>
              </div>
            </div>

            <!-- Author & Alternative Titles Row -->
            <div class="form-row-2">
              <div class="form-group" :class="{ 'has-error': fieldErrors.author_name }">
                <label for="comic-author" class="form-label">
                  Tác giả / Bút danh <span class="required-star">*</span>
                </label>
                <input
                  id="comic-author"
                  v-model="authorName"
                  type="text"
                  class="form-input"
                  maxlength="150"
                  placeholder="Tên tác giả hoặc nhóm tác giả..."
                  :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting"
                  @input="clearFieldError('author_name')"
                />
                <div class="input-helper">
                  <span v-if="fieldErrors.author_name" class="field-error" role="alert">
                    {{ fieldErrors.author_name[0] }}
                  </span>
                  <span class="char-counter">{{ authorName.length }} / 150</span>
                </div>
              </div>

              <div class="form-group" :class="{ 'has-error': fieldErrors.alternative_titles }">
                <label for="comic-alt-titles" class="form-label">
                  Tên khác (nếu có)
                </label>
                <input
                  id="comic-alt-titles"
                  v-model="alternativeTitles"
                  type="text"
                  class="form-input"
                  maxlength="255"
                  placeholder="Tên tiếng Anh hoặc tên phụ..."
                  :disabled="isComicPendingReview || isSubmitting"
                  @input="clearFieldError('alternative_titles')"
                />
                <div class="input-helper">
                  <span v-if="fieldErrors.alternative_titles" class="field-error" role="alert">
                    {{ fieldErrors.alternative_titles[0] }}
                  </span>
                  <span class="char-counter">{{ alternativeTitles.length }} / 255</span>
                </div>
              </div>
            </div>

            <!-- Age Rating & Status Row -->
            <div class="form-row-2">
              <div class="form-group" :class="{ 'has-error': fieldErrors.age_rating }">
                <label for="comic-age-rating" class="form-label">
                  Nhãn độ tuổi phù hợp <span class="required-star">*</span>
                </label>
                <select
                  id="comic-age-rating"
                  v-model="ageRating"
                  class="form-select"
                  :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting"
                  @change="clearFieldError('age_rating')"
                >
                  <option value="all">Mọi lứa tuổi (All ages)</option>
                  <option value="13+">13+ (Thiếu niên)</option>
                  <option value="16+">16+ (Trưởng thành)</option>
                  <option value="18+">18+ (Dành cho người lớn)</option>
                </select>
                <span v-if="fieldErrors.age_rating" class="field-error" role="alert">
                  {{ fieldErrors.age_rating[0] }}
                </span>
              </div>

              <div class="form-group" :class="{ 'has-error': fieldErrors.status }">
                <label for="comic-status" class="form-label">
                  Tiến độ sáng tác <span class="required-star">*</span>
                </label>
                <select
                  id="comic-status"
                  v-model="progressStatus"
                  class="form-select"
                  :disabled="isComicPendingReview || isSubmitting"
                  @change="clearFieldError('status')"
                >
                  <option value="ongoing">Đang tiến hành (Ongoing)</option>
                  <option value="completed">Đã hoàn thành (Completed)</option>
                </select>
                <span v-if="fieldErrors.status" class="field-error" role="alert">
                  {{ fieldErrors.status[0] }}
                </span>
              </div>
            </div>

            <!-- Description -->
            <div class="form-group" :class="{ 'has-error': fieldErrors.description }">
              <label for="comic-desc" class="form-label">
                Mô tả cốt truyện
              </label>
              <textarea
                id="comic-desc"
                v-model="description"
                class="form-textarea"
                rows="6"
                maxlength="5000"
                placeholder="Tóm tắt nội dung hấp dẫn để độc giả dễ dàng tiếp cận..."
                :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting"
                @input="clearFieldError('description')"
              ></textarea>
              <div class="input-helper">
                <span v-if="fieldErrors.description" class="field-error" role="alert">
                  {{ fieldErrors.description[0] }}
                </span>
                <span class="char-counter">{{ description.length }} / 5000</span>
              </div>
            </div>

            <!-- Genres Selection -->
            <div class="form-group" :class="{ 'has-error': fieldErrors.genres }">
              <label class="form-label">
                Thể loại truyện <span class="required-star">* (chọn ít nhất 1)</span>
              </label>
              <div class="chips-selector-grid">
                <button
                  v-for="genre in availableGenres"
                  :key="genre.id"
                  type="button"
                  class="chip-button"
                  :class="{ selected: selectedGenres.includes(genre.id) }"
                  :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting"
                  @click="toggleGenre(genre.id)"
                >
                  <span class="chip-check" aria-hidden="true">
                    {{ selectedGenres.includes(genre.id) ? '✓' : '+' }}
                  </span>
                  <span>{{ genre.name }}</span>
                </button>
              </div>
              <span v-if="fieldErrors.genres" class="field-error" role="alert">
                {{ fieldErrors.genres[0] }}
              </span>
            </div>

            <!-- Tags Selection (Max 10 per business rule) -->
            <div class="form-group" :class="{ 'has-error': fieldErrors.tags }">
              <div class="tags-header">
                <label class="form-label">
                  Nhãn đặc điểm (Tags)
                </label>
                <span class="tags-counter" :class="{ 'counter-max': isTagLimitReached }">
                  Đã chọn: {{ selectedTags.length }} / {{ MAX_TAGS }} tags
                </span>
              </div>

              <div class="chips-selector-grid">
                <button
                  v-for="tag in availableTags"
                  :key="tag.id"
                  type="button"
                  class="chip-button tag-chip"
                  :class="{
                    selected: selectedTags.includes(tag.id),
                    disabled: !selectedTags.includes(tag.id) && isTagLimitReached,
                  }"
                  :disabled="
                    (!selectedTags.includes(tag.id) && isTagLimitReached) ||
                    isApprovedWithPendingChanges ||
                    isComicPendingReview ||
                    isSubmitting
                  "
                  @click="toggleTag(tag.id)"
                >
                  <span class="chip-check" aria-hidden="true">
                    {{ selectedTags.includes(tag.id) ? '✓' : '#' }}
                  </span>
                  <span>{{ tag.name }}</span>
                </button>
              </div>
              <p v-if="isTagLimitReached" class="tag-limit-note">
                Bạn đã chọn tối đa 10 tags cho bộ truyện này. Bỏ chọn một tag hiện tại nếu muốn thêm tag khác.
              </p>
              <span v-if="fieldErrors.tags" class="field-error" role="alert">
                {{ fieldErrors.tags[0] }}
              </span>
            </div>
          </div>

          <!-- Right Column: Cover Upload -->
          <div class="form-side-col">
            <div class="cover-upload-panel" :class="{ 'has-error': fieldErrors.cover }">
              <label class="form-label">
                Ảnh bìa truyện <span v-if="!isEditMode" class="required-star">*</span>
              </label>
              <span class="cover-guide">
                Tỷ lệ chuẩn 2:3 (ví dụ 600×900 px). Định dạng: JPEG, PNG, WebP. Tối đa 5MB.
              </span>

              <!-- Cover Preview Box -->
              <div
                class="cover-dropzone"
                :class="{
                  'has-preview': coverPreviewUrl || existingCoverUrl,
                  'is-disabled': isApprovedWithPendingChanges || isComicPendingReview,
                }"
                @dragover.prevent
                @drop="handleCoverDrop"
              >
                <!-- Image Display -->
                <div v-if="coverPreviewUrl || existingCoverUrl" class="preview-box">
                  <img
                    :src="coverPreviewUrl || existingCoverUrl!"
                    alt="Xem trước ảnh bìa"
                    class="preview-image"
                  />
                  <div v-if="coverPreviewUrl" class="new-tag">Ảnh mới</div>
                </div>

                <!-- Empty Placeholder -->
                <div v-else class="dropzone-placeholder">
                  <span class="placeholder-icon" aria-hidden="true">🖼️</span>
                  <span class="placeholder-text">Kéo thả ảnh vào đây</span>
                  <span class="placeholder-sub">hoặc bấm nút bên dưới</span>
                </div>
              </div>

              <!-- Upload Actions -->
              <div v-if="!isApprovedWithPendingChanges && !isComicPendingReview" class="cover-actions">
                <label for="cover-file-input" class="btn-select-file">
                  <span>{{ coverPreviewUrl || existingCoverUrl ? 'Thay đổi ảnh bìa' : 'Chọn ảnh từ máy tính' }}</span>
                  <input
                    id="cover-file-input"
                    type="file"
                    class="hidden-file-input"
                    accept="image/jpeg,image/png,image/webp"
                    @change="handleCoverChange"
                  />
                </label>

                <button
                  v-if="coverPreviewUrl"
                  type="button"
                  class="btn-remove-new-cover"
                  @click="removeSelectedCover"
                >
                  Hủy ảnh mới chọn
                </button>
              </div>

              <span v-if="fieldErrors.cover" class="field-error error-cover" role="alert">
                {{ fieldErrors.cover[0] }}
              </span>
            </div>
          </div>
        </div>

        <!-- Sticky Footer Action Bar -->
        <div class="form-actions-bar">
          <RouterLink to="/creator/comics" class="btn-form-cancel">
            Hủy bỏ
          </RouterLink>

          <button
            type="submit"
            class="btn-form-submit"
            :disabled="isSubmitting || isApprovedWithPendingChanges || isComicPendingReview"
          >
            <span v-if="isSubmitting" class="spinner" aria-hidden="true"></span>
            <span>
              {{
                isSubmitting
                  ? 'Đang lưu dữ liệu...'
                  : isApprovedWithoutPendingChanges
                    ? 'Gửi duyệt bản sửa đổi'
                    : isEditMode
                      ? 'Lưu thay đổi'
                      : 'Tạo bản nháp mới'
              }}
            </span>
          </button>
        </div>
      </form>
    </div>
  </CreatorLayout>
</template>

<style scoped>
.creator-form-page {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
  max-width: 1080px;
  margin: 0 auto;
}

/* Page Navigation Header */
.form-page-header {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.btn-back {
  display: inline-flex;
  align-items: center;
  color: var(--color-text-muted);
  text-decoration: none;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  margin-bottom: 6px;
  transition: color var(--transition-fast);
}

.btn-back:hover {
  color: var(--color-primary);
}

.page-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  letter-spacing: -0.02em;
}

.page-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0;
}

/* Feedback banners */
.feedback-banner {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-md);
  padding: var(--spacing-md);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  line-height: var(--line-height-relaxed);
}

.banner-success {
  background-color: var(--color-success-soft);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.3);
}

.banner-error {
  background-color: var(--color-danger-soft);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.banner-warning-pending {
  background-color: var(--color-warning-soft);
  color: #fbbf24;
  border: 1px solid rgba(245, 158, 11, 0.4);
}

.banner-info-staged {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.35);
}

.banner-text strong {
  display: block;
  font-size: var(--font-size-sm);
  margin-bottom: 2px;
}

.banner-text p {
  margin: 0;
  font-size: var(--font-size-xs);
  opacity: 0.9;
}

/* Form Layout Grid */
.form-grid {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: var(--spacing-xl);
  align-items: start;
}

.form-main-col {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-xl);
}

.form-side-col {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-lg);
  position: sticky;
  top: 80px;
}

/* Form Elements */
.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-row-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--spacing-md);
}

.form-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.required-star {
  color: #f87171;
}

.form-input,
.form-select,
.form-textarea {
  width: 100%;
  padding: 10px 14px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  outline: none;
  font-family: inherit;
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary-soft);
}

.form-input:disabled,
.form-select:disabled,
.form-textarea:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background-color: rgba(30, 41, 59, 0.4);
}

.form-textarea {
  resize: vertical;
}

.input-helper {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
}

.field-error {
  color: #f87171;
  font-size: 11px;
  font-weight: var(--font-weight-medium);
}

.char-counter {
  margin-left: auto;
  color: var(--color-text-muted);
}

/* Chips Grid (Genres & Tags) */
.chips-selector-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
}

.chip-button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: var(--radius-full);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.chip-button:hover:not(:disabled) {
  border-color: var(--color-primary-soft);
  color: var(--color-text-primary);
}

.chip-button.selected {
  background-color: var(--color-primary-soft);
  border-color: var(--color-primary);
  color: var(--color-primary);
  font-weight: var(--font-weight-medium);
}

.chip-button.disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.tag-chip {
  border-radius: var(--radius-sm);
}

.tags-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.tags-counter {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
}

.tags-counter.counter-max {
  color: #fbbf24;
}

.tag-limit-note {
  margin: 6px 0 0 0;
  font-size: 11px;
  color: #fbbf24;
}

/* Cover Upload Panel */
.cover-upload-panel {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cover-guide {
  font-size: 11px;
  color: var(--color-text-muted);
  line-height: var(--line-height-normal);
}

.cover-dropzone {
  width: 100%;
  aspect-ratio: 2 / 3;
  border: 2px dashed var(--color-border);
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
  transition: border-color var(--transition-fast);
}

.cover-dropzone.has-preview {
  border-style: solid;
  border-color: var(--color-border-subtle);
}

.cover-dropzone.is-disabled {
  opacity: 0.7;
}

.preview-box {
  width: 100%;
  height: 100%;
  position: relative;
}

.preview-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.new-tag {
  position: absolute;
  top: 8px;
  right: 8px;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  padding: 2px 8px;
  border-radius: var(--radius-sm);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
}

.dropzone-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: var(--spacing-md);
}

.placeholder-icon {
  font-size: 36px;
  margin-bottom: 8px;
  color: var(--color-text-muted);
}

.placeholder-text {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.placeholder-sub {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

.cover-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 4px;
}

.btn-select-file {
  width: 100%;
  padding: 9px 12px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-align: center;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-select-file:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.hidden-file-input {
  display: none;
}

.btn-remove-new-cover {
  background: none;
  border: none;
  color: #f87171;
  font-size: 11px;
  cursor: pointer;
  padding: 4px;
  text-align: center;
}

.btn-remove-new-cover:hover {
  text-decoration: underline;
}

.error-cover {
  margin-top: 4px;
}

/* Actions Footer */
.form-actions-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  position: sticky;
  bottom: 16px;
  z-index: 30;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

.btn-form-cancel {
  padding: 10px 20px;
  border-radius: var(--radius-md);
  background-color: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-form-cancel:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-form-submit {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 24px;
  border-radius: var(--radius-md);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border: none;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
  transition: all var(--transition-fast);
  box-shadow: 0 2px 8px rgba(56, 189, 248, 0.3);
}

.btn-form-submit:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

.btn-form-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Skeleton loader */
.form-skeleton {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: var(--spacing-xl);
}

.skeleton-block {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  animation: pulse 1.5s infinite;
}

.block-main {
  height: 540px;
}

.block-side {
  height: 400px;
}

@keyframes pulse {
  0%, 100% {
    opacity: 0.6;
  }
  50% {
    opacity: 0.3;
  }
}

/* Responsive */
@media (max-width: 860px) {
  .form-grid {
    grid-template-columns: 1fr;
  }

  .form-side-col {
    position: static;
    order: -1;
  }

  .cover-dropzone {
    max-width: 200px;
    margin: 0 auto;
  }

  .form-row-2 {
    grid-template-columns: 1fr;
  }
}
</style>
