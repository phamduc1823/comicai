<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import CreatorLayout from '@/layouts/CreatorLayout.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import RejectionReasonModal from '@/components/common/RejectionReasonModal.vue'
import { CreatorService } from '@/services/creatorService'
import { getErrorMessage } from '@/services/httpClient'
import type { CreatorComic, ApprovalStatus, ComicProgressStatus } from '@/types/creator'
import type { PaginationMeta } from '@/types/catalog'

const router = useRouter()

// Data states
const comics = ref<CreatorComic[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)
const successFeedback = ref<string | null>(null)

// Filters
const searchQuery = ref('')
const selectedApprovalStatus = ref<ApprovalStatus | ''>('')
const selectedProgressStatus = ref<ComicProgressStatus | ''>('')
const currentPage = ref(1)

// Search debounce
let debounceTimeout: ReturnType<typeof setTimeout> | null = null

// Modals state
const isActionLoading = ref(false)

// Confirm Modal
const confirmModalOpen = ref(false)
const confirmModalTitle = ref('')
const confirmModalMessage = ref('')
const confirmModalType = ref<'primary' | 'danger' | 'warning'>('primary')
const confirmModalAction = ref<(() => Promise<void>) | null>(null)

// Rejection Reason Modal
const rejectionModalOpen = ref(false)
const rejectionModalComic = ref<CreatorComic | null>(null)

// Toast message auto-clear
let feedbackTimeout: ReturnType<typeof setTimeout> | null = null
function showSuccessFeedback(msg: string) {
  successFeedback.value = msg
  if (feedbackTimeout) clearTimeout(feedbackTimeout)
  feedbackTimeout = setTimeout(() => {
    successFeedback.value = null
  }, 4000)
}

/**
 * Fetch comics list with current filters and pagination
 */
async function loadComics(page = currentPage.value) {
  isLoading.value = true
  errorMessage.value = null

  try {
    const response = await CreatorService.getComics({
      page,
      per_page: 15,
      approval_status: selectedApprovalStatus.value || undefined,
      status: selectedProgressStatus.value || undefined,
      q: searchQuery.value.trim() || undefined,
    })

    comics.value = response.data || []
    paginationMeta.value = response.meta || null
    currentPage.value = page
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(
      err,
      'Không thể tải danh sách truyện. Vui lòng kiểm tra kết nối mạng và thử lại.'
    )
  } finally {
    isLoading.value = false
  }
}

function handleSearchInput() {
  if (debounceTimeout) clearTimeout(debounceTimeout)
  debounceTimeout = setTimeout(() => {
    currentPage.value = 1
    loadComics(1)
  }, 350)
}

function handleFilterChange() {
  currentPage.value = 1
  loadComics(1)
}

function handlePageChange(page: number) {
  if (page < 1 || (paginationMeta.value && page > paginationMeta.value.last_page)) return
  loadComics(page)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// ---------------- Thao tác nghiệp vụ ----------------

/**
 * Gửi truyện duyệt (draft / rejected -> pending)
 */
function promptSubmitReview(comic: CreatorComic) {
  confirmModalTitle.value = 'Gửi duyệt tác phẩm'
  confirmModalMessage.value = `Bạn có chắc chắn muốn gửi bộ truyện "${comic.title}" tới Quản trị viên để kiểm duyệt? Bộ truyện sẽ không thể chỉnh sửa trong thời gian chờ duyệt.`
  confirmModalType.value = 'primary'
  confirmModalAction.value = async () => {
    if (isActionLoading.value) return
    isActionLoading.value = true
    errorMessage.value = null
    try {
      await CreatorService.submitReview(comic.id)
      confirmModalOpen.value = false
      showSuccessFeedback(`Đã gửi duyệt bộ truyện "${comic.title}" thành công.`)
      await loadComics(currentPage.value)
    } catch (err: unknown) {
      confirmModalOpen.value = false
      errorMessage.value = getErrorMessage(err, 'Gửi duyệt không thành công.')
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      isActionLoading.value = false
    }
  }
  confirmModalOpen.value = true
}

/**
 * Rút yêu cầu duyệt (pending -> draft)
 */
function promptCancelReview(comic: CreatorComic) {
  confirmModalTitle.value = 'Rút yêu cầu kiểm duyệt'
  confirmModalMessage.value = `Bạn có chắc chắn muốn rút lại yêu cầu duyệt cho bộ truyện "${comic.title}"? Trạng thái sẽ quay về "Bản nháp" để bạn có thể tiếp tục chỉnh sửa.`
  confirmModalType.value = 'warning'
  confirmModalAction.value = async () => {
    if (isActionLoading.value) return
    isActionLoading.value = true
    errorMessage.value = null
    try {
      await CreatorService.cancelReview(comic.id)
      confirmModalOpen.value = false
      showSuccessFeedback(`Đã rút yêu cầu duyệt bộ truyện "${comic.title}".`)
      await loadComics(currentPage.value)
    } catch (err: unknown) {
      confirmModalOpen.value = false
      errorMessage.value = getErrorMessage(err, 'Rút yêu cầu duyệt không thành công.')
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      isActionLoading.value = false
    }
  }
  confirmModalOpen.value = true
}

/**
 * Xóa truyện nháp hoặc bị từ chối
 */
function promptDeleteComic(comic: CreatorComic) {
  confirmModalTitle.value = 'Xóa tác phẩm truyện'
  confirmModalMessage.value = `Hành động này sẽ xóa vĩnh viễn bộ truyện "${comic.title}" cùng toàn bộ ảnh bìa liên quan. Hành động này KHÔNG THỂ hoàn tác!`
  confirmModalType.value = 'danger'
  confirmModalAction.value = async () => {
    if (isActionLoading.value) return
    isActionLoading.value = true
    errorMessage.value = null
    try {
      await CreatorService.deleteComic(comic.id)
      confirmModalOpen.value = false
      showSuccessFeedback(`Đã xóa bộ truyện "${comic.title}" thành công.`)
      await loadComics(currentPage.value)
    } catch (err: unknown) {
      confirmModalOpen.value = false
      errorMessage.value = getErrorMessage(err, 'Xóa bộ truyện không thành công.')
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      isActionLoading.value = false
    }
  }
  confirmModalOpen.value = true
}

/**
 * Mở modal xem lý do từ chối
 */
function openRejectionModal(comic: CreatorComic) {
  rejectionModalComic.value = comic
  rejectionModalOpen.value = true
}

function handleEditFromRejectionModal() {
  if (rejectionModalComic.value) {
    const id = rejectionModalComic.value.id
    rejectionModalOpen.value = false
    router.push(`/creator/comics/${id}/edit`)
  }
}

async function handleConfirmModal() {
  if (confirmModalAction.value && !isActionLoading.value) {
    await confirmModalAction.value()
  }
}

onMounted(() => {
  loadComics()
})
</script>

<template>
  <CreatorLayout>
    <div class="comics-management-page">
      <!-- Header -->
      <div class="page-header">
        <div class="header-info">
          <h1 class="page-title">Truyện của tôi</h1>
          <p class="page-subtitle">
            Quản lý tác phẩm truyện tranh, theo dõi tiến độ và trạng thái kiểm duyệt của hệ thống
          </p>
        </div>
        <div class="header-action">
          <RouterLink to="/creator/comics/create" class="btn-create-comic">
            <span class="btn-icon" aria-hidden="true">➕</span>
            <span>Đăng truyện mới</span>
          </RouterLink>
        </div>
      </div>

      <!-- Feedback notifications -->
      <div v-if="successFeedback" class="feedback-banner banner-success" role="status">
        <span class="banner-icon" aria-hidden="true">✓</span>
        <span>{{ successFeedback }}</span>
        <button
          type="button"
          class="banner-close-btn"
          aria-label="Đóng thông báo"
          @click="successFeedback = null"
        >
          ✕
        </button>
      </div>

      <div v-if="errorMessage" class="feedback-banner banner-error" role="alert">
        <span class="banner-icon" aria-hidden="true">⚠️</span>
        <span>{{ errorMessage }}</span>
        <button
          type="button"
          class="banner-close-btn"
          aria-label="Đóng thông báo"
          @click="errorMessage = null"
        >
          ✕
        </button>
      </div>

      <!-- Verified Statistics Summary Cards -->
      <div class="stats-row">
        <div class="stat-card">
          <span class="stat-label">Tổng số tác phẩm</span>
          <span class="stat-value">
            {{ paginationMeta ? paginationMeta.total : '—' }}
          </span>
          <span class="stat-desc">Dữ liệu thực tế thuộc tài khoản tác giả</span>
        </div>

        <div class="stat-card">
          <span class="stat-label">Hiển thị trang này</span>
          <span class="stat-value">{{ comics.length }}</span>
          <span class="stat-desc">
            {{ paginationMeta ? `Trang ${paginationMeta.current_page} / ${paginationMeta.last_page}` : '—' }}
          </span>
        </div>
      </div>

      <!-- Search & Filters Toolbar -->
      <div class="filter-toolbar">
        <div class="search-box">
          <span class="search-icon" aria-hidden="true">🔍</span>
          <input
            v-model="searchQuery"
            type="search"
            class="search-input"
            placeholder="Tìm theo tiêu đề truyện hoặc tên tác giả..."
            @input="handleSearchInput"
          />
        </div>

        <div class="filters-group">
          <!-- Approval Status Filter -->
          <div class="filter-item">
            <label for="approval-filter" class="filter-label">Kiểm duyệt:</label>
            <select
              id="approval-filter"
              v-model="selectedApprovalStatus"
              class="filter-select"
              @change="handleFilterChange"
            >
              <option value="">Tất cả trạng thái</option>
              <option value="draft">Bản nháp (draft)</option>
              <option value="pending">Chờ duyệt (pending)</option>
              <option value="approved">Đã duyệt (approved)</option>
              <option value="rejected">Bị từ chối (rejected)</option>
            </select>
          </div>

          <!-- Progress Status Filter -->
          <div class="filter-item">
            <label for="progress-filter" class="filter-label">Tiến độ:</label>
            <select
              id="progress-filter"
              v-model="selectedProgressStatus"
              class="filter-select"
              @change="handleFilterChange"
            >
              <option value="">Tất cả tiến độ</option>
              <option value="ongoing">Đang tiến hành</option>
              <option value="completed">Đã hoàn thành</option>
            </select>
          </div>

          <button
            type="button"
            class="btn-refresh"
            title="Làm mới danh sách"
            :disabled="isLoading"
            @click="loadComics(currentPage)"
          >
            ↻
          </button>
        </div>
      </div>

      <!-- Main Content Table / List -->
      <div class="table-container">
        <!-- Loading Skeleton -->
        <div v-if="isLoading" class="skeleton-wrapper" aria-busy="true" aria-label="Đang tải danh sách truyện">
          <div v-for="i in 4" :key="i" class="skeleton-row">
            <div class="skeleton-cover"></div>
            <div class="skeleton-info">
              <div class="skeleton-line line-title"></div>
              <div class="skeleton-line line-meta"></div>
            </div>
            <div class="skeleton-badge"></div>
            <div class="skeleton-actions"></div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-else-if="comics.length === 0" class="empty-state">
          <div class="empty-icon" aria-hidden="true">📚</div>
          <h3 class="empty-title">
            {{ searchQuery || selectedApprovalStatus || selectedProgressStatus ? 'Không tìm thấy truyện phù hợp' : 'Bạn chưa có bộ truyện nào' }}
          </h3>
          <p class="empty-desc">
            {{ searchQuery || selectedApprovalStatus || selectedProgressStatus
              ? 'Hãy thử thay đổi từ khóa tìm kiếm hoặc đặt lại các bộ lọc trạng thái.'
              : 'Hãy bắt đầu sáng tác và chia sẻ tác phẩm truyện tranh đầu tiên của bạn đến độc giả BlueToon!' }}
          </p>
          <RouterLink
            v-if="!searchQuery && !selectedApprovalStatus && !selectedProgressStatus"
            to="/creator/comics/create"
            class="btn-create-comic btn-empty-cta"
          >
            + Đăng truyện ngay
          </RouterLink>
        </div>

        <!-- Comics Table (Desktop) -->
        <div v-else class="table-responsive">
          <table class="comics-table">
            <thead>
              <tr>
                <th scope="col" class="th-cover">Ảnh bìa</th>
                <th scope="col" class="th-title">Tác phẩm</th>
                <th scope="col" class="th-status">Kiểm duyệt</th>
                <th scope="col" class="th-progress">Tiến độ</th>
                <th scope="col" class="th-stats">Chương & Lượt xem</th>
                <th scope="col" class="th-actions">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="comic in comics" :key="comic.id" class="comic-row">
                <!-- Cover Column -->
                <td class="td-cover">
                  <div class="cover-wrapper">
                    <img
                      v-if="comic.cover_url"
                      :src="comic.cover_url"
                      :alt="comic.title"
                      class="comic-cover-img"
                      loading="lazy"
                    />
                    <div v-else class="cover-placeholder" aria-hidden="true">📖</div>
                  </div>
                </td>

                <!-- Title & Meta Column -->
                <td class="td-title">
                  <div class="title-cell">
                    <div class="comic-title-line">
                      <span class="comic-name" :title="comic.title">{{ comic.title }}</span>
                      <span v-if="comic.alternative_titles" class="comic-alt-title" :title="comic.alternative_titles">
                        ({{ comic.alternative_titles }})
                      </span>
                    </div>

                    <div class="comic-author">
                      <span class="meta-label">Bút danh:</span>
                      <span class="meta-value">{{ comic.author_name }}</span>
                      <span class="meta-bullet">•</span>
                      <span class="age-badge">{{ comic.age_rating }}</span>
                    </div>

                    <!-- Genre chips -->
                    <div v-if="comic.genres && comic.genres.length > 0" class="genre-chips">
                      <span
                        v-for="genre in comic.genres"
                        :key="genre.id"
                        class="genre-chip"
                      >
                        {{ genre.name }}
                      </span>
                    </div>
                  </div>
                </td>

                <!-- Approval Status Column -->
                <td class="td-status">
                  <StatusBadge
                    :status="comic.approval_status"
                    :has-pending-changes="comic.pending_changes !== null"
                  />
                  <!-- View rejection reason button if rejected -->
                  <button
                    v-if="comic.approval_status === 'rejected'"
                    type="button"
                    class="btn-link-rejection"
                    @click="openRejectionModal(comic)"
                  >
                    Xem lý do từ chối ➜
                  </button>
                </td>

                <!-- Progress Column -->
                <td class="td-progress">
                  <StatusBadge :progress-status="comic.status" />
                </td>

                <!-- Chapters & Views Column -->
                <td class="td-stats">
                  <div class="stats-cell">
                    <div class="stat-line">
                      <span class="stat-num">{{ comic.chapters_count }}</span>
                      <span class="stat-name">chương</span>
                      <span v-if="comic.published_chapters_count > 0" class="stat-pub">
                        ({{ comic.published_chapters_count }} đã duyệt)
                      </span>
                    </div>
                    <div class="stat-sub">
                      <span>👁️ {{ comic.views_count.toLocaleString('vi-VN') }}</span>
                      <span>⭐ {{ comic.follows_count.toLocaleString('vi-VN') }}</span>
                    </div>
                  </div>
                </td>

                <!-- Actions Column -->
                <td class="td-actions">
                  <div class="action-buttons">
                    <!-- 1. Draft Actions -->
                    <template v-if="comic.approval_status === 'draft'">
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/edit`"
                        class="btn-action btn-edit"
                        title="Chỉnh sửa thông tin truyện"
                      >
                        Sửa
                      </RouterLink>
                      <button
                        type="button"
                        class="btn-action btn-submit"
                        title="Gửi truyện tới Quản trị viên để duyệt xuất bản"
                        @click="promptSubmitReview(comic)"
                      >
                        Gửi duyệt
                      </button>
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/chapters`"
                        class="btn-action btn-chapters"
                        title="Quản lý danh sách chương"
                      >
                        Chương
                      </RouterLink>
                      <button
                        type="button"
                        class="btn-action btn-delete"
                        title="Xóa bộ truyện nháp này"
                        @click="promptDeleteComic(comic)"
                      >
                        Xóa
                      </button>
                    </template>

                    <!-- 2. Pending Actions -->
                    <template v-else-if="comic.approval_status === 'pending'">
                      <button
                        type="button"
                        class="btn-action btn-cancel-review"
                        title="Rút lại yêu cầu duyệt để tiếp tục chỉnh sửa"
                        @click="promptCancelReview(comic)"
                      >
                        Rút duyệt
                      </button>
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/chapters`"
                        class="btn-action btn-chapters"
                        title="Quản lý danh sách chương"
                      >
                        Chương
                      </RouterLink>
                      <span class="pending-notice" title="Bộ truyện đang được thẩm định; không thể sửa/xóa lúc này">
                        Đang chờ duyệt
                      </span>
                    </template>

                    <!-- 3. Approved Actions -->
                    <template v-else-if="comic.approval_status === 'approved'">
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/edit`"
                        class="btn-action btn-edit"
                        title="Chỉnh sửa thông tin truyện"
                      >
                        Sửa
                      </RouterLink>
                      <RouterLink
                        :to="`/comics/${comic.slug}`"
                        target="_blank"
                        class="btn-action btn-view-web"
                        title="Xem trang công khai của bộ truyện trên website"
                      >
                        Xem web ↗
                      </RouterLink>
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/chapters`"
                        class="btn-action btn-chapters"
                        title="Quản lý danh sách chương"
                      >
                        Chương
                      </RouterLink>
                    </template>

                    <!-- 4. Rejected Actions -->
                    <template v-else-if="comic.approval_status === 'rejected'">
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/edit`"
                        class="btn-action btn-edit"
                        title="Sửa lại thông tin truyện theo góp ý"
                      >
                        Sửa lại
                      </RouterLink>
                      <button
                        type="button"
                        class="btn-action btn-submit"
                        title="Gửi duyệt lại cho Quản trị viên"
                        @click="promptSubmitReview(comic)"
                      >
                        Gửi duyệt
                      </button>
                      <RouterLink
                        :to="`/creator/comics/${comic.id}/chapters`"
                        class="btn-action btn-chapters"
                        title="Quản lý danh sách chương"
                      >
                        Chương
                      </RouterLink>
                      <button
                        type="button"
                        class="btn-action btn-delete"
                        title="Xóa bộ truyện bị từ chối này"
                        @click="promptDeleteComic(comic)"
                      >
                        Xóa
                      </button>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="paginationMeta && paginationMeta.last_page > 1" class="pagination-bar">
        <button
          type="button"
          class="page-nav-btn"
          :disabled="currentPage <= 1 || isLoading"
          @click="handlePageChange(currentPage - 1)"
        >
          ‹ Trang trước
        </button>

        <span class="pagination-info">
          Trang <strong>{{ paginationMeta.current_page }}</strong> / {{ paginationMeta.last_page }}
          (Tổng số {{ paginationMeta.total }} truyện)
        </span>

        <button
          type="button"
          class="page-nav-btn"
          :disabled="currentPage >= paginationMeta.last_page || isLoading"
          @click="handlePageChange(currentPage + 1)"
        >
          Trang sau ›
        </button>
      </div>

      <!-- Confirm Action Modal -->
      <ConfirmModal
        :is-open="confirmModalOpen"
        :title="confirmModalTitle"
        :message="confirmModalMessage"
        :type="confirmModalType"
        :is-loading="isActionLoading"
        @confirm="handleConfirmModal"
        @close="confirmModalOpen = false"
      />

      <!-- Rejection Reason Modal -->
      <RejectionReasonModal
        :is-open="rejectionModalOpen"
        :comic-title="rejectionModalComic?.title"
        :reason="rejectionModalComic?.rejection_reason || null"
        @close="rejectionModalOpen = false"
        @edit="handleEditFromRejectionModal"
      />
    </div>
  </CreatorLayout>
</template>

<style scoped>
.comics-management-page {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

/* Page Header */
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--spacing-md);
}

.page-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  letter-spacing: -0.02em;
}

.page-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 4px 0 0 0;
}

.btn-create-comic {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  padding: 10px 18px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  text-decoration: none;
  transition: all var(--transition-fast);
  box-shadow: 0 2px 8px rgba(56, 189, 248, 0.25);
}

.btn-create-comic:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

/* Feedback banners */
.feedback-banner {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) var(--spacing-md);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
}

.banner-success {
  background-color: var(--color-success-soft);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.3);
}

.banner-error {
  background-color: var(--color-danger-soft);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.banner-close-btn {
  margin-left: auto;
  background: none;
  border: none;
  color: currentColor;
  cursor: pointer;
  padding: 2px 6px;
  font-size: 13px;
  opacity: 0.8;
}

.banner-close-btn:hover {
  opacity: 1;
}

/* Stats Row */
.stats-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--spacing-md);
}

.stat-card {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-md) var(--spacing-lg);
  display: flex;
  flex-direction: column;
}

.stat-label {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.stat-value {
  font-size: 28px;
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
  margin: 4px 0 2px 0;
}

.stat-desc {
  font-size: 11px;
  color: var(--color-text-muted);
}

/* Filters & Toolbar */
.filter-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--spacing-md);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-md);
}

.search-box {
  flex: 1;
  min-width: 260px;
  position: relative;
  display: flex;
  align-items: center;
}

.search-icon {
  position: absolute;
  left: 12px;
  font-size: 14px;
  color: var(--color-text-muted);
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 9px 12px 9px 36px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  outline: none;
  transition: border-color var(--transition-fast);
}

.search-input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary-soft);
}

.filters-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.filter-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
}

.filter-select {
  padding: 8px 12px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  outline: none;
  cursor: pointer;
}

.filter-select:focus {
  border-color: var(--color-primary);
}

.btn-refresh {
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  border-radius: var(--radius-md);
  padding: 8px 12px;
  font-size: 16px;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-refresh:hover:not(:disabled) {
  color: var(--color-primary);
  border-color: var(--color-primary-soft);
}

/* Table container */
.table-container {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.table-responsive {
  overflow-x: auto;
}

.comics-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--font-size-sm);
  text-align: left;
}

.comics-table th {
  background-color: rgba(11, 15, 25, 0.7);
  padding: 12px var(--spacing-md);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-bottom: 1px solid var(--color-border);
}

.comic-row {
  border-bottom: 1px solid var(--color-border);
  transition: background-color var(--transition-fast);
}

.comic-row:hover {
  background-color: rgba(255, 255, 255, 0.02);
}

.comics-table td {
  padding: 12px var(--spacing-md);
  vertical-align: middle;
}

/* Cover column */
.cover-wrapper {
  width: 48px;
  height: 64px;
  border-radius: var(--radius-xs);
  overflow: hidden;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  flex-shrink: 0;
}

.comic-cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: var(--color-text-muted);
}

/* Title column */
.title-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-width: 320px;
}

.comic-name {
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  line-height: 1.3;
}

.comic-alt-title {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-left: 4px;
}

.comic-author {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  display: flex;
  align-items: center;
  gap: 4px;
}

.meta-bullet {
  color: var(--color-border-subtle);
}

.age-badge {
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 4px;
  background-color: var(--color-bg-elevated);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border);
}

.genre-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 2px;
}

.genre-chip {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
  background-color: rgba(56, 189, 248, 0.08);
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.2);
}

.btn-link-rejection {
  display: inline-block;
  margin-top: 6px;
  font-size: 11px;
  color: #f87171;
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  text-decoration: underline;
}

.btn-link-rejection:hover {
  color: #ef4444;
}

/* Stats column */
.stats-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-line {
  display: flex;
  align-items: baseline;
  gap: 4px;
}

.stat-num {
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.stat-pub {
  font-size: 11px;
  color: #34d399;
}

.stat-sub {
  font-size: 11px;
  color: var(--color-text-muted);
  display: flex;
  gap: var(--spacing-sm);
}

/* Actions column */
.action-buttons {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.btn-action {
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all var(--transition-fast);
  white-space: nowrap;
}

.btn-edit {
  background-color: var(--color-bg-elevated);
  border-color: var(--color-border);
  color: var(--color-text-primary);
}

.btn-edit:hover {
  background-color: rgba(255, 255, 255, 0.1);
  border-color: var(--color-border-subtle);
}

.btn-submit {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border-color: rgba(56, 189, 248, 0.3);
}

.btn-submit:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.btn-cancel-review {
  background-color: var(--color-warning-soft);
  color: #fbbf24;
  border-color: rgba(245, 158, 11, 0.3);
}

.btn-cancel-review:hover {
  background-color: #d97706;
  color: #fff;
}

.btn-view-web {
  background-color: rgba(16, 185, 129, 0.12);
  color: #34d399;
  border-color: rgba(16, 185, 129, 0.25);
}

.btn-view-web:hover {
  background-color: #10b981;
  color: #fff;
}

.btn-chapters {
  background-color: var(--color-bg-elevated);
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-chapters:hover {
  color: var(--color-text-primary);
  border-color: var(--color-primary-soft);
}

.btn-delete {
  background-color: var(--color-danger-soft);
  color: #f87171;
  border-color: rgba(239, 68, 68, 0.25);
}

.btn-delete:hover {
  background-color: #dc2626;
  color: #fff;
}

.pending-notice {
  font-size: 11px;
  color: var(--color-text-muted);
  font-style: italic;
}

/* Skeleton Loading */
.skeleton-wrapper {
  padding: var(--spacing-md);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.skeleton-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-sm);
  background-color: rgba(255, 255, 255, 0.02);
  border-radius: var(--radius-md);
}

.skeleton-cover {
  width: 48px;
  height: 64px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-xs);
  animation: pulse 1.5s infinite;
}

.skeleton-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.skeleton-line {
  height: 14px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-xs);
  animation: pulse 1.5s infinite;
}

.line-title {
  width: 40%;
}

.line-meta {
  width: 25%;
}

.skeleton-badge {
  width: 80px;
  height: 24px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-sm);
  animation: pulse 1.5s infinite;
}

.skeleton-actions {
  width: 140px;
  height: 32px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-sm);
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 0.6;
  }
  50% {
    opacity: 0.3;
  }
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 60px var(--spacing-lg);
  display: flex;
  flex-direction: column;
  align-items: center;
}

.empty-icon {
  font-size: 48px;
  margin-bottom: var(--spacing-md);
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  max-width: 440px;
  margin: 8px 0 var(--spacing-lg) 0;
  line-height: var(--line-height-relaxed);
}

.btn-empty-cta {
  padding: 10px 24px;
}

/* Pagination */
.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.page-nav-btn {
  padding: 8px 16px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.page-nav-btn:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.page-nav-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.pagination-info {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

.pagination-info strong {
  color: var(--color-text-primary);
}
</style>
