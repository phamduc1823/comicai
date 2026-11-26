<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminCreatorService } from '@/services/adminCreatorService'
import { getErrorMessage, isAxiosError } from '@/services/httpClient'
import type { CreatorApplication, CreatorApplicationStatus } from '@/types/creatorApplication'
import type { AdminCreatorApplicationStats } from '@/types/adminCreator'

// State
const applications = ref<CreatorApplication[]>([])
const isLoading = ref(true)
const isExporting = ref(false)
const errorMessage = ref<string | null>(null)
const successToast = ref<string | null>(null)

// Stats
const stats = ref<AdminCreatorApplicationStats>({
  all: 0,
  pending: 0,
  approved: 0,
  rejected: 0,
  canceled: 0,
})

// Filters & Pagination
const statusFilter = ref<'all' | CreatorApplicationStatus>('all')
const searchQuery = ref('')
const currentPage = ref(1)
const lastPage = ref(1)
const totalItems = ref(0)
const perPage = ref(15)

// Modals State
const showApproveModal = ref(false)
const showRejectModal = ref(false)
const targetApplication = ref<CreatorApplication | null>(null)
const rejectReason = ref('')
const rejectError = ref<string | null>(null)
const isActionSubmitting = ref(false)

// Toast timer
let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(msg: string) {
  if (toastTimer) clearTimeout(toastTimer)
  successToast.value = msg
  toastTimer = setTimeout(() => {
    successToast.value = null
  }, 4000)
}

async function fetchApplications() {
  isLoading.value = true
  errorMessage.value = null

  try {
    const res = await AdminCreatorService.getApplications({
      page: currentPage.value,
      per_page: perPage.value,
      status: statusFilter.value,
      q: searchQuery.value.trim() || undefined,
    })

    applications.value = res.data
    stats.value = res.stats
    currentPage.value = res.meta.current_page
    lastPage.value = res.meta.last_page
    totalItems.value = res.meta.total
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách hồ sơ Creator.')
  } finally {
    isLoading.value = false
  }
}

function handleSearch() {
  currentPage.value = 1
  fetchApplications()
}

function setStatusFilter(status: 'all' | CreatorApplicationStatus) {
  statusFilter.value = status
  currentPage.value = 1
  fetchApplications()
}

function goToPage(page: number) {
  if (page >= 1 && page <= lastPage.value && page !== currentPage.value) {
    currentPage.value = page
    fetchApplications()
  }
}

async function handleExport() {
  isExporting.value = true
  try {
    await AdminCreatorService.exportApplications({
      status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      q: searchQuery.value.trim() || undefined,
    })
    showToast('Xuất danh sách hồ sơ ra Excel thành công!')
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể xuất file Excel.')
  } finally {
    isExporting.value = false
  }
}

// Approve Flow
function openApproveModal(app: CreatorApplication) {
  targetApplication.value = app
  showApproveModal.value = true
}

async function confirmApprove() {
  if (!targetApplication.value) return
  isActionSubmitting.value = true
  errorMessage.value = null

  try {
    await AdminCreatorService.approveApplication(targetApplication.value.id)
    showApproveModal.value = false
    showToast(`Đã duyệt hồ sơ ${targetApplication.value.application_code}! Người dùng đã được cấp quyền Creator.`)
    await fetchApplications()
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể phê duyệt hồ sơ.')
  } finally {
    isActionSubmitting.value = false
  }
}

// Reject Flow
function openRejectModal(app: CreatorApplication) {
  targetApplication.value = app
  rejectReason.value = ''
  rejectError.value = null
  showRejectModal.value = true
}

async function confirmReject() {
  if (!targetApplication.value) return

  const reason = rejectReason.value.trim()
  if (!reason) {
    rejectError.value = 'Lý do từ chối là bắt buộc.'
    return
  }

  isActionSubmitting.value = true
  rejectError.value = null

  try {
    await AdminCreatorService.rejectApplication(targetApplication.value.id, {
      reason,
    })
    showRejectModal.value = false
    showToast(`Đã từ chối hồ sơ ${targetApplication.value.application_code}.`)
    await fetchApplications()
  } catch (err: unknown) {
    if (isAxiosError<{ message?: string }>(err) && err.response?.data?.message) {
      rejectError.value = err.response.data.message
    } else {
      rejectError.value = getErrorMessage(err, 'Không thể từ chối hồ sơ.')
    }
  } finally {
    isActionSubmitting.value = false
  }
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

function getStatusBadgeClass(status: string): string {
  switch (status) {
    case 'pending':
      return 'badge-pending'
    case 'approved':
      return 'badge-approved'
    case 'rejected':
      return 'badge-rejected'
    case 'canceled':
      return 'badge-canceled'
    default:
      return 'badge-default'
  }
}

function getStatusLabel(status: string): string {
  switch (status) {
    case 'pending':
      return 'Chờ duyệt'
    case 'approved':
      return 'Đã duyệt'
    case 'rejected':
      return 'Từ chối'
    case 'canceled':
      return 'Đã hủy'
    default:
      return status
  }
}

// Watch filters
watch(perPage, () => {
  currentPage.value = 1
  fetchApplications()
})

onMounted(() => {
  document.title = 'Quản lý Hồ sơ Creator — BlueToon Admin'
  fetchApplications()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-page-container">
      <!-- Page Header -->
      <header class="page-header">
        <div class="header-left">
          <h1 class="page-title">Hồ sơ Đăng ký Creator</h1>
          <p class="page-desc">Xét duyệt và quản lý các hồ sơ đăng ký trở thành tác giả truyện tranh trên ComicAI.</p>
        </div>
        <div class="header-right">
          <button
            type="button"
            class="btn btn-export"
            :disabled="isExporting || isLoading"
            @click="handleExport"
          >
            <span v-if="isExporting" class="spinner-sm" aria-hidden="true"></span>
            <span v-else class="btn-icon" aria-hidden="true">📊</span>
            <span>{{ isExporting ? 'Đang xuất file...' : 'Xuất Excel (.xlsx)' }}</span>
          </button>
        </div>
      </header>

      <!-- Toast & Error Banners -->
      <div v-if="successToast" class="alert alert-success" role="alert">
        <span class="alert-icon">✓</span>
        <span>{{ successToast }}</span>
      </div>

      <div v-if="errorMessage" class="alert alert-danger" role="alert">
        <span class="alert-icon">⚠️</span>
        <span>{{ errorMessage }}</span>
        <button type="button" class="alert-close" aria-label="Đóng" @click="errorMessage = null">✕</button>
      </div>

      <!-- Stats Tabs Row -->
      <section class="stats-tabs-row" aria-label="Thống kê trạng thái">
        <button
          type="button"
          class="stat-tab-btn"
          :class="{ active: statusFilter === 'all' }"
          @click="setStatusFilter('all')"
        >
          <span class="tab-label">Tất cả</span>
          <span class="tab-badge">{{ stats.all }}</span>
        </button>

        <button
          type="button"
          class="stat-tab-btn tab-pending"
          :class="{ active: statusFilter === 'pending' }"
          @click="setStatusFilter('pending')"
        >
          <span class="tab-label">Chờ duyệt</span>
          <span class="tab-badge badge-pending">{{ stats.pending }}</span>
        </button>

        <button
          type="button"
          class="stat-tab-btn tab-approved"
          :class="{ active: statusFilter === 'approved' }"
          @click="setStatusFilter('approved')"
        >
          <span class="tab-label">Đã duyệt</span>
          <span class="tab-badge badge-approved">{{ stats.approved }}</span>
        </button>

        <button
          type="button"
          class="stat-tab-btn tab-rejected"
          :class="{ active: statusFilter === 'rejected' }"
          @click="setStatusFilter('rejected')"
        >
          <span class="tab-label">Từ chối</span>
          <span class="tab-badge badge-rejected">{{ stats.rejected }}</span>
        </button>

        <button
          type="button"
          class="stat-tab-btn tab-canceled"
          :class="{ active: statusFilter === 'canceled' }"
          @click="setStatusFilter('canceled')"
        >
          <span class="tab-label">Đã hủy</span>
          <span class="tab-badge badge-canceled">{{ stats.canceled }}</span>
        </button>
      </section>

      <!-- Filter Controls Bar -->
      <div class="filters-bar">
        <form class="search-form" @submit.prevent="handleSearch">
          <div class="search-input-wrapper">
            <span class="search-icon" aria-hidden="true">🔍</span>
            <input
              v-model="searchQuery"
              type="text"
              class="search-input"
              placeholder="Tìm theo mã hồ sơ, bút danh, tên, email..."
            />
            <button
              v-if="searchQuery"
              type="button"
              class="btn-clear-search"
              aria-label="Xóa tìm kiếm"
              @click="searchQuery = ''; handleSearch()"
            >
              ✕
            </button>
          </div>
          <button type="submit" class="btn btn-search">Tìm kiếm</button>
        </form>

        <div class="per-page-selector">
          <label for="app-per-page">Hiển thị:</label>
          <select id="app-per-page" v-model="perPage" class="select-control">
            <option :value="15">15 hồ sơ</option>
            <option :value="30">30 hồ sơ</option>
            <option :value="50">50 hồ sơ</option>
          </select>
        </div>
      </div>

      <!-- Data Table Section -->
      <div class="table-card">
        <div v-if="isLoading" class="table-loading" aria-busy="true">
          <div class="spinner" aria-hidden="true"></div>
          <p>Đang tải danh sách hồ sơ...</p>
        </div>

        <div v-else-if="applications.length === 0" class="table-empty">
          <span class="empty-icon">📋</span>
          <p class="empty-text">Không tìm thấy hồ sơ đăng ký nào phù hợp với bộ lọc.</p>
        </div>

        <div v-else class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 120px;">Mã hồ sơ</th>
                <th>Người đăng ký</th>
                <th>Bút danh</th>
                <th style="width: 80px;">Phiên bản</th>
                <th style="width: 140px;">Ngày nộp</th>
                <th style="width: 130px;">Trạng thái</th>
                <th style="width: 220px; text-align: right;">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="app in applications" :key="app.id" class="data-row">
                <td>
                  <RouterLink
                    :to="`/admin/creator-applications/${app.id}`"
                    class="code-link"
                    title="Xem chi tiết hồ sơ"
                  >
                    {{ app.application_code }}
                  </RouterLink>
                </td>
                <td>
                  <div class="applicant-cell">
                    <span class="applicant-name">{{ app.user?.name || 'N/A' }}</span>
                    <span class="applicant-email">{{ app.user?.email || 'N/A' }}</span>
                  </div>
                </td>
                <td>
                  <strong class="pen-name-cell">{{ app.pen_name }}</strong>
                </td>
                <td>
                  <span class="version-tag">v{{ app.version }}</span>
                </td>
                <td>
                  <span class="date-cell">{{ formatDate(app.created_at) }}</span>
                </td>
                <td>
                  <span class="status-badge" :class="getStatusBadgeClass(app.status)">
                    {{ getStatusLabel(app.status) }}
                  </span>
                </td>
                <td style="text-align: right;">
                  <div class="row-actions">
                    <RouterLink
                      :to="`/admin/creator-applications/${app.id}`"
                      class="btn-action btn-view"
                      title="Xem chi tiết & So sánh Diff"
                    >
                      Chi tiết
                    </RouterLink>

                    <!-- Quick Actions for PENDING -->
                    <template v-if="app.status === 'pending'">
                      <button
                        type="button"
                        class="btn-action btn-approve"
                        title="Duyệt hồ sơ"
                        @click="openApproveModal(app)"
                      >
                        Duyệt
                      </button>
                      <button
                        type="button"
                        class="btn-action btn-reject"
                        title="Từ chối hồ sơ"
                        @click="openRejectModal(app)"
                      >
                        Từ chối
                      </button>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Bar -->
        <div v-if="!isLoading && totalItems > 0" class="pagination-bar">
          <span class="pagination-info">
            Hiển thị {{ (currentPage - 1) * perPage + 1 }}–{{ Math.min(currentPage * perPage, totalItems) }} trên tổng số {{ totalItems }} hồ sơ
          </span>

          <div class="pagination-controls">
            <button
              type="button"
              class="btn-page"
              :disabled="currentPage <= 1"
              @click="goToPage(currentPage - 1)"
            >
              ← Trước
            </button>
            <span class="page-indicator">Trang {{ currentPage }} / {{ lastPage }}</span>
            <button
              type="button"
              class="btn-page"
              :disabled="currentPage >= lastPage"
              @click="goToPage(currentPage + 1)"
            >
              Sau →
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Approve Confirmation Modal -->
    <div
      v-if="showApproveModal"
      class="modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-labelledby="approve-modal-title"
    >
      <div class="modal-card">
        <div class="modal-header">
          <h3 id="approve-modal-title" class="modal-title">Phê duyệt hồ sơ Creator</h3>
          <button
            type="button"
            class="modal-close"
            :disabled="isActionSubmitting"
            @click="showApproveModal = false"
          >
            ✕
          </button>
        </div>
        <div class="modal-body">
          <p>
            Bạn có chắc chắn muốn phê duyệt hồ sơ <strong>{{ targetApplication?.application_code }}</strong> cho tác giả <strong>{{ targetApplication?.pen_name }}</strong>?
          </p>
          <div class="info-callout">
            <p><strong>Hiệu lực:</strong> Tài khoản người dùng sẽ được nâng cấp vai trò thành <code>creator</code>, cấp mã tác giả <code>CR-xxxxxx</code> và mở khóa quyền truy cập Creator Studio.</p>
          </div>
        </div>
        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-outline"
            :disabled="isActionSubmitting"
            @click="showApproveModal = false"
          >
            Hủy bỏ
          </button>
          <button
            type="button"
            class="btn btn-success"
            :disabled="isActionSubmitting"
            @click="confirmApprove"
          >
            <span v-if="isActionSubmitting" class="spinner-sm" aria-hidden="true"></span>
            <span>{{ isActionSubmitting ? 'Đang duyệt...' : 'Xác nhận duyệt hồ sơ' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Reject Modal -->
    <div
      v-if="showRejectModal"
      class="modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-labelledby="reject-modal-title"
    >
      <div class="modal-card">
        <div class="modal-header">
          <h3 id="reject-modal-title" class="modal-title">Từ chối hồ sơ Creator</h3>
          <button
            type="button"
            class="modal-close"
            :disabled="isActionSubmitting"
            @click="showRejectModal = false"
          >
            ✕
          </button>
        </div>
        <div class="modal-body">
          <p>
            Vui lòng nhập lý do từ chối hồ sơ <strong>{{ targetApplication?.application_code }}</strong> (Bắt buộc):
          </p>
          <div class="form-group" :class="{ 'has-error': !!rejectError }">
            <textarea
              v-model="rejectReason"
              class="form-control"
              rows="4"
              placeholder="Nêu rõ lý do chưa đạt tiêu chuẩn để tác giả biết và chỉnh sửa bổ sung..."
              maxlength="1000"
              :disabled="isActionSubmitting"
            ></textarea>
            <p v-if="rejectError" class="error-text" role="alert">{{ rejectError }}</p>
          </div>
        </div>
        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-outline"
            :disabled="isActionSubmitting"
            @click="showRejectModal = false"
          >
            Hủy bỏ
          </button>
          <button
            type="button"
            class="btn btn-danger"
            :disabled="isActionSubmitting"
            @click="confirmReject"
          >
            <span v-if="isActionSubmitting" class="spinner-sm" aria-hidden="true"></span>
            <span>{{ isActionSubmitting ? 'Đang xử lý...' : 'Xác nhận từ chối' }}</span>
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-page-container {
  padding: 24px 32px 60px;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
  gap: 16px;
}

.page-title {
  font-size: 24px;
  font-weight: 700;
  color: #f0f6fc;
  margin: 0 0 6px;
}

.page-desc {
  font-size: 14px;
  color: #8b949e;
  margin: 0;
}

/* Stats Tabs */
.stats-tabs-row {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  overflow-x: auto;
  padding-bottom: 4px;
}

.stat-tab-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 6px;
  color: #8b949e;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.stat-tab-btn:hover {
  border-color: #8b949e;
  color: #c9d1d9;
}

.stat-tab-btn.active {
  background-color: #21262d;
  border-color: #58a6ff;
  color: #f0f6fc;
}

.tab-badge {
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  background-color: #30363d;
  color: #f0f6fc;
}

.tab-badge.badge-pending {
  background-color: rgba(227, 179, 65, 0.2);
  color: #e3b341;
}

.tab-badge.badge-approved {
  background-color: rgba(46, 160, 67, 0.2);
  color: #3fb950;
}

.tab-badge.badge-rejected {
  background-color: rgba(248, 81, 73, 0.2);
  color: #f85149;
}

.tab-badge.badge-canceled {
  background-color: rgba(139, 148, 158, 0.2);
  color: #8b949e;
}

/* Filters Bar */
.filters-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  gap: 16px;
  flex-wrap: wrap;
}

.search-form {
  display: flex;
  gap: 10px;
  flex: 1;
  max-width: 500px;
}

.search-input-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  flex: 1;
}

.search-icon {
  position: absolute;
  left: 12px;
  color: #8b949e;
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 8px 34px 8px 36px;
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  color: #e6edf3;
  font-size: 13px;
}

.search-input:focus {
  outline: none;
  border-color: #58a6ff;
}

.btn-clear-search {
  position: absolute;
  right: 10px;
  background: transparent;
  border: none;
  color: #8b949e;
  cursor: pointer;
  padding: 2px 4px;
}

.btn-search {
  padding: 8px 14px;
  background-color: #21262d;
  border: 1px solid #30363d;
  color: #c9d1d9;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.per-page-selector {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #8b949e;
}

.select-control {
  padding: 6px 10px;
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  color: #e6edf3;
  font-size: 13px;
}

/* Table Card */
.table-card {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 8px;
  overflow: hidden;
}

.table-loading,
.table-empty {
  text-align: center;
  padding: 60px 20px;
  color: #8b949e;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid rgba(88, 166, 255, 0.2);
  border-top-color: #58a6ff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto 12px;
}

.spinner-sm {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-right: 6px;
  vertical-align: middle;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.empty-icon {
  font-size: 36px;
  display: block;
  margin-bottom: 8px;
}

.table-responsive {
  overflow-x: auto;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  text-align: left;
}

.data-table th {
  background-color: #0d1117;
  color: #8b949e;
  font-weight: 600;
  padding: 12px 16px;
  border-bottom: 1px solid #30363d;
}

.data-table td {
  padding: 14px 16px;
  border-bottom: 1px solid #21262d;
  color: #c9d1d9;
}

.data-row:hover td {
  background-color: rgba(56, 139, 253, 0.03);
}

.code-link {
  font-family: monospace;
  font-weight: 700;
  color: #58a6ff;
  text-decoration: none;
}

.code-link:hover {
  text-decoration: underline;
}

.applicant-cell {
  display: flex;
  flex-direction: column;
}

.applicant-name {
  color: #f0f6fc;
  font-weight: 600;
}

.applicant-email {
  font-size: 12px;
  color: #8b949e;
}

.pen-name-cell {
  color: #f0f6fc;
}

.version-tag {
  background-color: #21262d;
  color: #8b949e;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
}

.date-cell {
  font-size: 12px;
  color: #8b949e;
}

.status-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 12px;
  text-transform: uppercase;
}

.badge-pending {
  background-color: rgba(227, 179, 65, 0.2);
  color: #e3b341;
}

.badge-approved {
  background-color: rgba(46, 160, 67, 0.2);
  color: #3fb950;
}

.badge-rejected {
  background-color: rgba(248, 81, 73, 0.2);
  color: #f85149;
}

.badge-canceled {
  background-color: rgba(139, 148, 158, 0.2);
  color: #8b949e;
}

.row-actions {
  display: flex;
  justify-content: flex-end;
  gap: 6px;
}

.btn-action {
  padding: 4px 10px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  border: 1px solid transparent;
  transition: all 0.2s ease;
}

.btn-view {
  background-color: #21262d;
  border-color: #30363d;
  color: #c9d1d9;
}

.btn-view:hover {
  border-color: #58a6ff;
  color: #58a6ff;
}

.btn-approve {
  background-color: rgba(46, 160, 67, 0.15);
  border-color: rgba(46, 160, 67, 0.4);
  color: #3fb950;
}

.btn-approve:hover {
  background-color: #238636;
  color: #fff;
}

.btn-reject {
  background-color: rgba(248, 81, 73, 0.15);
  border-color: rgba(248, 81, 73, 0.4);
  color: #f85149;
}

.btn-reject:hover {
  background-color: #da3633;
  color: #fff;
}

/* Pagination */
.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 20px;
  background-color: #0d1117;
  border-top: 1px solid #30363d;
}

.pagination-info {
  font-size: 12px;
  color: #8b949e;
}

.pagination-controls {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-page {
  padding: 5px 12px;
  background-color: #21262d;
  border: 1px solid #30363d;
  border-radius: 4px;
  color: #c9d1d9;
  font-size: 12px;
  cursor: pointer;
}

.btn-page:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.page-indicator {
  font-size: 12px;
  color: #8b949e;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
}

.btn-export {
  background-color: #21262d;
  border-color: #30363d;
  color: #58a6ff;
}

.btn-export:hover:not(:disabled) {
  background-color: #30363d;
  border-color: #58a6ff;
}

.btn-outline {
  background-color: transparent;
  border-color: #30363d;
  color: #c9d1d9;
}

.btn-success {
  background-color: #238636;
  color: #fff;
}

.btn-danger {
  background-color: #da3633;
  color: #fff;
}

/* Alerts */
.alert {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 6px;
  margin-bottom: 20px;
  font-size: 13px;
}

.alert-success {
  background-color: rgba(46, 160, 67, 0.15);
  border: 1px solid rgba(46, 160, 67, 0.4);
  color: #3fb950;
}

.alert-danger {
  background-color: rgba(248, 81, 73, 0.15);
  border: 1px solid rgba(248, 81, 73, 0.4);
  color: #f85149;
}

.alert-close {
  margin-left: auto;
  background: transparent;
  border: none;
  color: inherit;
  cursor: pointer;
}

/* Modals */
.modal-backdrop {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background-color: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.modal-card {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 8px;
  width: 100%;
  max-width: 480px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid #30363d;
}

.modal-title {
  font-size: 16px;
  font-weight: 600;
  color: #f0f6fc;
  margin: 0;
}

.modal-close {
  background: transparent;
  border: none;
  color: #8b949e;
  cursor: pointer;
}

.modal-body {
  padding: 20px;
  font-size: 14px;
  color: #c9d1d9;
}

.info-callout {
  background-color: rgba(56, 139, 253, 0.1);
  border-left: 3px solid #58a6ff;
  padding: 10px 12px;
  border-radius: 0 4px 4px 0;
  margin-top: 12px;
  font-size: 12px;
  color: #58a6ff;
}

.form-control {
  width: 100%;
  padding: 10px;
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  color: #e6edf3;
  font-size: 13px;
  box-sizing: border-box;
  resize: vertical;
}

.error-text {
  color: #f85149;
  font-size: 12px;
  margin: 6px 0 0;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 20px;
  border-top: 1px solid #30363d;
}
</style>
