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
import { normalizeCommunityTag, getOrganizedTags } from '@/utils/tagUtils'
import { getOrganizedGenres } from '@/utils/genreUtils'

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
const isVip = ref(false)
const vipPrice = ref<number>(50)
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
  [title, authorName, alternativeTitles, ageRating, progressStatus, isVip, vipPrice, description, selectedGenres, selectedTags, coverFile],
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

// Genre Search & Selected-First UI State
const genreSearchText = ref('')
const isGenreListExpanded = ref(false)
const DEFAULT_UNSELECTED_GENRES_LIMIT = 8

const organizedGenresState = computed(() => {
  return getOrganizedGenres(
    availableGenres.value,
    selectedGenres.value,
    genreSearchText.value,
    isGenreListExpanded.value,
    DEFAULT_UNSELECTED_GENRES_LIMIT
  )
})

const displayedGenres = computed(() => organizedGenresState.value.displayedGenres)
const hasMoreUnselectedGenres = computed(() => organizedGenresState.value.hasMoreUnselected)
const isSearchingGenres = computed(() => !!genreSearchText.value.trim())

function toggleExpandGenres() {
  isGenreListExpanded.value = !isGenreListExpanded.value
}

function clearGenreSearch() {
  genreSearchText.value = ''
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

// Visible tags list: All selected tags first + 6 unselected tags
const DEFAULT_UNSELECTED_TAGS_LIMIT = 6
const displayedTags = computed(() => {
  return getOrganizedTags(availableTags.value, selectedTags.value, DEFAULT_UNSELECTED_TAGS_LIMIT)
})

// Tag Input & Autocomplete State
const tagInputContainerRef = ref<HTMLElement | null>(null)
const tagInputText = ref('')
const tagSuggestions = ref<Tag[]>([])
const isSearchingTags = ref(false)
const searchTagError = ref<string | null>(null)
const isDropdownOpen = ref(false)
const highlightedSuggestionIndex = ref(-1)
const isTagCreating = ref(false)

const normalizedInputTag = computed(() => normalizeCommunityTag(tagInputText.value))

const canCreateNewTag = computed(() => {
  if (!normalizedInputTag.value || normalizedInputTag.value.length < 2) return false
  if (isTagLimitReached.value) return false
  const matchesSuggestion = tagSuggestions.value.some(
    (s) => normalizeCommunityTag(s.name) === normalizedInputTag.value || s.slug === normalizedInputTag.value
  )
  const matchesSelected = availableTags.value.some(
    (t) => selectedTags.value.includes(t.id) && (normalizeCommunityTag(t.name) === normalizedInputTag.value || t.slug === normalizedInputTag.value)
  )
  return !matchesSuggestion && !matchesSelected
})

let tagSearchTimer: ReturnType<typeof setTimeout> | null = null
let tagSearchRequestId = 0

function handleTagInput() {
  clearFieldError('tags')
  const currentReqId = ++tagSearchRequestId
  const query = tagInputText.value.trim()

  if (!query) {
    tagSuggestions.value = []
    isSearchingTags.value = false
    searchTagError.value = null
    highlightedSuggestionIndex.value = -1
    return
  }

  isDropdownOpen.value = true
  highlightedSuggestionIndex.value = -1

  if (tagSearchTimer) clearTimeout(tagSearchTimer)
  tagSearchTimer = setTimeout(async () => {
    isSearchingTags.value = true
    searchTagError.value = null
    try {
      const res = await CatalogService.getTagSuggestions(query, selectedTags.value)
      if (currentReqId === tagSearchRequestId) {
        tagSuggestions.value = res.data || []
      }
    } catch {
      if (currentReqId === tagSearchRequestId) {
        searchTagError.value = 'Không thể tải gợi ý tag.'
      }
    } finally {
      if (currentReqId === tagSearchRequestId) {
        isSearchingTags.value = false
      }
    }
  }, 280)
}

function handleTagInputFocus() {
  if (tagInputText.value.trim()) {
    isDropdownOpen.value = true
    handleTagInput()
  }
}

function closeSuggestions() {
  isDropdownOpen.value = false
  highlightedSuggestionIndex.value = -1
}

function navigateSuggestion(direction: 1 | -1) {
  if (!isDropdownOpen.value) {
    isDropdownOpen.value = true
    return
  }
  const totalItems = tagSuggestions.value.length + (canCreateNewTag.value ? 1 : 0)
  if (totalItems === 0) return

  let next = highlightedSuggestionIndex.value + direction
  if (next < 0) next = totalItems - 1
  if (next >= totalItems) next = 0
  highlightedSuggestionIndex.value = next
}

function selectSuggestion(tag: Tag) {
  if (selectedTags.value.includes(tag.id)) {
    closeSuggestions()
    tagInputText.value = ''
    return
  }
  if (selectedTags.value.length >= MAX_TAGS) {
    closeSuggestions()
    return
  }
  if (!availableTags.value.some((t) => t.id === tag.id)) {
    availableTags.value.push(tag)
  }
  selectedTags.value.push(tag.id)
  tagInputText.value = ''
  closeSuggestions()
  clearFieldError('tags')
}

async function createAndAddCommunityTag() {
  const raw = tagInputText.value.trim()
  if (!raw || isTagCreating.value || isTagLimitReached.value) return
  isTagCreating.value = true
  try {
    const res = await CreatorService.createCommunityTag(raw)
    const newTag = res.data
    if (!availableTags.value.some((t) => t.id === newTag.id)) {
      availableTags.value.push(newTag)
    }
    if (!selectedTags.value.includes(newTag.id) && selectedTags.value.length < MAX_TAGS) {
      selectedTags.value.push(newTag.id)
    }
    tagInputText.value = ''
    closeSuggestions()
    clearFieldError('tags')
  } catch (err: unknown) {
    searchTagError.value = getErrorMessage(err, 'Không thể tạo tag mới.')
  } finally {
    isTagCreating.value = false
  }
}

function handleTagInputConfirm() {
  if (highlightedSuggestionIndex.value >= 0 && highlightedSuggestionIndex.value < tagSuggestions.value.length) {
    selectSuggestion(tagSuggestions.value[highlightedSuggestionIndex.value])
    return
  }

  if (highlightedSuggestionIndex.value === tagSuggestions.value.length && canCreateNewTag.value) {
    createAndAddCommunityTag()
    return
  }

  if (canCreateNewTag.value) {
    createAndAddCommunityTag()
    return
  }

  if (tagSuggestions.value.length > 0) {
    selectSuggestion(tagSuggestions.value[0])
    return
  }

  if (normalizedInputTag.value) {
    createAndAddCommunityTag()
  }
}

function handleClickOutside(event: MouseEvent) {
  if (tagInputContainerRef.value && !tagInputContainerRef.value.contains(event.target as Node)) {
    closeSuggestions()
  }
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
      isVip.value = Boolean(data.is_vip)
      vipPrice.value = Number(data.price) || 50
      description.value = data.description || ''
      selectedGenres.value = data.genres ? data.genres.map((g) => g.id) : []
      selectedTags.value = data.tags ? data.tags.map((t) => t.id) : []
      if (data.tags && Array.isArray(data.tags)) {
        data.tags.forEach((t) => {
          if (!availableTags.value.some((existing) => existing.id === t.id)) {
            availableTags.value.push(t)
          }
        })
      }
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
        is_vip: isVip.value,
        price: isVip.value ? vipPrice.value : 0,
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
        is_vip: isVip.value,
        price: isVip.value ? vipPrice.value : 0,
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
  document.addEventListener('click', handleClickOutside)
  initializePage()
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
  document.removeEventListener('click', handleClickOutside)
  if (tagSearchTimer) {
    clearTimeout(tagSearchTimer)
  }
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

            <!-- VIP Pricing Row -->
            <div class="form-row-2 vip-pricing-box">
              <div class="form-group checkbox-group">
                <label class="checkbox-label" for="comic-is-vip">
                  <input
                    id="comic-is-vip"
                    v-model="isVip"
                    type="checkbox"
                    class="form-checkbox"
                    :disabled="isComicPendingReview || isSubmitting"
                  />
                  <span class="checkbox-text font-bold text-yellow-500">👑 Đặt làm Truyện VIP (Thu phí bằng Coin)</span>
                </label>
                <p class="field-hint">
                  Khi bật VIP, độc giả sẽ cần trả Coin một lần để mở khóa toàn bộ các chương hiện tại và tương lai của truyện này.
                </p>
              </div>

              <div v-if="isVip" class="form-group" :class="{ 'has-error': fieldErrors.price }">
                <label for="comic-price" class="form-label">
                  Giá mua trọn bộ truyện (Coin) <span class="required-star">*</span>
                </label>
                <input
                  id="comic-price"
                  v-model.number="vipPrice"
                  type="number"
                  min="5"
                  max="500"
                  class="form-input"
                  :disabled="isComicPendingReview || isSubmitting"
                  placeholder="Ví dụ: 50"
                />
                <span v-if="fieldErrors.price" class="field-error" role="alert">
                  {{ fieldErrors.price[0] }}
                </span>
                <p class="field-hint">Gợi ý từ 20 đến 100 Coin tùy độ dài tác phẩm.</p>
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
              <div class="genres-header">
                <label for="comic-genre-search" class="form-label">
                  Thể loại truyện <span class="required-star">* (chọn ít nhất 1)</span>
                </label>
                <span v-if="selectedGenres.length > 0" class="genres-counter">
                  Đã chọn: {{ selectedGenres.length }} thể loại
                </span>
              </div>

              <!-- Genre Search Input -->
              <div class="genre-search-group">
                <div class="genre-search-wrapper">
                  <input
                    id="comic-genre-search"
                    v-model="genreSearchText"
                    type="text"
                    class="form-input genre-search-field"
                    placeholder="Tìm kiếm thể loại..."
                    maxlength="50"
                    :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting"
                    @input="clearFieldError('genres')"
                  />
                  <button
                    v-if="genreSearchText"
                    type="button"
                    class="genre-search-clear-btn"
                    title="Xóa tìm kiếm"
                    aria-label="Xóa tìm kiếm thể loại"
                    @click="clearGenreSearch"
                  >
                    ✕
                  </button>
                  <span v-else class="genre-search-icon" aria-hidden="true">🔍</span>
                </div>
              </div>

              <!-- Genres Chips (Selected first, then matching/popular unselected) -->
              <div class="chips-selector-grid">
                <button
                  v-for="genre in displayedGenres"
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

              <!-- No search results empty state -->
              <div v-if="isSearchingGenres && displayedGenres.length === 0" class="genre-empty-result">
                <span>Không tìm thấy thể loại nào phù hợp với "{{ genreSearchText }}".</span>
              </div>

              <!-- Expand / Collapse unselected genres toggle button -->
              <div
                v-if="!isSearchingGenres && (hasMoreUnselectedGenres || isGenreListExpanded)"
                class="genre-toggle-wrapper"
              >
                <button
                  type="button"
                  class="genre-toggle-btn"
                  @click="toggleExpandGenres"
                >
                  <span>{{ isGenreListExpanded ? '▲ Thu gọn bớt' : '▼ Xem thêm thể loại' }}</span>
                </button>
              </div>

              <span v-if="fieldErrors.genres" class="field-error" role="alert">
                {{ fieldErrors.genres[0] }}
              </span>
            </div>

            <!-- Tags Selection (Max 10 per business rule) -->
            <div class="form-group" :class="{ 'has-error': fieldErrors.tags }">
              <div class="tags-header">
                <label for="comic-tag-input" class="form-label">
                  Tag
                </label>
                <span class="tags-counter" :class="{ 'counter-max': isTagLimitReached }">
                  Đã chọn: {{ selectedTags.length }} / {{ MAX_TAGS }} tags
                </span>
              </div>

              <!-- Tag Input & Autocomplete Dropdown -->
              <div ref="tagInputContainerRef" class="tag-input-group">
                <div class="tag-input-wrapper">
                  <input
                    id="comic-tag-input"
                    v-model="tagInputText"
                    type="text"
                    class="form-input tag-input-field"
                    placeholder="Nhập Tag..."
                    maxlength="50"
                    :disabled="isApprovedWithPendingChanges || isComicPendingReview || isSubmitting || isTagLimitReached"
                    @input="handleTagInput"
                    @keydown.enter.prevent="handleTagInputConfirm"
                    @keydown.down.prevent="navigateSuggestion(1)"
                    @keydown.up.prevent="navigateSuggestion(-1)"
                    @keydown.esc="closeSuggestions"
                    @focus="handleTagInputFocus"
                  />
                  <button
                    type="button"
                    class="tag-add-btn"
                    :disabled="!normalizedInputTag || isApprovedWithPendingChanges || isComicPendingReview || isSubmitting || isTagLimitReached || isTagCreating"
                    title="Thêm tag"
                    @click="handleTagInputConfirm"
                  >
                    <span v-if="isTagCreating" class="tag-spinner-sm" aria-hidden="true"></span>
                    <span v-else aria-hidden="true">+</span>
                  </button>
                </div>

                <!-- Autocomplete Dropdown -->
                <div
                  v-if="isDropdownOpen && (isSearchingTags || tagSuggestions.length > 0 || normalizedInputTag || searchTagError)"
                  class="tag-autocomplete-dropdown"
                  role="listbox"
                >
                  <!-- Loading state -->
                  <div v-if="isSearchingTags" class="tag-dropdown-status">
                    <span class="tag-spinner-sm" aria-hidden="true"></span>
                    <span>Đang tìm kiếm...</span>
                  </div>

                  <!-- Error state -->
                  <div v-else-if="searchTagError" class="tag-dropdown-error">
                    {{ searchTagError }}
                  </div>

                  <!-- Suggestions list -->
                  <template v-else>
                    <div
                      v-for="(suggestion, idx) in tagSuggestions"
                      :key="suggestion.id"
                      class="tag-dropdown-item"
                      :class="{ active: highlightedSuggestionIndex === idx }"
                      role="option"
                      :aria-selected="highlightedSuggestionIndex === idx"
                      @mousedown.prevent="selectSuggestion(suggestion)"
                      @mouseenter="highlightedSuggestionIndex = idx"
                    >
                      <span class="tag-item-name">#{{ suggestion.name }}</span>
                      <span v-if="suggestion.slug && suggestion.slug !== suggestion.name" class="tag-item-slug">
                        ({{ suggestion.slug }})
                      </span>
                    </div>

                    <!-- Empty state when no suggestions and no new tag can be created -->
                    <div v-if="tagSuggestions.length === 0 && !canCreateNewTag" class="tag-dropdown-empty">
                      Không tìm thấy tag phù hợp.
                    </div>
                  </template>

                  <!-- Create new item (shown if user typed something valid and it's not already in suggestions) -->
                  <div
                    v-if="canCreateNewTag"
                    class="tag-dropdown-create"
                    :class="{ active: highlightedSuggestionIndex === tagSuggestions.length }"
                    role="option"
                    :aria-selected="highlightedSuggestionIndex === tagSuggestions.length"
                    @mousedown.prevent="createAndAddCommunityTag"
                    @mouseenter="highlightedSuggestionIndex = tagSuggestions.length"
                  >
                    <span class="tag-create-icon">+</span>
                    <span>Tạo mới: <strong class="tag-create-highlight">#{{ normalizedInputTag }}</strong></span>
                  </div>
                </div>
              </div>

              <div class="chips-selector-grid">
                <button
                  v-for="tag in displayedTags"
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

/* Genres Header, Search & Expand */
.genres-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.genres-counter {
  font-size: var(--font-size-xs);
  color: var(--color-primary);
  font-weight: var(--font-weight-medium);
}

.genre-search-group {
  position: relative;
  margin-top: 8px;
  margin-bottom: 12px;
}

.genre-search-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}

.genre-search-field {
  width: 100%;
  padding-right: 36px;
}

.genre-search-icon {
  position: absolute;
  right: 12px;
  font-size: 14px;
  color: var(--color-text-muted);
  pointer-events: none;
}

.genre-search-clear-btn {
  position: absolute;
  right: 10px;
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 14px;
  cursor: pointer;
  padding: 4px;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
}

.genre-search-clear-btn:hover {
  color: var(--color-text-primary);
}

.genre-empty-result {
  margin-top: 10px;
  padding: 10px 14px;
  background-color: var(--color-bg-surface-raised, #161b22);
  border: 1px dashed var(--color-border-subtle, #30363d);
  border-radius: var(--radius-md, 8px);
  font-size: var(--font-size-xs, 12px);
  color: var(--color-text-muted, #8b949e);
  text-align: center;
}

.genre-toggle-wrapper {
  margin-top: 10px;
  display: flex;
  justify-content: flex-start;
}

.genre-toggle-btn {
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: var(--font-size-xs);
  padding: 5px 12px;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.genre-toggle-btn:hover {
  background-color: var(--color-bg-elevated);
  color: var(--color-primary);
  border-color: var(--color-primary-soft);
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

/* Tag Input & Autocomplete */
.tag-input-group {
  position: relative;
  margin-top: 8px;
  margin-bottom: 12px;
}

.tag-input-wrapper {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tag-input-field {
  flex: 1;
  min-width: 0;
}

.tag-add-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  width: 40px;
  flex-shrink: 0;
  border-radius: var(--radius-md, 8px);
  background-color: var(--color-bg-surface-raised, #21262d);
  border: 1px solid var(--color-border-subtle, #30363d);
  color: var(--color-text-main, #e6edf3);
  font-size: 18px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.tag-add-btn:hover:not(:disabled) {
  background-color: var(--color-primary, #388bfd);
  border-color: var(--color-primary, #388bfd);
  color: #fff;
}

.tag-add-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.tag-spinner-sm {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.2);
  border-top-color: currentColor;
  border-radius: 50%;
  animation: tag-spin 0.6s linear infinite;
  display: inline-block;
}

@keyframes tag-spin {
  to { transform: rotate(360deg); }
}

.tag-autocomplete-dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background-color: var(--color-bg-surface-raised, #161b22);
  border: 1px solid var(--color-border-subtle, #30363d);
  border-radius: var(--radius-md, 8px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  z-index: 60;
  max-height: 240px;
  overflow-y: auto;
}

.tag-dropdown-status,
.tag-dropdown-empty,
.tag-dropdown-error {
  padding: 10px 14px;
  font-size: var(--font-size-xs, 12px);
  color: var(--color-text-muted, #8b949e);
  display: flex;
  align-items: center;
  gap: 8px;
}

.tag-dropdown-error {
  color: #f87171;
}

.tag-dropdown-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 14px;
  font-size: var(--font-size-sm, 13px);
  color: var(--color-text-main, #e6edf3);
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.tag-dropdown-item:hover,
.tag-dropdown-item.active {
  background-color: rgba(56, 139, 253, 0.15);
  color: var(--color-primary, #388bfd);
}

.tag-item-name {
  font-weight: var(--font-weight-medium, 500);
}

.tag-item-slug {
  font-size: 11px;
  color: var(--color-text-muted, #8b949e);
}

.tag-dropdown-create {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  font-size: var(--font-size-sm, 13px);
  color: var(--color-primary, #388bfd);
  cursor: pointer;
  border-top: 1px solid var(--color-border-subtle, #30363d);
  transition: background-color 0.15s ease;
}

.tag-dropdown-create:hover,
.tag-dropdown-create.active {
  background-color: rgba(56, 139, 253, 0.2);
}

.tag-create-icon {
  font-weight: 700;
  font-size: 15px;
}

.tag-create-highlight {
  color: #fff;
  font-weight: 600;
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
