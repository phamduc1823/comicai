<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminWalletItem, AdminCoinTransactionItem } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

// Active Tab
const activeTab = ref<'transactions' | 'wallets'>('transactions')

// State
const transactions = ref<AdminCoinTransactionItem[]>([])
const wallets = ref<AdminWalletItem[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)

// Filters
const searchQuery = ref('')
const typeFilter = ref('all')
const currentPage = ref(1)
const totalPages = ref(1)
const totalItems = ref(0)
const perPage = ref(20)

async function fetchTransactions() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getCoinTransactions({
      q: searchQuery.value || undefined,
      type: typeFilter.value !== 'all' ? typeFilter.value : undefined,
      page: currentPage.value,
      per_page: perPage.value,
    })
    transactions.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalItems.value = res.meta.total
    }
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải sổ cái giao dịch Coin.')
  } finally {
    isLoading.value = false
  }
}

async function fetchWallets() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getWallets({
      q: searchQuery.value || undefined,
      page: currentPage.value,
      per_page: perPage.value,
    })
    wallets.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalItems.value = res.meta.total
    }
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách ví người dùng.')
  } finally {
    isLoading.value = false
  }
}

function handleTabChange(tab: 'transactions' | 'wallets') {
  activeTab.value = tab
  currentPage.value = 1
  searchQuery.value = ''
  if (tab === 'transactions') {
    fetchTransactions()
  } else {
    fetchWallets()
  }
}

function handleSearch() {
  currentPage.value = 1
  if (activeTab.value === 'transactions') {
    fetchTransactions()
  } else {
    fetchWallets()
  }
}

function handlePageChange(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  if (activeTab.value === 'transactions') {
    fetchTransactions()
  } else {
    fetchWallets()
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
  fetchTransactions()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-wallet-transactions-page">
    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Sổ Cái & Ví Coin (Wallet & Ledger)</h1>
        <p class="page-subtitle">Sổ cái giao dịch ảo ComicAI lưu vết bất biến (Append-only Ledger) tất cả luồng nạp, tiêu và hoàn trả Coin.</p>
      </div>
    </header>

    <!-- Tabs Nav -->
    <div class="tabs-container">
      <button
        type="button"
        class="tab-btn"
        :class="{ active: activeTab === 'transactions' }"
        @click="handleTabChange('transactions')"
      >
        📜 Sổ cái giao dịch (Transactions)
      </button>
      <button
        type="button"
        class="tab-btn"
        :class="{ active: activeTab === 'wallets' }"
        @click="handleTabChange('wallets')"
      >
        👛 Ví người dùng (Wallets)
      </button>
    </div>

    <!-- Notice -->
    <div v-if="activeTab === 'transactions'" class="ledger-notice">
      <span>🛡️</span>
      <p>
        <strong>Nguyên tắc bất biến:</strong> Toàn bộ bản ghi trên sổ cái <code>coin_transactions</code> không thể chỉnh sửa hoặc xóa bỏ. Mọi thao tác đảo ngược giao dịch bắt buộc phải thực hiện thông qua giao dịch hoàn tiền (Refund transaction).
      </p>
    </div>

    <!-- Toolbar Filters -->
    <div class="toolbar">
      <div class="search-wrap">
        <input
          v-model="searchQuery"
          type="text"
          class="search-input"
          :placeholder="activeTab === 'transactions' ? 'Tìm theo mô tả, ID, tên người dùng...' : 'Tìm theo tên, email người dùng...'"
          @keyup.enter="handleSearch"
        />
        <button type="button" class="btn btn-secondary btn-sm" @click="handleSearch">🔍 Tìm</button>
      </div>

      <div v-if="activeTab === 'transactions'" class="filter-group">
        <label class="filter-label">Loại giao dịch:</label>
        <select v-model="typeFilter" class="filter-select" @change="handleSearch">
          <option value="all">Tất cả loại giao dịch</option>
          <option value="comic_purchase">Mua truyện VIP (comic_purchase)</option>
          <option value="refund">Hoàn tiền (refund)</option>
          <option value="welcome_bonus">Thưởng chào mừng (welcome_bonus)</option>
          <option value="faucet">Điểm danh / Vòi Coin (faucet)</option>
          <option value="chapter_unlock">Mở khóa chương cũ (chapter_unlock)</option>
        </select>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="handleTabChange(activeTab)">Thử lại</button>
    </div>

    <!-- Table Container -->
    <div class="table-container">
      <div v-if="isLoading" class="loading-state">
        <span class="spinner"></span>
        <p>Đang tải dữ liệu sổ cái...</p>
      </div>

      <!-- Transactions Table -->
      <table v-else-if="activeTab === 'transactions' && transactions.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 70px">ID</th>
            <th>Người dùng</th>
            <th>Loại giao dịch</th>
            <th>Số Coin</th>
            <th>Trước GD</th>
            <th>Sau GD</th>
            <th>Mô tả chi tiết</th>
            <th>Thời gian</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tx in transactions" :key="tx.id">
            <td>#{{ tx.id }}</td>
            <td>
              <div class="user-cell">
                <strong>{{ tx.user?.name || `User #${tx.user_id}` }}</strong>
                <span class="user-email">{{ tx.user?.email }}</span>
              </div>
            </td>
            <td>
              <span class="type-badge" :class="tx.type">{{ tx.type }}</span>
            </td>
            <td>
              <strong :class="tx.amount >= 0 ? 'text-green' : 'text-red'">
                {{ tx.amount >= 0 ? `+${tx.amount}` : tx.amount }}
              </strong>
            </td>
            <td class="text-muted">{{ tx.balance_before }}</td>
            <td><strong>{{ tx.balance_after }}</strong></td>
            <td>
              <span class="desc-text">{{ tx.description || '—' }}</span>
            </td>
            <td class="text-muted">{{ formatDate(tx.created_at) }}</td>
          </tr>
        </tbody>
      </table>

      <!-- Wallets Table -->
      <table v-else-if="activeTab === 'wallets' && wallets.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 70px">ID Ví</th>
            <th>Chủ sở hữu</th>
            <th>Vai trò</th>
            <th>Số dư Coin</th>
            <th>Trạng thái TK</th>
            <th>Cập nhật gần nhất</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="w in wallets" :key="w.id">
            <td>#{{ w.id }}</td>
            <td>
              <div class="user-cell">
                <strong>{{ w.user?.name || `User #${w.user_id}` }}</strong>
                <span class="user-email">{{ w.user?.email }}</span>
              </div>
            </td>
            <td>
              <span class="role-badge">{{ w.user?.role || 'reader' }}</span>
            </td>
            <td>
              <span class="coin-pill">💰 {{ w.balance }} Coin</span>
            </td>
            <td>
              <span class="status-badge" :class="w.user?.is_active ? 'active' : 'locked'">
                {{ w.user?.is_active ? 'Hoạt động' : 'Đã khóa' }}
              </span>
            </td>
            <td class="text-muted">{{ formatDate(w.updated_at) }}</td>
          </tr>
        </tbody>
      </table>

      <!-- Empty State -->
      <div v-else class="empty-state">
        <span class="empty-icon">📜</span>
        <h3>Không tìm thấy dữ liệu</h3>
        <p>Không có bản ghi nào phù hợp với điều kiện tìm kiếm.</p>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="pagination-bar">
        <span>Trang {{ currentPage }} / {{ totalPages }} (Tổng {{ totalItems }} bản ghi)</span>
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
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-wallet-transactions-page {
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

.tabs-container {
  display: flex;
  gap: 12px;
  border-bottom: 1px solid #334155;
  margin-bottom: 20px;
}

.tab-btn {
  background: transparent;
  border: none;
  color: #94a3b8;
  padding: 12px 20px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.2s;
}

.tab-btn.active {
  color: #38bdf8;
  border-bottom-color: #38bdf8;
}

.ledger-notice {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: rgba(56, 189, 248, 0.08);
  border: 1px solid rgba(56, 189, 248, 0.2);
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 20px;
  font-size: 13px;
  color: #cbd5e1;
}

.ledger-notice code {
  background: #0f172a;
  padding: 2px 6px;
  border-radius: 4px;
  color: #38bdf8;
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
  max-width: 450px;
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

.user-cell {
  display: flex;
  flex-direction: column;
}

.user-email {
  font-size: 12px;
  color: #94a3b8;
}

.type-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.type-badge.comic_purchase {
  background: rgba(168, 85, 247, 0.15);
  color: #c084fc;
}

.type-badge.refund {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.type-badge.welcome_bonus,
.type-badge.faucet {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.coin-pill {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 700;
}

.role-badge {
  background: #334155;
  color: #94a3b8;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
}

.status-badge.active {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.status-badge.locked {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.text-green {
  color: #34d399;
}

.text-red {
  color: #f87171;
}

.desc-text {
  max-width: 280px;
  display: inline-block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
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

.btn-secondary {
  background: #334155;
  color: #e2e8f0;
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid #ef4444;
  color: #fca5a5;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
}

.loading-state,
.empty-state {
  padding: 48px 24px;
  text-align: center;
  color: #94a3b8;
}
</style>
