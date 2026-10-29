<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'

const props = withDefaults(
  defineProps<{
    isOpen: boolean
    title?: string
    targetName?: string
    targetType?: 'comic' | 'comic_changes' | 'chapter'
    isLoading?: boolean
    errorMessage?: string | null
  }>(),
  {
    title: 'Từ chối phê duyệt',
    targetType: 'comic',
    isLoading: false,
    errorMessage: null,
  }
)

const emit = defineEmits<{
  (e: 'confirm', reason: string): void
  (e: 'close'): void
}>()

const reasonText = ref('')
const localError = ref<string | null>(null)

// Max character length based on backend FormRequest contracts:
// RejectComicRequest & RejectComicChangesRequest: max 1000 chars.
// RejectChapterRequest: max 500 chars.
const maxChars = computed(() => {
  return props.targetType === 'chapter' ? 500 : 1000
})

const charCount = computed(() => reasonText.value.length)
const trimmedLength = computed(() => reasonText.value.trim().length)

const isOverLimit = computed(() => charCount.value > maxChars.value)
const isUnderLimit = computed(() => trimmedLength.value > 0 && trimmedLength.value < 5)

const isSubmitDisabled = computed(() => {
  return (
    props.isLoading ||
    trimmedLength.value < 5 ||
    charCount.value > maxChars.value
  )
})

function handleInput() {
  localError.value = null
}

function handleClose() {
  if (props.isLoading) return
  emit('close')
}

function handleConfirm() {
  const trimmed = reasonText.value.trim()
  if (trimmed.length < 5) {
    localError.value = 'Lý do từ chối phải có ít nhất 5 ký tự.'
    return
  }
  if (reasonText.value.length > maxChars.value) {
    localError.value = `Lý do từ chối không được vượt quá ${maxChars.value} ký tự.`
    return
  }

  localError.value = null
  emit('confirm', trimmed)
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen && !props.isLoading) {
    handleClose()
  }
}

// Reset textarea and errors whenever modal opens
watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      reasonText.value = ''
      localError.value = null
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
        aria-labelledby="reject-modal-title"
        @click.self="handleClose"
      >
        <div class="modal-card">
          <!-- Header -->
          <div class="modal-header">
            <div class="modal-icon-badge" aria-hidden="true">
              ⚠️
            </div>
            <div class="modal-header-text">
              <h3 id="reject-modal-title" class="modal-title">
                {{ title }}
              </h3>
              <p v-if="targetName" class="target-name">
                Đối tượng: <span class="highlight">{{ targetName }}</span>
              </p>
            </div>
            <button
              type="button"
              class="modal-close-btn"
              aria-label="Đóng cửa sổ"
              :disabled="isLoading"
              @click="handleClose"
            >
              ✕
            </button>
          </div>

          <!-- Body -->
          <form class="modal-body" @submit.prevent="handleConfirm">
            <label for="rejection-reason-input" class="input-label">
              Lý do từ chối kiểm duyệt <span class="required">*</span>
            </label>
            <p class="input-hint">
              Vui lòng nêu rõ lý do hoặc các điểm cần sửa đổi để tác giả có thể cập nhật lại tác phẩm.
            </p>

            <div class="textarea-wrapper">
              <textarea
                id="rejection-reason-input"
                v-model="reasonText"
                rows="4"
                class="reason-textarea"
                :class="{ 'has-error': localError || errorMessage || isOverLimit }"
                :placeholder="`Nhập lý do từ chối (từ 5 đến ${maxChars} ký tự)...`"
                :disabled="isLoading"
                @input="handleInput"
              ></textarea>

              <div class="char-counter" :class="{ 'text-danger': isOverLimit, 'text-warning': isUnderLimit }">
                {{ charCount }} / {{ maxChars }} ký tự (tối thiểu 5)
              </div>
            </div>

            <!-- Error Alerts -->
            <div v-if="localError || errorMessage" class="modal-error-alert" role="alert">
              <span aria-hidden="true">⚠️</span>
              <span>{{ localError || errorMessage }}</span>
            </div>

            <!-- Footer Actions -->
            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-outline"
                :disabled="isLoading"
                @click="handleClose"
              >
                Hủy bỏ
              </button>
              <button
                type="submit"
                class="btn btn-danger"
                :disabled="isSubmitDisabled"
              >
                <span v-if="isLoading" class="btn-spinner" aria-hidden="true"></span>
                <span>{{ isLoading ? 'Đang gửi từ chối...' : 'Xác nhận từ chối' }}</span>
              </button>
            </div>
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
  z-index: 1050;
  background-color: rgba(5, 9, 18, 0.85);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
  animation: fadeIn 0.2s ease;
}

.modal-card {
  width: 100%;
  max-width: 540px;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-header {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-md);
  padding: var(--spacing-lg);
  border-bottom: 1px solid var(--color-border);
  position: relative;
}

.modal-icon-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: var(--radius-md);
  background-color: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.3);
  font-size: 20px;
  flex-shrink: 0;
}

.modal-header-text {
  flex: 1;
}

.modal-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  line-height: var(--line-height-tight);
}

.target-name {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 4px 0 0 0;
}

.target-name .highlight {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.modal-close-btn {
  background: none;
  border: none;
  color: var(--color-text-muted);
  font-size: 18px;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
  border-radius: var(--radius-xs);
  transition: color var(--transition-fast);
}

.modal-close-btn:hover:not(:disabled) {
  color: var(--color-text-primary);
}

.modal-body {
  padding: var(--spacing-lg);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.input-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.required {
  color: var(--color-danger);
}

.input-hint {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: -4px 0 var(--spacing-xs) 0;
  line-height: var(--line-height-normal);
}

.textarea-wrapper {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.reason-textarea {
  width: 100%;
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-family: inherit;
  font-size: var(--font-size-sm);
  line-height: var(--line-height-normal);
  resize: vertical;
  min-height: 100px;
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.reason-textarea:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px var(--color-primary-soft);
}

.reason-textarea.has-error {
  border-color: var(--color-danger);
}

.reason-textarea.has-error:focus {
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
}

.char-counter {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  text-align: right;
}

.char-counter.text-danger {
  color: var(--color-danger);
  font-weight: var(--font-weight-medium);
}

.char-counter.text-warning {
  color: var(--color-warning);
}

.modal-error-alert {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.25);
  border-radius: var(--radius-md);
  color: #fca5a5;
  font-size: var(--font-size-xs);
  animation: fadeIn 0.2s ease;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--spacing-sm);
  margin-top: var(--spacing-md);
  padding-top: var(--spacing-md);
  border-top: 1px solid var(--color-border);
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-sm) var(--spacing-lg);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
  border: 1px solid transparent;
}

.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn-outline {
  background-color: transparent;
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-outline:hover:not(:disabled) {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
  border-color: var(--color-border-hover);
}

.btn-danger {
  background-color: var(--color-danger);
  color: #ffffff;
}

.btn-danger:hover:not(:disabled) {
  background-color: #dc2626;
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid #ffffff;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes slideUp {
  from {
    transform: translateY(16px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
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
