<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useWalletStore } from '@/stores/wallet'
import type { TransactionType } from '@/types/wallet'

const authStore = useAuthStore()
const walletStore = useWalletStore()

// State
const selectedType = ref<TransactionType | ''>('')
const currentPage = ref(1)
const faucetSuccessMessage = ref<string | null>(null)
const faucetErrorMessage = ref<string | null>(null)

const transactionTypes: { label: string; value: TransactionType | '' }[] = [
  { label: 'Tất cả', value: '' },
  { label: 'Mở khóa VIP', value: 'chapter_unlock' },
  { label: 'Coin thử nghiệm', value: 'faucet' },
  { label: 'Thưởng chào mừng', value: 'welcome_bonus' },
  { label: 'Hoàn Coin', value: 'refund' },
]

onMounted(async () => {
  if (authStore.isAuthenticated) {
    await Promise.all([
      walletStore.fetchWallet(true),
      loadTransactions(1),
    ])
  }
})

watch(selectedType, () => {
  currentPage.value = 1
  loadTransactions(1)
})

async function loadTransactions(page: number) {
  currentPage.value = page
  await walletStore.fetchTransactions(page, 15, selectedType.value || undefined)
}

async function handleClaimFaucet() {
  if (walletStore.isClaimingFaucet || !walletStore.canClaimFaucet) return
  faucetSuccessMessage.value = null
  faucetErrorMessage.value = null

  try {
    const res = await walletStore.claimFaucet()
    faucetSuccessMessage.value = `Nhận thành công ${res.faucet_amount} Coin! Số dư mới: ${res.balance_after} Coin (còn ${res.faucet_remaining_today} lượt hôm nay).`
    // Reload first page of transactions to display the new faucet ledger entry
    loadTransactions(1)
  } catch (err: unknown) {
    faucetErrorMessage.value = walletStore.walletError || 'Không thể nhận Coin thử nghiệm. Vui lòng thử lại sau.'
  }
}

function formatTransactionType(type: TransactionType): { label: string; isCredit: boolean } {
  switch (type) {
    case 'welcome_bonus':
      return { label: 'Thưởng chào mừng', isCredit: true }
    case 'faucet':
      return { label: 'Coin thử nghiệm', isCredit: true }
    case 'chapter_unlock':
      return { label: 'Mở khóa chương VIP', isCredit: false }
    case 'refund':
      return { label: 'Hoàn Coin', isCredit: true }
    default:
      return { label: type, isCredit: false }
  }
}

function formatDate(dateStr: string): string {
  try {
    const d = new Date(dateStr)
    return d.toLocaleString('vi-VN', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return dateStr
  }
}
</script>

<template>
  <div class="wallet-page container">
    <header class="wallet-header">
      <div class="header-breadcrumb">
        <RouterLink to="/" class="breadcrumb-link">Trang chủ</RouterLink>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Ví Coin</span>
      </div>
      <h1 class="wallet-title">Quản lý Ví Coin & Giao dịch</h1>
      <p class="wallet-subtitle">
        Sử dụng Coin để mở khóa các chương truyện VIP có bản quyền trên BlueToon.
      </p>
    </header>

    <!-- Top Grid: Balance Card & Faucet Card -->
    <div class="wallet-cards-grid">
      <!-- Balance Card -->
      <section class="wallet-card balance-card" aria-label="Số dư ví">
        <div class="card-icon-wrap" aria-hidden="true">
          <span class="card-icon">🪙</span>
        </div>
        <div class="card-content">
          <span class="card-label">Số dư hiện tại</span>
          <div class="balance-display">
            <span class="balance-amount">{{ walletStore.formattedBalance }}</span>
            <span class="balance-currency">Coin</span>
          </div>
          <span class="card-hint">
            Tài khoản: {{ authStore.user?.email || authStore.user?.name }}
          </span>
        </div>
      </section>

      <!-- Faucet Card -->
      <section class="wallet-card faucet-card" aria-label="Nhận Coin miễn phí">
        <div class="card-icon-wrap" aria-hidden="true">
          <span class="card-icon">🎁</span>
        </div>
        <div class="card-content">
          <div class="faucet-header-row">
            <span class="card-label">Coin thử nghiệm miễn phí</span>
            <span
              class="faucet-remaining-pill"
              :class="{ 'is-depleted': !walletStore.canClaimFaucet }"
            >
              Còn {{ walletStore.faucetAvailableToday }} / 5 lượt hôm nay
            </span>
          </div>
          <p class="faucet-desc">
            Nhận 100 Coin mỗi lượt để thử nghiệm mở khóa đọc chương truyện VIP (tối đa 5 lần mỗi ngày, làm mới lúc 00:00).
          </p>
          <div class="faucet-action-wrap">
            <button
              type="button"
              class="btn-claim-faucet"
              :disabled="!walletStore.canClaimFaucet || walletStore.isClaimingFaucet"
              @click="handleClaimFaucet"
            >
              <span v-if="walletStore.isClaimingFaucet" class="btn-spinner" aria-hidden="true"></span>
              <span>
                {{ walletStore.isClaimingFaucet ? 'Đang nhận Coin...' : 'Nhận 100 Coin miễn phí' }}
              </span>
            </button>
          </div>

          <!-- Faucet Feedback Alerts -->
          <div v-if="faucetSuccessMessage" class="faucet-alert alert-success" role="alert">
            <span class="alert-icon" aria-hidden="true">🎉</span>
            <span class="alert-text">{{ faucetSuccessMessage }}</span>
            <button
              type="button"
              class="alert-close"
              aria-label="Đóng thông báo"
              @click="faucetSuccessMessage = null"
            >
              ✕
            </button>
          </div>
          <div v-if="faucetErrorMessage" class="faucet-alert alert-error" role="alert">
            <span class="alert-icon" aria-hidden="true">⚠️</span>
            <span class="alert-text">{{ faucetErrorMessage }}</span>
            <button
              type="button"
              class="alert-close"
              aria-label="Đóng thông báo"
              @click="faucetErrorMessage = null"
            >
              ✕
            </button>
          </div>
        </div>
      </section>
    </div>

    <!-- Transaction History Section -->
    <section class="transactions-section" aria-label="Lịch sử giao dịch ví Coin">
      <div class="section-header">
        <div class="title-with-badge">
          <h2 class="section-title">Lịch sử giao dịch</h2>
          <span v-if="walletStore.pagination" class="count-badge">
            {{ walletStore.pagination.total }} giao dịch
          </span>
        </div>

        <!-- Filter Tabs -->
        <div class="type-filters" role="tablist" aria-label="Lọc theo loại giao dịch">
          <button
            v-for="tab in transactionTypes"
            :key="tab.value"
            type="button"
            class="filter-tab"
            :class="{ 'is-active': selectedType === tab.value }"
            role="tab"
            :aria-selected="selectedType === tab.value"
            @click="selectedType = tab.value"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="walletStore.isLoadingTransactions" class="transactions-loading" aria-busy="true">
        <div class="loading-spinner" aria-hidden="true"></div>
        <p>Đang tải danh sách giao dịch...</p>
      </div>

      <!-- Error State -->
      <div v-else-if="walletStore.transactionsError" class="transactions-error" role="alert">
        <span class="error-icon" aria-hidden="true">⚠️</span>
        <p>{{ walletStore.transactionsError }}</p>
        <button type="button" class="btn-retry" @click="loadTransactions(currentPage)">
          Thử lại
        </button>
      </div>

      <!-- Empty State -->
      <div v-else-if="walletStore.transactions.length === 0" class="transactions-empty">
        <span class="empty-icon" aria-hidden="true">📭</span>
        <h3 class="empty-title">Chưa có giao dịch nào</h3>
        <p class="empty-desc">
          {{ selectedType ? 'Không tìm thấy giao dịch thuộc loại này.' : 'Các biến động số dư Coin (thưởng, mở khóa, hoàn tiền) sẽ xuất hiện tại đây.' }}
        </p>
      </div>

      <!-- Transactions Table -->
      <div v-else class="transactions-table-wrapper">
        <table class="transactions-table">
          <thead>
            <tr>
              <th scope="col">Thời gian</th>
              <th scope="col">Loại giao dịch</th>
              <th scope="col" class="text-right">Biến động</th>
              <th scope="col" class="text-right">Số dư sau</th>
              <th scope="col">Mô tả</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tx in walletStore.transactions" :key="tx.id">
              <td class="col-time">{{ formatDate(tx.created_at) }}</td>
              <td class="col-type">
                <span
                  class="tx-type-tag"
                  :class="'type-' + tx.type"
                >
                  {{ formatTransactionType(tx.type).label }}
                </span>
              </td>
              <td class="col-amount text-right">
                <span
                  class="amount-value"
                  :class="formatTransactionType(tx.type).isCredit ? 'amount-credit' : 'amount-debit'"
                >
                  {{ formatTransactionType(tx.type).isCredit ? '+' : '-' }}{{ tx.amount }} Coin
                </span>
              </td>
              <td class="col-balance text-right">
                <span class="balance-after">{{ tx.balance_after }} Coin</span>
              </td>
              <td class="col-desc">
                <span class="desc-text" :title="tx.description">{{ tx.description }}</span>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination Bar -->
        <nav
          v-if="walletStore.pagination && walletStore.pagination.last_page > 1"
          class="pagination-bar"
          aria-label="Phân trang lịch sử giao dịch"
        >
          <button
            type="button"
            class="btn-page"
            :disabled="currentPage <= 1 || walletStore.isLoadingTransactions"
            @click="loadTransactions(currentPage - 1)"
          >
            ‹ Trang trước
          </button>
          <span class="page-info">
            Trang {{ currentPage }} / {{ walletStore.pagination.last_page }}
          </span>
          <button
            type="button"
            class="btn-page"
            :disabled="currentPage >= walletStore.pagination.last_page || walletStore.isLoadingTransactions"
            @click="loadTransactions(currentPage + 1)"
          >
            Trang sau ›
          </button>
        </nav>
      </div>
    </section>
  </div>
</template>

<style scoped>
.wallet-page {
  padding-top: var(--spacing-xl);
  padding-bottom: var(--spacing-3xl);
  min-height: 80vh;
}

.wallet-header {
  margin-bottom: var(--spacing-xl);
}

.header-breadcrumb {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin-bottom: var(--spacing-xs);
}

.breadcrumb-link {
  color: var(--color-text-muted);
  text-decoration: none;
  transition: color 0.15s ease;
}

.breadcrumb-link:hover {
  color: var(--color-primary);
}

.breadcrumb-separator {
  color: var(--color-border);
}

.breadcrumb-current {
  color: var(--color-text);
  font-weight: 500;
}

.wallet-title {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  color: var(--color-text);
  margin: 0 0 var(--spacing-xs) 0;
}

.wallet-subtitle {
  color: var(--color-text-muted);
  font-size: var(--font-size-base);
  margin: 0;
}

/* Top Cards Grid */
.wallet-cards-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--spacing-lg);
  margin-bottom: var(--spacing-2xl);
}

@media (min-width: 768px) {
  .wallet-cards-grid {
    grid-template-columns: 1fr 1.4fr;
  }
}

.wallet-card {
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-xl);
  display: flex;
  gap: var(--spacing-lg);
  align-items: flex-start;
  box-shadow: var(--shadow-sm);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.wallet-card:hover {
  border-color: var(--color-primary-light, #60a5fa);
  box-shadow: var(--shadow-md);
}

.card-icon-wrap {
  width: 56px;
  height: 56px;
  border-radius: var(--radius-md);
  background: rgba(234, 179, 8, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.faucet-card .card-icon-wrap {
  background: rgba(99, 102, 241, 0.1);
}

.card-icon {
  font-size: 28px;
}

.card-content {
  flex: 1;
  min-width: 0;
}

.card-label {
  display: block;
  font-size: var(--font-size-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
  margin-bottom: var(--spacing-xs);
}

.balance-display {
  display: flex;
  align-items: baseline;
  gap: var(--spacing-xs);
  margin-bottom: var(--spacing-xs);
}

.balance-amount {
  font-size: 36px;
  font-weight: 800;
  color: #eab308;
  line-height: 1;
}

.balance-currency {
  font-size: var(--font-size-lg);
  font-weight: 600;
  color: var(--color-text-muted);
}

.card-hint {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.faucet-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--spacing-xs);
  margin-bottom: var(--spacing-xs);
}

.faucet-remaining-pill {
  font-size: var(--font-size-xs);
  font-weight: 600;
  padding: 3px 8px;
  border-radius: var(--radius-full);
  background: rgba(34, 197, 94, 0.15);
  color: #16a34a;
}

.faucet-remaining-pill.is-depleted {
  background: rgba(239, 68, 68, 0.15);
  color: #dc2626;
}

.faucet-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0 0 var(--spacing-md) 0;
  line-height: 1.5;
}

.btn-claim-faucet {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  padding: 10px 20px;
  background: linear-gradient(135deg, #4f46e5, #6366f1);
  color: #ffffff;
  border: none;
  border-radius: var(--radius-md);
  font-weight: 600;
  font-size: var(--font-size-sm);
  cursor: pointer;
  transition: opacity 0.2s ease, transform 0.15s ease;
}

.btn-claim-faucet:hover:not(:disabled) {
  opacity: 0.95;
  transform: translateY(-1px);
}

.btn-claim-faucet:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

.faucet-alert {
  margin-top: var(--spacing-md);
  padding: var(--spacing-sm) var(--spacing-md);
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  font-size: var(--font-size-sm);
}

.faucet-alert.alert-success {
  background: rgba(34, 197, 94, 0.12);
  color: #15803d;
  border: 1px solid rgba(34, 197, 94, 0.3);
}

.faucet-alert.alert-error {
  background: rgba(239, 68, 68, 0.12);
  color: #b91c1c;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.alert-text {
  flex: 1;
}

.alert-close {
  background: none;
  border: none;
  color: inherit;
  font-size: 16px;
  cursor: pointer;
  padding: 0 4px;
}

/* Transactions Section */
.transactions-section {
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-xl);
  box-shadow: var(--shadow-sm);
}

.section-header {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
  margin-bottom: var(--spacing-lg);
}

@media (min-width: 768px) {
  .section-header {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

.title-with-badge {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.section-title {
  font-size: var(--font-size-xl);
  font-weight: 700;
  color: var(--color-text);
  margin: 0;
}

.count-badge {
  font-size: var(--font-size-xs);
  background: var(--color-bg-subtle, rgba(255, 255, 255, 0.08));
  color: var(--color-text-muted);
  padding: 2px 8px;
  border-radius: var(--radius-full);
}

.type-filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-xs);
}

.filter-tab {
  padding: 6px 14px;
  border-radius: var(--radius-full);
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-muted);
  font-size: var(--font-size-xs);
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.filter-tab:hover {
  border-color: var(--color-primary);
  color: var(--color-text);
}

.filter-tab.is-active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #ffffff;
}

/* States */
.transactions-loading,
.transactions-error,
.transactions-empty {
  padding: var(--spacing-3xl) var(--spacing-md);
  text-align: center;
  color: var(--color-text-muted);
}

.empty-icon,
.error-icon {
  font-size: 36px;
  display: block;
  margin-bottom: var(--spacing-xs);
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: 600;
  color: var(--color-text);
  margin: 0 0 var(--spacing-xs) 0;
}

.empty-desc {
  font-size: var(--font-size-sm);
  margin: 0;
}

.btn-retry {
  margin-top: var(--spacing-md);
  padding: 6px 16px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-bg-surface);
  color: var(--color-text);
  cursor: pointer;
}

/* Table */
.transactions-table-wrapper {
  overflow-x: auto;
}

.transactions-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--font-size-sm);
}

.transactions-table th {
  text-align: left;
  padding: 12px 16px;
  background: var(--color-bg-subtle, rgba(255, 255, 255, 0.04));
  color: var(--color-text-muted);
  font-weight: 600;
  border-bottom: 1px solid var(--color-border);
}

.transactions-table td {
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-border);
  color: var(--color-text);
}

.text-right {
  text-align: right;
}

.col-time {
  white-space: nowrap;
  color: var(--color-text-muted);
}

.tx-type-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: 600;
}

.tx-type-tag.type-welcome_bonus {
  background: rgba(234, 179, 8, 0.15);
  color: #ca8a04;
}

.tx-type-tag.type-faucet {
  background: rgba(99, 102, 241, 0.15);
  color: #4f46e5;
}

.tx-type-tag.type-chapter_unlock {
  background: rgba(239, 68, 68, 0.15);
  color: #dc2626;
}

.tx-type-tag.type-refund {
  background: rgba(34, 197, 94, 0.15);
  color: #16a34a;
}

.amount-value {
  font-weight: 700;
  white-space: nowrap;
}

.amount-credit {
  color: #16a34a;
}

.amount-debit {
  color: #dc2626;
}

.balance-after {
  color: var(--color-text-muted);
  font-weight: 500;
}

.col-desc {
  max-width: 300px;
}

.desc-text {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Pagination Bar */
.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--spacing-md);
  margin-top: var(--spacing-lg);
  padding-top: var(--spacing-md);
  border-top: 1px solid var(--color-border);
}

.btn-page {
  padding: 6px 14px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-bg-surface);
  color: var(--color-text);
  font-size: var(--font-size-sm);
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-page:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.page-info {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}
</style>
