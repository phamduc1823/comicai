<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminComicCatalogItem } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

// State
const comics = ref<AdminComicCatalogItem[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Filters
const searchQuery = ref('')
const creatorQuery = ref('')
const approvalStatusFilter = ref('all')
const publicationStatusFilter = ref('all')
const vipFilter = ref('all')
const currentPage = ref(1)
const totalPages = ref(1)
const totalComics = ref(0)
const perPage = ref(20)

// VIP Edit Modal
const isVipModalOpen = ref(false)
const targetComic = ref<AdminComicCatalogItem | null>(null)
const formIsVip = ref(false)
const formPrice = ref(0)
const isUpdatingVip = ref(false)

// Status / Approval Modal
const isStatusModalOpen = ref(false)
const formStatus = ref<'ongoing' | 'completed' | 'hiatus'>('ongoing')
const formApprovalStatus = ref<'draft' | 'pending' | 'approved' | 'rejected'>('approved')
const formIsFeatured = ref(false)
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

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

async function fetchComics() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getComicsCatalog({
      q: searchQuery.value || undefined,
      creator: creatorQuery.value || undefined,
      approval_status: approvalStatusFilter.value !== 'all' ? approvalStatusFilter.value : undefined,
      status: publicationStatusFilter.value !== 'all' ? publicationStatusFilter.value : undefined,
      is_vip: vipFilter.value !== 'all' ? vipFilter.value : undefined,
      page: currentPage.value,
      per_page: perPage.value,
    })
    comics.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalComics.value = res.meta.total
    }
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh mục truyện.')
  } finally {
    isLoading.value = false
  }
}

function handleSearch() {
  currentPage.value = 1
  fetchComics()
}

function handlePageChange(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  fetchComics()
}

// Open VIP Modal
function openVipModal(comic: AdminComicCatalogItem) {
  targetComic.value = comic
  formIsVip.value = comic.is_vip
  formPrice.value = comic.price || 0
  isVipModalOpen.value = true
}

async function handleSaveVip() {
  if (!targetComic.value) return
  isUpdatingVip.value = true
  try {
    const res = await AdminService.updateComicVip(targetComic.value.id, {
      is_vip: formIsVip.value,
      price: formIsVip.value ? Number(formPrice.value) : 0,
    })
    targetComic.value.is_vip = res.data.is_vip
    targetComic.value.price = res.data.price
    showToast(res.message || 'Cập nhật thiết lập VIP thành công!')
    isVipModalOpen.value = false
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể cập nhật thiết lập VIP.'), 'error')
  } finally {
    isUpdatingVip.value = false
  }
}

// Open Status Modal
function openStatusModal(comic: AdminComicCatalogItem) {
  targetComic.value = comic
  formStatus.value = comic.status
  formApprovalStatus.value = comic.approval_status
  formIsFeatured.value = comic.is_featured
  formRejectionReason.value = ''
  isStatusModalOpen.value = true
}

async function handleSaveStatus() {
  if (!targetComic.value) return
  isUpdatingStatus.value = true
  try {
    // Update publication status & featured
    await AdminService.updateComicStatus(targetComic.value.id, {
      status: formStatus.value,
      is_featured: formIsFeatured.value,
    })

    // If approval status changed
    if (formApprovalStatus.value !== targetComic.value.approval_status) {
      await AdminService.updateComicApprovalStatus(targetComic.value.id, {
        approval_status: formApprovalStatus.value,
        rejection_reason: formRejectionReason.value || undefined,
      })
    }

    showToast('Cập nhật trạng thái truyện thành công!')
    isStatusModalOpen.value = false
    fetchComics()
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể cập nhật trạng thái truyện.'), 'error')
  } finally {
    isUpdatingStatus.value = false
  }
}

onMounted(() => {
  fetchComics()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-comics-catalog-page">
    <!-- Toast -->
    <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
      <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
      <span class="toast-text">{{ toastMessage.text }}</span>
    </div>

    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Quản lý truyện (Comics Management)</h1>
        <p class="page-subtitle">Quản lý toàn bộ danh mục tác phẩm, xem chi tiết, điều chỉnh VIP & giá Coin, và quản lý chương thuộc từng truyện ({{ totalComics }} bộ truyện).</p>
      </div>
    </header>

    <!-- Toolbar Filters -->
    <div class="toolbar">
      <div class="search-wrap">
        <input
          v-model="searchQuery"
          type="text"
          class="search-input"
          placeholder="Tìm theo tên truyện, tác giả, slug..."
          @keyup.enter="handleSearch"
        />
        <input
          v-model="creatorQuery"
          type="text"
          class="search-input creator-input"
          placeholder="Lọc theo Creator (tên, email)..."
          @keyup.enter="handleSearch"
        />
        <button type="button" class="btn btn-secondary btn-sm" @click="handleSearch">🔍 Tìm</button>
      </div>

      <div class="filters-row">
        <div class="filter-item">
          <label class="filter-label">Duyệt:</label>
          <select v-model="approvalStatusFilter" class="filter-select" @change="handleSearch">
            <option value="all">Tất cả duyệt</option>
            <option value="approved">Đã duyệt (Approved)</option>
            <option value="pending">Chờ duyệt (Pending)</option>
            <option value="draft">Bản nháp (Draft)</option>
            <option value="rejected">Bị từ chối (Rejected)</option>
          </select>
        </div>

        <div class="filter-item">
          <label class="filter-label">Tiến độ:</label>
          <select v-model="publicationStatusFilter" class="filter-select" @change="handleSearch">
            <option value="all">Tất cả tiến độ</option>
            <option value="ongoing">Đang tiến hành</option>
            <option value="completed">Đã hoàn thành</option>
            <option value="hiatus">Tạm ngưng</option>
          </select>
        </div>

        <div class="filter-item">
          <label class="filter-label">Mô hình VIP:</label>
          <select v-model="vipFilter" class="filter-select" @change="handleSearch">
            <option value="all">Tất cả</option>
            <option value="1">Truyện VIP (Có phí Coin)</option>
            <option value="0">Truyện miễn phí</option>
          </select>
        </div>

        <div class="filter-item">
          <label class="filter-label">Hiển thị:</label>
          <select v-model.number="perPage" class="filter-select" @change="handleSearch">
            <option :value="10">10 / trang</option>
            <option :value="20">20 / trang</option>
            <option :value="50">50 / trang</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="fetchComics">Thử lại</button>
    </div>

    <!-- Table Container -->
    <div class="table-container">
      <div v-if="isLoading" class="loading-state">
        <span class="spinner"></span>
        <p>Đang tải danh mục truyện tranh...</p>
      </div>

      <table v-else-if="comics.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 60px">ID</th>
            <th>Bộ truyện</th>
            <th>Tác giả / Người đăng</th>
            <th>Duyệt</th>
            <th>Tiến độ</th>
            <th>Mô hình VIP</th>
            <th>Chương</th>
            <th>Cập nhật</th>
            <th style="width: 170px; text-align: right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in comics" :key="c.id">
            <td>#{{ c.id }}</td>
            <td>
              <div class="comic-cell">
                <div class="comic-title">
                  <strong>{{ c.title }}</strong>
                  <span v-if="c.is_featured" class="badge-featured" title="Đang nổi bật">⭐ Nổi bật</span>
                </div>
                <div class="comic-slug">{{ c.slug }}</div>
              </div>
            </td>
            <td>
              <div class="author-cell">
                <div>{{ c.author_name }}</div>
                <div class="text-muted text-xs">Đăng bởi: {{ c.user?.name || 'Ẩn danh' }}</div>
              </div>
            </td>
            <td>
              <span class="approval-badge" :class="c.approval_status">
                {{ c.approval_status === 'approved' ? 'Đã duyệt' : c.approval_status === 'pending' ? 'Chờ duyệt' : c.approval_status === 'draft' ? 'Bản nháp' : 'Từ chối' }}
              </span>
            </td>
            <td>
              <span class="status-pill-text">{{ c.status }}</span>
            </td>
            <td>
              <span v-if="c.is_vip" class="vip-badge">
                💎 VIP ({{ c.price }} Coin)
              </span>
              <span v-else class="free-badge">Miễn phí</span>
            </td>
            <td>
              <span class="badge-count">{{ c.chapters_count }} chương</span>
            </td>
            <td>
              <span class="text-xs text-muted">{{ formatDate(c.updated_at) }}</span>
            </td>
            <td style="text-align: right">
              <div class="actions-group">
                <RouterLink
                  :to="'/admin/comics/' + c.id"
                  class="btn btn-sm btn-primary action-btn-link"
                  title="Chi tiết truyện & Quản lý chương"
                >
                  📖 Chi tiết
                </RouterLink>
                <button
                  type="button"
                  class="btn-icon"
                  title="Cài đặt VIP & Giá Coin"
                  @click="openVipModal(c)"
                >
                  💎
                </button>
                <button
                  type="button"
                  class="btn-icon"
                  title="Thay đổi trạng thái & duyệt"
                  @click="openStatusModal(c)"
                >
                  ⚙️
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-else class="empty-state">
        <span class="empty-icon">📚</span>
        <h3>Không tìm thấy truyện tranh</h3>
        <p>Thử điều chỉnh từ khóa tìm kiếm hoặc các tiêu chí bộ lọc.</p>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="pagination-bar">
        <span>Trang {{ currentPage }} / {{ totalPages }} (Tổng {{ totalComics }} truyện)</span>
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

    <!-- Edit VIP Modal -->
    <div v-if="isVipModalOpen" class="modal-backdrop" @click.self="isVipModalOpen = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Thiết lập VIP: {{ targetComic?.title }}</h3>
          <button type="button" class="btn-close" @click="isVipModalOpen = false">✕</button>
        </div>
        <form @submit.prevent="handleSaveVip">
          <div class="modal-body">
            <p class="text-muted" style="margin-bottom: 16px; font-size: 13px">
              Theo quy tắc nghiệp vụ <strong>COMIC_PURCHASE_ONLY</strong>, độc giả chỉ trả Coin 1 lần cho cả bộ truyện để đọc tất cả các chương (bao gồm cả chương đăng thêm trong tương lai).
            </p>

            <div class="form-group checkbox-group">
              <label class="checkbox-label">
                <input v-model="formIsVip" type="checkbox" />
                <span>Đặt bộ truyện này thành <strong>Truyện VIP (Thu phí bằng Coin)</strong></span>
              </label>
            </div>

            <div v-if="formIsVip" class="form-group">
              <label class="form-label">Giá mua trọn bộ (Coin) <span class="req">*</span></label>
              <input
                v-model.number="formPrice"
                type="number"
                min="1"
                max="1000000"
                class="form-input"
                required
              />
              <span class="field-hint">Số Coin người dùng cần chi trả để sở hữu quyền đọc vĩnh viễn.</span>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="isUpdatingVip" @click="isVipModalOpen = false">Hủy</button>
            <button type="submit" class="btn btn-primary" :disabled="isUpdatingVip">
              {{ isUpdatingVip ? 'Đang lưu...' : 'Lưu thiết lập VIP' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Status & Approval Modal -->
    <div v-if="isStatusModalOpen" class="modal-backdrop" @click.self="isStatusModalOpen = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Điều chỉnh trạng thái: {{ targetComic?.title }}</h3>
          <button type="button" class="btn-close" @click="isStatusModalOpen = false">✕</button>
        </div>
        <form @submit.prevent="handleSaveStatus">
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Trạng thái duyệt (Approval Status)</label>
              <select v-model="formApprovalStatus" class="form-input">
                <option value="approved">Đã duyệt (Approved - Hiển thị công khai)</option>
                <option value="pending">Chờ duyệt (Pending)</option>
                <option value="draft">Bản nháp (Draft - Ẩn khỏi trang chủ)</option>
                <option value="rejected">Từ chối / Thu hồi (Rejected)</option>
              </select>
            </div>

            <div v-if="formApprovalStatus === 'rejected'" class="form-group">
              <label class="form-label">Lý do từ chối / gỡ bỏ</label>
              <textarea
                v-model="formRejectionReason"
                class="form-textarea"
                rows="3"
                placeholder="Nhập lý do gửi đến tác giả..."
              ></textarea>
            </div>

            <div class="form-group">
              <label class="form-label">Tiến độ xuất bản (Status)</label>
              <select v-model="formStatus" class="form-input">
                <option value="ongoing">Đang tiến hành (Ongoing)</option>
                <option value="completed">Đã hoàn thành (Completed)</option>
                <option value="hiatus">Tạm ngưng (Hiatus)</option>
              </select>
            </div>

            <div class="form-group checkbox-group">
              <label class="checkbox-label">
                <input v-model="formIsFeatured" type="checkbox" />
                <span>Đánh dấu truyện Nổi Bật (Featured)</span>
              </label>
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
.admin-comics-catalog-page {
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
  max-width: 580px;
}

.creator-input {
  max-width: 220px;
}

.actions-group {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  justify-content: flex-end;
}

.action-btn-link {
  text-decoration: none;
  font-size: 12px;
  padding: 4px 10px;
  display: inline-flex;
  align-items: center;
  white-space: nowrap;
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

.filters-row {
  display: flex;
  gap: 16px;
  align-items: center;
  flex-wrap: wrap;
}

.filter-item {
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

.comic-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.comic-title strong {
  color: #fff;
}

.badge-featured {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
}

.comic-slug {
  font-size: 12px;
  color: #64748b;
  font-family: monospace;
}

.approval-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.approval-badge.approved {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.approval-badge.pending {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}

.approval-badge.draft {
  background: rgba(100, 116, 139, 0.15);
  color: #94a3b8;
}

.approval-badge.rejected {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.vip-badge {
  background: rgba(168, 85, 247, 0.15);
  color: #c084fc;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
}

.free-badge {
  color: #94a3b8;
  font-size: 13px;
}

.badge-count {
  background: #334155;
  color: #38bdf8;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
}

.btn-icon {
  background: transparent;
  border: none;
  font-size: 16px;
  cursor: pointer;
  padding: 6px;
  border-radius: 6px;
  margin-left: 4px;
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
  max-width: 520px;
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

.field-hint {
  font-size: 12px;
  color: #64748b;
  margin-top: 4px;
  display: block;
}

.checkbox-group {
  margin-top: 12px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
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
