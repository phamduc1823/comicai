<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminCreatorService } from '@/services/adminCreatorService'
import { getErrorMessage } from '@/services/httpClient'
import type { CreatorProfileInfo } from '@/types/creatorApplication'
import type { AdminCreatorStats, ListAdminCreatorsParams } from '@/types/adminCreator'

// State
const creators = ref<CreatorProfileInfo[]>([])
const isLoading = ref(true)
const isExporting = ref(false)
const errorMessage = ref<string | null>(null)
const successToast = ref<string | null>(null)

// Stats
const stats = ref<AdminCreatorStats>({
  all: 0,
  active: 0,
  revoked: 0,
})

// Filters & Pagination
const statusFilter = ref<'all' | 'active' | 'revoked'>('all')
const searchQuery = ref('')
const currentPage = ref(1)
const lastPage = ref(1)
const totalItems = ref(0)
const perPage = ref(15)

// Toast timer
let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(msg: string) {
  if (toastTimer) clearTimeout(toastTimer)
  successToast.value = msg
  toastTimer = setTimeout(() => {
    successToast.value = null
  }, 4000)
}

async function fetchCreators() {
  isLoading.value = true
  errorMessage.value = null

  try {
    const params: ListAdminCreatorsParams = {
      page: currentPage.value,
      per_page: perPage.value,
      status: statusFilter.value,
      q: searchQuery.value.trim() || undefined,
    }

    const res = await AdminCreatorService.getCreators(params)
    creators.value = res.data
    stats.value = res.stats
    currentPage.value = res.meta.current_page
    lastPage.value = res.meta.last_page
    totalItems.value = res.meta.total
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách Creator.')
  } finally {
    isLoading.value = false
  }
}

function handleSearch() {
  currentPage.value = 1
  fetchCreators()
}

function setStatusFilter(status: 'all' | 'active' | 'revoked') {
  statusFilter.value = status
  currentPage.value = 1
  fetchCreators()
}

function goToPage(page: number) {
  if (page >= 1 && page <= lastPage.value && page !== currentPage.value) {
    currentPage.value = page
    fetchCreators()
  }
}

async function handleExport() {
  isExporting.value = true
  try {
    await AdminCreatorService.exportCreators({
      status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      q: searchQuery.value.trim() || undefined,
    })
    showToast('Xuất danh sách Creator ra Excel thành công!')
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể xuất file Excel.')
  } finally {
    isExporting.value = false
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

onMounted(() => {
  fetchCreators()
})

watch(perPage, () => {
  currentPage.value = 1
  fetchCreators()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-creators-page">
      <!-- Toast Notification -->
      <Transition name="fade">
        <div v-if="successToast" class="admin-toast success" role="status">
          <span class="toast-icon">✓</span>
          <span>{{ successToast }}</span>
        </div>
      </Transition>

      <!-- Page Header -->
      <header class="page-header">
        <div class="header-left">
          <h1 class="page-title">Quản lý Creator</h1>
          <p class="page-subtitle">
            Danh sách tất cả tác giả truyện tranh trên nền tảng, quản lý trạng thái hoạt động và thu hồi/cấp phép quyền.
          </p>
        </div>
        <div class="header-actions">
          <button
            type="button"
            class="btn-export"
            :disabled="isExporting"
            @click="handleExport"
            title="Xuất toàn bộ danh sách phù hợp bộ lọc ra file Excel"
          >
            <span v-if="isExporting" class="spinner-small" aria-hidden="true"></span>
            <span v-else class="btn-icon">📊</span>
            <span>{{ isExporting ? 'Đang xuất Excel...' : 'Xuất Excel (.xlsx)' }}</span>
          </button>
        </div>
      </header>

      <!-- Statistics Tabs -->
      <div class="stats-tabs" role="tablist" aria-label="Bộ lọc trạng thái creator">
        <button
          type="button"
          class="stat-tab-item"
          :class="{ active: statusFilter === 'all' }"
          role="tab"
          :aria-selected="statusFilter === 'all'"
          @click="setStatusFilter('all')"
        >
          <span class="tab-label">Tất cả Creator</span>
          <span class="tab-count count-all">{{ stats.all }}</span>
        </button>

        <button
          type="button"
          class="stat-tab-item"
          :class="{ active: statusFilter === 'active' }"
          role="tab"
          :aria-selected="statusFilter === 'active'"
          @click="setStatusFilter('active')"
        >
          <span class="tab-label">Đang hoạt động</span>
          <span class="tab-count count-active">{{ stats.active }}</span>
        </button>

        <button
          type="button"
          class="stat-tab-item"
          :class="{ active: statusFilter === 'revoked' }"
          role="tab"
          :aria-selected="statusFilter === 'revoked'"
          @click="setStatusFilter('revoked')"
        >
          <span class="tab-label">Đã thu hồi quyền</span>
          <span class="tab-count count-revoked">{{ stats.revoked }}</span>
        </button>
      </div>

      <!-- Filters & Search Toolbar -->
      <div class="toolbar-card">
        <form class="search-box" @submit.prevent="handleSearch">
          <span class="search-icon" aria-hidden="true">🔍</span>
          <input
            v-model="searchQuery"
            type="text"
            class="search-input"
            placeholder="Tìm theo bút danh, tên tài khoản, email, mã Creator (CR-xxxxxx)..."
            aria-label="Tìm kiếm creator"
          />
          <button
            v-if="searchQuery"
            type="button"
            class="search-clear"
            aria-label="Xóa tìm kiếm"
            @click="searchQuery = ''; handleSearch()"
          >
            ✕
          </button>
          <button type="submit" class="btn-search">Tìm kiếm</button>
        </form>

        <div class="toolbar-controls">
          <label class="per-page-select-wrap">
            <span class="control-label">Hiển thị:</span>
            <select v-model="perPage" class="select-input">
              <option :value="15">15 / trang</option>
              <option :value="30">30 / trang</option>
              <option :value="50">50 / trang</option>
            </select>
          </label>
        </div>
      </div>

      <!-- Error State -->
      <div v-if="errorMessage" class="error-banner" role="alert">
        <span class="error-icon">⚠️</span>
        <div class="error-content">
          <div class="error-text">{{ errorMessage }}</div>
          <button type="button" class="btn-retry" @click="fetchCreators">Thử lại</button>
        </div>
      </div>

      <!-- Data Table Card -->
      <div class="table-card">
        <!-- Loading Indicator -->
        <div v-if="isLoading" class="table-loading">
          <div class="spinner"></div>
          <p>Đang tải danh sách Creator...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="creators.length === 0" class="table-empty">
          <div class="empty-icon">🎨</div>
          <h2 class="empty-title">Không tìm thấy Creator nào</h2>
          <p class="empty-desc">
            Không có Creator nào khớp với bộ lọc hoặc từ khóa tìm kiếm hiện tại.
          </p>
          <button
            v-if="searchQuery || statusFilter !== 'all'"
            type="button"
            class="btn-reset-filters"
            @click="searchQuery = ''; statusFilter = 'all'; handleSearch()"
          >
            Xóa tất cả bộ lọc
          </button>
        </div>

        <!-- Table View -->
        <div v-else class="table-responsive">
          <table class="data-table" aria-label="Bảng danh sách Creator">
            <thead>
              <tr>
                <th scope="col" class="th-code">Mã Creator</th>
                <th scope="col" class="th-user">Tác giả</th>
                <th scope="col" class="th-penname">Bút danh</th>
                <th scope="col" class="th-comics">Số truyện</th>
                <th scope="col" class="th-status">Trạng thái</th>
                <th scope="col" class="th-reapply">Quyền nộp lại</th>
                <th scope="col" class="th-date">Ngày duyệt</th>
                <th scope="col" class="th-action text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="creator in creators"
                :key="creator.id"
                class="table-row"
              >
                <!-- Creator Code -->
                <td class="td-code">
                  <RouterLink
                    :to="`/admin/creators/${creator.id}`"
                    class="code-link"
                    :title="`Xem chi tiết Creator ${creator.creator_code}`"
                  >
                    {{ creator.creator_code }}
                  </RouterLink>
                </td>

                <!-- User Info -->
                <td class="td-user">
                  <div class="user-cell">
                    <div class="avatar-cell">
                      <img
                        v-if="creator.user?.avatar_path"
                        :src="creator.user.avatar_path"
                        :alt="creator.user.name"
                        class="avatar-img"
                      />
                      <span v-else class="avatar-fallback">
                        {{ creator.user?.name ? creator.user.name.charAt(0).toUpperCase() : 'C' }}
                      </span>
                    </div>
                    <div class="user-text">
                      <span class="user-name">{{ creator.user?.name || '—' }}</span>
                      <span class="user-email">{{ creator.user?.email || '—' }}</span>
                    </div>
                  </div>
                </td>

                <!-- Pen Name -->
                <td class="td-penname">
                  <span class="pen-name-badge">
                    <span class="pen-icon">✍️</span>
                    <strong>{{ creator.pen_name }}</strong>
                  </span>
                </td>

                <!-- Comics Count -->
                <td class="td-comics">
                  <span class="comics-count-badge" :title="`${creator.comics_count || 0} truyện`">
                    📚 {{ creator.comics_count ?? 0 }}
                  </span>
                </td>

                <!-- Status Badge -->
                <td class="td-status">
                  <span
                    class="status-pill"
                    :class="creator.status === 'active' ? 'pill-active' : 'pill-revoked'"
                  >
                    <span class="pill-dot"></span>
                    {{ creator.status === 'active' ? 'Đang hoạt động' : 'Đã thu hồi' }}
                  </span>
                </td>

                <!-- Reapply Permission -->
                <td class="td-reapply">
                  <span
                    v-if="creator.status === 'revoked'"
                    class="reapply-badge"
                    :class="creator.can_reapply ? 'reapply-allowed' : 'reapply-blocked'"
                  >
                    {{ creator.can_reapply ? 'Được phép nộp lại' : 'Chưa cấp phép' }}
                  </span>
                  <span v-else class="text-muted">—</span>
                </td>

                <!-- Approved Date -->
                <td class="td-date text-muted">
                  {{ formatDate(creator.approved_at) }}
                </td>

                <!-- Actions -->
                <td class="td-action text-right">
                  <RouterLink
                    :to="`/admin/creators/${creator.id}`"
                    class="btn-view-detail"
                  >
                    Chi tiết &rarr;
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Bar -->
        <div v-if="lastPage > 1" class="table-pagination">
          <div class="pagination-info">
            Trang <strong>{{ currentPage }}</strong> / <strong>{{ lastPage }}</strong>
            (Tổng cộng <strong>{{ totalItems }}</strong> Creator)
          </div>
          <div class="pagination-buttons">
            <button
              type="button"
              class="btn-page"
              :disabled="currentPage <= 1 || isLoading"
              @click="goToPage(currentPage - 1)"
            >
              &larr; Trang trước
            </button>
            <button
              type="button"
              class="btn-page"
              :disabled="currentPage >= lastPage || isLoading"
              @click="goToPage(currentPage + 1)"
            >
              Trang sau &rarr;
            </button>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-creators-page {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

/* Toast */
.admin-toast {
  position: fixed;
  top: 1.5rem;
  right: 1.5rem;
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1.25rem;
  border-radius: 8px;
  font-size: 0.9375rem;
  font-weight: 500;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
}

.admin-toast.success {
  background-color: #10b981;
  color: #ffffff;
}

.toast-icon {
  font-weight: 700;
  font-size: 1.125rem;
}

/* Page Header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.page-title {
  margin: 0 0 0.375rem;
  font-size: 1.75rem;
  font-weight: 700;
  color: #f1f5f9;
  letter-spacing: -0.02em;
}

.page-subtitle {
  margin: 0;
  font-size: 0.9375rem;
  color: #94a3b8;
  max-width: 650px;
  line-height: 1.5;
}

.btn-export {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.125rem;
  background-color: #1e293b;
  color: #38bdf8;
  border: 1px solid #334155;
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-export:hover:not(:disabled) {
  background-color: #0284c7;
  color: #ffffff;
  border-color: #0284c7;
}

.btn-export:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.spinner-small {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(56, 189, 248, 0.3);
  border-top-color: #38bdf8;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

/* Stats Tabs */
.stats-tabs {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.stat-tab-item {
  display: inline-flex;
  align-items: center;
  gap: 0.625rem;
  padding: 0.625rem 1rem;
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 8px;
  color: #94a3b8;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
}

.stat-tab-item:hover {
  border-color: #475569;
  color: #e2e8f0;
}

.stat-tab-item.active {
  background-color: #0f172a;
  border-color: #38bdf8;
  color: #38bdf8;
  font-weight: 600;
}

.tab-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 0.375rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
}

.count-all {
  background-color: #334155;
  color: #cbd5e1;
}

.count-active {
  background-color: rgba(16, 185, 129, 0.2);
  color: #34d399;
}

.count-revoked {
  background-color: rgba(239, 68, 68, 0.2);
  color: #f87171;
}

/* Toolbar */
.toolbar-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  flex-wrap: wrap;
}

.search-box {
  position: relative;
  display: flex;
  align-items: center;
  flex: 1;
  min-width: 280px;
  gap: 0.5rem;
}

.search-icon {
  position: absolute;
  left: 0.875rem;
  color: #64748b;
  pointer-events: none;
}

.search-input {
  flex: 1;
  padding: 0.5625rem 2.25rem 0.5625rem 2.5rem;
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 6px;
  color: #f1f5f9;
  font-size: 0.875rem;
  outline: none;
  transition: border-color 0.2s;
}

.search-input:focus {
  border-color: #38bdf8;
}

.search-clear {
  position: absolute;
  right: 6.25rem;
  background: transparent;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  padding: 0.25rem;
  font-size: 0.875rem;
}

.btn-search {
  padding: 0.5625rem 1rem;
  background-color: #0284c7;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.2s;
}

.btn-search:hover {
  background-color: #0369a1;
}

.toolbar-controls {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.per-page-select-wrap {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.control-label {
  font-size: 0.8125rem;
  color: #94a3b8;
}

.select-input {
  padding: 0.5rem 0.75rem;
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 6px;
  color: #f1f5f9;
  font-size: 0.875rem;
  outline: none;
}

/* Error Banner */
.error-banner {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 1rem 1.25rem;
  background-color: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.35);
  border-radius: 8px;
  color: #fca5a5;
}

.error-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
}

.btn-retry {
  padding: 0.375rem 0.875rem;
  background-color: #ef4444;
  color: #ffffff;
  border: none;
  border-radius: 4px;
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
}

/* Table Card */
.table-card {
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  overflow: hidden;
}

.table-loading,
.table-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
  color: #94a3b8;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 0.75rem;
}

.empty-title {
  margin: 0 0 0.5rem;
  font-size: 1.125rem;
  font-weight: 600;
  color: #e2e8f0;
}

.empty-desc {
  margin: 0 0 1.25rem;
  font-size: 0.875rem;
  max-width: 400px;
}

.btn-reset-filters {
  padding: 0.5rem 1rem;
  background-color: #334155;
  color: #f1f5f9;
  border: none;
  border-radius: 6px;
  font-size: 0.875rem;
  cursor: pointer;
}

/* Table */
.table-responsive {
  width: 100%;
  overflow-x: auto;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 0.875rem;
}

.data-table th {
  padding: 0.875rem 1rem;
  background-color: #0f172a;
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-bottom: 1px solid #334155;
}

.data-table td {
  padding: 1rem;
  border-bottom: 1px solid #334155;
  color: #cbd5e1;
  vertical-align: middle;
}

.table-row:hover {
  background-color: rgba(51, 65, 85, 0.4);
}

.code-link {
  font-family: monospace;
  font-weight: 700;
  color: #38bdf8;
  text-decoration: none;
  padding: 0.25rem 0.5rem;
  background-color: rgba(56, 189, 248, 0.1);
  border-radius: 4px;
}

.code-link:hover {
  text-decoration: underline;
  background-color: rgba(56, 189, 248, 0.2);
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.avatar-cell {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  overflow: hidden;
  background-color: #334155;
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
  font-size: 0.875rem;
  font-weight: 700;
  color: #94a3b8;
}

.user-text {
  display: flex;
  flex-direction: column;
}

.user-name {
  font-weight: 600;
  color: #f1f5f9;
}

.user-email {
  font-size: 0.75rem;
  color: #94a3b8;
}

.pen-name-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  color: #e2e8f0;
}

.pen-icon {
  font-size: 0.875rem;
}

.comics-count-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.25rem 0.625rem;
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 6px;
  font-weight: 600;
  color: #f1f5f9;
}

/* Status Pill */
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.25rem 0.625rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.pill-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.pill-active {
  background-color: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.pill-active .pill-dot {
  background-color: #10b981;
}

.pill-revoked {
  background-color: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.pill-revoked .pill-dot {
  background-color: #ef4444;
}

/* Reapply Badge */
.reapply-badge {
  display: inline-flex;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
}

.reapply-allowed {
  background-color: rgba(56, 189, 248, 0.15);
  color: #38bdf8;
}

.reapply-blocked {
  background-color: rgba(100, 116, 139, 0.2);
  color: #94a3b8;
}

.btn-view-detail {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.375rem 0.75rem;
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 6px;
  color: #38bdf8;
  font-size: 0.8125rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.2s;
}

.btn-view-detail:hover {
  background-color: #0284c7;
  color: #ffffff;
  border-color: #0284c7;
}

/* Pagination */
.table-pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.25rem;
  background-color: #0f172a;
  border-top: 1px solid #334155;
  font-size: 0.875rem;
  color: #94a3b8;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.pagination-buttons {
  display: flex;
  gap: 0.5rem;
}

.btn-page {
  padding: 0.375rem 0.875rem;
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 6px;
  color: #f1f5f9;
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-page:hover:not(:disabled) {
  background-color: #334155;
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.text-muted {
  color: #64748b;
}

.text-right {
  text-align: right;
}

/* Spinner */
.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid rgba(56, 189, 248, 0.2);
  border-top-color: #38bdf8;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 0.75rem;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
