<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import type { AdminModerationChapter } from '@/types/admin'

const props = withDefaults(
  defineProps<{
    isOpen: boolean
    chapter: AdminModerationChapter | null
    isLoading?: boolean
  }>(),
  {
    isLoading: false,
  }
)

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'approve', chapter: AdminModerationChapter): void
  (e: 'reject', chapter: AdminModerationChapter): void
}>()

const failedImageIds = ref<Set<number>>(new Set())

function resolveStorageUrl(url: string | null | undefined): string | null {
  if (!url) return null
  // Convert absolute http://localhost/storage/ or http://127.0.0.1/storage/ to relative /storage/
  // so that the Vite dev proxy reliably routes the request to port 8000
  if (url.startsWith('http://localhost/storage/')) {
    return url.replace('http://localhost/storage/', '/storage/')
  }
  if (url.startsWith('http://127.0.0.1/storage/')) {
    return url.replace('http://127.0.0.1/storage/', '/storage/')
  }
  return url
}

function handleImageError(id: number) {
  failedImageIds.value.add(id)
}

function handleClose() {
  if (props.isLoading) return
  emit('close')
}

function handleApprove() {
  if (props.chapter && isParentApproved.value && !props.isLoading) {
    emit('approve', props.chapter)
  }
}

function handleReject() {
  if (props.chapter && !props.isLoading) {
    emit('reject', props.chapter)
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen && !props.isLoading) {
    handleClose()
  }
}

watch(
  () => [props.isOpen, props.chapter?.id],
  ([open]) => {
    if (open) {
      failedImageIds.value.clear()
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

const sortedImages = computed(() => {
  if (!props.chapter?.images) return []
  return [...props.chapter.images].sort((a, b) => a.page_number - b.page_number)
})

const isParentApproved = computed(() => {
  return props.chapter?.comic?.approval_status === 'approved'
})

const parentStatusLabel = computed(() => {
  switch (props.chapter?.comic?.approval_status) {
    case 'approved':
      return 'Đã duyệt'
    case 'pending':
      return 'Chờ duyệt'
    case 'rejected':
      return 'Bị từ chối'
    case 'draft':
      return 'Bản nháp'
    default:
      return props.chapter?.comic?.approval_status || 'Chưa xác định'
  }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="isOpen && chapter"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="preview-chapter-title"
        @click.self="handleClose"
      >
        <div class="modal-card">
          <!-- Sticky Header -->
          <header class="modal-header">
            <div class="header-left">
              <div class="comic-breadcrumbs">
                <span class="comic-label">Truyện:</span>
                <span class="comic-name" :title="chapter.comic?.title">
                  {{ chapter.comic?.title || 'Không rõ truyện' }}
                </span>
                <span
                  class="parent-status-chip"
                  :class="isParentApproved ? 'chip-approved' : 'chip-warning'"
                >
                  {{ isParentApproved ? '✓ Truyện đã duyệt' : `⚠️ Truyện: ${parentStatusLabel}` }}
                </span>
              </div>
              <h2 id="preview-chapter-title" class="chapter-title">
                Chương {{ chapter.chapter_number }}<span v-if="chapter.title">: {{ chapter.title }}</span>
              </h2>
            </div>

            <div class="header-right">
              <span class="image-count-badge">
                📑 {{ sortedImages.length }} trang ảnh
              </span>
              <button
                type="button"
                class="modal-close-btn"
                aria-label="Đóng trình xem ảnh"
                :disabled="isLoading"
                @click="handleClose"
              >
                ✕
              </button>
            </div>
          </header>

          <!-- Parent Comic Not Approved Warning Alert -->
          <div
            v-if="!isParentApproved"
            class="parent-warning-alert"
            role="alert"
          >
            <span class="alert-icon" aria-hidden="true">⚠️</span>
            <div class="alert-content">
              <strong>Không thể duyệt xuất bản chương:</strong>
              Bộ truyện cha hiện đang ở trạng thái <em>"{{ parentStatusLabel }}"</em>.
              Quy tắc hệ thống bắt buộc bộ truyện phải được duyệt (approved) trước khi có thể duyệt chương.
            </div>
          </div>

          <!-- Webtoon Vertical Scroll Viewer -->
          <div class="webtoon-scroll-container">
            <!-- Empty images state -->
            <div v-if="sortedImages.length === 0" class="no-images-state">
              <span class="no-images-icon" aria-hidden="true">🖼️</span>
              <p class="no-images-title">Chương này chưa có trang ảnh nào</p>
              <p class="no-images-sub">
                Tác giả chưa tải lên ảnh trang Webtoon cho chương truyện này.
              </p>
            </div>

            <!-- Vertical Webtoon Image Strip -->
            <div v-else class="webtoon-strip">
              <div
                v-for="image in sortedImages"
                :key="image.id"
                class="page-wrapper"
              >
                <!-- Image Tag -->
                <img
                  v-if="!failedImageIds.has(image.id)"
                  :src="resolveStorageUrl(image.image_url || image.image_path) || ''"
                  :alt="`Trang ${image.page_number}`"
                  class="webtoon-page-img"
                  loading="lazy"
                  @error="handleImageError(image.id)"
                />

                <!-- Fallback if image fails to load -->
                <div v-else class="image-load-fallback" role="alert">
                  <span class="fallback-icon" aria-hidden="true">⚠️</span>
                  <div class="fallback-text">
                    <span class="fallback-page">Trang {{ image.page_number }}</span>
                    <span class="fallback-msg">Không thể tải ảnh từ máy chủ lưu trữ.</span>
                    <span class="fallback-path font-mono">{{ image.image_path }}</span>
                  </div>
                </div>

                <!-- Page Number Tag on Side -->
                <span class="page-indicator-pill">
                  Trang {{ image.page_number }}
                </span>
              </div>
            </div>
          </div>

          <!-- Sticky Footer Actions -->
          <footer class="modal-footer">
            <button
              type="button"
              class="btn btn-outline"
              :disabled="isLoading"
              @click="handleClose"
            >
              Đóng
            </button>

            <div class="action-buttons-group">
              <button
                type="button"
                class="btn btn-danger"
                :disabled="isLoading"
                title="Từ chối duyệt chương này"
                @click="handleReject"
              >
                <span>✕ Từ chối chương</span>
              </button>

              <button
                type="button"
                class="btn btn-success"
                :disabled="!isParentApproved || isLoading"
                :title="!isParentApproved ? 'Cần duyệt bộ truyện cha trước khi duyệt chương' : 'Phê duyệt xuất bản chương này'"
                @click="handleApprove"
              >
                <span>✓ Phê duyệt xuất bản</span>
              </button>
            </div>
          </footer>
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
  background-color: rgba(5, 9, 18, 0.9);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-sm);
  animation: fadeIn 0.2s ease;
}

.modal-card {
  width: 100%;
  max-width: 900px;
  height: 94vh;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.8);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Sticky Header */
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-xl);
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  flex-shrink: 0;
  z-index: 10;
}

.header-left {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.comic-breadcrumbs {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  flex-wrap: wrap;
}

.comic-label {
  text-transform: uppercase;
  font-size: 10px;
  letter-spacing: 0.05em;
}

.comic-name {
  color: var(--color-primary);
  font-weight: var(--font-weight-medium);
  max-width: 320px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.parent-status-chip {
  font-size: 10px;
  padding: 1px 8px;
  border-radius: var(--radius-full);
  font-weight: var(--font-weight-semibold);
}

.chip-approved {
  background-color: rgba(16, 185, 129, 0.15);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.3);
}

.chip-warning {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.chapter-title {
  margin: 0;
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.header-right {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  flex-shrink: 0;
}

.image-count-badge {
  font-size: var(--font-size-xs);
  padding: 4px 10px;
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  color: var(--color-text-secondary);
}

.modal-close-btn {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: var(--font-size-lg);
  cursor: pointer;
  padding: 4px 8px;
  border-radius: var(--radius-md);
  transition: all var(--transition-fast);
}

.modal-close-btn:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: rgba(255, 255, 255, 0.1);
}

/* Parent Warning Alert */
.parent-warning-alert {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) var(--spacing-xl);
  background-color: rgba(245, 158, 11, 0.15);
  border-bottom: 1px solid rgba(245, 158, 11, 0.35);
  color: #fbbf24;
  font-size: var(--font-size-xs);
  flex-shrink: 0;
}

.alert-icon {
  font-size: 16px;
  flex-shrink: 0;
}

.alert-content em {
  font-style: normal;
  text-decoration: underline;
}

/* Webtoon Vertical Scroll Container */
.webtoon-scroll-container {
  flex: 1;
  overflow-y: auto;
  background-color: #060911;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: var(--spacing-md) 0;
}

.webtoon-strip {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  max-width: 800px;
}

.page-wrapper {
  position: relative;
  width: 100%;
  margin: 0;
  padding: 0;
  display: flex;
  justify-content: center;
  background-color: #0b0f19;
}

.webtoon-page-img {
  width: 100%;
  height: auto;
  display: block;
  object-fit: contain;
}

.page-indicator-pill {
  position: absolute;
  bottom: 8px;
  right: 12px;
  font-size: 10px;
  background-color: rgba(0, 0, 0, 0.65);
  color: rgba(255, 255, 255, 0.8);
  padding: 2px 8px;
  border-radius: var(--radius-full);
  pointer-events: none;
  backdrop-filter: blur(2px);
}

.image-load-fallback {
  width: 100%;
  min-height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-xl);
  background-color: rgba(239, 68, 68, 0.08);
  border: 1px dashed rgba(239, 68, 68, 0.3);
  color: var(--color-danger);
  box-sizing: border-box;
}

.fallback-icon {
  font-size: 24px;
}

.fallback-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.fallback-page {
  font-weight: var(--font-weight-semibold);
  font-size: var(--font-size-sm);
}

.fallback-msg {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.fallback-path {
  font-size: 11px;
  color: var(--color-text-secondary);
}

.font-mono {
  font-family: monospace;
}

/* Empty State */
.no-images-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px var(--spacing-xl);
  text-align: center;
  gap: var(--spacing-xs);
  color: var(--color-text-muted);
}

.no-images-icon {
  font-size: 48px;
  margin-bottom: var(--spacing-xs);
}

.no-images-title {
  margin: 0;
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.no-images-sub {
  margin: 0;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* Sticky Footer */
.modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md) var(--spacing-xl);
  border-top: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  flex-shrink: 0;
  z-index: 10;
}

.action-buttons-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: var(--spacing-xs) var(--spacing-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border-radius: var(--radius-md);
  border: 1px solid transparent;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.btn-outline {
  background-color: transparent;
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-outline:hover:not(:disabled) {
  background-color: var(--color-bg-base);
  color: var(--color-text-primary);
}

.btn-danger {
  background-color: var(--color-danger);
  color: #ffffff;
}

.btn-danger:hover:not(:disabled) {
  background-color: #dc2626;
}

.btn-success {
  background-color: #10b981;
  color: #ffffff;
}

.btn-success:hover:not(:disabled) {
  background-color: #059669;
}

/* Animations */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

@keyframes slideUp {
  from {
    transform: translateY(20px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
</style>
