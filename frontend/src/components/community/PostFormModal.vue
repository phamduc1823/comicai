<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import type { Post } from '@/types/community'
import type { ComicListItem } from '@/types/catalog'
import { CommunityService } from '@/services/communityService'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage, getValidationErrors } from '@/services/httpClient'

const props = defineProps<{
  isOpen: boolean
  post?: Post | null
  initialComicId?: number | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'saved', post: Post): void
}>()

// Constants
const MAX_CONTENT_LENGTH = 5000
const MAX_IMAGE_SIZE_BYTES = 2 * 1024 * 1024 // 2 MB
const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp']

// Form State
const content = ref('')
const selectedComicId = ref<number | null>(null)
const selectedImageFile = ref<File | null>(null)
const imagePreviewUrl = ref<string | null>(null)
const removeImage = ref(false)

// UI State
const isSubmitting = ref(false)
const generalError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const availableComics = ref<ComicListItem[]>([])
const isLoadingComics = ref(false)

const fileInputRef = ref<HTMLInputElement | null>(null)

// Computed
const isEditMode = computed(() => !!props.post)
const contentLength = computed(() => content.value.length)
const isContentTooLong = computed(() => contentLength.value > MAX_CONTENT_LENGTH)
const isContentEmpty = computed(() => !content.value.trim())

const currentExistingImageUrl = computed(() => {
  if (removeImage.value) return null
  return props.post?.image_url || null
})

// Load approved comics for the select dropdown
async function loadComics() {
  if (availableComics.value.length > 0) return
  isLoadingComics.value = true
  try {
    const res = await CatalogService.getComics({ per_page: 50 })
    availableComics.value = res.data || []
  } catch (err) {
    console.error('Không thể tải danh sách truyện:', err)
  } finally {
    isLoadingComics.value = false
  }
}

// Reset form values to initial / edit state
function resetForm() {
  generalError.value = null
  fieldErrors.value = {}
  removeImage.value = false
  selectedImageFile.value = null

  if (imagePreviewUrl.value) {
    URL.revokeObjectURL(imagePreviewUrl.value)
    imagePreviewUrl.value = null
  }

  if (props.post) {
    content.value = props.post.content
    selectedComicId.value = props.post.comic ? props.post.comic.id : null
  } else {
    content.value = ''
    selectedComicId.value = props.initialComicId ?? null
  }
}

// Handle file selection
function handleFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return

  // Validate MIME type
  if (!ALLOWED_MIME_TYPES.includes(file.type)) {
    generalError.value = 'Ảnh bài đăng chỉ chấp nhận định dạng JPEG, PNG, JPG hoặc WebP.'
    target.value = ''
    return
  }

  // Validate File Size
  if (file.size > MAX_IMAGE_SIZE_BYTES) {
    generalError.value = 'Kích thước ảnh không được vượt quá 2 MB.'
    target.value = ''
    return
  }

  generalError.value = null
  selectedImageFile.value = file
  removeImage.value = false

  if (imagePreviewUrl.value) {
    URL.revokeObjectURL(imagePreviewUrl.value)
  }
  imagePreviewUrl.value = URL.createObjectURL(file)
}

function triggerFileInput() {
  fileInputRef.value?.click()
}

function handleRemoveNewImage() {
  selectedImageFile.value = null
  if (imagePreviewUrl.value) {
    URL.revokeObjectURL(imagePreviewUrl.value)
    imagePreviewUrl.value = null
  }
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

function handleMarkRemoveExistingImage() {
  removeImage.value = true
  handleRemoveNewImage()
}

function handleRestoreExistingImage() {
  removeImage.value = false
}

// Submit Form
async function handleSubmit() {
  if (isSubmitting.value) return

  // Client-side validation
  if (isContentEmpty.value) {
    generalError.value = 'Nội dung bài đăng không được để trống hoặc chỉ chứa khoảng trắng.'
    return
  }

  if (isContentTooLong.value) {
    generalError.value = 'Nội dung bài đăng không được vượt quá 5000 ký tự.'
    return
  }

  isSubmitting.value = true
  generalError.value = null
  fieldErrors.value = {}

  try {
    if (isEditMode.value && props.post) {
      // Update existing post
      const payload: {
        content: string
        comic_id: number | null
        image?: File | null
        remove_image?: boolean
      } = {
        content: content.value.trim(),
        comic_id: selectedComicId.value,
      }

      if (selectedImageFile.value) {
        payload.image = selectedImageFile.value
      } else if (removeImage.value) {
        payload.remove_image = true
      }

      const res = await CommunityService.updatePost(props.post.id, payload)
      emit('saved', res.data)
      emit('close')
    } else {
      // Create new post
      const res = await CommunityService.createPost({
        content: content.value.trim(),
        comic_id: selectedComicId.value,
        image: selectedImageFile.value,
      })
      emit('saved', res.data)
      emit('close')
    }
  } catch (err: unknown) {
    fieldErrors.value = getValidationErrors(err)
    generalError.value = getErrorMessage(err, 'Đã xảy ra lỗi khi lưu bài viết.')
  } finally {
    isSubmitting.value = false
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen && !isSubmitting.value) {
    emit('close')
  }
}

watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      resetForm()
      loadComics()
      window.addEventListener('keydown', handleKeyDown)
      document.body.style.overflow = 'hidden'
    } else {
      window.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = ''
    }
  }
)

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
  document.body.style.overflow = ''
  if (imagePreviewUrl.value) {
    URL.revokeObjectURL(imagePreviewUrl.value)
  }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="isOpen"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="'post-modal-title'"
        @click.self="!isSubmitting && emit('close')"
      >
        <div class="modal-card">
          <!-- Modal Header -->
          <header class="modal-header">
            <div class="header-title-group">
              <span class="header-icon" aria-hidden="true">{{ isEditMode ? '✏️' : '✍️' }}</span>
              <h2 id="post-modal-title" class="modal-title">
                {{ isEditMode ? 'Chỉnh sửa bài đăng' : 'Tạo bài đăng mới' }}
              </h2>
            </div>
            <button
              v-if="!isSubmitting"
              type="button"
              class="modal-close-btn"
              aria-label="Đóng cửa sổ"
              @click="emit('close')"
            >
              ✕
            </button>
          </header>

          <!-- Form Body -->
          <form class="modal-body" @submit.prevent="handleSubmit">
            <!-- Global Error Banner -->
            <div v-if="generalError" class="alert-error" role="alert">
              <span>⚠️ {{ generalError }}</span>
            </div>

            <!-- Content Area -->
            <div class="form-group">
              <label for="post-content" class="form-label">
                Nội dung bài viết <span class="required">*</span>
              </label>
              <textarea
                id="post-content"
                v-model="content"
                rows="5"
                class="form-textarea"
                :class="{ 'has-error': isContentTooLong || fieldErrors.content }"
                placeholder="Chia sẻ tin tức, cảm nghĩ hoặc thảo luận về truyện tranh..."
                :disabled="isSubmitting"
              ></textarea>
              <div class="form-helper-row">
                <span v-if="fieldErrors.content" class="field-error-text">
                  {{ fieldErrors.content[0] }}
                </span>
                <span
                  class="char-counter"
                  :class="{ 'char-counter--danger': isContentTooLong }"
                >
                  {{ contentLength }} / {{ MAX_CONTENT_LENGTH }}
                </span>
              </div>
            </div>

            <!-- Comic Selection (Optional) -->
            <div class="form-group">
              <label for="post-comic" class="form-label">
                Gắn kèm truyện (tùy chọn)
              </label>
              <select
                id="post-comic"
                v-model="selectedComicId"
                class="form-select"
                :disabled="isSubmitting || isLoadingComics"
              >
                <option :value="null">-- Không gắn kèm truyện --</option>
                <option
                  v-for="c in availableComics"
                  :key="c.id"
                  :value="c.id"
                >
                  {{ c.title }}
                </option>
              </select>
              <span v-if="fieldErrors.comic_id" class="field-error-text">
                {{ fieldErrors.comic_id[0] }}
              </span>
            </div>

            <!-- Image Upload & Preview -->
            <div class="form-group">
              <label class="form-label">Hình ảnh đính kèm (tùy chọn, tối đa 2MB)</label>

              <!-- Hidden Native Input -->
              <input
                ref="fileInputRef"
                type="file"
                class="hidden-file-input"
                accept="image/jpeg,image/png,image/jpg,image/webp"
                @change="handleFileChange"
              />

              <!-- Preview: New selected image -->
              <div v-if="imagePreviewUrl" class="image-preview-wrapper">
                <img :src="imagePreviewUrl" alt="Xem trước ảnh tải lên" class="preview-img" />
                <div class="preview-overlay">
                  <button
                    type="button"
                    class="btn-preview-action btn-change"
                    :disabled="isSubmitting"
                    @click="triggerFileInput"
                  >
                    Thay ảnh
                  </button>
                  <button
                    type="button"
                    class="btn-preview-action btn-delete"
                    :disabled="isSubmitting"
                    @click="handleRemoveNewImage"
                  >
                    Hủy ảnh
                  </button>
                </div>
              </div>

              <!-- Preview: Existing image in edit mode -->
              <div v-else-if="currentExistingImageUrl" class="image-preview-wrapper">
                <img :src="currentExistingImageUrl" alt="Ảnh hiện tại của bài viết" class="preview-img" />
                <div class="preview-overlay">
                  <button
                    type="button"
                    class="btn-preview-action btn-change"
                    :disabled="isSubmitting"
                    @click="triggerFileInput"
                  >
                    Thay ảnh khác
                  </button>
                  <button
                    type="button"
                    class="btn-preview-action btn-delete"
                    :disabled="isSubmitting"
                    @click="handleMarkRemoveExistingImage"
                  >
                    Xóa ảnh này
                  </button>
                </div>
              </div>

              <!-- State: Marked for removal of existing image -->
              <div v-else-if="isEditMode && props.post?.image_url && removeImage" class="image-removed-notice">
                <span>Ảnh hiện tại sẽ bị xóa khi lưu.</span>
                <button
                  type="button"
                  class="btn-text-link"
                  :disabled="isSubmitting"
                  @click="handleRestoreExistingImage"
                >
                  Giữ lại ảnh cũ
                </button>
              </div>

              <!-- Empty state: Button to choose image -->
              <div v-else class="image-upload-empty">
                <button
                  type="button"
                  class="btn-upload-trigger"
                  :disabled="isSubmitting"
                  @click="triggerFileInput"
                >
                  <span class="upload-icon" aria-hidden="true">📷</span>
                  <span>Chọn ảnh tải lên (JPEG, PNG, WebP $\le$ 2MB)</span>
                </button>
              </div>

              <span v-if="fieldErrors.image" class="field-error-text">
                {{ fieldErrors.image[0] }}
              </span>
            </div>

            <!-- Modal Footer -->
            <footer class="modal-footer">
              <button
                type="button"
                class="btn-cancel"
                :disabled="isSubmitting"
                @click="emit('close')"
              >
                Hủy bỏ
              </button>
              <button
                type="submit"
                class="btn-submit"
                :disabled="isSubmitting || isContentEmpty || isContentTooLong"
              >
                <span v-if="isSubmitting" class="spinner" aria-hidden="true"></span>
                <span>{{ isSubmitting ? 'Đang lưu...' : (isEditMode ? 'Cập nhật' : 'Đăng bài') }}</span>
              </button>
            </footer>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
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

.modal-card {
  width: 100%;
  max-width: 620px;
  max-height: 90vh;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.6), 0 8px 10px -6px rgba(0, 0, 0, 0.6);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md) var(--spacing-lg);
  border-bottom: 1px solid var(--color-border);
}

.header-title-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.header-icon {
  font-size: 20px;
}

.modal-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
}

.modal-close-btn {
  background: none;
  border: none;
  color: var(--color-text-muted);
  font-size: 16px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: var(--radius-sm);
}

.modal-close-btn:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.modal-body {
  padding: var(--spacing-lg);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.alert-error {
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-sm);
  color: var(--color-danger);
  font-size: var(--font-size-xs);
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-secondary);
}

.required {
  color: var(--color-danger);
}

.form-textarea {
  width: 100%;
  padding: var(--spacing-sm);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-family: inherit;
  font-size: var(--font-size-sm);
  line-height: var(--line-height-relaxed);
  resize: vertical;
  transition: border-color var(--transition-fast);
}

.form-textarea:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}

.form-textarea.has-error {
  border-color: var(--color-danger);
}

.form-helper-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--font-size-xs);
}

.field-error-text {
  color: var(--color-danger);
  font-size: var(--font-size-xs);
}

.char-counter {
  margin-left: auto;
  color: var(--color-text-muted);
}

.char-counter--danger {
  color: var(--color-danger);
  font-weight: var(--font-weight-bold);
}

.form-select {
  width: 100%;
  height: 40px;
  padding: 0 var(--spacing-sm);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  cursor: pointer;
}

.form-select:focus {
  outline: none;
  border-color: var(--color-primary);
}

.hidden-file-input {
  display: none;
}

.image-upload-empty {
  display: flex;
}

.btn-upload-trigger {
  width: 100%;
  padding: var(--spacing-md);
  border: 2px dashed var(--color-border);
  border-radius: var(--radius-md);
  background-color: rgba(255, 255, 255, 0.02);
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  transition: all var(--transition-fast);
}

.btn-upload-trigger:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
  background-color: var(--color-primary-soft);
}

.upload-icon {
  font-size: 18px;
}

.image-preview-wrapper {
  position: relative;
  max-height: 240px;
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--color-border);
  background-color: #000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.preview-img {
  max-width: 100%;
  max-height: 240px;
  object-fit: contain;
}

.preview-overlay {
  position: absolute;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-sm);
  opacity: 0;
  transition: opacity var(--transition-fast);
}

.image-preview-wrapper:hover .preview-overlay {
  opacity: 1;
}

.btn-preview-action {
  padding: 6px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  border: none;
  cursor: pointer;
}

.btn-change {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.btn-delete {
  background-color: var(--color-danger);
  color: #fff;
}

.image-removed-notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: rgba(245, 158, 11, 0.1);
  border: 1px dashed var(--color-warning);
  border-radius: var(--radius-sm);
  color: var(--color-warning);
  font-size: var(--font-size-xs);
}

.btn-text-link {
  background: none;
  border: none;
  color: var(--color-primary);
  cursor: pointer;
  font-size: var(--font-size-xs);
  text-decoration: underline;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--spacing-sm);
  padding-top: var(--spacing-md);
  border-top: 1px solid var(--color-border);
}

.btn-cancel {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  background-color: transparent;
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-cancel:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-submit {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 20px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border: none;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-submit:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
  box-shadow: 0 0 12px rgba(56, 189, 248, 0.4);
}

.btn-submit:disabled,
.btn-cancel:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}
</style>
