<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps<{
  isSending: boolean
  error: string | null
}>()

const emit = defineEmits<{
  (e: 'send', body: string): void
}>()

const text = ref('')
const localError = ref<string | null>(null)

const trimmedText = computed(() => text.value.trim())
const charCount = computed(() => text.value.length)
const isOverLimit = computed(() => charCount.value > 2000)
const canSend = computed(() => {
  return trimmedText.value.length > 0 && !isOverLimit.value && !props.isSending
})

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    submitMessage()
  }
}

function submitMessage() {
  localError.value = null
  if (!trimmedText.value) {
    return
  }
  if (isOverLimit.value) {
    localError.value = 'Nội dung tin nhắn không được vượt quá 2000 ký tự.'
    return
  }
  if (props.isSending) {
    return
  }

  const messageToSend = text.value
  emit('send', messageToSend)
}

function clear() {
  text.value = ''
  localError.value = null
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

    <div class="composer-box">
      <textarea
        v-model="text"
        rows="1"
        placeholder="Nhập tin nhắn... (Nhấn Enter để gửi, Shift+Enter để xuống dòng)"
        class="composer-textarea"
        :disabled="isSending"
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
