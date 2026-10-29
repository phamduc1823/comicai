<script setup lang="ts">
import { watch, onUnmounted } from 'vue'

const props = defineProps<{
  isOpen: boolean
  title?: string
  comicTitle?: string
  reason: string | null
  guideText?: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'edit'): void
}>()

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen) {
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
        aria-labelledby="rejection-modal-title"
        @click.self="emit('close')"
      >
        <div class="modal-card">
          <!-- Header -->
          <div class="modal-header">
            <div class="modal-icon-badge" aria-hidden="true">
              ⚠️
            </div>
            <div class="modal-header-text">
              <h3 id="rejection-modal-title" class="modal-title">
                {{ title || 'Lý do từ chối phê duyệt' }}
              </h3>
              <p v-if="comicTitle" class="comic-name">{{ comicTitle }}</p>
            </div>
            <button
              type="button"
              class="modal-close-btn"
              aria-label="Đóng cửa sổ"
              @click="emit('close')"
            >
              ✕
            </button>
          </div>

          <!-- Body -->
          <div class="modal-body">
            <div class="reason-banner">
              <span class="reason-label">Ghi chú từ Quản trị viên:</span>
              <div class="reason-content">
                {{ reason || 'Không có lý do chi tiết được ghi lại.' }}
              </div>
            </div>
            <p class="guide-text">
              {{ guideText || 'Bạn có thể cập nhật lại thông tin bộ truyện theo yêu cầu của Quản trị viên và gửi lại yêu cầu duyệt bất cứ lúc nào.' }}
            </p>
          </div>

          <!-- Footer -->
          <div class="modal-footer">
            <button
              type="button"
              class="btn-close"
              @click="emit('close')"
            >
              Đóng
            </button>
            <button
              type="button"
              class="btn-edit-action"
              @click="emit('edit')"
            >
              Chỉnh sửa & Gửi duyệt lại
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

.modal-icon-badge {
  width: 36px;
  height: 36px;
  border-radius: var(--radius-full);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  background-color: var(--color-danger-soft);
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

.comic-name {
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

.reason-banner {
  background-color: rgba(239, 68, 68, 0.08);
  border: 1px solid rgba(239, 68, 68, 0.25);
  border-radius: var(--radius-md);
  padding: var(--spacing-md);
}

.reason-label {
  display: block;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: #f87171;
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.reason-content {
  font-size: var(--font-size-sm);
  color: var(--color-text-primary);
  line-height: var(--line-height-relaxed);
  white-space: pre-wrap;
  word-break: break-word;
}

.guide-text {
  margin: 0;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  line-height: var(--line-height-normal);
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

.btn-close {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  background-color: transparent;
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  cursor: pointer;
}

.btn-close:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-edit-action {
  padding: 8px 18px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border: none;
  cursor: pointer;
  transition: background-color var(--transition-fast);
}

.btn-edit-action:hover {
  background-color: var(--color-primary-hover);
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
