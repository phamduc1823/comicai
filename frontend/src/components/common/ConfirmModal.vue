<script setup lang="ts">
import { watch, onUnmounted } from 'vue'

const props = withDefaults(
  defineProps<{
    isOpen: boolean
    title: string
    message: string
    confirmText?: string
    cancelText?: string
    type?: 'primary' | 'danger' | 'warning'
    isLoading?: boolean
  }>(),
  {
    confirmText: 'Xác nhận',
    cancelText: 'Hủy bỏ',
    type: 'primary',
    isLoading: false,
  }
)

const emit = defineEmits<{
  (e: 'confirm'): void
  (e: 'close'): void
}>()

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen && !props.isLoading) {
    emit('close')
  }
}

watch(
  () => props.isOpen,
  (open) => {
    if (open) {
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
        :aria-labelledby="title ? 'modal-title' : undefined"
        @click.self="!isLoading && emit('close')"
      >
        <div class="modal-card" :class="`modal-${type}`">
          <!-- Modal Header -->
          <div class="modal-header">
            <div class="modal-icon-badge" aria-hidden="true">
              <span v-if="type === 'danger'">⚠️</span>
              <span v-else-if="type === 'warning'">⚡</span>
              <span v-else>💡</span>
            </div>
            <h3 id="modal-title" class="modal-title">{{ title }}</h3>
            <button
              v-if="!isLoading"
              type="button"
              class="modal-close-btn"
              aria-label="Đóng cửa sổ"
              @click="emit('close')"
            >
              ✕
            </button>
          </div>

          <!-- Modal Body -->
          <div class="modal-body">
            <p class="modal-message">{{ message }}</p>
            <slot />
          </div>

          <!-- Modal Footer Actions -->
          <div class="modal-footer">
            <button
              type="button"
              class="btn-cancel"
              :disabled="isLoading"
              @click="emit('close')"
            >
              {{ cancelText }}
            </button>
            <button
              type="button"
              class="btn-confirm"
              :class="`btn-${type}`"
              :disabled="isLoading"
              @click="emit('confirm')"
            >
              <span v-if="isLoading" class="spinner" aria-hidden="true"></span>
              <span>{{ isLoading ? 'Đang xử lý...' : confirmText }}</span>
            </button>
          </div>
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
  background-color: rgba(5, 9, 18, 0.78);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
}

.modal-card {
  width: 100%;
  max-width: 480px;
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

.modal-icon-badge {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  background-color: rgba(255, 255, 255, 0.05);
  flex-shrink: 0;
}

.modal-danger .modal-icon-badge {
  background-color: var(--color-danger-soft);
}

.modal-warning .modal-icon-badge {
  background-color: var(--color-warning-soft);
}

.modal-primary .modal-icon-badge {
  background-color: var(--color-primary-soft);
}

.modal-title {
  flex: 1;
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.modal-close-btn {
  background: none;
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

.modal-close-btn:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.modal-body {
  padding: var(--spacing-lg);
  color: var(--color-text-secondary);
  font-size: var(--font-size-base);
  line-height: var(--line-height-relaxed);
}

.modal-message {
  margin: 0;
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

.btn-confirm {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 18px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border: none;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-confirm:disabled,
.btn-cancel:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.btn-primary:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
}

.btn-danger {
  background-color: var(--color-danger);
  color: #fff;
}

.btn-danger:hover:not(:disabled) {
  background-color: #dc2626;
}

.btn-warning {
  background-color: var(--color-warning);
  color: var(--color-text-inverse);
}

.btn-warning:hover:not(:disabled) {
  background-color: #d97706;
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
