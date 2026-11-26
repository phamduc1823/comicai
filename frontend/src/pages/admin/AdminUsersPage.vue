<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminUser } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

// State
const users = ref<AdminUser[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Filters & Pagination
const searchQuery = ref('')
const roleFilter = ref('all')
const statusFilter = ref('all')
const currentPage = ref(1)
const totalPages = ref(1)
const totalUsers = ref(0)
const perPage = ref(15)

// Role Change Modal
const isRoleModalOpen = ref(false)
const selectedUser = ref<AdminUser | null>(null)
const targetRole = ref<'reader' | 'creator' | 'admin'>('reader')
const isUpdatingRole = ref(false)
const roleUpdateError = ref<string | null>(null)

// Detail Modal
const isDetailModalOpen = ref(false)
const userDetail = ref<any | null>(null)
const isLoadingDetail = ref(false)

let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

async function fetchUsers() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getUsers({
      q: searchQuery.value || undefined,
      role: roleFilter.value !== 'all' ? roleFilter.value : undefined,
      is_active: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      page: currentPage.value,
      per_page: perPage.value,
    })
    users.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalUsers.value = res.meta.total
    }
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách người dùng.')
  } finally {
    isLoading.value = false
  }
}

function handleSearch() {
  currentPage.value = 1
  fetchUsers()
}

function handlePageChange(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  fetchUsers()
}

// Toggle Active / Lock
async function handleToggleActive(user: AdminUser) {
  if (user.id === authStore.user?.id) {
    showToast('Bạn không thể tự khóa tài khoản của chính mình.', 'error')
    return
  }

  try {
    const res = await AdminService.toggleUserActive(user.id)
    user.is_active = res.data.is_active
    showToast(res.message || `Đã ${user.is_active ? 'mở khóa' : 'khóa'} tài khoản ${user.email}.`)
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể thay đổi trạng thái tài khoản.'), 'error')
  }
}

// Open Role Modal
function openRoleModal(user: AdminUser) {
  selectedUser.value = user
  targetRole.value = user.role
  roleUpdateError.value = null
  isRoleModalOpen.value = true
}

async function handleUpdateRole() {
  if (!selectedUser.value) return
  isUpdatingRole.value = true
  roleUpdateError.value = null
  try {
    const res = await AdminService.updateUserRole(selectedUser.value.id, targetRole.value)
    selectedUser.value.role = res.data.role
    showToast(res.message || `Cập nhật vai trò thành công!`)
    isRoleModalOpen.value = false
    fetchUsers()
  } catch (err: unknown) {
    roleUpdateError.value = getErrorMessage(err, 'Không thể cập nhật vai trò.')
  } finally {
    isUpdatingRole.value = false
  }
}

// Open Detail Modal
async function openDetailModal(user: AdminUser) {
  selectedUser.value = user
  isDetailModalOpen.value = true
  isLoadingDetail.value = true
  try {
    const res = await AdminService.getUser(user.id)
    userDetail.value = res.data
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể tải thông tin chi tiết người dùng.'), 'error')
  } finally {
    isLoadingDetail.value = false
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
  fetchUsers()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-users-page">
    <!-- Toast Notification -->
    <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
      <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
      <span class="toast-text">{{ toastMessage.text }}</span>
    </div>

    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Quản lý Người dùng</h1>
        <p class="page-subtitle">Theo dõi tài khoản độc giả, tác giả và quản trị viên trong toàn hệ thống ({{ totalUsers }} tài khoản).</p>
      </div>
    </header>

    <!-- Toolbar Filters -->
    <div class="toolbar">
      <div class="search-wrap">
        <input
          v-model="searchQuery"
          type="text"
          class="search-input"
          placeholder="Tìm theo tên, email hoặc ID..."
          @keyup.enter="handleSearch"
        />
        <button type="button" class="btn btn-secondary btn-sm" @click="handleSearch">🔍 Tìm</button>
      </div>

      <div class="filters-row">
        <div class="filter-item">
          <label class="filter-label">Vai trò:</label>
          <select v-model="roleFilter" class="filter-select" @change="handleSearch">
            <option value="all">Tất cả vai trò</option>
            <option value="reader">Độc giả (Reader)</option>
            <option value="creator">Tác giả (Creator)</option>
            <option value="admin">Quản trị viên (Admin)</option>
          </select>
        </div>

        <div class="filter-item">
          <label class="filter-label">Trạng thái:</label>
          <select v-model="statusFilter" class="filter-select" @change="handleSearch">
            <option value="all">Tất cả</option>
            <option value="1">Đang hoạt động</option>
            <option value="0">Đang bị khóa</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="fetchUsers">Thử lại</button>
    </div>

    <!-- Users Table -->
    <div class="table-container">
      <div v-if="isLoading" class="loading-state">
        <span class="spinner"></span>
        <p>Đang tải dữ liệu người dùng...</p>
      </div>

      <table v-else-if="users.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 70px">ID</th>
            <th>Người dùng</th>
            <th>Vai trò</th>
            <th>Trạng thái</th>
            <th>Số dư Coin</th>
            <th>Bộ truyện</th>
            <th>Ngày tạo</th>
            <th style="width: 140px; text-align: right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id" :class="{ 'row-locked': !u.is_active }">
            <td>#{{ u.id }}</td>
            <td>
              <div class="user-cell">
                <div class="user-avatar-initial">{{ u.name.charAt(0).toUpperCase() }}</div>
                <div>
                  <strong class="user-name">{{ u.name }}</strong>
                  <div class="user-email">{{ u.email }}</div>
                </div>
              </div>
            </td>
            <td>
              <span class="role-badge" :class="u.role">
                {{ u.role === 'admin' ? '🛡️ Admin' : u.role === 'creator' ? '✍️ Creator' : '📖 Reader' }}
              </span>
            </td>
            <td>
              <span class="status-badge" :class="u.is_active ? 'active' : 'locked'">
                {{ u.is_active ? 'Hoạt động' : 'Đã khóa' }}
              </span>
            </td>
            <td>
              <span class="coin-badge">💰 {{ u.wallet?.balance ?? 0 }}</span>
            </td>
            <td>
              <span v-if="u.comics_count" class="badge-count">{{ u.comics_count }} truyện</span>
              <span v-else class="text-muted">—</span>
            </td>
            <td class="text-muted">{{ formatDate(u.created_at) }}</td>
            <td style="text-align: right">
              <button
                type="button"
                class="btn-icon"
                title="Xem chi tiết"
                @click="openDetailModal(u)"
              >
                👁️
              </button>
              <button
                type="button"
                class="btn-icon"
                title="Thay đổi vai trò"
                @click="openRoleModal(u)"
              >
                ⚙️
              </button>
              <button
                type="button"
                class="btn-icon"
                :class="{ danger: u.is_active, success: !u.is_active }"
                :title="u.is_active ? 'Khóa tài khoản' : 'Mở khóa tài khoản'"
                :disabled="u.id === authStore.user?.id"
                @click="handleToggleActive(u)"
              >
                {{ u.is_active ? '🔒' : '🔓' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-else class="empty-state">
        <span class="empty-icon">👥</span>
        <h3>Không tìm thấy người dùng</h3>
        <p>Thử điều chỉnh từ khóa tìm kiếm hoặc bộ lọc vai trò.</p>
      </div>

      <!-- Pagination Footer -->
      <div v-if="totalPages > 1" class="pagination-bar">
        <span class="pagination-info">Trang {{ currentPage }} / {{ totalPages }} (Tổng {{ totalUsers }} người dùng)</span>
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

    <!-- Update Role Modal -->
    <div v-if="isRoleModalOpen" class="modal-backdrop" @click.self="isRoleModalOpen = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Phân quyền vai trò: {{ selectedUser?.name }}</h3>
          <button type="button" class="btn-close" @click="isRoleModalOpen = false">✕</button>
        </div>
        <form @submit.prevent="handleUpdateRole">
          <div class="modal-body">
            <div v-if="roleUpdateError" class="alert-error" role="alert">
              {{ roleUpdateError }}
            </div>

            <p class="role-modal-hint">
              Chọn vai trò mới cho tài khoản <strong>{{ selectedUser?.email }}</strong>:
            </p>

            <div class="role-options">
              <label class="role-radio-item" :class="{ selected: targetRole === 'reader' }">
                <input v-model="targetRole" type="radio" value="reader" />
                <div class="role-desc">
                  <strong>📖 Độc giả (Reader)</strong>
                  <span>Tài khoản đọc truyện, nạp coin và tham gia cộng đồng thông thường.</span>
                </div>
              </label>

              <label class="role-radio-item" :class="{ selected: targetRole === 'creator' }">
                <input v-model="targetRole" type="radio" value="creator" />
                <div class="role-desc">
                  <strong>✍️ Tác giả (Creator)</strong>
                  <span>Được quyền truy cập Creator Studio, đăng truyện tranh và chương mới.</span>
                </div>
              </label>

              <label class="role-radio-item" :class="{ selected: targetRole === 'admin' }">
                <input v-model="targetRole" type="radio" value="admin" />
                <div class="role-desc">
                  <strong>🛡️ Quản trị viên (Admin)</strong>
                  <span>Toàn quyền truy cập bảng điều khiển quản trị, kiểm duyệt nội dung và quản lý hệ thống.</span>
                </div>
              </label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="isUpdatingRole" @click="isRoleModalOpen = false">Hủy</button>
            <button type="submit" class="btn btn-primary" :disabled="isUpdatingRole">
              {{ isUpdatingRole ? 'Đang lưu...' : 'Xác nhận cập nhật' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Detail Modal -->
    <div v-if="isDetailModalOpen" class="modal-backdrop" @click.self="isDetailModalOpen = false">
      <div class="modal-box modal-lg">
        <div class="modal-header">
          <h3>Hồ sơ người dùng: {{ selectedUser?.name }}</h3>
          <button type="button" class="btn-close" @click="isDetailModalOpen = false">✕</button>
        </div>
        <div class="modal-body">
          <div v-if="isLoadingDetail" class="loading-state">
            <span class="spinner"></span>
            <p>Đang tải chi tiết...</p>
          </div>
          <div v-else-if="userDetail" class="user-detail-content">
            <div class="detail-grid">
              <div class="detail-card">
                <h4>Thông tin tài khoản</h4>
                <p><strong>ID:</strong> #{{ userDetail.user?.id }}</p>
                <p><strong>Email:</strong> {{ userDetail.user?.email }}</p>
                <p><strong>Vai trò:</strong> {{ userDetail.user?.role }}</p>
                <p><strong>Trạng thái:</strong> {{ userDetail.user?.is_active ? 'Hoạt động' : 'Bị khóa' }}</p>
                <p><strong>Email xác minh:</strong> {{ userDetail.user?.email_verified_at ? formatDate(userDetail.user.email_verified_at) : 'Chưa xác minh' }}</p>
              </div>

              <div class="detail-card">
                <h4>Tài chính & Ví Coin</h4>
                <p><strong>Số dư ví:</strong> 💰 {{ userDetail.user?.wallet?.balance ?? 0 }} Coin</p>
                <p><strong>Lượt mua truyện VIP:</strong> {{ userDetail.user?.comic_purchases_count ?? 0 }} lần</p>
                <p><strong>Truyện đã theo dõi:</strong> {{ userDetail.user?.follows_count ?? 0 }}</p>
                <p><strong>Lịch sử đọc:</strong> {{ userDetail.user?.reading_histories_count ?? 0 }} chương</p>
              </div>
            </div>

            <!-- Recent Transactions -->
            <div class="recent-section">
              <h4>Giao dịch Coin gần nhất</h4>
              <table v-if="userDetail.recent_transactions?.length" class="mini-table">
                <thead>
                  <tr>
                    <th>Loại</th>
                    <th>Số Coin</th>
                    <th>Số dư sau</th>
                    <th>Thời gian</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="tx in userDetail.recent_transactions" :key="tx.id">
                    <td>{{ tx.type }}</td>
                    <td :class="tx.amount >= 0 ? 'text-green' : 'text-red'">
                      {{ tx.amount >= 0 ? `+${tx.amount}` : tx.amount }}
                    </td>
                    <td>{{ tx.balance_after }}</td>
                    <td>{{ formatDate(tx.created_at) }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-else class="text-muted">Chưa có giao dịch Coin nào.</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="isDetailModalOpen = false">Đóng</button>
        </div>
      </div>
    </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-users-page {
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

.filters-row {
  display: flex;
  gap: 16px;
  align-items: center;
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

.user-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-avatar-initial {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #0284c7;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
}

.user-name {
  color: #fff;
  font-weight: 600;
}

.user-email {
  color: #94a3b8;
  font-size: 12px;
}

.role-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
}

.role-badge.admin {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.role-badge.creator {
  background: rgba(14, 165, 233, 0.15);
  color: #38bdf8;
}

.role-badge.reader {
  background: rgba(100, 116, 139, 0.15);
  color: #94a3b8;
}

.status-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
}

.status-badge.active {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.status-badge.locked {
  background: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.coin-badge {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.badge-count {
  background: #334155;
  color: #38bdf8;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.row-locked {
  opacity: 0.6;
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

.modal-lg {
  max-width: 700px;
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

.role-modal-hint {
  font-size: 14px;
  margin-bottom: 16px;
  color: #94a3b8;
}

.role-options {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.role-radio-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px;
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.role-radio-item.selected {
  border-color: #38bdf8;
  background: rgba(56, 189, 248, 0.08);
}

.role-desc strong {
  display: block;
  color: #fff;
  font-size: 14px;
  margin-bottom: 4px;
}

.role-desc span {
  font-size: 12px;
  color: #94a3b8;
}

.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 20px;
}

.detail-card {
  background: #0f172a;
  padding: 16px;
  border-radius: 8px;
  border: 1px solid #334155;
}

.detail-card h4 {
  font-size: 15px;
  color: #38bdf8;
  margin-bottom: 12px;
}

.detail-card p {
  font-size: 13px;
  margin-bottom: 8px;
}

.recent-section h4 {
  font-size: 15px;
  color: #fff;
  margin-bottom: 12px;
}

.mini-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.mini-table th,
.mini-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #334155;
}

.text-green {
  color: #34d399;
}

.text-red {
  color: #f87171;
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid #ef4444;
  color: #fca5a5;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
}

.loading-state,
.empty-state {
  padding: 48px 24px;
  text-align: center;
  color: #94a3b8;
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
</style>
