<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import { getErrorMessage } from '@/services/httpClient'
import type {
  Report,
  ReportableType,
  ReportStatus,
  UpdateReportPayload,
} from '@/types/report'

// Data States
const reports = ref<Report[]>([])
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Pagination & Filter States
const currentPage = ref(1)
const lastPage = ref(1)
const totalReports = ref(0)
const perPage = ref(15)

const statusFilter = ref<'all' | ReportStatus>('all')
const typeFilter = ref<'all' | ReportableType>('all')
const reasonFilter = ref<string>('')

// Stats
const countPending = ref(0)
const countResolved = ref(0)
const countDismissed = ref(0)

// Detail Modal
const selectedReport = ref<Report | null>(null)
const isDetailModalOpen = ref(false)

// Resolve / Action Modal
const isActionModalOpen = ref(false)
const actionReport = ref<Report | null>(null)
const actionStatus = ref<'resolved' | 'dismissed'>('resolved')
const actionAdminNotes = ref('')
const isActionSubmitting = ref(false)
const actionError = ref<string | null>(null)

// Toast Handler
let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

function dismissToast() {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = null
}

// Fetch Reports from API
async function fetchReports(page = currentPage.value) {
  isLoading.value = true
  errorMessage.value = null
  try {
    const params: Record<string, unknown> = {
      page,
      per_page: perPage.value,
    }
    if (statusFilter.value !== 'all') {
      params.status = statusFilter.value
    }
    if (typeFilter.value !== 'all') {
      params.reportable_type = typeFilter.value
    }
    if (reasonFilter.value.trim() !== '') {
      params.reason = reasonFilter.value.trim()
    }

    const res = await AdminService.getReports(params)
    reports.value = res.data || []
    currentPage.value = res.meta?.current_page || page
    lastPage.value = res.meta?.last_page || 1
    totalReports.value = res.meta?.total || 0

    // Compute basic local counts when on 'all' filter
    if (statusFilter.value === 'all' && typeFilter.value === 'all') {
      countPending.value = reports.value.filter((r) => r.status === 'pending').length
      countResolved.value = reports.value.filter((r) => r.status === 'resolved').length
      countDismissed.value = reports.value.filter((r) => r.status === 'dismissed').length
    }
  } catch (err: unknown) {
    reports.value = []
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách báo cáo vi phạm.')
  } finally {
    isLoading.value = false
  }
}

// Watch filters
watch([statusFilter, typeFilter], () => {
  currentPage.value = 1
  fetchReports(1)
})

function applyReasonFilter() {
  currentPage.value = 1
  fetchReports(1)
}

function resetFilters() {
  statusFilter.value = 'all'
  typeFilter.value = 'all'
  reasonFilter.value = ''
  currentPage.value = 1
  fetchReports(1)
}

// Navigation
function goToPage(page: number) {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return
  currentPage.value = page
  fetchReports(page)
}

// Open Modals
function openDetailModal(report: Report) {
  selectedReport.value = report
  isDetailModalOpen.value = true
}

function closeDetailModal() {
  isDetailModalOpen.value = false
  selectedReport.value = null
}

function openActionModal(report: Report) {
  actionReport.value = report
  actionStatus.value = 'resolved'
  actionAdminNotes.value = ''
  actionError.value = null
  isActionModalOpen.value = true
}

function closeActionModal() {
  if (isActionSubmitting.value) return
  isActionModalOpen.value = false
  actionReport.value = null
  actionAdminNotes.value = ''
  actionError.value = null
}

// Submit Resolve/Dismiss Action
async function handleActionSubmit() {
  if (!actionReport.value) return

  if (actionAdminNotes.value.trim().length > 1000) {
    actionError.value = 'Ghi chú quản trị viên không được vượt quá 1000 ký tự.'
    return
  }

  isActionSubmitting.value = true
  actionError.value = null

  try {
    const payload: UpdateReportPayload = {
      status: actionStatus.value,
      admin_notes: actionAdminNotes.value.trim() || null,
    }

    const res = await AdminService.updateReport(actionReport.value.id, payload)

    // Update in local array
    const idx = reports.value.findIndex((r) => r.id === actionReport.value?.id)
    if (idx !== -1 && res.data) {
      reports.value[idx] = res.data
    }

    // Also update selectedReport if currently viewed
    if (selectedReport.value && selectedReport.value.id === actionReport.value.id) {
      selectedReport.value = res.data
    }

    showToast(
      actionStatus.value === 'resolved'
        ? `Đã giải quyết báo cáo #${actionReport.value.id} thành công!`
        : `Đã bác bỏ báo cáo #${actionReport.value.id}.`,
      'success'
    )
    closeActionModal()
  } catch (err: unknown) {
    actionError.value = getErrorMessage(err, 'Không thể xử lý báo cáo. Vui lòng thử lại.')
  } finally {
    isActionSubmitting.value = false
  }
}

// Helpers
function formatTypeLabel(type: ReportableType): { label: string; icon: string; className: string } {
  switch (type) {
    case 'post':
      return { label: 'Bài viết', icon: '📝', className: 'badge-post' }
    case 'comment':
      return { label: 'Bình luận', icon: '💬', className: 'badge-comment' }
    case 'comic':
      return { label: 'Truyện', icon: '📚', className: 'badge-comic' }
    case 'chapter':
      return { label: 'Chương', icon: '📑', className: 'badge-chapter' }
    case 'user':
      return { label: 'Người dùng', icon: '👤', className: 'badge-user' }
    default:
      return { label: type, icon: '📌', className: 'badge-default' }
  }
}

function formatStatusBadge(status: ReportStatus): { label: string; icon: string; className: string } {
  switch (status) {
    case 'pending':
      return { label: 'Chờ xử lý', icon: '⏳', className: 'status-pending' }
    case 'resolved':
      return { label: 'Đã giải quyết', icon: '✅', className: 'status-resolved' }
    case 'dismissed':
      return { label: 'Đã bác bỏ', icon: '❌', className: 'status-dismissed' }
    default:
      return { label: status, icon: '•', className: 'status-default' }
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  const date = new Date(iso)
  if (isNaN(date.getTime())) return '—'
  return date.toLocaleString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function getTargetExcerpt(report: Report): string {
  if (!report.target) return 'ID #' + report.reportable_id
  if (!report.target.exists) return report.target.summary || 'Nội dung đã bị xóa hoặc không tồn tại'

  switch (report.reportable_type) {
    case 'post':
      return report.target.content ? `"${report.target.content}"` : '(Bài viết có hình ảnh)'
    case 'comment':
      return report.target.content ? `"${report.target.content}"` : '(Bình luận)'
    case 'comic':
      return report.target.title ? `Truyện: ${report.target.title}` : `Truyện #${report.reportable_id}`
    case 'chapter':
      return report.target.chapter_number
        ? `Chương ${report.target.chapter_number}${report.target.title ? ': ' + report.target.title : ''}`
        : `Chương #${report.reportable_id}`
    case 'user':
      return report.target.name ? `User: ${report.target.name}` : `User #${report.reportable_id}`
    default:
      return `ID #${report.reportable_id}`
  }
}

onMounted(() => {
  fetchReports(1)
})
</script>

<template>
  <AdminLayout>
    <div class="reports-page">
      <!-- Toast Notification -->
      <Transition name="toast">
        <div
          v-if="toastMessage"
          class="toast-alert"
          :class="`toast-${toastMessage.type}`"
          role="alert"
        >
          <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
          <span class="toast-text">{{ toastMessage.text }}</span>
          <button type="button" class="toast-close" @click="dismissToast">✕</button>
        </div>
      </Transition>

      <!-- Page Header -->
      <div class="page-header">
        <div class="header-left">
          <div class="header-badge-row">
            <span class="header-tag">Quản trị kiểm duyệt</span>
            <span class="header-count">{{ totalReports }} báo cáo</span>
          </div>
          <h1 class="page-title">Quản lý Báo cáo vi phạm</h1>
          <p class="page-subtitle">
            Tiếp nhận, kiểm tra và giải quyết khiếu nại, báo cáo vi phạm nội dung từ cộng đồng độc giả.
          </p>
        </div>
        <div class="header-actions">
          <button
            type="button"
            class="btn btn-secondary btn-refresh"
            :disabled="isLoading"
            title="Tải lại danh sách"
            @click="fetchReports(currentPage)"
          >
            <span class="btn-icon" :class="{ spinning: isLoading }">🔄</span>
            <span>Làm mới</span>
          </button>
        </div>
      </div>

      <!-- Quick Status Filter Tabs -->
      <div class="status-tabs-card">
        <div class="status-tabs">
          <button
            type="button"
            class="tab-btn"
            :class="{ active: statusFilter === 'all' }"
            @click="statusFilter = 'all'"
          >
            <span>Tất cả</span>
            <span class="tab-badge">{{ totalReports }}</span>
          </button>
          <button
            type="button"
            class="tab-btn tab-pending"
            :class="{ active: statusFilter === 'pending' }"
            @click="statusFilter = 'pending'"
          >
            <span>⏳ Chờ xử lý</span>
          </button>
          <button
            type="button"
            class="tab-btn tab-resolved"
            :class="{ active: statusFilter === 'resolved' }"
            @click="statusFilter = 'resolved'"
          >
            <span>✅ Đã giải quyết</span>
          </button>
          <button
            type="button"
            class="tab-btn tab-dismissed"
            :class="{ active: statusFilter === 'dismissed' }"
            @click="statusFilter = 'dismissed'"
          >
            <span>❌ Đã bác bỏ</span>
          </button>
        </div>

        <!-- Secondary Filter Controls -->
        <div class="filter-controls">
          <div class="filter-group">
            <label for="filter-type" class="filter-label">Loại nội dung:</label>
            <select
              id="filter-type"
              v-model="typeFilter"
              class="filter-select"
            >
              <option value="all">Tất cả các loại</option>
              <option value="post">📝 Bài viết (Post)</option>
              <option value="comment">💬 Bình luận (Comment)</option>
              <option value="comic">📚 Bộ truyện (Comic)</option>
              <option value="chapter">📑 Chương truyện (Chapter)</option>
              <option value="user">👤 Người dùng (User)</option>
            </select>
          </div>

          <div class="filter-group filter-search">
            <label for="filter-reason" class="filter-label">Lý do:</label>
            <div class="search-input-wrapper">
              <input
                id="filter-reason"
                v-model="reasonFilter"
                type="text"
                class="filter-input"
                placeholder="Nhập lý do cần tìm..."
                @keyup.enter="applyReasonFilter"
              />
              <button
                type="button"
                class="btn-search-submit"
                title="Lọc theo lý do"
                @click="applyReasonFilter"
              >
                🔍
              </button>
            </div>
          </div>

          <button
            v-if="statusFilter !== 'all' || typeFilter !== 'all' || reasonFilter"
            type="button"
            class="btn-reset-filters"
            title="Đặt lại bộ lọc"
            @click="resetFilters"
          >
            ✕ Xóa bộ lọc
          </button>
        </div>
      </div>

      <!-- Main Content Area -->
      <div class="table-container-card">
        <!-- Error State -->
        <div v-if="errorMessage" class="error-banner">
          <span class="error-icon">⚠️</span>
          <div class="error-content">
            <p class="error-text">{{ errorMessage }}</p>
            <button
              type="button"
              class="btn-retry"
              @click="fetchReports(currentPage)"
            >
              Thử lại
            </button>
          </div>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading" class="loading-state">
          <div class="spinner"></div>
          <p class="loading-text">Đang tải danh sách báo cáo vi phạm...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="reports.length === 0" class="empty-state">
          <div class="empty-icon">🛡️</div>
          <h2 class="empty-title">Không có báo cáo vi phạm nào</h2>
          <p class="empty-desc">
            {{
              statusFilter !== 'all' || typeFilter !== 'all' || reasonFilter
                ? 'Không tìm thấy báo cáo nào khớp với điều kiện lọc hiện tại.'
                : 'Hiện tại hệ thống không ghi nhận báo cáo vi phạm nào từ người dùng.'
            }}
          </p>
          <button
            v-if="statusFilter !== 'all' || typeFilter !== 'all' || reasonFilter"
            type="button"
            class="btn btn-secondary mt-3"
            @click="resetFilters"
          >
            Xóa bộ lọc
          </button>
        </div>

        <!-- Reports Data Table -->
        <div v-else class="table-responsive">
          <table class="reports-table">
            <thead>
              <tr>
                <th class="th-id">ID</th>
                <th class="th-type">Loại</th>
                <th class="th-target">Mục bị báo cáo</th>
                <th class="th-reason">Lý do & Mô tả</th>
                <th class="th-reporter">Người báo cáo</th>
                <th class="th-status">Trạng thái</th>
                <th class="th-time">Thời gian</th>
                <th class="th-actions text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="report in reports"
                :key="report.id"
                class="report-row"
                :class="{ 'row-pending': report.status === 'pending' }"
              >
                <!-- ID -->
                <td class="td-id">
                  <span class="id-badge">#{{ report.id }}</span>
                </td>

                <!-- Target Type -->
                <td class="td-type">
                  <span
                    class="type-badge"
                    :class="formatTypeLabel(report.reportable_type).className"
                  >
                    <span class="badge-icon">{{ formatTypeLabel(report.reportable_type).icon }}</span>
                    <span>{{ formatTypeLabel(report.reportable_type).label }}</span>
                  </span>
                </td>

                <!-- Target Content Excerpt -->
                <td class="td-target">
                  <div class="target-box">
                    <span
                      v-if="report.target && !report.target.exists"
                      class="tag-deleted"
                    >
                      Đã xóa
                    </span>
                    <span class="target-text" :title="getTargetExcerpt(report)">
                      {{ getTargetExcerpt(report) }}
                    </span>
                  </div>
                </td>

                <!-- Reason & Description -->
                <td class="td-reason">
                  <div class="reason-wrap">
                    <span class="reason-pill">{{ report.reason }}</span>
                    <p
                      v-if="report.description"
                      class="desc-preview"
                      :title="report.description"
                    >
                      {{ report.description }}
                    </p>
                  </div>
                </td>

                <!-- Reporter -->
                <td class="td-reporter">
                  <div v-if="report.reporter" class="user-cell">
                    <div class="avatar-sm">
                      <img
                        v-if="report.reporter.avatar_url"
                        :src="report.reporter.avatar_url"
                        :alt="report.reporter.name"
                        class="avatar-img"
                      />
                      <span v-else class="avatar-fallback">
                        {{ report.reporter.name.charAt(0).toUpperCase() }}
                      </span>
                    </div>
                    <div class="user-meta">
                      <span class="user-name">{{ report.reporter.name }}</span>
                      <span class="user-role-tag">{{ report.reporter.role }}</span>
                    </div>
                  </div>
                  <span v-else class="text-muted">Hệ thống</span>
                </td>

                <!-- Status -->
                <td class="td-status">
                  <span
                    class="status-pill"
                    :class="formatStatusBadge(report.status).className"
                  >
                    <span class="status-icon">{{ formatStatusBadge(report.status).icon }}</span>
                    <span>{{ formatStatusBadge(report.status).label }}</span>
                  </span>
                </td>

                <!-- Created At -->
                <td class="td-time">
                  <span class="time-text">{{ formatDate(report.created_at) }}</span>
                </td>

                <!-- Actions -->
                <td class="td-actions text-right">
                  <div class="action-buttons">
                    <button
                      type="button"
                      class="btn-action btn-view"
                      title="Xem chi tiết báo cáo"
                      @click="openDetailModal(report)"
                    >
                      👁️ Xem
                    </button>

                    <button
                      v-if="report.status === 'pending'"
                      type="button"
                      class="btn-action btn-resolve"
                      title="Xử lý báo cáo này"
                      @click="openActionModal(report)"
                    >
                      ⚖️ Xử lý
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Controls -->
        <div v-if="totalReports > perPage" class="pagination-bar">
          <div class="pagination-info">
            Trang <strong>{{ currentPage }}</strong> / <strong>{{ lastPage }}</strong>
            (Tổng <strong>{{ totalReports }}</strong> báo cáo)
          </div>
          <div class="pagination-buttons">
            <button
              type="button"
              class="btn-page"
              :disabled="currentPage <= 1 || isLoading"
              @click="goToPage(currentPage - 1)"
            >
              ◀ Trước
            </button>
            <button
              type="button"
              class="btn-page"
              :disabled="currentPage >= lastPage || isLoading"
              @click="goToPage(currentPage + 1)"
            >
              Sau ▶
            </button>
          </div>
        </div>
      </div>

      <!-- Detail Modal -->
      <Transition name="fade">
        <div v-if="isDetailModalOpen && selectedReport" class="modal-backdrop" @click="closeDetailModal">
          <div class="modal-card modal-detail" @click.stop>
            <div class="modal-header">
              <div class="modal-title-wrap">
                <span class="modal-icon">🚩</span>
                <h2 class="modal-title">Chi tiết Báo cáo vi phạm #{{ selectedReport.id }}</h2>
              </div>
              <button
                type="button"
                class="modal-close"
                aria-label="Đóng cửa sổ"
                @click="closeDetailModal"
              >
                ✕
              </button>
            </div>

            <div class="modal-body">
              <!-- Status & Meta Grid -->
              <div class="detail-grid">
                <div class="detail-item">
                  <span class="detail-label">Trạng thái:</span>
                  <span
                    class="status-pill"
                    :class="formatStatusBadge(selectedReport.status).className"
                  >
                    <span>{{ formatStatusBadge(selectedReport.status).icon }}</span>
                    <span>{{ formatStatusBadge(selectedReport.status).label }}</span>
                  </span>
                </div>

                <div class="detail-item">
                  <span class="detail-label">Loại đối tượng:</span>
                  <span
                    class="type-badge"
                    :class="formatTypeLabel(selectedReport.reportable_type).className"
                  >
                    <span>{{ formatTypeLabel(selectedReport.reportable_type).icon }}</span>
                    <span>{{ formatTypeLabel(selectedReport.reportable_type).label }}</span>
                  </span>
                </div>

                <div class="detail-item">
                  <span class="detail-label">Thời gian gửi:</span>
                  <span class="detail-value">{{ formatDate(selectedReport.created_at) }}</span>
                </div>

                <div class="detail-item">
                  <span class="detail-label">Người báo cáo:</span>
                  <div v-if="selectedReport.reporter" class="detail-user">
                    <strong>{{ selectedReport.reporter.name }}</strong>
                    <span class="user-role-tag ml-1">({{ selectedReport.reporter.role }})</span>
                  </div>
                  <span v-else class="detail-value">Hệ thống</span>
                </div>
              </div>

              <!-- Reason & Description -->
              <div class="detail-section">
                <h3 class="section-title">Lý do báo cáo</h3>
                <div class="reason-banner">
                  <span class="reason-tag">📌 {{ selectedReport.reason }}</span>
                </div>
                <div v-if="selectedReport.description" class="desc-box">
                  <p class="desc-title">Mô tả chi tiết từ người báo cáo:</p>
                  <p class="desc-content">{{ selectedReport.description }}</p>
                </div>
              </div>

              <!-- Reported Target Details -->
              <div class="detail-section">
                <h3 class="section-title">Nội dung đối tượng bị báo cáo</h3>
                <div class="target-detail-card">
                  <div class="target-header">
                    <span class="target-type-name">
                      {{ formatTypeLabel(selectedReport.reportable_type).label }} #{{ selectedReport.reportable_id }}
                    </span>
                    <span
                      v-if="selectedReport.target && !selectedReport.target.exists"
                      class="tag-deleted"
                    >
                      Đã bị xóa hoặc không còn tồn tại
                    </span>
                  </div>

                  <div v-if="selectedReport.target" class="target-body">
                    <!-- Post / Comment Content -->
                    <div v-if="selectedReport.target.content" class="content-quote">
                      <p class="quote-text">{{ selectedReport.target.content }}</p>
                    </div>

                    <!-- Comic / Chapter info -->
                    <div v-if="selectedReport.target.title" class="target-field">
                      <span class="field-label">Tiêu đề:</span>
                      <span class="field-value font-medium">{{ selectedReport.target.title }}</span>
                    </div>

                    <div v-if="selectedReport.target.chapter_number" class="target-field">
                      <span class="field-label">Chương số:</span>
                      <span class="field-value">{{ selectedReport.target.chapter_number }}</span>
                    </div>

                    <div v-if="selectedReport.target.name" class="target-field">
                      <span class="field-label">Tên người dùng:</span>
                      <span class="field-value">{{ selectedReport.target.name }}</span>
                    </div>

                    <div v-if="selectedReport.target.has_image" class="target-field">
                      <span class="field-label">Đính kèm ảnh:</span>
                      <span class="field-value">Có hình ảnh</span>
                    </div>
                  </div>
                  <div v-else class="text-muted italic p-3">
                    Không có thông tin chi tiết đối tượng.
                  </div>
                </div>
              </div>

              <!-- Resolution Info (if resolved/dismissed) -->
              <div
                v-if="selectedReport.status !== 'pending'"
                class="detail-section resolution-section"
              >
                <h3 class="section-title">Kết quả giải quyết</h3>
                <div class="resolution-card">
                  <div class="resolution-item">
                    <span class="detail-label">Người xử lý:</span>
                    <span class="detail-value font-medium">
                      {{ selectedReport.resolver?.name || 'Quản trị viên' }}
                    </span>
                  </div>
                  <div class="resolution-item">
                    <span class="detail-label">Thời gian xử lý:</span>
                    <span class="detail-value">{{ formatDate(selectedReport.resolved_at) }}</span>
                  </div>
                  <div v-if="selectedReport.admin_notes" class="resolution-notes">
                    <span class="detail-label">Ghi chú quản trị:</span>
                    <p class="admin-notes-text">{{ selectedReport.admin_notes }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button
                v-if="selectedReport.status === 'pending'"
                type="button"
                class="btn btn-primary"
                @click="openActionModal(selectedReport); closeDetailModal()"
              >
                ⚖️ Xử lý báo cáo này
              </button>
              <button
                type="button"
                class="btn btn-secondary"
                @click="closeDetailModal"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Action Modal (Resolve / Dismiss) -->
      <Transition name="fade">
        <div v-if="isActionModalOpen && actionReport" class="modal-backdrop" @click="closeActionModal">
          <div class="modal-card modal-action" @click.stop>
            <div class="modal-header">
              <div class="modal-title-wrap">
                <span class="modal-icon">⚖️</span>
                <h2 class="modal-title">Xử lý Báo cáo vi phạm #{{ actionReport.id }}</h2>
              </div>
              <button
                type="button"
                class="modal-close"
                aria-label="Đóng cửa sổ"
                :disabled="isActionSubmitting"
                @click="closeActionModal"
              >
                ✕
              </button>
            </div>

            <form @submit.prevent="handleActionSubmit" class="modal-form">
              <div class="modal-body">
                <!-- Target Overview -->
                <div class="action-overview">
                  <div class="overview-row">
                    <span class="overview-label">Đối tượng:</span>
                    <span class="type-badge" :class="formatTypeLabel(actionReport.reportable_type).className">
                      {{ formatTypeLabel(actionReport.reportable_type).label }} #{{ actionReport.reportable_id }}
                    </span>
                  </div>
                  <div class="overview-row">
                    <span class="overview-label">Lý do:</span>
                    <span class="overview-val font-semibold">{{ actionReport.reason }}</span>
                  </div>
                </div>

                <!-- Decision Select -->
                <div class="form-group mt-3">
                  <label class="form-label required">Quyết định xử lý:</label>
                  <div class="decision-options">
                    <label
                      class="decision-card"
                      :class="{ selected: actionStatus === 'resolved' }"
                    >
                      <input
                        v-model="actionStatus"
                        type="radio"
                        value="resolved"
                        name="action-decision"
                        class="sr-only"
                      />
                      <div class="decision-content">
                        <span class="decision-icon">✅</span>
                        <div class="decision-texts">
                          <span class="decision-title">Xác nhận vi phạm (Resolved)</span>
                          <span class="decision-desc">
                            Chấp thuận báo cáo. Nội dung đã xác định có vi phạm quy định cộng đồng.
                          </span>
                        </div>
                      </div>
                    </label>

                    <label
                      class="decision-card"
                      :class="{ selected: actionStatus === 'dismissed' }"
                    >
                      <input
                        v-model="actionStatus"
                        type="radio"
                        value="dismissed"
                        name="action-decision"
                        class="sr-only"
                      />
                      <div class="decision-content">
                        <span class="decision-icon">❌</span>
                        <div class="decision-texts">
                          <span class="decision-title">Bác bỏ báo cáo (Dismissed)</span>
                          <span class="decision-desc">
                            Từ chối báo cáo. Nội dung hợp lệ hoặc không đủ bằng chứng vi phạm.
                          </span>
                        </div>
                      </div>
                    </label>
                  </div>
                </div>

                <!-- Admin Notes -->
                <div class="form-group mt-3">
                  <div class="label-with-counter">
                    <label for="admin-notes" class="form-label">
                      Ghi chú của Quản trị viên:
                    </label>
                    <span
                      class="counter"
                      :class="{ 'counter-warn': actionAdminNotes.length > 900 }"
                    >
                      {{ actionAdminNotes.length }}/1000
                    </span>
                  </div>
                  <textarea
                    id="admin-notes"
                    v-model="actionAdminNotes"
                    rows="3"
                    maxlength="1000"
                    class="form-textarea"
                    placeholder="Nhập lý do xử lý hoặc ghi chú nội bộ của quản trị viên (không bắt buộc)..."
                    :disabled="isActionSubmitting"
                  ></textarea>
                </div>

                <!-- Error feedback -->
                <div v-if="actionError" class="modal-error-alert mt-3">
                  <span>⚠️ {{ actionError }}</span>
                </div>
              </div>

              <div class="modal-footer">
                <button
                  type="button"
                  class="btn btn-secondary"
                  :disabled="isActionSubmitting"
                  @click="closeActionModal"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  class="btn btn-primary"
                  :class="actionStatus === 'resolved' ? 'btn-danger-action' : 'btn-neutral-action'"
                  :disabled="isActionSubmitting"
                >
                  <span v-if="isActionSubmitting" class="btn-spinner"></span>
                  <span>{{ isActionSubmitting ? 'Đang lưu...' : 'Xác nhận xử lý' }}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </div>
  </AdminLayout>
</template>

<style scoped>
.reports-page {
  padding: 1.5rem;
  max-width: 1400px;
  margin: 0 auto;
}

/* Page Header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.5rem;
  gap: 1rem;
}

.header-badge-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}

.header-tag {
  background: rgba(99, 102, 241, 0.15);
  color: #818cf8;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.15rem 0.6rem;
  border-radius: 9999px;
  text-transform: uppercase;
}

.header-count {
  background: #374151;
  color: #9ca3af;
  font-size: 0.75rem;
  padding: 0.15rem 0.5rem;
  border-radius: 9999px;
}

.page-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #f3f4f6;
  margin: 0;
}

.page-subtitle {
  color: #9ca3af;
  font-size: 0.875rem;
  margin-top: 0.25rem;
}

/* Status Tabs Card */
.status-tabs-card {
  background: #1f2937;
  border: 1px solid #374151;
  border-radius: 0.75rem;
  padding: 1rem 1.25rem;
  margin-bottom: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.status-tabs {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 1rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;
  background: #111827;
  color: #9ca3af;
  border: 1px solid #374151;
  cursor: pointer;
  transition: all 0.15s ease;
}

.tab-btn:hover {
  background: #252e3e;
  color: #f3f4f6;
}

.tab-btn.active {
  background: #3b82f6;
  color: #ffffff;
  border-color: #3b82f6;
}

.tab-btn.tab-pending.active {
  background: #f59e0b;
  border-color: #f59e0b;
}

.tab-btn.tab-resolved.active {
  background: #10b981;
  border-color: #10b981;
}

.tab-btn.tab-dismissed.active {
  background: #6b7280;
  border-color: #6b7280;
}

.tab-badge {
  background: rgba(255, 255, 255, 0.2);
  padding: 0.1rem 0.4rem;
  border-radius: 9999px;
  font-size: 0.75rem;
}

/* Secondary Filter Controls */
.filter-controls {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  padding-top: 0.75rem;
  border-top: 1px solid #2d3748;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.filter-label {
  font-size: 0.8125rem;
  color: #9ca3af;
}

.filter-select,
.filter-input {
  background: #111827;
  border: 1px solid #374151;
  border-radius: 0.375rem;
  color: #f3f4f6;
  font-size: 0.875rem;
  padding: 0.4rem 0.75rem;
  outline: none;
}

.filter-select:focus,
.filter-input:focus {
  border-color: #6366f1;
}

.search-input-wrapper {
  display: flex;
  align-items: center;
}

.search-input-wrapper .filter-input {
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}

.btn-search-submit {
  background: #374151;
  border: 1px solid #374151;
  border-left: none;
  border-top-right-radius: 0.375rem;
  border-bottom-right-radius: 0.375rem;
  color: #f3f4f6;
  padding: 0.4rem 0.6rem;
  cursor: pointer;
}

.btn-search-submit:hover {
  background: #4b5563;
}

.btn-reset-filters {
  background: transparent;
  border: none;
  color: #f87171;
  font-size: 0.8125rem;
  cursor: pointer;
  padding: 0.4rem 0.5rem;
}

.btn-reset-filters:hover {
  text-decoration: underline;
}

/* Table Card */
.table-container-card {
  background: #1f2937;
  border: 1px solid #374151;
  border-radius: 0.75rem;
  overflow: hidden;
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
}

.reports-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 0.875rem;
}

.reports-table th {
  background: #111827;
  color: #9ca3af;
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #374151;
}

.reports-table td {
  padding: 0.875rem 1rem;
  border-bottom: 1px solid #2d3748;
  vertical-align: middle;
  color: #d1d5db;
}

.report-row:hover {
  background: rgba(55, 65, 81, 0.4);
}

.report-row.row-pending {
  background: rgba(245, 158, 11, 0.03);
}

/* Badges & Elements */
.id-badge {
  font-family: monospace;
  font-weight: 600;
  color: #9ca3af;
}

.type-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.2rem 0.5rem;
  border-radius: 0.375rem;
  font-size: 0.75rem;
  font-weight: 600;
}

.badge-post {
  background: rgba(59, 130, 246, 0.15);
  color: #60a5fa;
}

.badge-comment {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.badge-comic {
  background: rgba(168, 85, 247, 0.15);
  color: #c084fc;
}

.badge-chapter {
  background: rgba(236, 72, 153, 0.15);
  color: #f472b6;
}

.badge-user {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}

.badge-default {
  background: #374151;
  color: #9ca3af;
}

.target-box {
  max-width: 240px;
}

.target-text {
  display: block;
  font-size: 0.8125rem;
  color: #e5e7eb;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tag-deleted {
  display: inline-block;
  background: #dc2626;
  color: #ffffff;
  font-size: 0.6875rem;
  padding: 0.1rem 0.35rem;
  border-radius: 0.25rem;
  margin-bottom: 0.25rem;
}

.reason-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  max-width: 220px;
}

.reason-pill {
  font-weight: 600;
  color: #f3f4f6;
  font-size: 0.8125rem;
}

.desc-preview {
  font-size: 0.75rem;
  color: #9ca3af;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* User Cell */
.user-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.avatar-sm {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  overflow: hidden;
  background: #374151;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-fallback {
  font-size: 0.75rem;
  font-weight: bold;
  color: #e5e7eb;
}

.user-meta {
  display: flex;
  flex-direction: column;
}

.user-name {
  font-size: 0.8125rem;
  font-weight: 500;
  color: #f3f4f6;
}

.user-role-tag {
  font-size: 0.6875rem;
  color: #9ca3af;
  text-transform: capitalize;
}

/* Status Pill */
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.status-pending {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}

.status-resolved {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.status-dismissed {
  background: rgba(107, 114, 128, 0.2);
  color: #9ca3af;
}

.time-text {
  font-size: 0.75rem;
  color: #9ca3af;
  white-space: nowrap;
}

/* Action Buttons */
.action-buttons {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}

.btn-action {
  padding: 0.3rem 0.6rem;
  border-radius: 0.375rem;
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all 0.15s ease;
}

.btn-view {
  background: #374151;
  color: #f3f4f6;
}

.btn-view:hover {
  background: #4b5563;
}

.btn-resolve {
  background: rgba(59, 130, 246, 0.2);
  color: #60a5fa;
  border-color: rgba(59, 130, 246, 0.3);
}

.btn-resolve:hover {
  background: #3b82f6;
  color: #ffffff;
}

/* Pagination Bar */
.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.875rem 1.25rem;
  background: #111827;
  border-top: 1px solid #374151;
  font-size: 0.8125rem;
  color: #9ca3af;
}

.pagination-buttons {
  display: flex;
  gap: 0.5rem;
}

.btn-page {
  background: #1f2937;
  border: 1px solid #374151;
  color: #f3f4f6;
  padding: 0.35rem 0.75rem;
  border-radius: 0.375rem;
  font-size: 0.75rem;
  cursor: pointer;
}

.btn-page:hover:not(:disabled) {
  background: #374151;
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* Modal Shared Styles */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 999;
  padding: 1rem;
}

.modal-card {
  background: #1f2937;
  border: 1px solid #374151;
  border-radius: 0.75rem;
  width: 100%;
  max-width: 600px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
  overflow: hidden;
}

.modal-detail {
  max-width: 680px;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #374151;
}

.modal-title-wrap {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.modal-title {
  font-size: 1.125rem;
  font-weight: 700;
  color: #f3f4f6;
  margin: 0;
}

.modal-close {
  background: transparent;
  border: none;
  color: #9ca3af;
  font-size: 1.25rem;
  cursor: pointer;
  padding: 0.25rem;
}

.modal-close:hover {
  color: #f3f4f6;
}

.modal-body {
  padding: 1.25rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-top: 1px solid #374151;
  background: #111827;
}

/* Detail Modal Specifics */
.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
  background: #111827;
  padding: 1rem;
  border-radius: 0.5rem;
}

.detail-item {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.detail-label {
  font-size: 0.75rem;
  color: #9ca3af;
  text-transform: uppercase;
  font-weight: 600;
}

.detail-value {
  color: #f3f4f6;
  font-size: 0.875rem;
}

.section-title {
  font-size: 0.875rem;
  font-weight: 700;
  color: #f3f4f6;
  margin: 0 0 0.5rem 0;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.reason-banner {
  margin-bottom: 0.5rem;
}

.reason-tag {
  display: inline-block;
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 0.25rem 0.6rem;
  border-radius: 0.375rem;
  font-weight: 600;
  font-size: 0.875rem;
}

.desc-box {
  background: #111827;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  border-left: 3px solid #6366f1;
}

.desc-title {
  font-size: 0.75rem;
  color: #9ca3af;
  margin: 0 0 0.25rem 0;
}

.desc-content {
  color: #f3f4f6;
  font-size: 0.875rem;
  margin: 0;
  white-space: pre-wrap;
}

.target-detail-card {
  background: #111827;
  border: 1px solid #374151;
  border-radius: 0.5rem;
  padding: 0.875rem;
}

.target-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
  border-bottom: 1px solid #2d3748;
  padding-bottom: 0.5rem;
}

.target-type-name {
  font-weight: 600;
  color: #818cf8;
  font-size: 0.875rem;
}

.content-quote {
  background: #1f2937;
  padding: 0.75rem;
  border-radius: 0.375rem;
  margin-top: 0.5rem;
}

.quote-text {
  color: #f3f4f6;
  font-size: 0.875rem;
  margin: 0;
  line-height: 1.4;
  white-space: pre-wrap;
}

.target-field {
  display: flex;
  gap: 0.5rem;
  font-size: 0.8125rem;
  margin-top: 0.35rem;
}

.field-label {
  color: #9ca3af;
}

.field-value {
  color: #f3f4f6;
}

.resolution-card {
  background: #111827;
  border: 1px solid #374151;
  border-radius: 0.5rem;
  padding: 0.875rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.resolution-notes {
  margin-top: 0.5rem;
  padding-top: 0.5rem;
  border-top: 1px solid #2d3748;
}

.admin-notes-text {
  background: #1f2937;
  padding: 0.5rem 0.75rem;
  border-radius: 0.375rem;
  color: #e5e7eb;
  font-size: 0.875rem;
  margin-top: 0.25rem;
  white-space: pre-wrap;
}

/* Action Modal Specifics */
.action-overview {
  background: #111827;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.overview-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
}

.overview-label {
  color: #9ca3af;
  width: 80px;
}

.overview-val {
  color: #f3f4f6;
}

.decision-options {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.decision-card {
  display: block;
  border: 1px solid #374151;
  background: #111827;
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
  cursor: pointer;
  transition: all 0.15s ease;
}

.decision-card:hover {
  background: #1e293b;
}

.decision-card.selected {
  border-color: #6366f1;
  background: rgba(99, 102, 241, 0.08);
}

.decision-content {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.decision-icon {
  font-size: 1.25rem;
}

.decision-texts {
  display: flex;
  flex-direction: column;
}

.decision-title {
  font-weight: 600;
  font-size: 0.875rem;
  color: #f3f4f6;
}

.decision-desc {
  font-size: 0.75rem;
  color: #9ca3af;
  margin-top: 0.15rem;
}

.label-with-counter {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.35rem;
}

.form-label {
  font-size: 0.875rem;
  font-weight: 600;
  color: #d1d5db;
}

.counter {
  font-size: 0.75rem;
  color: #9ca3af;
}

.counter-warn {
  color: #f87171;
}

.form-textarea {
  width: 100%;
  background: #111827;
  border: 1px solid #374151;
  border-radius: 0.5rem;
  color: #f3f4f6;
  font-size: 0.875rem;
  padding: 0.6rem 0.75rem;
  outline: none;
  resize: vertical;
}

.form-textarea:focus {
  border-color: #6366f1;
}

.modal-error-alert {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
  padding: 0.5rem 0.75rem;
  border-radius: 0.375rem;
  font-size: 0.8125rem;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: all 0.15s ease;
}

.btn-primary {
  background: #3b82f6;
  color: #ffffff;
}

.btn-primary:hover:not(:disabled) {
  background: #2563eb;
}

.btn-danger-action {
  background: #ef4444;
  color: #ffffff;
}

.btn-danger-action:hover:not(:disabled) {
  background: #dc2626;
}

.btn-neutral-action {
  background: #6b7280;
  color: #ffffff;
}

.btn-neutral-action:hover:not(:disabled) {
  background: #4b5563;
}

.btn-secondary {
  background: #374151;
  color: #d1d5db;
}

.btn-secondary:hover:not(:disabled) {
  background: #4b5563;
}

.btn-refresh {
  padding: 0.4rem 0.8rem;
}

.btn-icon.spinning {
  animation: spin 1s linear infinite;
}

/* Empty & Loading States */
.loading-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3.5rem 1.5rem;
  text-align: center;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 0.75rem;
}

.empty-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: #f3f4f6;
  margin: 0 0 0.35rem 0;
}

.empty-desc {
  color: #9ca3af;
  font-size: 0.875rem;
  max-width: 420px;
  margin: 0;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 0.75rem;
}

.loading-text {
  color: #9ca3af;
  font-size: 0.875rem;
  margin: 0;
}

.error-banner {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  background: rgba(239, 68, 68, 0.15);
  border-bottom: 1px solid rgba(239, 68, 68, 0.2);
}

.error-text {
  color: #fca5a5;
  font-size: 0.875rem;
  margin: 0;
}

.btn-retry {
  background: #ef4444;
  color: #ffffff;
  border: none;
  padding: 0.25rem 0.6rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  cursor: pointer;
  margin-top: 0.25rem;
}

/* Toast */
.toast-alert {
  position: fixed;
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
  z-index: 1000;
}

.toast-success {
  background: #065f46;
  color: #d1fae5;
  border: 1px solid #059669;
}

.toast-error {
  background: #7f1d1d;
  color: #fee2e2;
  border: 1px solid #b91c1c;
}

.toast-close {
  background: transparent;
  border: none;
  color: inherit;
  font-size: 1rem;
  cursor: pointer;
  margin-left: 0.5rem;
}

/* Animations */
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(1rem);
}

/* Utilities */
.text-right {
  text-align: right;
}

.text-muted {
  color: #9ca3af;
}

.font-medium {
  font-weight: 500;
}

.font-semibold {
  font-weight: 600;
}

.italic {
  font-style: italic;
}

.mt-3 {
  margin-top: 0.75rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}
</style>
