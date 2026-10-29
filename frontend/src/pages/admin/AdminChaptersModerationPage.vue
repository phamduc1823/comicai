<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import AdminChapterPreviewModal from '@/components/admin/AdminChapterPreviewModal.vue'
import AdminRejectModal from '@/components/admin/AdminRejectModal.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import { AdminService } from '@/services/adminService'
import { getErrorMessage } from '@/services/httpClient'
import type {
  AdminModerationChapter,
  ListAdminModerationChaptersParams,
} from '@/types/admin'
import type { PaginationMeta } from '@/types/catalog'

// Data states
const chapters = ref<AdminModerationChapter[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)

// Filter & query states
const comicIdInput = ref('')
const debouncedComicId = ref('')
const currentPage = ref(1)
const perPage = ref(15)

// Loading & UI states
const isLoading = ref(true)
const generalError = ref<string | null>(null)
const toastMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)
let toastTimer: ReturnType<typeof setTimeout> | null = null

// Concurrency control to discard stale responses
let requestSequence = 0

// Modals state
const selectedChapterForPreview = ref<AdminModerationChapter | null>(null)
const isPreviewModalOpen = ref(false)

const selectedChapterForReject = ref<AdminModerationChapter | null>(null)
const isRejectModalOpen = ref(false)
const isRejectSubmitting = ref(false)
const rejectErrorMessage = ref<string | null>(null)

const selectedChapterForApprove = ref<AdminModerationChapter | null>(null)
const isApproveConfirmOpen = ref(false)
const isApproveSubmitting = ref(false)

// Context tracking for modal restoration on cancellation
const restoredParentModal = ref<'preview' | null>(null)

// Toast notification helper
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { type, text }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

function dismissToast() {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = null
}

// Comic ID filter debounce
let debounceTimer: ReturnType<typeof setTimeout> | null = null
watch(comicIdInput, (val) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    debouncedComicId.value = val.trim()
    currentPage.value = 1
  }, 300)
})

function clearComicFilter() {
  comicIdInput.value = ''
  debouncedComicId.value = ''
  currentPage.value = 1
}

// Fetch chapters moderation queue
async function loadModerationChapters() {
  const currentSeq = ++requestSequence
  isLoading.value = true
  generalError.value = null

  try {
    const params: ListAdminModerationChaptersParams = {
      page: currentPage.value,
      per_page: perPage.value,
    }

    if (debouncedComicId.value) {
      const parsedId = parseInt(debouncedComicId.value, 10)
      if (!isNaN(parsedId) && parsedId > 0) {
        params.comic_id = parsedId
      }
    }

    const response = await AdminService.getModerationChapters(params)

    // Ignore if another request was triggered afterwards
    if (currentSeq !== requestSequence) return

    chapters.value = response.data
    paginationMeta.value = response.meta
  } catch (err: unknown) {
    if (currentSeq !== requestSequence) return
    generalError.value = getErrorMessage(
      err,
      'Không thể tải danh sách kiểm duyệt chương. Vui lòng thử lại.'
    )
  } finally {
    if (currentSeq === requestSequence) {
      isLoading.value = false
    }
  }
}

// Watch filters
watch([debouncedComicId, currentPage], () => {
  loadModerationChapters()
})

function goToPage(page: number) {
  if (page < 1 || (paginationMeta.value && page > paginationMeta.value.last_page)) return
  currentPage.value = page
}

// Preview modal handlers
function openPreviewModal(chapter: AdminModerationChapter) {
  selectedChapterForPreview.value = chapter
  isPreviewModalOpen.value = true
}

function closePreviewModal() {
  isPreviewModalOpen.value = false
  selectedChapterForPreview.value = null
}

// Approve modal handlers
function triggerApproveChapter(chapter: AdminModerationChapter) {
  // Validate parent comic condition before opening confirm modal
  if (chapter.comic?.approval_status !== 'approved') {
    showToast(
      `Không thể duyệt chương: Bộ truyện cha "${chapter.comic?.title || ''}" chưa được phê duyệt (trạng thái: ${chapter.comic?.approval_status || 'chưa rõ'}).`,
      'error'
    )
    return
  }

  // Close parent preview modal before opening confirm modal to prevent stacking
  if (isPreviewModalOpen.value) {
    restoredParentModal.value = 'preview'
    isPreviewModalOpen.value = false
  } else {
    restoredParentModal.value = null
  }

  selectedChapterForApprove.value = chapter
  isApproveConfirmOpen.value = true
}

const approveModalTitle = computed(() => {
  return 'Xác nhận phê duyệt xuất bản chương'
})

const approveModalMessage = computed(() => {
  if (!selectedChapterForApprove.value) return ''
  const ch = selectedChapterForApprove.value
  const comicTitle = ch.comic?.title || 'bộ truyện'
  return `Bạn có chắc chắn muốn phê duyệt xuất bản Chương ${ch.chapter_number} của bộ truyện "${comicTitle}"? Chương sẽ được hiển thị công khai ngay lập tức cho độc giả.`
})

function handleCancelApprove() {
  if (isApproveSubmitting.value) return
  isApproveConfirmOpen.value = false
  selectedChapterForApprove.value = null

  // Restore parent preview modal if action originated from inside it
  if (restoredParentModal.value === 'preview') {
    isPreviewModalOpen.value = true
  }
  restoredParentModal.value = null
}

async function handleConfirmApprove() {
  if (!selectedChapterForApprove.value || isApproveSubmitting.value) return

  const targetChapter = selectedChapterForApprove.value
  isApproveSubmitting.value = true

  try {
    await AdminService.approveChapter(targetChapter.id)
    showToast(`Đã phê duyệt xuất bản Chương ${targetChapter.chapter_number} thành công.`)

    isApproveConfirmOpen.value = false
    selectedChapterForApprove.value = null
    restoredParentModal.value = null

    closePreviewModal()
    await loadModerationChapters()
  } catch (err: unknown) {
    const errorMsg = getErrorMessage(
      err,
      'Phê duyệt chương truyện thất bại. Vui lòng thử lại.'
    )
    showToast(errorMsg, 'error')

    // If 422 indicates parent comic is not approved or status shifted, refresh list
    await loadModerationChapters()
  } finally {
    isApproveSubmitting.value = false
  }
}

// Reject modal handlers
function triggerRejectChapter(chapter: AdminModerationChapter) {
  // Close parent preview modal before opening reject modal to prevent stacking
  if (isPreviewModalOpen.value) {
    restoredParentModal.value = 'preview'
    isPreviewModalOpen.value = false
  } else {
    restoredParentModal.value = null
  }

  selectedChapterForReject.value = chapter
  rejectErrorMessage.value = null
  isRejectModalOpen.value = true
}

const rejectModalTitle = computed(() => {
  return 'Từ chối duyệt chương Webtoon'
})

function handleCancelReject() {
  if (isRejectSubmitting.value) return
  isRejectModalOpen.value = false
  selectedChapterForReject.value = null
  rejectErrorMessage.value = null

  // Restore parent preview modal if action originated from inside it
  if (restoredParentModal.value === 'preview') {
    isPreviewModalOpen.value = true
  }
  restoredParentModal.value = null
}

async function handleConfirmReject(reason: string) {
  if (!selectedChapterForReject.value || isRejectSubmitting.value) return

  const targetChapter = selectedChapterForReject.value
  isRejectSubmitting.value = true
  rejectErrorMessage.value = null

  try {
    await AdminService.rejectChapter(targetChapter.id, { rejection_reason: reason })
    showToast(`Đã từ chối duyệt Chương ${targetChapter.chapter_number}.`)

    isRejectModalOpen.value = false
    selectedChapterForReject.value = null
    restoredParentModal.value = null

    closePreviewModal()
    await loadModerationChapters()
  } catch (err: unknown) {
    const errorMsg = getErrorMessage(
      err,
      'Từ chối duyệt chương thất bại. Vui lòng thử lại.'
    )
    rejectErrorMessage.value = errorMsg
  } finally {
    isRejectSubmitting.value = false
  }
}

function formatDate(isoString: string | null | undefined): string {
  if (!isoString) return '—'
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

function getParentStatusBadge(status?: string) {
  switch (status) {
    case 'approved':
      return { label: 'Truyện đã duyệt', className: 'status-approved', icon: '✓' }
    case 'pending':
      return { label: 'Truyện chờ duyệt', className: 'status-warning', icon: '⏳' }
    case 'rejected':
      return { label: 'Truyện bị từ chối', className: 'status-rejected', icon: '✕' }
    case 'draft':
      return { label: 'Truyện bản nháp', className: 'status-draft', icon: '📝' }
    default:
      return { label: status || 'Không rõ', className: 'status-draft', icon: '•' }
  }
}

onMounted(() => {
  loadModerationChapters()
})

onUnmounted(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
  if (toastTimer) clearTimeout(toastTimer)
})
</script>

<template>
  <AdminLayout>
    <div class="chapters-moderation-page">
      <!-- Toast Alert Notification -->
      <Transition name="fade">
        <div
          v-if="toastMessage"
          class="toast-alert"
          :class="'toast-' + toastMessage.type"
          role="status"
          aria-live="polite"
        >
          <span class="toast-icon" aria-hidden="true">
            {{ toastMessage.type === 'success' ? '✓' : '⚠️' }}
          </span>
          <span class="toast-text">{{ toastMessage.text }}</span>
          <button
            type="button"
            class="toast-close"
            aria-label="Đóng thông báo"
            @click="dismissToast"
          >
            ✕
          </button>
        </div>
      </Transition>

      <!-- Page Header -->
      <div class="page-header">
        <div class="header-left">
          <h1 class="page-title">Hàng đợi kiểm duyệt chương Webtoon</h1>
          <p class="page-subtitle">
            Xét duyệt nội dung và xem trước toàn bộ trang ảnh của các chương truyện đang chờ xuất bản.
          </p>
        </div>

        <div class="header-right">
          <button
            type="button"
            class="btn btn-outline btn-refresh"
            :disabled="isLoading"
            aria-label="Tải lại danh sách chương"
            @click="loadModerationChapters"
          >
            <span class="refresh-icon" :class="{ spinning: isLoading }" aria-hidden="true">🔄</span>
            <span>Làm mới</span>
          </button>
        </div>
      </div>

      <!-- Filter Toolbar -->
      <div class="toolbar-card">
        <div class="toolbar-info">
          <span class="queue-status-tag">Hàng đợi: Chỉ chương chờ duyệt (Status: Pending)</span>
        </div>

        <!-- Filter by Comic ID -->
        <div class="search-box">
          <span class="search-icon" aria-hidden="true">🔍</span>
          <input
            v-model="comicIdInput"
            type="text"
            class="search-input"
            placeholder="Lọc theo Comic ID (ví dụ: 1, 2)..."
            aria-label="Lọc theo Comic ID"
          />
          <button
            v-if="comicIdInput"
            type="button"
            class="search-clear-btn"
            aria-label="Xóa bộ lọc"
            @click="clearComicFilter"
          >
            ✕
          </button>
        </div>
      </div>

      <!-- Error State -->
      <div v-if="generalError" class="error-banner" role="alert">
        <span class="error-icon" aria-hidden="true">⚠️</span>
        <div class="error-message">
          <p class="error-text">{{ generalError }}</p>
        </div>
        <button
          type="button"
          class="btn btn-outline btn-sm"
          @click="loadModerationChapters"
        >
          Thử lại
        </button>
      </div>

      <!-- Main Moderation Content -->
      <div class="table-container">
        <!-- Loading State -->
        <div v-if="isLoading" class="loading-state" aria-live="polite">
          <div class="spinner" aria-hidden="true"></div>
          <span class="loading-text">Đang tải danh sách chương chờ duyệt...</span>
        </div>

        <!-- Empty State -->
        <div v-else-if="chapters.length === 0" class="empty-state">
          <div class="empty-icon" aria-hidden="true">📑</div>
          <h3 class="empty-title">Không có chương nào trong hàng đợi</h3>
          <p v-if="debouncedComicId" class="empty-desc">
            Không tìm thấy chương nào thuộc Comic ID #<strong>{{ debouncedComicId }}</strong> đang chờ duyệt.
          </p>
          <p v-else class="empty-desc">
            Hiện không có chương truyện Webtoon nào đang trong trạng thái chờ kiểm duyệt.
          </p>

          <button
            v-if="debouncedComicId"
            type="button"
            class="btn btn-outline btn-sm empty-action"
            @click="clearComicFilter"
          >
            Xóa bộ lọc Comic ID
          </button>
        </div>

        <!-- Table View -->
        <div v-else class="table-responsive">
          <table class="moderation-table">
            <thead>
              <tr>
                <th scope="col" class="th-chapter">Chương truyện</th>
                <th scope="col" class="th-comic">Tác phẩm cha</th>
                <th scope="col" class="th-images">Số lượng ảnh</th>
                <th scope="col" class="th-date">Thời gian gửi</th>
                <th scope="col" class="th-actions">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in chapters"
                :key="item.id"
                class="moderation-row"
              >
                <!-- Chapter Info -->
                <td class="td-chapter">
                  <div class="chapter-cell">
                    <button
                      type="button"
                      class="chapter-number-link"
                      :title="`Xem trước Chương ${item.chapter_number}`"
                      @click="openPreviewModal(item)"
                    >
                      Chương {{ item.chapter_number }}
                    </button>
                    <span v-if="item.title" class="chapter-title-text" :title="item.title">
                      {{ item.title }}
                    </span>
                    <span class="chapter-slug font-mono">/{{ item.slug }}</span>
                  </div>
                </td>

                <!-- Parent Comic Info -->
                <td class="td-comic">
                  <div class="comic-cell">
                    <div class="comic-header-line">
                      <span class="comic-title" :title="item.comic?.title">
                        {{ item.comic?.title || 'Không rõ truyện' }}
                      </span>
                      <span class="comic-id-tag">ID: #{{ item.comic?.id }}</span>
                    </div>

                    <div class="comic-creator-line">
                      <span class="creator-label">Đăng bởi:</span>
                      <span class="creator-name">{{ item.comic?.creator?.name || 'Tác giả ẩn danh' }}</span>
                    </div>

                    <!-- Parent Comic Status Badge & Warning -->
                    <div class="parent-status-wrap">
                      <span
                        class="parent-badge"
                        :class="getParentStatusBadge(item.comic?.approval_status).className"
                      >
                        <span aria-hidden="true">{{ getParentStatusBadge(item.comic?.approval_status).icon }}</span>
                        <span>{{ getParentStatusBadge(item.comic?.approval_status).label }}</span>
                      </span>

                      <span
                        v-if="item.comic?.approval_status !== 'approved'"
                        class="prerequisite-warning"
                        title="Bộ truyện cha chưa được duyệt. Không thể xuất bản chương này cho đến khi duyệt truyện."
                      >
                        ⚠️ Cần duyệt truyện trước
                      </span>
                    </div>
                  </div>
                </td>

                <!-- Images Count -->
                <td class="td-images">
                  <div class="images-cell">
                    <span class="images-count-pill">
                      📑 {{ item.images?.length ?? item.images_count }} trang
                    </span>
                  </div>
                </td>

                <!-- Date -->
                <td class="td-date">
                  <div class="date-cell">
                    <span class="date-created">{{ formatDate(item.created_at) }}</span>
                    <span v-if="item.updated_at && item.updated_at !== item.created_at" class="date-updated">
                      Cập nhật: {{ formatDate(item.updated_at) }}
                    </span>
                  </div>
                </td>

                <!-- Actions -->
                <td class="td-actions">
                  <div class="actions-button-wrap">
                    <!-- Button to preview webtoon images -->
                    <button
                      type="button"
                      class="btn-action btn-action-preview"
                      title="Xem trước toàn bộ ảnh cuộn dọc"
                      @click="openPreviewModal(item)"
                    >
                      Xem ảnh
                    </button>

                    <!-- Button to reject chapter -->
                    <button
                      type="button"
                      class="btn-action btn-action-reject"
                      title="Từ chối duyệt chương này"
                      @click="triggerRejectChapter(item)"
                    >
                      Từ chối
                    </button>

                    <!-- Button to approve chapter (disabled if parent comic not approved) -->
                    <button
                      type="button"
                      class="btn-action btn-action-approve"
                      :disabled="item.comic?.approval_status !== 'approved'"
                      :title="item.comic?.approval_status !== 'approved' ? 'Không thể duyệt xuất bản: Bộ truyện cha chưa được duyệt.' : 'Phê duyệt xuất bản chương này'"
                      @click="triggerApproveChapter(item)"
                    >
                      Phê duyệt
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Bar -->
        <div
          v-if="paginationMeta && paginationMeta.last_page > 1"
          class="pagination-bar"
        >
          <div class="pagination-info">
            Hiển thị trang {{ paginationMeta.current_page }} / {{ paginationMeta.last_page }}
            (Tổng cộng {{ paginationMeta.total.toLocaleString() }} chương)
          </div>

          <div class="pagination-controls">
            <button
              type="button"
              class="btn-page"
              :disabled="paginationMeta.current_page <= 1 || isLoading"
              @click="goToPage(paginationMeta.current_page - 1)"
            >
              ← Trước
            </button>

            <span class="current-page-badge">
              {{ paginationMeta.current_page }}
            </span>

            <button
              type="button"
              class="btn-page"
              :disabled="paginationMeta.current_page >= paginationMeta.last_page || isLoading"
              @click="goToPage(paginationMeta.current_page + 1)"
            >
              Sau →
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Chapter Preview Modal -->
    <AdminChapterPreviewModal
      :is-open="isPreviewModalOpen"
      :chapter="selectedChapterForPreview"
      @close="closePreviewModal"
      @approve="triggerApproveChapter"
      @reject="triggerRejectChapter"
    />

    <!-- Admin Reject Modal (targetType="chapter", 5-500 chars) -->
    <AdminRejectModal
      :is-open="isRejectModalOpen"
      :title="rejectModalTitle"
      :target-name="`Chương ${selectedChapterForReject?.chapter_number || ''}`"
      target-type="chapter"
      :is-loading="isRejectSubmitting"
      :error-message="rejectErrorMessage"
      @confirm="handleConfirmReject"
      @close="handleCancelReject"
    />

    <!-- Confirm Modal for Approving Chapter -->
    <ConfirmModal
      :is-open="isApproveConfirmOpen"
      :title="approveModalTitle"
      :message="approveModalMessage"
      confirm-text="Xác nhận xuất bản"
      cancel-text="Hủy bỏ"
      type="primary"
      :is-loading="isApproveSubmitting"
      @confirm="handleConfirmApprove"
      @close="handleCancelApprove"
    />
  </AdminLayout>
</template>

<style scoped>
.chapters-moderation-page {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
  position: relative;
}

/* Toast Alert */
.toast-alert {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 1100;
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-md) var(--spacing-lg);
  border-radius: var(--radius-md);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  animation: slideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.toast-success {
  background-color: #064e3b;
  color: #34d399;
  border: 1px solid #059669;
}

.toast-error {
  background-color: #7f1d1d;
  color: #fca5a5;
  border: 1px solid #dc2626;
}

.toast-icon {
  font-size: 16px;
  font-weight: bold;
}

.toast-text {
  flex: 1;
}

.toast-close {
  background: transparent;
  border: none;
  color: currentColor;
  cursor: pointer;
  padding: 2px 4px;
  opacity: 0.8;
  transition: opacity var(--transition-fast);
}

.toast-close:hover {
  opacity: 1;
}

/* Page Header */
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.header-left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.page-title {
  margin: 0;
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
}

.page-subtitle {
  margin: 0;
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

.btn-refresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.refresh-icon.spinning {
  animation: spin 1s linear infinite;
}

/* Toolbar Card */
.toolbar-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  flex-wrap: wrap;
}

.queue-status-tag {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  background-color: var(--color-bg-base);
  padding: 6px 12px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
}

.search-box {
  display: flex;
  align-items: center;
  gap: 8px;
  position: relative;
  min-width: 280px;
  max-width: 380px;
  flex: 1;
}

.search-icon {
  position: absolute;
  left: 12px;
  font-size: 13px;
  color: var(--color-text-muted);
  pointer-events: none;
}

.search-input {
  width: 100%;
  height: 36px;
  padding: 0 32px;
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-xs);
  outline: none;
  transition: border-color var(--transition-fast);
}

.search-input:focus {
  border-color: var(--color-primary);
}

.search-clear-btn {
  position: absolute;
  right: 8px;
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 12px;
  cursor: pointer;
  padding: 4px;
}

.search-clear-btn:hover {
  color: var(--color-text-primary);
}

/* Error Banner */
.error-banner {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: var(--radius-md);
  color: var(--color-danger);
}

.error-icon {
  font-size: 20px;
}

.error-message {
  flex: 1;
}

.error-text {
  margin: 0;
  font-size: var(--font-size-sm);
}

/* Table Container */
.table-container {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
}

.moderation-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.moderation-table th {
  padding: var(--spacing-md) var(--spacing-lg);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
  background-color: var(--color-bg-elevated);
  border-bottom: 1px solid var(--color-border);
}

.th-chapter {
  width: 25%;
}

.th-comic {
  width: 30%;
}

.th-images {
  width: 15%;
}

.th-date {
  width: 15%;
}

.th-actions {
  width: 15%;
  text-align: right;
}

.moderation-row {
  border-bottom: 1px solid var(--color-border);
  transition: background-color var(--transition-fast);
}

.moderation-row:hover {
  background-color: rgba(255, 255, 255, 0.02);
}

.moderation-table td {
  padding: var(--spacing-md) var(--spacing-lg);
  vertical-align: middle;
}

/* Chapter Cell */
.chapter-cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.chapter-number-link {
  background: transparent;
  border: none;
  padding: 0;
  text-align: left;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
  cursor: pointer;
  transition: color var(--transition-fast);
}

.chapter-number-link:hover {
  text-decoration: underline;
  color: var(--color-primary-hover);
}

.chapter-title-text {
  font-size: var(--font-size-xs);
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 220px;
}

.chapter-slug {
  font-size: 10px;
  color: var(--color-text-muted);
}

.font-mono {
  font-family: monospace;
}

/* Comic Cell */
.comic-cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.comic-header-line {
  display: flex;
  align-items: center;
  gap: 6px;
}

.comic-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  max-width: 220px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.comic-id-tag {
  font-size: 10px;
  color: var(--color-text-muted);
}

.comic-creator-line {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--color-text-muted);
}

.creator-name {
  color: var(--color-text-secondary);
}

.parent-status-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 2px;
}

.parent-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  padding: 1px 7px;
  border-radius: var(--radius-sm);
  font-weight: var(--font-weight-medium);
}

.status-approved {
  background-color: rgba(16, 185, 129, 0.15);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.3);
}

.status-warning {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.status-rejected {
  background-color: rgba(239, 68, 68, 0.15);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.status-draft {
  background-color: var(--color-bg-base);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border);
}

.prerequisite-warning {
  font-size: 10px;
  color: var(--color-warning);
  font-weight: var(--font-weight-medium);
}

/* Images Cell */
.images-cell {
  display: flex;
  align-items: center;
}

.images-count-pill {
  font-size: var(--font-size-xs);
  padding: 3px 10px;
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  color: var(--color-text-secondary);
  font-weight: var(--font-weight-medium);
}

/* Date Cell */
.date-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.date-created {
  font-size: var(--font-size-xs);
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.date-updated {
  font-size: 10px;
  color: var(--color-text-muted);
}

/* Actions Cell */
.td-actions {
  text-align: right;
}

.actions-button-wrap {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
  flex-wrap: wrap;
}

.btn-action {
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  border: 1px solid transparent;
  transition: all var(--transition-fast);
}

.btn-action:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.btn-action-preview {
  background-color: var(--color-bg-base);
  border-color: var(--color-border);
  color: var(--color-primary);
}

.btn-action-preview:hover:not(:disabled) {
  background-color: var(--color-primary-soft);
  border-color: var(--color-primary);
}

.btn-action-reject {
  background-color: rgba(239, 68, 68, 0.1);
  border-color: rgba(239, 68, 68, 0.3);
  color: var(--color-danger);
}

.btn-action-reject:hover:not(:disabled) {
  background-color: var(--color-danger);
  color: #ffffff;
}

.btn-action-approve {
  background-color: rgba(16, 185, 129, 0.15);
  border-color: rgba(16, 185, 129, 0.35);
  color: #34d399;
}

.btn-action-approve:hover:not(:disabled) {
  background-color: #10b981;
  color: #ffffff;
}

/* Loading & Empty States */
.loading-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px var(--spacing-xl);
  gap: var(--spacing-sm);
  text-align: center;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.loading-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

.empty-icon {
  font-size: 44px;
  line-height: 1;
  margin-bottom: var(--spacing-xs);
}

.empty-title {
  margin: 0;
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.empty-desc {
  margin: 0;
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  max-width: 440px;
}

.empty-action {
  margin-top: var(--spacing-sm);
}

/* Pagination Bar */
.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md) var(--spacing-xl);
  border-top: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  font-size: var(--font-size-xs);
  flex-wrap: wrap;
  gap: var(--spacing-md);
}

.pagination-info {
  color: var(--color-text-muted);
}

.pagination-controls {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.btn-page {
  padding: 4px 10px;
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-page:hover:not(:disabled) {
  background-color: var(--color-bg-surface);
  color: var(--color-text-primary);
  border-color: var(--color-primary);
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.current-page-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  padding: 0 6px;
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.3);
  border-radius: var(--radius-sm);
  font-weight: var(--font-weight-semibold);
}

/* General Button Utilities */
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

.btn-sm {
  padding: 4px 10px;
  font-size: var(--font-size-xs);
}

.btn-outline {
  background-color: transparent;
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-outline:hover:not(:disabled) {
  background-color: var(--color-bg-base);
  color: var(--color-text-primary);
  border-color: var(--color-primary);
}

/* Animations */
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes slideInRight {
  from {
    transform: translateX(30px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
