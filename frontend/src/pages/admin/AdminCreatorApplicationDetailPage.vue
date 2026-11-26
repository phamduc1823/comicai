<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import ApplicationDiffViewer from '@/components/admin/ApplicationDiffViewer.vue'
import { AdminCreatorService } from '@/services/adminCreatorService'
import { getErrorMessage, isAxiosError } from '@/services/httpClient'
import type { CreatorApplication } from '@/types/creatorApplication'

const route = useRoute()

const applicationId = computed(() => route.params.id as string)

// State
const application = ref<CreatorApplication | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)
const successToast = ref<string | null>(null)

// Active Tab ('detail' | 'diff' | 'history')
const activeTab = ref<'detail' | 'diff' | 'history'>('detail')

// Modals State
const showApproveModal = ref(false)
const showRejectModal = ref(false)
const rejectReason = ref('')
const rejectError = ref<string | null>(null)
const isActionSubmitting = ref(false)

// Toast
let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(msg: string) {
  if (toastTimer) clearTimeout(toastTimer)
  successToast.value = msg
  toastTimer = setTimeout(() => {
    successToast.value = null
  }, 4000)
}

async function fetchApplicationDetail() {
  isLoading.value = true
  errorMessage.value = null

  try {
    const res = await AdminCreatorService.getApplication(applicationId.value)
    application.value = res.data
    document.title = `${res.data.application_code} — Chi tiết Hồ sơ Creator`
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải chi tiết hồ sơ.')
  } finally {
    isLoading.value = false
  }
}

async function confirmApprove() {
  if (!application.value) return
  isActionSubmitting.value = true
  errorMessage.value = null

  try {
    await AdminCreatorService.approveApplication(application.value.id)
    showApproveModal.value = false
    showToast(`Đã duyệt hồ sơ ${application.value.application_code}! Người dùng đã được cấp quyền Creator.`)
    await fetchApplicationDetail()
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể phê duyệt hồ sơ.')
  } finally {
    isActionSubmitting.value = false
  }
}

async function confirmReject() {
  if (!application.value) return

  const reason = rejectReason.value.trim()
  if (!reason) {
    rejectError.value = 'Lý do từ chối là bắt buộc.'
    return
  }

  isActionSubmitting.value = true
  rejectError.value = null

  try {
    await AdminCreatorService.rejectApplication(application.value.id, {
      reason,
    })
    showRejectModal.value = false
    showToast(`Đã từ chối hồ sơ ${application.value.application_code}.`)
    await fetchApplicationDetail()
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

function getStatusBadgeClass(status: string | undefined): string {
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

function getStatusLabel(status: string | undefined): string {
  switch (status) {
    case 'pending':
      return 'Đang chờ duyệt'
    case 'approved':
      return 'Đã duyệt'
    case 'rejected':
      return 'Bị từ chối'
    case 'canceled':
      return 'Đã hủy'
    default:
      return status || '—'
  }
}

function getActionLabel(action: string): string {
  switch (action) {
    case 'submitted':
      return 'Nộp hồ sơ lần đầu'
    case 'resubmitted':
      return 'Chỉnh sửa & nộp lại'
    case 'rejected':
      return 'Admin từ chối'
    case 'approved':
      return 'Admin phê duyệt'
    case 'canceled':
      return 'Người dùng hủy'
    default:
      return action
  }
}

onMounted(() => {
  fetchApplicationDetail()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-detail-page">
      <!-- Breadcrumb & Back -->
      <nav class="breadcrumb-nav" aria-label="Điều hướng">
        <RouterLink to="/admin/creator-applications" class="btn-back">
          ← Quay lại danh sách hồ sơ
        </RouterLink>
      </nav>

      <!-- Feedback Alerts -->
      <div v-if="successToast" class="alert alert-success" role="alert">
        <span class="alert-icon">✓</span>
        <span>{{ successToast }}</span>
      </div>

      <div v-if="errorMessage" class="alert alert-danger" role="alert">
        <span class="alert-icon">⚠️</span>
        <span>{{ errorMessage }}</span>
        <button type="button" class="alert-close" aria-label="Đóng" @click="errorMessage = null">✕</button>
      </div>

      <!-- Loading State -->
      <div v-if="isLoading" class="detail-loading" aria-busy="true">
        <div class="spinner" aria-hidden="true"></div>
        <p>Đang tải chi tiết hồ sơ...</p>
      </div>

      <!-- Detail Content when Loaded -->
      <div v-else-if="application" class="detail-content">
        <!-- Top Summary Card -->
        <header class="detail-header-card">
          <div class="header-main-info">
            <div class="code-row">
              <h1 class="app-code">{{ application.application_code }}</h1>
              <span class="status-badge" :class="getStatusBadgeClass(application.status)">
                {{ getStatusLabel(application.status) }}
              </span>
              <span class="version-tag">Phiên bản v{{ application.version }}</span>
            </div>
            <p class="applicant-summary">
              Người nộp: <strong>{{ application.user?.name }}</strong> ({{ application.user?.email }})
              • Nộp lúc: {{ formatDate(application.created_at) }}
            </p>
          </div>

          <!-- Action Buttons for PENDING -->
          <div v-if="application.status === 'pending'" class="header-action-buttons">
            <button
              type="button"
              class="btn btn-success"
              @click="showApproveModal = true"
            >
              Phê duyệt hồ sơ ✓
            </button>
            <button
              type="button"
              class="btn btn-danger"
              @click="showRejectModal = true"
            >
              Từ chối hồ sơ ✕
            </button>
          </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="detail-tabs-nav" aria-label="Chuyển mục hiển thị">
          <button
            type="button"
            class="detail-tab-btn"
            :class="{ active: activeTab === 'detail' }"
            @click="activeTab = 'detail'"
          >
            📋 Chi tiết hồ sơ
          </button>
          <button
            type="button"
            class="detail-tab-btn"
            :class="{ active: activeTab === 'diff' }"
            @click="activeTab = 'diff'"
          >
            🔍 So sánh Diff các phiên bản ({{ application.histories?.length || 1 }})
          </button>
          <button
            type="button"
            class="detail-tab-btn"
            :class="{ active: activeTab === 'history' }"
            @click="activeTab = 'history'"
          >
            ⏳ Lịch sử xét duyệt
          </button>
        </nav>

        <!-- TAB 1: Chi tiết hồ sơ -->
        <section v-if="activeTab === 'detail'" class="tab-pane detail-pane">
          <div class="content-grid">
            <!-- Left Column: Application Payload -->
            <div class="card-box">
              <h3 class="box-title">Nội dung hồ sơ tác giả</h3>

              <div class="info-group">
                <span class="info-label">Bút danh đăng ký:</span>
                <span class="info-value font-bold text-accent">{{ application.pen_name }}</span>
              </div>

              <div class="info-group">
                <span class="info-label">Giới thiệu bản thân:</span>
                <div class="info-text-block">{{ application.bio }}</div>
              </div>

              <div class="info-group">
                <span class="info-label">Lý do đăng ký Creator:</span>
                <div class="info-text-block">{{ application.reason }}</div>
              </div>

              <div class="info-group">
                <span class="info-label">Tác phẩm mẫu:</span>
                <ul v-if="application.sample_works && application.sample_works.length > 0" class="sample-links">
                  <li v-for="(link, lIdx) in application.sample_works" :key="lIdx">
                    <a :href="link" target="_blank" rel="noopener noreferrer" class="sample-link">
                      🔗 {{ link }}
                    </a>
                  </li>
                </ul>
                <span v-else class="text-muted">Không đính kèm liên kết tác phẩm mẫu</span>
              </div>

              <div v-if="application.rejection_reason" class="info-group rejection-group">
                <span class="info-label text-danger">Lý do từ chối:</span>
                <div class="info-text-block block-rejected">{{ application.rejection_reason }}</div>
              </div>
            </div>

            <!-- Right Column: Meta & Review Info -->
            <div class="card-box meta-box">
              <h3 class="box-title">Thông tin xét duyệt & Tài khoản</h3>

              <div class="meta-row">
                <span class="meta-label">ID Người dùng:</span>
                <span class="meta-val">#{{ application.user_id }}</span>
              </div>

              <div class="meta-row">
                <span class="meta-label">Tên tài khoản:</span>
                <span class="meta-val font-semibold">{{ application.user?.name }}</span>
              </div>

              <div class="meta-row">
                <span class="meta-label">Email:</span>
                <span class="meta-val">{{ application.user?.email }}</span>
              </div>

              <div class="meta-row">
                <span class="meta-label">Thời gian nộp:</span>
                <span class="meta-val">{{ formatDate(application.created_at) }}</span>
              </div>

              <div v-if="application.reviewed_at" class="meta-row">
                <span class="meta-label">Thời gian xét duyệt:</span>
                <span class="meta-val">{{ formatDate(application.reviewed_at) }}</span>
              </div>

              <div v-if="application.reviewer" class="meta-row">
                <span class="meta-label">Người xét duyệt:</span>
                <span class="meta-val font-semibold">{{ application.reviewer.name }}</span>
              </div>

              <div v-if="application.canceled_at" class="meta-row">
                <span class="meta-label">Thời gian hủy:</span>
                <span class="meta-val">{{ formatDate(application.canceled_at) }}</span>
              </div>
            </div>
          </div>
        </section>

        <!-- TAB 2: So sánh Diff Viewer -->
        <section v-else-if="activeTab === 'diff'" class="tab-pane diff-pane">
          <ApplicationDiffViewer
            :application="application"
            :histories="application.histories"
          />
        </section>

        <!-- TAB 3: Lịch sử xét duyệt -->
        <section v-else-if="activeTab === 'history'" class="tab-pane history-pane">
          <div class="card-box">
            <h3 class="box-title">Dòng thời gian các phiên bản & Thao tác</h3>

            <div v-if="application.histories && application.histories.length > 0" class="timeline-list">
              <div
                v-for="h in application.histories"
                :key="h.id"
                class="timeline-item"
              >
                <div class="timeline-marker" :class="'marker-' + h.action_type"></div>
                <div class="timeline-card">
                  <div class="timeline-top">
                    <span class="timeline-badge">{{ getActionLabel(h.action_type) }}</span>
                    <span class="timeline-v">Phiên bản v{{ h.version }}</span>
                    <span class="timeline-time">{{ formatDate(h.action_at || h.created_at) }}</span>
                  </div>
                  <div class="timeline-body">
                    <p><strong>Bút danh:</strong> {{ h.pen_name }}</p>
                    <p v-if="h.rejection_reason" class="text-danger">
                      <strong>Lý do từ chối:</strong> {{ h.rejection_reason }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="text-muted">Chưa có lịch sử phiên bản nào được ghi nhận.</div>
          </div>
        </section>
      </div>
    </div>

    <!-- Approve Confirmation Modal -->
    <div
      v-if="showApproveModal"
      class="modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-labelledby="approve-detail-title"
    >
      <div class="modal-card">
        <div class="modal-header">
          <h3 id="approve-detail-title" class="modal-title">Phê duyệt hồ sơ Creator</h3>
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
            Bạn có chắc chắn muốn phê duyệt hồ sơ <strong>{{ application?.application_code }}</strong> cho tác giả <strong>{{ application?.pen_name }}</strong>?
          </p>
          <div class="callout-box">
            <p>Tài khoản sẽ được chuyển thành Creator và cấp mã tác giả <code>CR-xxxxxx</code>.</p>
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
            <span>{{ isActionSubmitting ? 'Đang duyệt...' : 'Xác nhận duyệt' }}</span>
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
      aria-labelledby="reject-detail-title"
    >
      <div class="modal-card">
        <div class="modal-header">
          <h3 id="reject-detail-title" class="modal-title">Từ chối hồ sơ Creator</h3>
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
            Vui lòng nhập lý do từ chối hồ sơ <strong>{{ application?.application_code }}</strong> (Bắt buộc):
          </p>
          <div class="form-group" :class="{ 'has-error': !!rejectError }">
            <textarea
              v-model="rejectReason"
              class="form-control"
              rows="4"
              placeholder="Nêu rõ lý do chưa đạt để tác giả biết và chỉnh sửa..."
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
.admin-detail-page {
  padding: 24px 32px 60px;
}

.breadcrumb-nav {
  margin-bottom: 20px;
}

.btn-back {
  display: inline-flex;
  align-items: center;
  color: #58a6ff;
  font-size: 13px;
  text-decoration: none;
  font-weight: 600;
}

.btn-back:hover {
  text-decoration: underline;
}

/* Detail Header Card */
.detail-header-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 8px;
  padding: 24px;
  margin-bottom: 24px;
  gap: 16px;
  flex-wrap: wrap;
}

.code-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 6px;
}

.app-code {
  font-family: monospace;
  font-size: 24px;
  font-weight: 700;
  color: #58a6ff;
  margin: 0;
}

.applicant-summary {
  font-size: 13px;
  color: #8b949e;
  margin: 0;
}

.header-action-buttons {
  display: flex;
  gap: 10px;
}

/* Tabs */
.detail-tabs-nav {
  display: flex;
  gap: 8px;
  border-bottom: 1px solid #30363d;
  margin-bottom: 24px;
}

.detail-tab-btn {
  padding: 10px 18px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: #8b949e;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.detail-tab-btn:hover {
  color: #c9d1d9;
}

.detail-tab-btn.active {
  color: #58a6ff;
  border-bottom-color: #58a6ff;
}

/* Content Grid */
.content-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 24px;
}

.card-box {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 8px;
  padding: 20px;
}

.box-title {
  font-size: 15px;
  font-weight: 600;
  color: #f0f6fc;
  border-bottom: 1px solid #30363d;
  padding-bottom: 12px;
  margin: 0 0 16px;
}

.info-group {
  margin-bottom: 16px;
}

.info-label {
  font-size: 12px;
  color: #8b949e;
  display: block;
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.info-value {
  font-size: 15px;
  color: #f0f6fc;
}

.text-accent {
  color: #58a6ff;
}

.info-text-block {
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  padding: 12px 14px;
  font-size: 13px;
  color: #c9d1d9;
  white-space: pre-wrap;
  line-height: 1.5;
}

.block-rejected {
  border-color: rgba(248, 81, 73, 0.4);
  background-color: rgba(248, 81, 73, 0.05);
  color: #f85149;
}

.sample-links {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.sample-link {
  color: #58a6ff;
  text-decoration: none;
  font-size: 13px;
  word-break: break-all;
}

.sample-link:hover {
  text-decoration: underline;
}

/* Meta Box */
.meta-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #21262d;
  font-size: 13px;
}

.meta-label {
  color: #8b949e;
}

.meta-val {
  color: #f0f6fc;
  text-align: right;
}

/* Timeline */
.timeline-list {
  position: relative;
  padding-left: 20px;
}

.timeline-list::before {
  content: '';
  position: absolute;
  top: 8px;
  bottom: 8px;
  left: 6px;
  width: 2px;
  background-color: #30363d;
}

.timeline-item {
  position: relative;
  margin-bottom: 16px;
}

.timeline-marker {
  position: absolute;
  left: -20px;
  top: 6px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background-color: #21262d;
  border: 2px solid #58a6ff;
}

.marker-submitted { border-color: #58a6ff; }
.marker-resubmitted { border-color: #e3b341; }
.marker-approved { border-color: #3fb950; }
.marker-rejected { border-color: #f85149; }
.marker-canceled { border-color: #8b949e; }

.timeline-card {
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  padding: 12px 14px;
  font-size: 13px;
}

.timeline-top {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
}

.timeline-badge {
  font-weight: 600;
  color: #f0f6fc;
}

.timeline-v {
  color: #8b949e;
  font-size: 12px;
}

.timeline-time {
  color: #8b949e;
  font-size: 11px;
  margin-left: auto;
}

.timeline-body p {
  margin: 2px 0;
  color: #c9d1d9;
}

/* Badges & Tags */
.status-badge {
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
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

.version-tag {
  background-color: #21262d;
  color: #8b949e;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
}

.btn-success {
  background-color: #238636;
  color: #fff;
}

.btn-danger {
  background-color: #da3633;
  color: #fff;
}

.btn-outline {
  background-color: transparent;
  border-color: #30363d;
  color: #c9d1d9;
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
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.detail-loading {
  text-align: center;
  padding: 80px 20px;
  color: #8b949e;
}

.text-muted { color: #8b949e; }
.text-danger { color: #f85149; }
.font-semibold { font-weight: 600; }
.font-bold { font-weight: 700; }

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

.callout-box {
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

@media (max-width: 840px) {
  .content-grid {
    grid-template-columns: 1fr;
  }
}
</style>
