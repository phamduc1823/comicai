<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

export interface CommentFormUser {
  id: number
  name: string
  avatar_url?: string | null
  avatar_path?: string | null
  role?: string
}

export interface CommentFormProps {
  mode?: 'create' | 'reply'
  isSubmitting?: boolean
  isAuthenticated?: boolean
  currentUser?: CommentFormUser | null
  retryAfterSeconds?: number
  placeholder?: string
  initialContent?: string
}

const props = withDefaults(defineProps<CommentFormProps>(), {
  mode: 'create',
  isSubmitting: false,
  isAuthenticated: false,
  currentUser: null,
  retryAfterSeconds: 0,
  placeholder: '',
  initialContent: '',
})

const emit = defineEmits<{
  (e: 'submit', content: string): void
  (e: 'cancel'): void
  (e: 'login'): void
}>()

const MAX_CONTENT_LENGTH = 2000

// Internal reactive states
const content = ref(props.initialContent || '')
const validationError = ref<string | null>(null)
const avatarLoadError = ref(false)
const remainingSeconds = ref(props.retryAfterSeconds || 0)
const textareaRef = ref<HTMLTextAreaElement | null>(null)
let countdownTimer: ReturnType<typeof setInterval> | null = null

// Computed properties
const contentLength = computed(() => content.value.length)
const isContentEmpty = computed(() => content.value.trim().length === 0)
const isContentTooLong = computed(() => content.value.length > MAX_CONTENT_LENGTH)

const resolvedPlaceholder = computed(() => {
  if (props.placeholder) {
    return props.placeholder
  }
  return props.mode === 'reply'
    ? 'Viết câu trả lời của bạn... (Tối đa 2.000 ký tự)'
    : 'Viết bình luận của bạn... (Tối đa 2.000 ký tự)'
})

const userAvatarUrl = computed(() => {
  return props.currentUser?.avatar_url || props.currentUser?.avatar_path || null
})

const userName = computed(() => props.currentUser?.name || 'Người dùng')

const userInitial = computed(() => {
  return props.currentUser?.name ? props.currentUser.name.charAt(0).toUpperCase() : '?'
})

const isSubmitDisabled = computed(() => {
  return (
    props.isSubmitting ||
    remainingSeconds.value > 0 ||
    isContentEmpty.value ||
    isContentTooLong.value
  )
})

const submitButtonText = computed(() => {
  if (props.isSubmitting) {
    return 'Đang gửi...'
  }
  if (remainingSeconds.value > 0) {
    return `Chờ (${remainingSeconds.value}s)`
  }
  return props.mode === 'reply' ? 'Trả lời' : 'Bình luận'
})

// Auto-adjust textarea height to content
function adjustTextareaHeight() {
  const el = textareaRef.value
  if (!el) return
  el.style.height = 'auto'
  const nextHeight = Math.min(Math.max(el.scrollHeight, props.mode === 'reply' ? 68 : 84), 260)
  el.style.height = `${nextHeight}px`
}

// Cooldown interval handling
function startCountdown(seconds: number) {
  stopCountdown()
  remainingSeconds.value = Math.max(0, Math.floor(seconds))
  if (remainingSeconds.value > 0) {
    countdownTimer = setInterval(() => {
      if (remainingSeconds.value > 1) {
        remainingSeconds.value -= 1
      } else {
        remainingSeconds.value = 0
        stopCountdown()
      }
    }, 1000)
  }
}

function stopCountdown() {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
}

// Form Submission handling
function handleSubmit() {
  if (props.isSubmitting || remainingSeconds.value > 0) {
    return
  }

  const trimmed = content.value.trim()

  if (trimmed.length === 0) {
    validationError.value = 'Nội dung bình luận không được để trống hoặc chỉ chứa khoảng trắng.'
    return
  }

  if (content.value.length > MAX_CONTENT_LENGTH) {
    validationError.value = `Nội dung bình luận không được vượt quá ${MAX_CONTENT_LENGTH} ký tự.`
    return
  }

  validationError.value = null
  emit('submit', trimmed)
}

function handleKeydown(event: KeyboardEvent) {
  // Support Ctrl + Enter or Cmd + Enter for quick submission
  if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
    event.preventDefault()
    handleSubmit()
  }
}

function resetForm() {
  content.value = props.initialContent || ''
  validationError.value = null
  nextTick(() => {
    adjustTextareaHeight()
  })
}

function focus() {
  textareaRef.value?.focus()
}

// Watchers
watch(
  () => props.retryAfterSeconds,
  (newVal) => {
    startCountdown(newVal)
  },
  { immediate: true }
)

watch(
  () => props.initialContent,
  (newVal) => {
    if (newVal !== undefined && newVal !== content.value) {
      content.value = newVal
      nextTick(() => {
        adjustTextareaHeight()
      })
    }
  }
)

watch(
  () => props.currentUser,
  () => {
    avatarLoadError.value = false
  }
)

watch(content, () => {
  if (validationError.value && !isContentEmpty.value && !isContentTooLong.value) {
    validationError.value = null
  }
  nextTick(() => {
    adjustTextareaHeight()
  })
})

onMounted(() => {
  adjustTextareaHeight()
  if (props.mode === 'reply' && textareaRef.value) {
    textareaRef.value.focus()
    const len = content.value.length
    textareaRef.value.setSelectionRange(len, len)
  }
})

onUnmounted(() => {
  stopCountdown()
})

defineExpose({
  resetForm,
  focus,
})
</script>

<template>
  <div
    class="comment-form-container"
    :class="{
      'mode-reply': mode === 'reply',
      'is-guest': !isAuthenticated,
    }"
  >
    <!-- Guest Unauthenticated Prompt -->
    <div v-if="!isAuthenticated" class="guest-auth-prompt" role="region" aria-label="Yêu cầu đăng nhập">
      <div class="guest-prompt-icon" aria-hidden="true">💬</div>
      <div class="guest-prompt-body">
        <h4 class="guest-prompt-title">Đăng nhập để tham gia thảo luận</h4>
        <p class="guest-prompt-desc">
          Chia sẻ cảm nghĩ, đánh giá nội dung và trao đổi cùng cộng đồng độc giả BlueToon.
        </p>
      </div>
      <button
        type="button"
        class="btn-guest-login"
        aria-label="Chuyển tới trang đăng nhập để bình luận"
        @click="emit('login')"
      >
        <span>Đăng nhập</span>
        <span class="btn-arrow" aria-hidden="true">→</span>
      </button>
    </div>

    <!-- Authenticated Interactive Comment Form -->
    <div v-else class="comment-form-wrapper">
      <!-- Reply Header Mode Indicator -->
      <div v-if="mode === 'reply'" class="reply-mode-banner">
        <div class="reply-badge">
          <span class="reply-icon" aria-hidden="true">↩</span>
          <span class="reply-label">Đang trả lời bình luận</span>
        </div>
        <button
          type="button"
          class="btn-reply-cancel-top"
          :disabled="isSubmitting"
          aria-label="Hủy chế độ trả lời"
          title="Hủy trả lời"
          @click="emit('cancel')"
        >
          ✕
        </button>
      </div>

      <!-- Main Form Row -->
      <form class="comment-form" @submit.prevent="handleSubmit">
        <!-- User Avatar Column -->
        <div class="comment-avatar-col">
          <div class="user-avatar" :title="userName" aria-hidden="true">
            <img
              v-if="userAvatarUrl && !avatarLoadError"
              :src="userAvatarUrl"
              :alt="userName"
              class="avatar-img"
              @error="avatarLoadError = true"
            />
            <span v-else class="avatar-initial">{{ userInitial }}</span>
          </div>
        </div>

        <!-- Input Area Column -->
        <div class="comment-input-col">
          <div
            class="textarea-box"
            :class="{
              'is-focused': false,
              'has-error': isContentTooLong || !!validationError,
              'is-disabled': isSubmitting || remainingSeconds > 0,
            }"
          >
            <textarea
              id="comment-content-textarea"
              ref="textareaRef"
              v-model="content"
              class="comment-textarea"
              :placeholder="resolvedPlaceholder"
              :disabled="isSubmitting || remainingSeconds > 0"
              aria-label="Nội dung bình luận"
              :aria-invalid="isContentTooLong || !!validationError"
              :aria-describedby="validationError ? 'comment-form-error' : undefined"
              @keydown="handleKeydown"
            ></textarea>
          </div>

          <!-- Rate Limit Cooldown Notice -->
          <div
            v-if="remainingSeconds > 0"
            class="cooldown-banner"
            role="alert"
            aria-live="polite"
          >
            <span class="cooldown-icon" aria-hidden="true">⏳</span>
            <span class="cooldown-text">
              Bạn đang gửi bình luận quá nhanh. Vui lòng đợi
              <strong class="cooldown-seconds">{{ remainingSeconds }}s</strong> trước khi gửi tiếp.
            </span>
          </div>

          <!-- Validation Error Message -->
          <div
            v-if="validationError"
            id="comment-form-error"
            class="validation-error-banner"
            role="alert"
            aria-live="polite"
          >
            <span class="error-icon" aria-hidden="true">⚠️</span>
            <span class="error-text">{{ validationError }}</span>
          </div>

          <!-- Form Bottom Toolbar -->
          <div class="comment-form-footer">
            <div class="footer-left">
              <span
                class="char-counter"
                :class="{
                  'char-counter--warning': contentLength > 1800 && contentLength <= MAX_CONTENT_LENGTH,
                  'char-counter--danger': isContentTooLong,
                }"
                aria-live="polite"
              >
                {{ contentLength }} / {{ MAX_CONTENT_LENGTH }}
              </span>
              <span class="shortcut-hint" aria-hidden="true">
                Nhấn <kbd>Ctrl</kbd> + <kbd>Enter</kbd> để gửi
              </span>
            </div>

            <div class="footer-actions">
              <!-- Cancel button in reply mode -->
              <button
                v-if="mode === 'reply'"
                type="button"
                class="btn-cancel"
                :disabled="isSubmitting"
                @click="emit('cancel')"
              >
                Hủy
              </button>

              <!-- Submit button -->
              <button
                type="submit"
                class="btn-submit"
                :class="{ 'btn-submit--reply': mode === 'reply' }"
                :disabled="isSubmitDisabled"
                :aria-busy="isSubmitting"
              >
                <span v-if="isSubmitting" class="spinner-mini" aria-hidden="true"></span>
                <span>{{ submitButtonText }}</span>
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.comment-form-container {
  width: 100%;
  border-radius: var(--radius-lg);
  transition: all var(--transition-normal, 0.2s ease);
}

/* Guest Authentication Banner */
.guest-auth-prompt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: var(--color-bg-card);
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-lg);
}

.guest-prompt-icon {
  font-size: 1.75rem;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-full);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
}

.guest-prompt-body {
  flex: 1;
}

.guest-prompt-title {
  margin: 0 0 2px 0;
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.guest-prompt-desc {
  margin: 0;
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  line-height: var(--line-height-normal);
}

.btn-guest-login {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: 8px 16px;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  white-space: nowrap;
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-guest-login:hover {
  background-color: var(--color-primary-hover);
  box-shadow: 0 0 12px var(--color-primary-glow);
  transform: translateY(-1px);
}

.btn-guest-login:active {
  transform: translateY(0);
}

.btn-arrow {
  font-size: 1.1em;
  transition: transform var(--transition-fast, 0.15s ease);
}

.btn-guest-login:hover .btn-arrow {
  transform: translateX(2px);
}

/* Authenticated Form Wrapper */
.comment-form-wrapper {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-md);
  transition: border-color var(--transition-fast, 0.15s ease);
}

.comment-form-container.mode-reply .comment-form-wrapper {
  background-color: var(--color-bg-surface);
  border-color: var(--color-border-subtle);
  border-left: 3px solid var(--color-primary);
  padding: var(--spacing-sm) var(--spacing-md);
}

/* Reply Header Mode Banner */
.reply-mode-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--spacing-sm);
  padding-bottom: var(--spacing-xs);
  border-bottom: 1px solid var(--color-border);
}

.reply-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-primary);
}

.reply-icon {
  font-size: 1.1em;
  transform: scaleX(-1);
}

.btn-reply-cancel-top {
  background: none;
  border: none;
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
  cursor: pointer;
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-reply-cancel-top:hover:not(:disabled) {
  color: var(--color-danger);
  background-color: var(--color-danger-soft);
}

/* Comment Form Layout */
.comment-form {
  display: flex;
  gap: var(--spacing-md);
  align-items: flex-start;
}

/* Avatar Column */
.comment-avatar-col {
  flex-shrink: 0;
  padding-top: 2px;
}

.user-avatar {
  width: 40px;
  height: 40px;
  border-radius: var(--radius-full);
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-border-subtle);
}

.mode-reply .user-avatar {
  width: 32px;
  height: 32px;
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-initial {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
}

.mode-reply .avatar-initial {
  font-size: var(--font-size-sm);
}

/* Input Area Column */
.comment-input-col {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.textarea-box {
  width: 100%;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  transition: all var(--transition-fast, 0.15s ease);
}

.textarea-box:focus-within {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary-soft);
}

.textarea-box.has-error {
  border-color: var(--color-danger);
}

.textarea-box.has-error:focus-within {
  box-shadow: 0 0 0 2px var(--color-danger-soft);
}

.textarea-box.is-disabled {
  opacity: 0.65;
  background-color: var(--color-bg);
  cursor: not-allowed;
}

.comment-textarea {
  width: 100%;
  background: transparent;
  border: none;
  outline: none;
  color: var(--color-text-primary);
  font-family: var(--font-family-sans);
  font-size: var(--font-size-sm);
  line-height: var(--line-height-normal);
  padding: 10px 12px;
  resize: none;
  box-sizing: border-box;
  display: block;
}

.comment-textarea::placeholder {
  color: var(--color-text-muted);
}

.comment-textarea:disabled {
  cursor: not-allowed;
}

/* Cooldown Banner */
.cooldown-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background-color: var(--color-warning-soft);
  border: 1px solid var(--color-warning);
  border-radius: var(--radius-sm);
  color: var(--color-warning);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
}

.cooldown-icon {
  font-size: 1.1em;
}

.cooldown-seconds {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-bold);
}

/* Validation Error Banner */
.validation-error-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-sm);
  color: var(--color-danger);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
}

.error-icon {
  font-size: 1.1em;
}

/* Footer Toolbar */
.comment-form-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: var(--spacing-2xs);
  gap: var(--spacing-sm);
  flex-wrap: wrap;
}

.footer-left {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.char-counter {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
}

.char-counter--warning {
  color: var(--color-warning);
  font-weight: var(--font-weight-medium);
}

.char-counter--danger {
  color: var(--color-danger);
  font-weight: var(--font-weight-bold);
}

.shortcut-hint {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  display: inline-block;
}

.shortcut-hint kbd {
  display: inline-block;
  padding: 1px 4px;
  font-size: 0.7rem;
  font-family: var(--font-family-mono);
  color: var(--color-text-secondary);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xs);
}

.footer-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  margin-left: auto;
}

.btn-cancel {
  padding: 6px 12px;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-cancel:hover:not(:disabled) {
  color: var(--color-text-primary);
  border-color: var(--color-border-subtle);
  background-color: var(--color-bg-elevated);
}

.btn-cancel:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-submit {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 16px;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-inverse);
  background-color: var(--color-primary);
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-submit:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
  box-shadow: 0 0 10px var(--color-primary-glow);
  transform: translateY(-1px);
}

.btn-submit:active:not(:disabled) {
  transform: translateY(0);
}

.btn-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}

.btn-submit--reply {
  padding: 6px 14px;
}

.spinner-mini {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(11, 15, 25, 0.3);
  border-top-color: var(--color-text-inverse);
  border-radius: var(--radius-full);
  animation: spin 0.8s linear infinite;
  display: inline-block;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Responsive Styles */
@media (max-width: 640px) {
  .guest-auth-prompt {
    flex-direction: column;
    text-align: center;
    align-items: center;
  }

  .btn-guest-login {
    width: 100%;
    justify-content: center;
  }

  .shortcut-hint {
    display: none;
  }

  .comment-avatar-col {
    display: none;
  }

  .comment-form-wrapper {
    padding: var(--spacing-sm);
  }

  .footer-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .btn-submit,
  .btn-cancel {
    flex: 1;
    justify-content: center;
  }
}
</style>
