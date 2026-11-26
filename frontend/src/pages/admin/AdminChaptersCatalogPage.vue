<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminChapterCatalogItem } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

// State
const chapters = ref<AdminChapterCatalogItem[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Filters
const searchQuery = ref('')
const statusFilter = ref('all')
const currentPage = ref(1)
const totalPages = ref(1)
const totalChapters = ref(0)
const perPage = ref(20)

// Modal State
const isStatusModalOpen = ref(false)
const targetChapter = ref<AdminChapterCatalogItem | null>(null)
const formStatus = ref<'draft' | 'pending' | 'published' | 'rejected'>('published')
const formRejectionReason = ref('')
const isUpdatingStatus = ref(false)

let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

async function fetchChapters() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getChaptersCatalog({
      q: searchQuery.value || undefined,
      status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      page: currentPage.value,
      per_page: perPage.value,
    })
    chapters.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalChapters.value = res.meta.total
    }
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách chương.')
  } finally {
    isLoading.value = false
  }
}

function handleSearch() {
  currentPage.value = 1
  fetchChapters()
}

function handlePageChange(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  fetchChapters()
}

function openStatusModal(chap: AdminChapterCatalogItem) {
  targetChapter.value = chap
  formStatus.value = chap.status
  formRejectionReason.value = chap.rejection_reason || ''
  isStatusModalOpen.value = true
}

async function handleSaveStatus() {
  if (!targetChapter.value) return
  isUpdatingStatus.value = true
  try {
    const res = await AdminService.updateChapterCatalogStatus(targetChapter.value.id, {
      status: formStatus.value,
      rejection_reason: formRejectionReason.value || undefined,
    })
    targetChapter.value.status = res.data.status
    showToast(res.message || 'Cập nhật trạng thái chương thành công!')
    isStatusModalOpen.value = false
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể cập nhật trạng thái chương.'), 'error')
  } finally {
    isUpdatingStatus.value = false
  }
}

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

onMounted(() => {
  fetchChapters()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-chapters-catalog-page">
    <!-- Toast -->
    <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
      <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
      <span class="toast-text">{{ toastMessage.text }}</span>
    </div>

    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Kho Chương Webtoon (All Chapters Catalog)</h1>
        <p class="page-subtitle">Quản lý toàn bộ danh mục chương, hỗ trợ gỡ bỏ hoặc điều chỉnh trạng thái khi có tranh chấp bản quyền ({{ totalChapters }} chương).</p>
      </div>
    </header>

    <!-- Toolbar Filters -->
    <div class="toolbar">
      <div class="search-wrap">
        <input
          v-model="searchQuery"
          type="text"
          class="search-input"
          placeholder="Tìm theo số chương, tiêu đề..."
          @keyup.enter="handleSearch"
        />
        <button type="button" class="btn btn-secondary btn-sm" @click="handleSearch">🔍 Tìm</button>
      </div>

      <div class="filter-group">
        <label class="filter-label">Trạng thái:</label>
        <select v-model="statusFilter" class="filter-select" @change="handleSearch">
          <option value="all">Tất cả trạng thái</option>
          <option value="published">Đã phát hành (Published)</option>
          <option value="pending">Chờ duyệt (Pending)</option>
          <option value="draft">Bản nháp (Draft)</option>
          <option value="rejected">Bị từ chối / Gỡ bỏ (Rejected)</option>
        </select>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="fetchChapters">Thử lại</button>
    </div>

    <!-- Table Container -->
    <div class="table-container">
      <div v-if="isLoading" class="loading-state">
        <span class="spinner"></span>
        <p>Đang tải danh sách chương...</p>
      </div>

      <table v-else-if="chapters.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 70px">ID</th>
            <th>Bộ truyện liên kết</th>
            <th>Chương</th>
            <th>Tiêu đề chương</th>
            <th style="width: 100px">Số ảnh</th>
            <th style="width: 130px">Trạng thái</th>
            <th>Ngày tạo</th>
            <th style="width: 100px; text-align: right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="ch in chapters" :key="ch.id">
            <td>#{{ ch.id }}</td>
            <td>
              <div class="comic-cell">
                <strong class="comic-name">{{ ch.comic?.title || `Truyện #${ch.comic_id}` }}</strong>
                <span class="text-muted text-xs">Đăng bởi: {{ ch.comic?.user?.name || 'Ẩn danh' }}</span>
              </div>
            </td>
            <td>
              <span class="badge-chapter">Chương {{ ch.chapter_number }}</span>
            </td>
            <td>
              <span v-if="ch.title" class="chapter-title">{{ ch.title }}</span>
              <span v-else class="text-muted">—</span>
            </td>
            <td>
              <span class="badge-images">{{ ch.images_count }} trang</span>
            </td>
            <td>
              <span class="status-badge" :class="ch.status">
                {{ ch.status === 'published' ? 'Đã phát hành' : ch.status === 'pending' ? 'Chờ duyệt' : ch.status === 'draft' ? 'Bản nháp' : 'Gỡ bỏ' }}
              </span>
            </td>
            <td class="text-muted">{{ formatDate(ch.created_at) }}</td>
            <td style="text-align: right">
              <button
                type="button"
                class="btn-icon"
                title="Điều chỉnh trạng thái chương"
                @click="openStatusModal(ch)"
              >
                ⚙️
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-else class="empty-state">
        <span class="empty-icon">📑</span>
        <h3>Không tìm thấy chương nào</h3>
        <p>Thử điều chỉnh từ khóa tìm kiếm hoặc lọc theo trạng thái khác.</p>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="pagination-bar">
        <span>Trang {{ currentPage }} / {{ totalPages }} (Tổng {{ totalChapters }} chương)</span>
        <div class="pagination-buttons">
          <button
            type="button"
            class="btn btn-secondary btn-sm"
            :disabled="currentPage <= 1"
            @click="handlePageChange(currentPage - 1)"
          >
            ← Trước
          </button>
          <button
            type="button"
            class="btn btn-secondary btn-sm"
            :disabled="currentPage >= totalPages"
            @click="handlePageChange(currentPage + 1)"
          >
            Sau →
          </button>
        </div>
      </div>
    </div>

    <!-- Status Change Modal -->
    <div v-if="isStatusModalOpen" class="modal-backdrop" @click.self="isStatusModalOpen = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Điều chỉnh trạng thái Chương {{ targetChapter?.chapter_number }}</h3>
          <button type="button" class="btn-close" @click="isStatusModalOpen = false">✕</button>
        </div>
        <form @submit.prevent="handleSaveStatus">
          <div class="modal-body">
            <p class="text-muted" style="margin-bottom: 16px; font-size: 13px">
              Bộ truyện: <strong>{{ targetChapter?.comic?.title }}</strong>
            </p>

            <div class="form-group">
              <label class="form-label">Trạng thái chương</label>
              <select v-model="formStatus" class="form-input">
                <option value="published">Đã phát hành (Published - Độc giả có thể đọc)</option>
                <option value="pending">Chờ kiểm duyệt (Pending)</option>
                <option value="draft">Bản nháp (Draft - Ẩn khỏi độc giả)</option>
                <option value="rejected">Gỡ bỏ / Từ chối (Rejected - Do vi phạm quy chuẩn)</option>
              </select>
            </div>

            <div v-if="formStatus === 'rejected'" class="form-group">
              <label class="form-label">Lý do từ chối / gỡ bỏ</label>
              <textarea
                v-model="formRejectionReason"
                class="form-textarea"
                rows="3"
                placeholder="Nhập lý do gửi đến tác giả..."
              ></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="isUpdatingStatus" @click="isStatusModalOpen = false">Hủy</button>
            <button type="submit" class="btn btn-primary" :disabled="isUpdatingStatus">
              {{ isUpdatingStatus ? 'Đang lưu...' : 'Lưu trạng thái' }}
            </button>
          </div>
        </form>
      </div>
    </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-chapters-catalog-page {
  padding: 0;
  color: var(--text-main, #e2e8f0);
}

.page-header {
  margin-bottom: 24px;
}

.page-title {
  font-size: 24px;
  font-weight: 700;
  color: #fff;
}

.page-subtitle {
  color: #94a3b8;
  font-size: 14px;
  margin-top: 4px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  gap: 16px;
  flex-wrap: wrap;
}

.search-wrap {
  display: flex;
  gap: 8px;
  flex: 1;
  max-width: 420px;
}

.search-input {
  flex: 1;
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 14px;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-label {
  font-size: 13px;
  color: #94a3b8;
}

.filter-select {
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 14px;
}

.table-container {
  background: #1e293b;
  border-radius: 12px;
  border: 1px solid #334155;
  overflow: hidden;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 14px;
}

.data-table th,
.data-table td {
  padding: 14px 18px;
  border-bottom: 1px solid #334155;
}

.data-table th {
  background: #0f172a;
  color: #94a3b8;
  font-weight: 600;
  text-transform: uppercase;
  font-size: 12px;
  letter-spacing: 0.05em;
}

.comic-cell {
  display: flex;
  flex-direction: column;
}

.comic-name {
  color: #fff;
}

.badge-chapter {
  background: #334155;
  color: #38bdf8;
  padding: 4px 8px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 12px;
}

.badge-images {
  background: #0f172a;
  color: #94a3b8;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
}

.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.status-badge.published {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.status-badge.pending {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}

.status-badge.draft {
  background: rgba(100, 116, 139, 0.15);
  color: #94a3b8;
}

.status-badge.rejected {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.btn-icon {
  background: transparent;
  border: none;
  font-size: 16px;
  cursor: pointer;
  padding: 6px;
  border-radius: 6px;
}

.btn-icon:hover {
  background: #334155;
}

.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 20px;
  background: #0f172a;
  border-top: 1px solid #334155;
  font-size: 13px;
  color: #94a3b8;
}

.pagination-buttons {
  display: flex;
  gap: 8px;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 8px;
  border: none;
  cursor: pointer;
}

.btn-primary {
  background: #0284c7;
  color: #fff;
}

.btn-secondary {
  background: #334155;
  color: #e2e8f0;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.modal-box {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  width: 100%;
  max-width: 500px;
  overflow: hidden;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 18px 24px;
  border-bottom: 1px solid #334155;
}

.modal-header h3 {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
}

.btn-close {
  background: transparent;
  border: none;
  color: #94a3b8;
  font-size: 18px;
  cursor: pointer;
}

.modal-body {
  padding: 20px 24px;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid #334155;
  background: #0f172a;
}

.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
  margin-bottom: 6px;
}

.form-input,
.form-textarea {
  width: 100%;
  background: #0f172a;
  border: 1px solid #334155;
  color: #fff;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 14px;
}

.toast-wrapper {
  position: fixed;
  top: 24px;
  right: 24px;
  background: #0f172a;
  border: 1px solid #334155;
  padding: 14px 20px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  z-index: 1100;
}

.toast-wrapper.success {
  border-color: #10b981;
}

.toast-wrapper.error {
  border-color: #ef4444;
}

.loading-state,
.empty-state {
  padding: 48px 24px;
  text-align: center;
  color: #94a3b8;
}

.text-xs {
  font-size: 11px;
}
</style>
