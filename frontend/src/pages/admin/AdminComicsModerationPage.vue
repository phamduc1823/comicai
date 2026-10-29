<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import AdminComicDetailModal from '@/components/admin/AdminComicDetailModal.vue'
import AdminComicDiffModal from '@/components/admin/AdminComicDiffModal.vue'
import AdminRejectModal from '@/components/admin/AdminRejectModal.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import { AdminService } from '@/services/adminService'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage } from '@/services/httpClient'
import type {
  AdminModerationComic,
  ModerationComicFilterType,
  ListAdminModerationComicsParams,
} from '@/types/admin'
import type { PaginationMeta, Genre, Tag } from '@/types/catalog'

// Data states
const comics = ref<AdminModerationComic[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)
const availableGenres = ref<Genre[]>([])
const availableTags = ref<Tag[]>([])

// Filter & query states
const filterType = ref<ModerationComicFilterType>('all')
const searchInput = ref('')
const debouncedQuery = ref('')
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
const selectedComicForDetail = ref<AdminModerationComic | null>(null)
const isDetailModalOpen = ref(false)

const selectedComicForDiff = ref<AdminModerationComic | null>(null)
const isDiffModalOpen = ref(false)
const diffActionError = ref<string | null>(null)

const selectedComicForReject = ref<AdminModerationComic | null>(null)
const rejectTargetType = ref<'comic' | 'comic_changes'>('comic')
const isRejectModalOpen = ref(false)
const isRejectSubmitting = ref(false)
const rejectErrorMessage = ref<string | null>(null)

const selectedComicForApprove = ref<AdminModerationComic | null>(null)
const approveActionType = ref<'comic' | 'comic_changes'>('comic')
const isApproveConfirmOpen = ref(false)
const isApproveSubmitting = ref(false)

// Context tracking for modal restoration on cancellation
const restoredParentModal = ref<'detail' | 'diff' | null>(null)

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

// Search debounce
let debounceTimer: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    debouncedQuery.value = val.trim()
    currentPage.value = 1
  }, 300)
})

function clearSearch() {
  searchInput.value = ''
  debouncedQuery.value = ''
  currentPage.value = 1
}

// Fetch comics moderation list
async function loadModerationComics() {
  const currentSeq = ++requestSequence
  isLoading.value = true
  generalError.value = null

  try {
    const params: ListAdminModerationComicsParams = {
      type: filterType.value,
      page: currentPage.value,
      per_page: perPage.value,
    }

    if (debouncedQuery.value) {
      params.q = debouncedQuery.value
    }

    const response = await AdminService.getModerationComics(params)

    // Ignore if another request was triggered afterwards
    if (currentSeq !== requestSequence) return

    comics.value = response.data
    paginationMeta.value = response.meta
  } catch (err: unknown) {
    if (currentSeq !== requestSequence) return
    generalError.value = getErrorMessage(
      err,
      'Không thể tải danh sách kiểm duyệt truyện. Vui lòng thử lại.'
    )
  } finally {
    if (currentSeq === requestSequence) {
      isLoading.value = false
    }
  }
}

// Initial catalog taxonomy load
async function loadTaxonomies() {
  try {
    const [genresRes, tagsRes] = await Promise.allSettled([
      CatalogService.getGenres(),
      CatalogService.getTags(),
    ])
    if (genresRes.status === 'fulfilled') {
      availableGenres.value = genresRes.value.data
    }
    if (tagsRes.status === 'fulfilled') {
      availableTags.value = tagsRes.value.data
    }
  } catch {
    // Non-fatal, diff modal has fallback IDs
  }
}

// Watch filters
watch([filterType, debouncedQuery, currentPage], () => {
  loadModerationComics()
})

function handleFilterChange(newType: ModerationComicFilterType) {
  if (filterType.value === newType) return
  filterType.value = newType
  currentPage.value = 1
}

function goToPage(page: number) {
  if (page < 1 || (paginationMeta.value && page > paginationMeta.value.last_page)) return
  currentPage.value = page
}

// Detail modal handlers
function openDetailModal(comic: AdminModerationComic) {
  selectedComicForDetail.value = comic
  isDetailModalOpen.value = true
}

function closeDetailModal() {
  isDetailModalOpen.value = false
  selectedComicForDetail.value = null
}

// Diff modal handlers
function openDiffModal(comic: AdminModerationComic) {
  // Ensure detail modal is closed so diff and detail never stack
  if (isDetailModalOpen.value) {
    isDetailModalOpen.value = false
    selectedComicForDetail.value = null
  }
  selectedComicForDiff.value = comic
  diffActionError.value = null
  isDiffModalOpen.value = true
}

function closeDiffModal() {
  isDiffModalOpen.value = false
  selectedComicForDiff.value = null
  diffActionError.value = null
}

// Approve modal handlers
function triggerApproveComic(comic: AdminModerationComic) {
  // Close parent modal before opening confirm modal to prevent backdrop stacking and Escape key conflicts
  if (isDetailModalOpen.value) {
    restoredParentModal.value = 'detail'
    isDetailModalOpen.value = false
  } else if (isDiffModalOpen.value) {
    restoredParentModal.value = 'diff'
    isDiffModalOpen.value = false
  } else {
    restoredParentModal.value = null
  }

  selectedComicForApprove.value = comic
  approveActionType.value = 'comic'
  isApproveConfirmOpen.value = true
}

function triggerApproveChanges(comic: AdminModerationComic) {
  // Close parent modal before opening confirm modal to prevent backdrop stacking and Escape key conflicts
  if (isDetailModalOpen.value) {
    restoredParentModal.value = 'detail'
    isDetailModalOpen.value = false
  } else if (isDiffModalOpen.value) {
    restoredParentModal.value = 'diff'
    isDiffModalOpen.value = false
  } else {
    restoredParentModal.value = null
  }

  selectedComicForApprove.value = comic
  approveActionType.value = 'comic_changes'
  isApproveConfirmOpen.value = true
}

const approveModalTitle = computed(() => {
  return approveActionType.value === 'comic_changes'
    ? 'Xác nhận duyệt bản chỉnh sửa'
    : 'Xác nhận phê duyệt truyện'
})

const approveModalMessage = computed(() => {
  if (!selectedComicForApprove.value) return ''
  return approveActionType.value === 'comic_changes'
    ? `Bạn có chắc chắn muốn phê duyệt và áp dụng các thay đổi metadata cho bộ truyện "${selectedComicForApprove.value.title}"? Dữ liệu mới sẽ được cập nhật công khai ngay lập tức.`
    : `Bạn có chắc chắn muốn phê duyệt bộ truyện "${selectedComicForApprove.value.title}"? Bộ truyện sẽ được chuyển sang trạng thái đã duyệt (approved) và có thể bắt đầu đăng chương.`
})

function handleCancelApprove() {
  if (isApproveSubmitting.value) return
  isApproveConfirmOpen.value = false
  selectedComicForApprove.value = null

  // Restore parent modal if action was triggered from inside it
  if (restoredParentModal.value === 'detail') {
    isDetailModalOpen.value = true
  } else if (restoredParentModal.value === 'diff') {
    isDiffModalOpen.value = true
  }
  restoredParentModal.value = null
}

async function handleConfirmApprove() {
  if (!selectedComicForApprove.value || isApproveSubmitting.value) return

  const targetComic = selectedComicForApprove.value
  const isChanges = approveActionType.value === 'comic_changes'
  isApproveSubmitting.value = true

  try {
    if (isChanges) {
      await AdminService.approveComicChanges(targetComic.id)
      showToast(`Đã duyệt bản chỉnh sửa của truyện "${targetComic.title}" thành công.`)
    } else {
      await AdminService.approveComic(targetComic.id)
      showToast(`Đã phê duyệt bộ truyện "${targetComic.title}" thành công.`)
    }

    isApproveConfirmOpen.value = false
    selectedComicForApprove.value = null
    restoredParentModal.value = null

    // Ensure parent modals are closed and cleaned
    closeDetailModal()
    closeDiffModal()

    // Reload list
    await loadModerationComics()
  } catch (err: unknown) {
    const errorMsg = getErrorMessage(
      err,
      isChanges
        ? 'Duyệt bản chỉnh sửa thất bại. Vui lòng thử lại.'
        : 'Phê duyệt bộ truyện thất bại. Vui lòng thử lại.'
    )
    if (isDiffModalOpen.value) {
      diffActionError.value = errorMsg
    }
    showToast(errorMsg, 'error')
  } finally {
    isApproveSubmitting.value = false
  }
}

// Reject modal handlers
function triggerRejectComic(comic: AdminModerationComic) {
  // Close parent modal before opening reject modal to prevent backdrop stacking and Escape key conflicts
  if (isDetailModalOpen.value) {
    restoredParentModal.value = 'detail'
    isDetailModalOpen.value = false
  } else if (isDiffModalOpen.value) {
    restoredParentModal.value = 'diff'
    isDiffModalOpen.value = false
  } else {
    restoredParentModal.value = null
  }

  selectedComicForReject.value = comic
  rejectTargetType.value = 'comic'
  rejectErrorMessage.value = null
  isRejectModalOpen.value = true
}

function triggerRejectChanges(comic: AdminModerationComic) {
  // Close parent modal before opening reject modal to prevent backdrop stacking and Escape key conflicts
  if (isDetailModalOpen.value) {
    restoredParentModal.value = 'detail'
    isDetailModalOpen.value = false
  } else if (isDiffModalOpen.value) {
    restoredParentModal.value = 'diff'
    isDiffModalOpen.value = false
  } else {
    restoredParentModal.value = null
  }

  selectedComicForReject.value = comic
  rejectTargetType.value = 'comic_changes'
  rejectErrorMessage.value = null
  isRejectModalOpen.value = true
}

const rejectModalTitle = computed(() => {
  return rejectTargetType.value === 'comic_changes'
    ? 'Từ chối bản chỉnh sửa truyện'
    : 'Từ chối phê duyệt bộ truyện'
})

function handleCancelReject() {
  if (isRejectSubmitting.value) return
  isRejectModalOpen.value = false
  selectedComicForReject.value = null
  rejectErrorMessage.value = null

  // Restore parent modal if action was triggered from inside it
  if (restoredParentModal.value === 'detail') {
    isDetailModalOpen.value = true
  } else if (restoredParentModal.value === 'diff') {
    isDiffModalOpen.value = true
  }
  restoredParentModal.value = null
}

async function handleConfirmReject(reason: string) {
  if (!selectedComicForReject.value || isRejectSubmitting.value) return

  const targetComic = selectedComicForReject.value
  const isChanges = rejectTargetType.value === 'comic_changes'
  isRejectSubmitting.value = true
  rejectErrorMessage.value = null

  try {
    if (isChanges) {
      await AdminService.rejectComicChanges(targetComic.id, { rejection_reason: reason })
      showToast(`Đã từ chối bản chỉnh sửa của truyện "${targetComic.title}".`)
    } else {
      await AdminService.rejectComic(targetComic.id, { rejection_reason: reason })
      showToast(`Đã từ chối bộ truyện "${targetComic.title}".`)
    }

    isRejectModalOpen.value = false
    selectedComicForReject.value = null
    restoredParentModal.value = null

    // Ensure parent modals are closed and cleaned
    closeDetailModal()
    closeDiffModal()

    // Reload list
    await loadModerationComics()
  } catch (err: unknown) {
    const errorMsg = getErrorMessage(
      err,
      isChanges
        ? 'Từ chối bản chỉnh sửa thất bại. Vui lòng thử lại.'
        : 'Từ chối bộ truyện thất bại. Vui lòng thử lại.'
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

// Lifecycle
onMounted(() => {
  loadTaxonomies()
  loadModerationComics()
})

onUnmounted(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
  if (toastTimer) clearTimeout(toastTimer)
})
</script>

<template>
  <AdminLayout>
    <div class="comics-moderation-page">
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
          <h1 class="page-title">Hàng đợi kiểm duyệt truyện tranh</h1>
          <p class="page-subtitle">
            Xét duyệt truyện mới đăng ký và các bản chỉnh sửa metadata của tác giả trước khi công khai trên BlueToon.
          </p>
        </div>

        <div class="header-right">
          <button
            type="button"
            class="btn btn-outline btn-refresh"
            :disabled="isLoading"
            aria-label="Tải lại danh sách"
            @click="loadModerationComics"
          >
            <span class="refresh-icon" :class="{ spinning: isLoading }" aria-hidden="true">🔄</span>
            <span>Làm mới</span>
          </button>
        </div>
      </div>

      <!-- Filter Tabs & Search Toolbar -->
      <div class="toolbar-card">
        <!-- Filter Tabs -->
        <div class="filter-tabs" role="tablist" aria-label="Bộ lọc hàng đợi">
          <button
            type="button"
            role="tab"
            class="tab-btn"
            :class="{ active: filterType === 'all' }"
            :aria-selected="filterType === 'all'"
            @click="handleFilterChange('all')"
          >
            <span>Tất cả</span>
          </button>
          <button
            type="button"
            role="tab"
            class="tab-btn"
            :class="{ active: filterType === 'new' }"
            :aria-selected="filterType === 'new'"
            @click="handleFilterChange('new')"
          >
            <span>Truyện mới</span>
          </button>
          <button
            type="button"
            role="tab"
            class="tab-btn"
            :class="{ active: filterType === 'changes' }"
            :aria-selected="filterType === 'changes'"
            @click="handleFilterChange('changes')"
          >
            <span>Bản chỉnh sửa</span>
          </button>
        </div>

        <!-- Search Input -->
        <div class="search-box">
          <span class="search-icon" aria-hidden="true">🔍</span>
          <input
            v-model="searchInput"
            type="text"
            class="search-input"
            placeholder="Tìm theo tên truyện hoặc tên tác giả..."
            aria-label="Tìm kiếm truyện trong hàng đợi"
          />
          <button
            v-if="searchInput"
            type="button"
            class="search-clear-btn"
            aria-label="Xóa từ khóa tìm kiếm"
            @click="clearSearch"
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
          @click="loadModerationComics"
        >
          Thử lại
        </button>
      </div>

      <!-- Main Moderation Content -->
      <div class="table-container">
        <!-- Loading State -->
        <div v-if="isLoading" class="loading-state" aria-live="polite">
          <div class="spinner" aria-hidden="true"></div>
          <span class="loading-text">Đang tải dữ liệu hàng đợi kiểm duyệt...</span>
        </div>

        <!-- Empty State -->
        <div v-else-if="comics.length === 0" class="empty-state">
          <div class="empty-icon" aria-hidden="true">📚</div>
          <h3 class="empty-title">Không có truyện nào trong hàng đợi</h3>
          <p v-if="debouncedQuery" class="empty-desc">
            Không tìm thấy truyện nào khớp với từ khóa "<strong>{{ debouncedQuery }}</strong>".
          </p>
          <p v-else-if="filterType === 'new'" class="empty-desc">
            Hiện không có truyện mới nào đang trong trạng thái chờ duyệt.
          </p>
          <p v-else-if="filterType === 'changes'" class="empty-desc">
            Hiện không có bộ truyện nào có bản chỉnh sửa metadata chờ duyệt.
          </p>
          <p v-else class="empty-desc">
            Hàng đợi kiểm duyệt hiện đang trống. Mọi tác phẩm gửi lên đã được xử lý xong!
          </p>

          <button
            v-if="debouncedQuery"
            type="button"
            class="btn btn-outline btn-sm empty-action"
            @click="clearSearch"
          >
            Xóa tìm kiếm
          </button>
        </div>

        <!-- Table View -->
        <div v-else class="table-responsive">
          <table class="moderation-table">
            <thead>
              <tr>
                <th scope="col" class="th-comic">Truyện tranh</th>
                <th scope="col" class="th-author">Tác giả & Người đăng</th>
                <th scope="col" class="th-type">Yêu cầu duyệt</th>
                <th scope="col" class="th-date">Thời gian</th>
                <th scope="col" class="th-actions">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in comics"
                :key="item.id"
                class="moderation-row"
              >
                <!-- Comic Info -->
                <td class="td-comic">
                  <div class="comic-cell">
                    <div class="cover-thumb" @click="openDetailModal(item)">
                      <img
                        v-if="item.cover_url || item.cover_path"
                        :src="item.cover_url || item.cover_path || ''"
                        :alt="item.title"
                        class="thumb-img"
                        loading="lazy"
                      />
                      <div v-else class="thumb-fallback">
                        <span>📖</span>
                      </div>
                    </div>
                    <div class="comic-text-meta">
                      <button
                        type="button"
                        class="comic-title-link"
                        :title="item.title"
                        @click="openDetailModal(item)"
                      >
                        {{ item.title }}
                      </button>
                      <span class="comic-slug font-mono">/{{ item.slug }}</span>
                      <div v-if="item.genres && item.genres.length > 0" class="genre-pill-row">
                        <span
                          v-for="g in item.genres.slice(0, 3)"
                          :key="g.id"
                          class="genre-mini-pill"
                        >
                          {{ g.name }}
                        </span>
                        <span v-if="item.genres.length > 3" class="genre-more">
                          +{{ item.genres.length - 3 }}
                        </span>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Author & Creator -->
                <td class="td-author">
                  <div class="author-cell">
                    <span class="author-name">
                      {{ item.author_name }}
                    </span>
                    <div class="creator-mini-profile">
                      <span class="creator-label">Đăng bởi:</span>
                      <span class="creator-name" :title="item.creator?.name">
                        {{ item.creator?.name || 'Tác giả ẩn danh' }}
                      </span>
                    </div>
                  </div>
                </td>

                <!-- Moderation Request Type -->
                <td class="td-type">
                  <div class="type-badge-container">
                    <!-- Case 1: Pending new comic -->
                    <span
                      v-if="item.approval_status === 'pending'"
                      class="request-pill pill-new"
                    >
                      <span class="dot-indicator dot-pending" aria-hidden="true">●</span>
                      <span>Truyện mới</span>
                    </span>

                    <!-- Case 2: Approved comic with pending changes -->
                    <span
                      v-else-if="item.approval_status === 'approved' && item.has_pending_changes"
                      class="request-pill pill-changes"
                    >
                      <span class="dot-indicator dot-changes" aria-hidden="true">●</span>
                      <span>Bản chỉnh sửa</span>
                    </span>

                    <!-- Fallback status -->
                    <StatusBadge
                      v-else
                      :status="item.approval_status"
                      :progress-status="item.status === 'hiatus' ? undefined : item.status"
                      :has-pending-changes="item.has_pending_changes"
                    />
                  </div>
                </td>

                <!-- Date -->
                <td class="td-date">
                  <div class="date-cell">
                    <span class="date-updated">{{ formatDate(item.updated_at) }}</span>
                    <span class="date-created">Tạo: {{ formatDate(item.created_at) }}</span>
                  </div>
                </td>

                <!-- Actions -->
                <td class="td-actions">
                  <div class="actions-button-wrap">
                    <!-- Button to view full details -->
                    <button
                      type="button"
                      class="btn-action btn-action-detail"
                      title="Xem toàn bộ thông tin chi tiết"
                      @click="openDetailModal(item)"
                    >
                      Chi tiết
                    </button>

                    <!-- Actions for Approved comic with Pending Changes -->
                    <template v-if="item.approval_status === 'approved' && item.has_pending_changes">
                      <button
                        type="button"
                        class="btn-action btn-action-diff"
                        title="So sánh chi tiết thay đổi"
                        @click="openDiffModal(item)"
                      >
                        So sánh
                      </button>
                      <button
                        type="button"
                        class="btn-action btn-action-reject"
                        title="Từ chối bản chỉnh sửa này"
                        @click="triggerRejectChanges(item)"
                      >
                        Từ chối
                      </button>
                      <button
                        type="button"
                        class="btn-action btn-action-approve"
                        title="Duyệt áp dụng bản chỉnh sửa"
                        @click="triggerApproveChanges(item)"
                      >
                        Duyệt đổi
                      </button>
                    </template>

                    <!-- Actions for Pending New Comic -->
                    <template v-else-if="item.approval_status === 'pending'">
                      <button
                        type="button"
                        class="btn-action btn-action-reject"
                        title="Từ chối duyệt bộ truyện này"
                        @click="triggerRejectComic(item)"
                      >
                        Từ chối
                      </button>
                      <button
                        type="button"
                        class="btn-action btn-action-approve"
                        title="Phê duyệt bộ truyện này"
                        @click="triggerApproveComic(item)"
                      >
                        Phê duyệt
                      </button>
                    </template>
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
            (Tổng cộng {{ paginationMeta.total.toLocaleString() }} bộ truyện)
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

    <!-- Comic Detail Modal -->
    <AdminComicDetailModal
      :is-open="isDetailModalOpen"
      :comic="selectedComicForDetail"
      @close="closeDetailModal"
      @approve="triggerApproveComic"
      @reject="triggerRejectComic"
      @view-diff="openDiffModal"
    />

    <!-- Comic Diff Modal -->
    <AdminComicDiffModal
      :is-open="isDiffModalOpen"
      :comic="selectedComicForDiff"
      :genres-list="availableGenres"
      :tags-list="availableTags"
      :action-error="diffActionError"
      @close="closeDiffModal"
      @approve-changes="triggerApproveChanges"
      @reject-changes="triggerRejectChanges"
    />

    <!-- Admin Reject Modal (Reusable for Comic and Comic Changes) -->
    <AdminRejectModal
      :is-open="isRejectModalOpen"
      :title="rejectModalTitle"
      :target-name="selectedComicForReject?.title"
      :target-type="rejectTargetType"
      :is-loading="isRejectSubmitting"
      :error-message="rejectErrorMessage"
      @confirm="handleConfirmReject"
      @close="handleCancelReject"
    />

    <!-- Confirm Modal for Approving (Comic or Changes) -->
    <ConfirmModal
      :is-open="isApproveConfirmOpen"
      :title="approveModalTitle"
      :message="approveModalMessage"
      confirm-text="Xác nhận duyệt"
      cancel-text="Hủy bỏ"
      type="primary"
      :is-loading="isApproveSubmitting"
      @confirm="handleConfirmApprove"
      @close="handleCancelApprove"
    />
  </AdminLayout>
</template>

<style scoped>
.comics-moderation-page {
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

.filter-tabs {
  display: flex;
  align-items: center;
  background-color: var(--color-bg-base);
  padding: 3px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  gap: 2px;
}

.tab-btn {
  background: transparent;
  border: none;
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  padding: 6px 14px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.tab-btn:hover {
  color: var(--color-text-primary);
}

.tab-btn.active {
  background-color: var(--color-bg-elevated);
  color: var(--color-primary);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
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

.th-comic {
  width: 35%;
}

.th-author {
  width: 20%;
}

.th-type {
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

/* Comic Cell */
.comic-cell {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
}

.cover-thumb {
  width: 48px;
  height: 64px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-base);
  flex-shrink: 0;
  cursor: pointer;
}

.thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform var(--transition-fast);
}

.cover-thumb:hover .thumb-img {
  transform: scale(1.05);
}

.thumb-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: var(--color-text-muted);
}

.comic-text-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.comic-title-link {
  background: transparent;
  border: none;
  padding: 0;
  text-align: left;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 280px;
  transition: color var(--transition-fast);
}

.comic-title-link:hover {
  color: var(--color-primary);
  text-decoration: underline;
}

.comic-slug {
  font-size: 11px;
  color: var(--color-text-muted);
}

.font-mono {
  font-family: monospace;
}

.genre-pill-row {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
}

.genre-mini-pill {
  font-size: 10px;
  padding: 1px 6px;
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text-secondary);
}

.genre-more {
  font-size: 10px;
  color: var(--color-text-muted);
}

/* Author Cell */
.author-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.author-name {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.creator-mini-profile {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--color-text-muted);
}

.creator-name {
  color: var(--color-text-secondary);
  font-weight: var(--font-weight-medium);
}

/* Type Cell */
.type-badge-container {
  display: flex;
  align-items: center;
}

.request-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: var(--radius-full);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
}

.dot-indicator {
  font-size: 8px;
  line-height: 1;
}

.pill-new {
  background-color: rgba(56, 189, 248, 0.12);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.dot-pending {
  color: var(--color-primary);
}

.pill-changes {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.dot-changes {
  color: var(--color-warning);
}

/* Date Cell */
.date-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.date-updated {
  font-size: var(--font-size-xs);
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.date-created {
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

.btn-action-detail {
  background-color: var(--color-bg-base);
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-action-detail:hover {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
}

.btn-action-diff {
  background-color: rgba(245, 158, 11, 0.15);
  border-color: rgba(245, 158, 11, 0.35);
  color: var(--color-warning);
}

.btn-action-diff:hover {
  background-color: var(--color-warning);
  color: #0f172a;
}

.btn-action-reject {
  background-color: rgba(239, 68, 68, 0.1);
  border-color: rgba(239, 68, 68, 0.3);
  color: var(--color-danger);
}

.btn-action-reject:hover {
  background-color: var(--color-danger);
  color: #ffffff;
}

.btn-action-approve {
  background-color: rgba(16, 185, 129, 0.15);
  border-color: rgba(16, 185, 129, 0.35);
  color: #34d399;
}

.btn-action-approve:hover {
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
