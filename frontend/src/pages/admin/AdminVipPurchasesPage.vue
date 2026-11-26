<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminComicPurchaseItem } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

// State
const purchases = ref<AdminComicPurchaseItem[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Filters
const searchQuery = ref('')
const statusFilter = ref('all')
const currentPage = ref(1)
const totalPages = ref(1)
const totalPurchases = ref(0)
const perPage = ref(20)

// Refund Modal
const isRefundModalOpen = ref(false)
const selectedPurchase = ref<AdminComicPurchaseItem | null>(null)
const refundReason = ref('')
const isRefunding = ref(false)
const refundError = ref<string | null>(null)

let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

async function fetchPurchases() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getComicPurchases({
      q: searchQuery.value || undefined,
      status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      page: currentPage.value,
      per_page: perPage.value,
    })
    purchases.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalPurchases.value = res.meta.total
    }
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách mua truyện VIP.')
  } finally {
    isLoading.value = false
  }
}

function handleSearch() {
  currentPage.value = 1
  fetchPurchases()
}

function handlePageChange(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  fetchPurchases()
}

function openRefundModal(item: AdminComicPurchaseItem) {
  selectedPurchase.value = item
  refundReason.value = ''
  refundError.value = null
  isRefundModalOpen.value = true
}

async function handleConfirmRefund() {
  if (!selectedPurchase.value) return
  if (!refundReason.value.trim()) {
    refundError.value = 'Vui lòng nhập lý do hoàn tiền.'
    return
  }

  isRefunding.value = true
  refundError.value = null
  try {
    const res = await AdminService.refundPurchase({
      transaction_id: selectedPurchase.value.transaction_id,
      reason: refundReason.value.trim(),
    })
    showToast(res.message || 'Hoàn tiền và thu hồi quyền đọc thành công!')
    isRefundModalOpen.value = false
    fetchPurchases()
  } catch (err: unknown) {
    refundError.value = getErrorMessage(err, 'Xử lý hoàn tiền thất bại.')
  } finally {
    isRefunding.value = false
  }
}

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

onMounted(() => {
  fetchPurchases()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-vip-purchases-page">
    <!-- Toast -->
    <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
      <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
      <span class="toast-text">{{ toastMessage.text }}</span>
    </div>

    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Mua Truyện VIP & Hoàn Tiền (VIP Purchases & Refund)</h1>
        <p class="page-subtitle">Theo dõi lịch sử mua truyện VIP bằng Coin và xử lý hoàn tiền, thu hồi quyền đọc khi cần thiết ({{ totalPurchases }} lượt mua).</p>
      </div>
    </header>

    <!-- Toolbar Filters -->
    <div class="toolbar">
      <div class="search-wrap">
        <input
          v-model="searchQuery"
          type="text"
          class="search-input"
          placeholder="Tìm theo tên truyện hoặc tên người mua..."
          @keyup.enter="handleSearch"
        />
        <button type="button" class="btn btn-secondary btn-sm" @click="handleSearch">🔍 Tìm</button>
      </div>

      <div class="filter-group">
        <label class="filter-label">Trạng thái quyền đọc:</label>
        <select v-model="statusFilter" class="filter-select" @change="handleSearch">
          <option value="all">Tất cả</option>
          <option value="active">Đang hiệu lực (Active)</option>
          <option value="revoked">Đã hoàn tiền / Thu hồi (Revoked)</option>
        </select>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="fetchPurchases">Thử lại</button>
    </div>

    <!-- Purchases Table -->
    <div class="table-container">
      <div v-if="isLoading" class="loading-state">
        <span class="spinner"></span>
        <p>Đang tải danh sách giao dịch mua VIP...</p>
      </div>

      <table v-else-if="purchases.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 70px">ID</th>
            <th>Bộ truyện VIP</th>
            <th>Người mua</th>
            <th>Giá mua</th>
            <th>Trạng thái</th>
            <th>Mã GD</th>
            <th>Thời gian mua</th>
            <th style="width: 140px; text-align: right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in purchases" :key="p.id">
            <td>#{{ p.id }}</td>
            <td>
              <div class="comic-cell">
                <strong>{{ p.comic?.title || `Truyện #${p.comic_id}` }}</strong>
                <span class="text-muted text-xs">Slug: {{ p.comic?.slug }}</span>
              </div>
            </td>
            <td>
              <div class="user-cell">
                <strong>{{ p.user?.name || `User #${p.user_id}` }}</strong>
                <span class="text-muted text-xs">{{ p.user?.email }}</span>
              </div>
            </td>
            <td>
              <span class="coin-badge">💰 {{ p.price_paid }} Coin</span>
            </td>
            <td>
              <span class="status-badge" :class="p.status">
                {{ p.status === 'active' ? 'Đang hiệu lực' : 'Đã thu hồi' }}
              </span>
            </td>
            <td>
              <code>#{{ p.transaction_id }}</code>
            </td>
            <td class="text-muted">{{ formatDate(p.purchased_at) }}</td>
            <td style="text-align: right">
              <button
                v-if="p.status === 'active'"
                type="button"
                class="btn btn-sm btn-danger-outline"
                title="Hoàn tiền và thu hồi quyền đọc"
                @click="openRefundModal(p)"
              >
                💸 Hoàn tiền
              </button>
              <span v-else class="text-muted text-xs">Đã thu hồi lúc {{ formatDate(p.revoked_at) }}</span>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-else class="empty-state">
        <span class="empty-icon">💎</span>
        <h3>Không tìm thấy giao dịch mua VIP nào</h3>
        <p>Chưa có độc giả nào mua truyện VIP hoặc không khớp với bộ lọc.</p>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="pagination-bar">
        <span>Trang {{ currentPage }} / {{ totalPages }} (Tổng {{ totalPurchases }} lượt mua)</span>
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

    <!-- Refund Modal -->
    <div v-if="isRefundModalOpen" class="modal-backdrop" @click.self="isRefundModalOpen = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Xác nhận hoàn tiền mua VIP</h3>
          <button type="button" class="btn-close" @click="isRefundModalOpen = false">✕</button>
        </div>
        <form @submit.prevent="handleConfirmRefund">
          <div class="modal-body">
            <div v-if="refundError" class="alert-error" role="alert">
              {{ refundError }}
            </div>

            <div class="refund-info-box">
              <p><strong>Truyện:</strong> {{ selectedPurchase?.comic?.title }}</p>
              <p><strong>Độc giả:</strong> {{ selectedPurchase?.user?.name }} ({{ selectedPurchase?.user?.email }})</p>
              <p><strong>Số Coin hoàn trả:</strong> <span class="text-amber">💰 {{ selectedPurchase?.price_paid }} Coin</span></p>
            </div>

            <p class="refund-warning">
              ⚠️ <strong>Lưu ý:</strong> Sau khi hoàn tiền, hệ thống sẽ:
              <br />1. Cộng lại <strong>{{ selectedPurchase?.price_paid }} Coin</strong> vào ví của độc giả.
              <br />2. Thu hồi quyền đọc VIP vĩnh viễn (độc giả sẽ bị chặn truy cập các chương VIP).
              <br />3. Ghi vết giao dịch hoàn tiền trên sổ cái CoinTransaction.
            </p>

            <div class="form-group">
              <label class="form-label">Lý do hoàn tiền <span class="req">*</span></label>
              <textarea
                v-model="refundReason"
                class="form-textarea"
                rows="3"
                placeholder="Ví dụ: Độc giả khiếu nại chất lượng chương hoặc truyện bị gỡ bỏ bản quyền..."
                required
              ></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="isRefunding" @click="isRefundModalOpen = false">Hủy</button>
            <button type="submit" class="btn btn-danger" :disabled="isRefunding">
              {{ isRefunding ? 'Đang xử lý...' : 'Xác nhận hoàn Coin & Thu hồi' }}
            </button>
          </div>
        </form>
      </div>
    </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-vip-purchases-page {
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

.comic-cell,
.user-cell {
  display: flex;
  flex-direction: column;
}

.comic-cell strong,
.user-cell strong {
  color: #fff;
}

.coin-badge {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
}

.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.status-badge.active {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.status-badge.revoked {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.btn-danger-outline {
  background: transparent;
  border: 1px solid #ef4444;
  color: #f87171;
  padding: 6px 12px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.btn-danger-outline:hover {
  background: rgba(239, 68, 68, 0.15);
}

.refund-info-box {
  background: #0f172a;
  padding: 14px;
  border-radius: 8px;
  border: 1px solid #334155;
  margin-bottom: 14px;
  font-size: 13px;
}

.refund-info-box p {
  margin-bottom: 6px;
}

.refund-warning {
  font-size: 13px;
  color: #cbd5e1;
  background: rgba(245, 158, 11, 0.1);
  border: 1px solid rgba(245, 158, 11, 0.2);
  padding: 12px;
  border-radius: 8px;
  margin-bottom: 16px;
  line-height: 1.5;
}

.text-amber {
  color: #fbbf24;
  font-weight: 700;
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

.btn-danger {
  background: #ef4444;
  color: #fff;
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
