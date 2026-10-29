<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import type { AdminModerationComic } from '@/types/admin'
import StatusBadge from '@/components/common/StatusBadge.vue'

const props = withDefaults(
  defineProps<{
    isOpen: boolean
    comic: AdminModerationComic | null
    isLoading?: boolean
  }>(),
  {
    isLoading: false,
  }
)

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'approve', comic: AdminModerationComic): void
  (e: 'reject', comic: AdminModerationComic): void
  (e: 'viewDiff', comic: AdminModerationComic): void
}>()

const coverLoadError = ref(false)
const bannerLoadError = ref(false)

function handleClose() {
  if (props.isLoading) return
  emit('close')
}

function handleApprove() {
  if (props.comic && !props.isLoading) {
    emit('approve', props.comic)
  }
}

function handleReject() {
  if (props.comic && !props.isLoading) {
    emit('reject', props.comic)
  }
}

function handleViewDiff() {
  if (props.comic && !props.isLoading) {
    emit('viewDiff', props.comic)
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen && !props.isLoading) {
    handleClose()
  }
}

watch(
  () => [props.isOpen, props.comic?.id],
  ([open]) => {
    if (open) {
      coverLoadError.value = false
      bannerLoadError.value = false
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

function formatDate(isoString: string | null | undefined): string {
  if (!isoString) return 'Chưa ghi nhận'
  try {
    const d = new Date(isoString)
    if (isNaN(d.getTime())) return isoString
    return new Intl.DateTimeFormat('vi-VN', {
      hour: '2-digit',
      minute: '2-digit',
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(d)
  } catch {
    return isoString
  }
}

const ageRatingLabel = computed(() => {
  switch (props.comic?.age_rating) {
    case 'all':
      return 'Mọi lứa tuổi (All)'
    case '13+':
      return '13+ (Thiếu niên)'
    case '16+':
      return '16+ (Trưởng thành)'
    case '18+':
      return '18+ (Người lớn)'
    default:
      return props.comic?.age_rating || 'Chưa xác định'
  }
})

const statusLabel = computed(() => {
  switch (props.comic?.status) {
    case 'ongoing':
      return 'Đang tiến hành'
    case 'completed':
      return 'Đã hoàn thành'
    case 'hiatus':
      return 'Tạm ngưng'
    default:
      return props.comic?.status || 'Chưa xác định'
  }
})

const coverDisplayUrl = computed(() => {
  if (!props.comic) return null
  return props.comic.cover_url || props.comic.cover_path
})

const bannerDisplayUrl = computed(() => {
  if (!props.comic) return null
  return props.comic.banner_url || props.comic.banner_path
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="isOpen && comic"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="comic-detail-title"
        @click.self="handleClose"
      >
        <div class="modal-card">
          <!-- Header -->
          <div class="modal-header">
            <div class="header-main">
              <div class="header-badges">
                <StatusBadge
                  :status="comic.approval_status"
                  :progress-status="comic.status === 'hiatus' ? undefined : comic.status"
                  :has-pending-changes="comic.has_pending_changes"
                />
                <span
                  v-if="comic.approval_status === 'pending'"
                  class="type-pill pill-new"
                >
                  Truyện mới chờ duyệt
                </span>
                <span
                  v-else-if="comic.has_pending_changes"
                  class="type-pill pill-changes"
                >
                  Bản chỉnh sửa chờ duyệt
                </span>
              </div>
              <h2 id="comic-detail-title" class="modal-title">
                {{ comic.title }}
              </h2>
              <p v-if="comic.alternative_titles" class="alternative-titles">
                Tên khác: {{ comic.alternative_titles }}
              </p>
            </div>

            <button
              type="button"
              class="modal-close-btn"
              aria-label="Đóng cửa sổ chi tiết"
              :disabled="isLoading"
              @click="handleClose"
            >
              ✕
            </button>
          </div>

          <!-- Body -->
          <div class="modal-body">
            <!-- Banner preview if available -->
            <div
              v-if="bannerDisplayUrl && !bannerLoadError"
              class="banner-preview"
            >
              <img
                :src="bannerDisplayUrl"
                :alt="`Banner ${comic.title}`"
                class="banner-img"
                @error="bannerLoadError = true"
              />
            </div>

            <div class="content-layout">
              <!-- Left Column: Cover Image & Creator -->
              <div class="media-column">
                <div class="cover-container">
                  <img
                    v-if="coverDisplayUrl && !coverLoadError"
                    :src="coverDisplayUrl"
                    :alt="comic.title"
                    class="cover-img"
                    @error="coverLoadError = true"
                  />
                  <div v-else class="cover-placeholder" aria-label="Không có ảnh bìa">
                    <span class="placeholder-icon" aria-hidden="true">📖</span>
                    <span class="placeholder-text">Không có ảnh bìa</span>
                  </div>
                </div>

                <!-- Creator Card -->
                <div class="creator-card">
                  <div class="creator-header-label">Người đăng tải (Creator)</div>
                  <div class="creator-info">
                    <div class="creator-avatar">
                      <img
                        v-if="comic.creator?.avatar_path"
                        :src="comic.creator.avatar_path"
                        :alt="comic.creator.name"
                        class="creator-avatar-img"
                      />
                      <span v-else class="creator-initial">
                        {{ comic.creator?.name ? comic.creator.name.charAt(0).toUpperCase() : 'C' }}
                      </span>
                    </div>
                    <div class="creator-meta">
                      <span class="creator-name">{{ comic.creator?.name || 'Không xác định' }}</span>
                      <span class="creator-id">User ID: #{{ comic.creator?.id || '—' }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Right Column: Metadata & Description -->
              <div class="info-column">
                <!-- Metadata Grid -->
                <div class="metadata-grid">
                  <div class="meta-item">
                    <span class="meta-label">Tác giả gốc</span>
                    <span class="meta-value">{{ comic.author_name }}</span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Slug định danh</span>
                    <span class="meta-value font-mono">{{ comic.slug }}</span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Độ tuổi phù hợp</span>
                    <span class="meta-value">{{ ageRatingLabel }}</span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Tình trạng phát hành</span>
                    <span class="meta-value">{{ statusLabel }}</span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Lượt xem / Theo dõi</span>
                    <span class="meta-value">
                      👁️ {{ comic.views_count.toLocaleString() }} · ⭐ {{ comic.follows_count.toLocaleString() }}
                    </span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Nổi bật (Featured)</span>
                    <span class="meta-value">{{ comic.is_featured ? 'Có (Hiển thị)' : 'Không' }}</span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Thời gian tạo</span>
                    <span class="meta-value">{{ formatDate(comic.created_at) }}</span>
                  </div>

                  <div class="meta-item">
                    <span class="meta-label">Cập nhật gần nhất</span>
                    <span class="meta-value">{{ formatDate(comic.updated_at) }}</span>
                  </div>

                  <div v-if="comic.approver" class="meta-item full-width">
                    <span class="meta-label">Người duyệt trước đó</span>
                    <span class="meta-value">
                      {{ comic.approver.name }}
                      <span v-if="comic.approved_at" class="meta-sub">
                        ({{ formatDate(comic.approved_at) }})
                      </span>
                    </span>
                  </div>
                </div>

                <!-- Rejection reason from previous review if any -->
                <div v-if="comic.rejection_reason" class="rejection-box" role="alert">
                  <div class="rejection-header">
                    <span class="rejection-icon" aria-hidden="true">⚠️</span>
                    <span class="rejection-title">Lý do từ chối trước đó:</span>
                  </div>
                  <p class="rejection-content">{{ comic.rejection_reason }}</p>
                </div>

                <!-- Genres -->
                <div class="tax-group">
                  <span class="tax-label">Thể loại:</span>
                  <div v-if="comic.genres && comic.genres.length > 0" class="tag-cloud">
                    <span
                      v-for="genre in comic.genres"
                      :key="genre.id"
                      class="genre-chip"
                    >
                      {{ genre.name }}
                    </span>
                  </div>
                  <span v-else class="text-muted">Chưa có thể loại nào</span>
                </div>

                <!-- Tags -->
                <div class="tax-group">
                  <span class="tax-label">Tags:</span>
                  <div v-if="comic.tags && comic.tags.length > 0" class="tag-cloud">
                    <span
                      v-for="tag in comic.tags"
                      :key="tag.id"
                      class="tag-chip"
                    >
                      #{{ tag.name }}
                    </span>
                  </div>
                  <span v-else class="text-muted">Chưa có thẻ tags</span>
                </div>

                <!-- Description -->
                <div class="description-section">
                  <span class="tax-label">Mô tả tóm tắt:</span>
                  <div class="description-box">
                    <p v-if="comic.description" class="description-text">
                      {{ comic.description }}
                    </p>
                    <p v-else class="text-muted italic">
                      Bộ truyện này chưa có mô tả.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer Actions -->
          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-outline"
              :disabled="isLoading"
              @click="handleClose"
            >
              Đóng
            </button>

            <!-- Actions for comic with pending changes -->
            <div
              v-if="comic.approval_status === 'approved' && comic.has_pending_changes"
              class="action-buttons-group"
            >
              <button
                type="button"
                class="btn btn-warning"
                :disabled="isLoading"
                @click="handleViewDiff"
              >
                <span>🔍 So sánh bản chỉnh sửa (Diff)</span>
              </button>
            </div>

            <!-- Actions for new pending comic -->
            <div
              v-else-if="comic.approval_status === 'pending'"
              class="action-buttons-group"
            >
              <button
                type="button"
                class="btn btn-danger"
                :disabled="isLoading"
                @click="handleReject"
              >
                <span>✕ Từ chối duyệt</span>
              </button>
              <button
                type="button"
                class="btn btn-success"
                :disabled="isLoading"
                @click="handleApprove"
              >
                <span>✓ Phê duyệt truyện</span>
              </button>
            </div>
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
  max-width: 860px;
  max-height: 90vh;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.7);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-lg) var(--spacing-xl);
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
}

.header-main {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
  flex: 1;
}

.header-badges {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-wrap: wrap;
}

.type-pill {
  display: inline-flex;
  align-items: center;
  font-size: var(--font-size-xs);
  padding: 2px 8px;
  border-radius: var(--radius-full);
  font-weight: var(--font-weight-semibold);
}

.pill-new {
  background-color: rgba(56, 189, 248, 0.15);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.pill-changes {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.modal-title {
  margin: 0;
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
}

.alternative-titles {
  margin: 0;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
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

.modal-body {
  padding: var(--spacing-xl);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

.banner-preview {
  width: 100%;
  height: 140px;
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-base);
}

.banner-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.content-layout {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: var(--spacing-xl);
}

@media (max-width: 680px) {
  .content-layout {
    grid-template-columns: 1fr;
  }
}

.media-column {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.cover-container {
  width: 100%;
  aspect-ratio: 3 / 4;
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-base);
  box-shadow: var(--shadow-sm);
}

.cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  color: var(--color-text-muted);
}

.placeholder-icon {
  font-size: 32px;
}

.placeholder-text {
  font-size: var(--font-size-xs);
}

.creator-card {
  padding: var(--spacing-sm);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
}

.creator-header-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
  margin-bottom: var(--spacing-xs);
}

.creator-info {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.creator-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.creator-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.creator-initial {
  color: var(--color-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
}

.creator-meta {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.creator-name {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.creator-id {
  font-size: 10px;
  color: var(--color-text-muted);
}

.info-column {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.metadata-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--spacing-sm) var(--spacing-md);
  padding: var(--spacing-md);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
}

@media (max-width: 500px) {
  .metadata-grid {
    grid-template-columns: 1fr;
  }
}

.meta-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.meta-item.full-width {
  grid-column: 1 / -1;
}

.meta-label {
  font-size: 11px;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.meta-value {
  font-size: var(--font-size-sm);
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.meta-sub {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.font-mono {
  font-family: monospace;
}

.rejection-box {
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: var(--radius-md);
}

.rejection-header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
}

.rejection-icon {
  font-size: 14px;
}

.rejection-title {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-danger);
}

.rejection-content {
  margin: 0;
  font-size: var(--font-size-xs);
  color: var(--color-text-primary);
  white-space: pre-wrap;
  word-break: break-word;
}

.tax-group {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.tax-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  font-weight: var(--font-weight-semibold);
}

.tag-cloud {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.genre-chip {
  padding: 3px 8px;
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
}

.tag-chip {
  padding: 3px 8px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
}

.text-muted {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.italic {
  font-style: italic;
}

.description-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.description-box {
  padding: var(--spacing-md);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  max-height: 200px;
  overflow-y: auto;
}

.description-text {
  margin: 0;
  font-size: var(--font-size-sm);
  line-height: var(--line-height-relaxed);
  color: var(--color-text-primary);
  white-space: pre-wrap;
  word-break: break-word;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md) var(--spacing-xl);
  border-top: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  gap: var(--spacing-md);
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
  opacity: 0.5;
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

.btn-warning {
  background-color: var(--color-warning);
  color: #0f172a;
  font-weight: var(--font-weight-semibold);
}

.btn-warning:hover:not(:disabled) {
  opacity: 0.9;
  box-shadow: 0 0 10px rgba(245, 158, 11, 0.4);
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

/* Modal Animations */
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
