<script setup lang="ts">
import { ref, computed } from 'vue'
import type { ComicListItem } from '@/types/catalog'
import AttachComicModal from '@/components/chat/AttachComicModal.vue'

const props = defineProps<{
  isSending: boolean
  error: string | null
}>()

const emit = defineEmits<{
  (e: 'send', payload: {
    body: string
    images?: File[]
    comic_id?: number
    comic_preview?: any
  }): void
}>()

const text = ref('')
const localError = ref<string | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

// Attachments state
const selectedImages = ref<File[]>([])
const selectedImagePreviews = ref<string[]>([])
const selectedComic = ref<ComicListItem | null>(null)
const isAttachComicModalOpen = ref(false)

const trimmedText = computed(() => text.value.trim())
const charCount = computed(() => text.value.length)
const isOverLimit = computed(() => charCount.value > 2000)

const hasAttachments = computed(() => {
  return selectedImages.value.length > 0 || selectedComic.value !== null
})

const canSend = computed(() => {
  return (trimmedText.value.length > 0 || hasAttachments.value) && !isOverLimit.value
})

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    submitMessage()
  }
}

function triggerImagePicker() {
  localError.value = null
  fileInputRef.value?.click()
}

function handleImageSelection(event: Event) {
  const target = event.target as HTMLInputElement
  const files = target.files
  if (!files || files.length === 0) return

  const fileList = Array.from(files)
  const remainingSlots = 5 - selectedImages.value.length

  if (fileList.length > remainingSlots) {
    localError.value = `Chỉ có thể chọn thêm tối đa ${remainingSlots} hình ảnh (tổng tối đa 5 ảnh).`
    target.value = ''
    return
  }

  for (const file of fileList) {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      localError.value = 'Chỉ chấp nhận hình ảnh định dạng JPEG, PNG hoặc WebP.'
      target.value = ''
      return
    }
    if (file.size > 5 * 1024 * 1024) {
      localError.value = 'Mỗi hình ảnh không được vượt quá 5MB.'
      target.value = ''
      return
    }
  }

  for (const file of fileList) {
    selectedImages.value.push(file)
    selectedImagePreviews.value.push(URL.createObjectURL(file))
  }

  target.value = ''
}

function removeImage(index: number) {
  if (selectedImagePreviews.value[index]) {
    URL.revokeObjectURL(selectedImagePreviews.value[index])
  }
  selectedImages.value.splice(index, 1)
  selectedImagePreviews.value.splice(index, 1)
}

function openComicPicker() {
  localError.value = null
  isAttachComicModalOpen.value = true
}

function handleComicSelected(comic: ComicListItem) {
  selectedComic.value = comic
}

function removeComic() {
  selectedComic.value = null
}

function submitMessage() {
  localError.value = null
  if (!canSend.value) {
    return
  }
  if (isOverLimit.value) {
    localError.value = 'Nội dung tin nhắn không được vượt quá 2000 ký tự.'
    return
  }

  const payload = {
    body: text.value,
    images: selectedImages.value.length > 0 ? [...selectedImages.value] : undefined,
    comic_id: selectedComic.value?.id,
    comic_preview: selectedComic.value ? {
      id: selectedComic.value.id,
      title: selectedComic.value.title,
      slug: selectedComic.value.slug,
      cover_url: selectedComic.value.cover_url,
      author_name: selectedComic.value.author_name,
      is_unavailable: false,
    } : null,
  }

  clear()
  emit('send', payload)
}

function clear() {
  text.value = ''
  localError.value = null
  selectedImages.value = []
  selectedImagePreviews.value.forEach((url) => URL.revokeObjectURL(url))
  selectedImagePreviews.value = []
  selectedComic.value = null
}

defineExpose({
  clear,
})
</script>

<template>
  <div class="message-composer" aria-label="Khung nhập tin nhắn">
    <!-- Error notification if sending failed -->
    <div v-if="localError || error" class="composer-error" role="alert">
      <span>{{ localError || error }}</span>
      <button type="button" class="error-close" aria-label="Đóng thông báo" @click="localError = null">
        ✕
      </button>
    </div>

    <!-- Hidden file input for image uploads -->
    <input
      ref="fileInputRef"
      type="file"
      multiple
      accept="image/jpeg,image/png,image/webp"
      style="display: none"
      @change="handleImageSelection"
    />

    <!-- Attachments Preview Strip -->
    <div v-if="hasAttachments" class="attachments-strip">
      <!-- Image Previews -->
      <div
        v-for="(previewUrl, index) in selectedImagePreviews"
        :key="index"
        class="preview-img-wrapper"
      >
        <img :src="previewUrl" alt="Ảnh đính kèm" class="preview-img" />
        <button
          type="button"
          class="btn-remove-attachment"
          title="Xóa ảnh này"
          aria-label="Xóa ảnh này"
          @click="removeImage(index)"
        >
          ✕
        </button>
      </div>

      <!-- Comic Preview Chip -->
      <div v-if="selectedComic" class="preview-comic-chip">
        <div class="comic-chip-cover">
          <img v-if="selectedComic.cover_url" :src="selectedComic.cover_url" :alt="selectedComic.title" class="chip-img" />
          <span v-else>📖</span>
        </div>
        <div class="comic-chip-info">
          <span class="chip-badge">Truyện đính kèm</span>
          <span class="chip-title">{{ selectedComic.title }}</span>
        </div>
        <button
          type="button"
          class="btn-remove-attachment"
          title="Bỏ đính kèm truyện"
          aria-label="Bỏ đính kèm truyện"
          @click="removeComic"
        >
          ✕
        </button>
      </div>
    </div>

    <div class="composer-box">
      <!-- Attachment Actions -->
      <div class="attachment-buttons">
        <button
          type="button"
          class="btn-attach"
          title="Đính kèm hình ảnh (tối đa 5 ảnh, <= 5MB/ảnh)"
          aria-label="Đính kèm hình ảnh"
          :disabled="selectedImages.length >= 5"
          @click="triggerImagePicker"
        >
          📷
        </button>

        <button
          type="button"
          class="btn-attach"
          title="Đính kèm truyện tranh"
          aria-label="Đính kèm truyện tranh"
          :disabled="selectedComic !== null"
          @click="openComicPicker"
        >
          📖
        </button>
      </div>

      <textarea
        v-model="text"
        rows="1"
        placeholder="Nhập tin nhắn... (Nhấn Enter để gửi, Shift+Enter để xuống dòng)"
        class="composer-textarea"
        maxlength="2100"
        aria-label="Nội dung tin nhắn"
        @keydown="handleKeydown"
      ></textarea>

      <div class="composer-actions">
        <span
          class="char-counter"
          :class="{ 'over-limit': isOverLimit, 'near-limit': charCount > 1800 }"
        >
          {{ charCount }}/2000
        </span>

        <button
          type="button"
          class="btn-send"
          :disabled="!canSend"
          :aria-busy="isSending"
          aria-label="Gửi tin nhắn"
          @click="submitMessage"
        >
          <span v-if="isSending" class="send-spinner" aria-hidden="true"></span>
          <span v-else class="send-icon" aria-hidden="true">➤</span>
        </button>
      </div>
    </div>

    <!-- Attach Comic Modal -->
    <AttachComicModal
      :is-open="isAttachComicModalOpen"
      @close="isAttachComicModalOpen = false"
      @select="handleComicSelected"
    />
  </div>
</template>

<style scoped>
.message-composer {
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: var(--color-bg-surface);
  border-top: 1px solid var(--color-border);
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-2xs);
}

.composer-error {
  padding: 4px 8px;
  background-color: var(--color-danger-soft);
  color: var(--color-danger);
  border-radius: var(--radius-sm);
  font-size: 11px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.error-close {
  background: transparent;
  border: none;
  color: var(--color-danger);
  font-size: 10px;
  cursor: pointer;
  padding: 0 4px;
}

.attachments-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 0;
  overflow-x: auto;
}

.preview-img-wrapper {
  position: relative;
  width: 50px;
  height: 50px;
  border-radius: 6px;
  overflow: hidden;
  border: 1px solid var(--color-border);
  flex-shrink: 0;
}

.preview-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.preview-comic-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  padding: 4px 8px;
  border-radius: 8px;
  flex-shrink: 0;
  max-width: 240px;
}

.comic-chip-cover {
  width: 24px;
  height: 34px;
  border-radius: 4px;
  overflow: hidden;
  background-color: #0b0f19;
  display: flex;
  align-items: center;
  justify-content: center;
}

.chip-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.comic-chip-info {
  flex: 1;
  min-width: 0;
}

.chip-badge {
  display: block;
  font-size: 9px;
  color: var(--color-primary);
  font-weight: 700;
}

.chip-title {
  display: block;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.btn-remove-attachment {
  position: absolute;
  top: 2px;
  right: 2px;
  background: rgba(0, 0, 0, 0.7);
  color: #ffffff;
  border: none;
  border-radius: 50%;
  width: 16px;
  height: 16px;
  font-size: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.preview-comic-chip .btn-remove-attachment {
  position: static;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 11px;
}

.composer-box {
  display: flex;
  align-items: flex-end;
  gap: var(--spacing-xs);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 6px var(--spacing-sm);
  transition: border-color var(--transition-fast);
}

.composer-box:focus-within {
  border-color: var(--color-primary);
}

.attachment-buttons {
  display: flex;
  align-items: center;
  gap: 4px;
  padding-bottom: 2px;
}

.btn-attach {
  background: transparent;
  border: none;
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
  border-radius: 4px;
  transition: all 0.15s ease;
  line-height: 1;
}

.btn-attach:hover:not(:disabled) {
  background-color: var(--color-bg-surface);
  transform: scale(1.1);
}

.btn-attach:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.composer-textarea {
  flex: 1;
  background: transparent;
  border: none;
  color: var(--color-text-primary);
  font-family: var(--font-family-sans);
  font-size: var(--font-size-sm);
  line-height: var(--line-height-normal);
  resize: none;
  min-height: 24px;
  max-height: 120px;
  outline: none;
  padding: 2px 0;
}

.composer-textarea::placeholder {
  color: var(--color-text-muted);
}

.composer-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-shrink: 0;
}

.char-counter {
  font-size: 10px;
  color: var(--color-text-muted);
}

.char-counter.near-limit {
  color: var(--color-warning);
}

.char-counter.over-limit {
  color: var(--color-danger);
  font-weight: var(--font-weight-bold);
}

.btn-send {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border: none;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-send:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

.btn-send:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.send-icon {
  font-size: 13px;
  transform: translate(1px, -1px);
}

.send-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
