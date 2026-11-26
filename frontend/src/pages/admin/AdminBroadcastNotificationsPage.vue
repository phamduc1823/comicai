<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminBroadcastHistoryItem } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

// Form State
const title = ref('')
const message = ref('')
const broadcastType = ref<'announcement' | 'maintenance' | 'promotion' | 'update'>('announcement')
const targetAudience = ref<'all' | 'readers' | 'creators'>('all')
const actionUrl = ref('/')

const isSending = ref(false)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)
const formErrors = ref<Record<string, string>>({})

// History State
const history = ref<AdminBroadcastHistoryItem[]>([])
const isLoadingHistory = ref(false)

let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

async function fetchHistory() {
  isLoadingHistory.value = true
  try {
    const res = await AdminService.getBroadcastsHistory({ per_page: 20 })
    history.value = res.data || []
  } catch (err: unknown) {
    console.error('Không thể tải lịch sử thông báo:', err)
  } finally {
    isLoadingHistory.value = false
  }
}

async function handleSendBroadcast() {
  formErrors.value = {}
  if (!title.value.trim()) {
    formErrors.value.title = 'Vui lòng nhập tiêu đề thông báo.'
    return
  }
  if (!message.value.trim()) {
    formErrors.value.message = 'Vui lòng nhập nội dung chi tiết.'
    return
  }

  isSending.value = true
  try {
    const res = await AdminService.broadcastNotification({
      title: title.value.trim(),
      message: message.value.trim(),
      broadcast_type: broadcastType.value,
      target_audience: targetAudience.value,
      action_url: actionUrl.value.trim() || undefined,
    })
    showToast(res.message || 'Đã phát thông báo hệ thống thành công!')
    title.value = ''
    message.value = ''
    actionUrl.value = '/'
    fetchHistory()
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể gửi thông báo.'), 'error')
  } finally {
    isSending.value = false
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
  fetchHistory()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-broadcast-page">
    <!-- Toast -->
    <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
      <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
      <span class="toast-text">{{ toastMessage.text }}</span>
    </div>

    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Thông Báo Hệ Thống (System Broadcasts)</h1>
        <p class="page-subtitle">Gửi thông báo nội bộ trực tiếp đến chuông thông báo (Notification Bell) của độc giả và tác giả.</p>
      </div>
    </header>

    <div class="broadcast-layout">
      <!-- Form Panel -->
      <div class="broadcast-form-card">
        <h2 class="card-title">Tạo Thông Báo Mới</h2>
        <form @submit.prevent="handleSendBroadcast">
          <div class="form-group">
            <label class="form-label">Tiêu đề thông báo <span class="req">*</span></label>
            <input
              v-model="title"
              type="text"
              class="form-input"
              placeholder="Ví dụ: Lịch bảo trì hệ thống định kỳ..."
              required
            />
            <p v-if="formErrors.title" class="form-error">{{ formErrors.title }}</p>
          </div>

          <div class="form-group">
            <label class="form-label">Nội dung chi tiết <span class="req">*</span></label>
            <textarea
              v-model="message"
              class="form-textarea"
              rows="4"
              placeholder="Nhập thông điệp gửi đến người dùng..."
              required
            ></textarea>
            <p v-if="formErrors.message" class="form-error">{{ formErrors.message }}</p>
          </div>

          <div class="form-row">
            <div class="form-group flex-1">
              <label class="form-label">Loại thông báo</label>
              <select v-model="broadcastType" class="form-select">
                <option value="announcement">📢 Thông báo chung (Announcement)</option>
                <option value="maintenance">🔧 Lịch bảo trì (Maintenance)</option>
                <option value="promotion">🎁 Khuyến mãi / Sự kiện (Promotion)</option>
                <option value="update">🚀 Bản cập nhật tính năng (Update)</option>
              </select>
            </div>

            <div class="form-group flex-1">
              <label class="form-label">Đối tượng nhận</label>
              <select v-model="targetAudience" class="form-select">
                <option value="all">Toàn bộ người dùng hoạt động (Tất cả)</option>
                <option value="readers">Chỉ độc giả (Readers only)</option>
                <option value="creators">Chỉ tác giả (Creators only)</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Đường dẫn điều hướng khi click (Action URL)</label>
            <input
              v-model="actionUrl"
              type="text"
              class="form-input"
              placeholder="/ hoặc /creator/comics hoặc /announcements..."
            />
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary" :disabled="isSending">
              <span>{{ isSending ? 'Đang phát sóng...' : '🚀 Phát thông báo ngay' }}</span>
            </button>
          </div>
        </form>
      </div>

      <!-- Preview Panel -->
      <div class="preview-panel">
        <h2 class="card-title">Xem trước hiển thị (Notification Preview)</h2>
        <div class="notification-preview-card">
          <div class="preview-icon">
            <span v-if="broadcastType === 'maintenance'">🔧</span>
            <span v-else-if="broadcastType === 'promotion'">🎁</span>
            <span v-else-if="broadcastType === 'update'">🚀</span>
            <span v-else>📢</span>
          </div>
          <div class="preview-content">
            <h4 class="preview-title">{{ title || 'Tiêu đề thông báo mẫu' }}</h4>
            <p class="preview-body">{{ message || 'Nội dung thông báo sẽ xuất hiện tại đây khi người dùng mở chuông thông báo trên thanh Navbar...' }}</p>
            <div class="preview-footer">
              <span class="preview-badge">Admin Broadcast</span>
              <span class="preview-time">Vừa xong</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- History Table -->
    <div class="history-section">
      <h2 class="section-title">Lịch Sử Các Lần Phát Sóng Gần Đây</h2>
      <div class="table-container">
        <div v-if="isLoadingHistory" class="loading-state">
          <span class="spinner"></span>
          <p>Đang tải lịch sử thông báo...</p>
        </div>

        <table v-else-if="history.length > 0" class="data-table">
          <thead>
            <tr>
              <th>Tiêu đề</th>
              <th>Nội dung</th>
              <th>Loại</th>
              <th>Thời gian phát</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in history" :key="item.id">
              <td><strong>{{ item.data?.title || 'Thông báo hệ thống' }}</strong></td>
              <td class="text-muted desc-text">{{ item.data?.body || '—' }}</td>
              <td>
                <span class="type-pill">{{ item.data?.broadcast_type || 'announcement' }}</span>
              </td>
              <td class="text-muted">{{ formatDate(item.created_at) }}</td>
            </tr>
          </tbody>
        </table>

        <div v-else class="empty-state">
          <span class="empty-icon">📭</span>
          <h3>Chưa có thông báo hệ thống nào</h3>
          <p>Lịch sử các lần phát sóng sẽ được lưu vết tại đây.</p>
        </div>
      </div>
    </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-broadcast-page {
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

.broadcast-layout {
  display: grid;
  grid-template-columns: 1.5fr 1fr;
  gap: 24px;
  margin-bottom: 32px;
}

@media (max-width: 900px) {
  .broadcast-layout {
    grid-template-columns: 1fr;
  }
}

.broadcast-form-card,
.preview-panel {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  padding: 24px;
}

.card-title {
  font-size: 16px;
  font-weight: 700;
  color: #fff;
  margin-bottom: 18px;
}

.form-group {
  margin-bottom: 16px;
}

.form-row {
  display: flex;
  gap: 16px;
}

.flex-1 {
  flex: 1;
}

.form-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
  margin-bottom: 6px;
}

.form-input,
.form-textarea,
.form-select {
  width: 100%;
  background: #0f172a;
  border: 1px solid #334155;
  color: #fff;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 14px;
}

.form-actions {
  margin-top: 24px;
  display: flex;
  justify-content: flex-end;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-primary {
  background: #0284c7;
  color: #fff;
}
.btn-primary:hover {
  background: #0369a1;
}

.notification-preview-card {
  display: flex;
  gap: 14px;
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 16px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
}

.preview-icon {
  font-size: 24px;
  line-height: 1;
}

.preview-title {
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  margin-bottom: 4px;
}

.preview-body {
  font-size: 13px;
  color: #cbd5e1;
  line-height: 1.5;
  margin-bottom: 10px;
}

.preview-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.preview-badge {
  background: rgba(56, 189, 248, 0.15);
  color: #38bdf8;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
  font-weight: 600;
}

.preview-time {
  font-size: 11px;
  color: #64748b;
}

.history-section {
  margin-top: 24px;
}

.section-title {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
  margin-bottom: 16px;
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

.desc-text {
  max-width: 450px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.type-pill {
  background: #334155;
  color: #38bdf8;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 12px;
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
</style>
