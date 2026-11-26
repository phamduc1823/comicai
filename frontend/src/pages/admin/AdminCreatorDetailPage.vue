<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminCreatorService } from '@/services/adminCreatorService'
import { getErrorMessage, isAxiosError } from '@/services/httpClient'
import type { CreatorProfileInfo } from '@/types/creatorApplication'
import type {
  CreatorComicSummary,
  PenNameHistoryItem,
  CreatorAuditLog,
} from '@/types/adminCreator'

const route = useRoute()

const creatorId = computed(() => route.params.id as string)

// State
const creator = ref<CreatorProfileInfo | null>(null)
const comics = ref<CreatorComicSummary[]>([])
const penNameHistory = ref<PenNameHistoryItem[]>([])
const auditLogs = ref<CreatorAuditLog[]>([])

const isLoading = ref(true)
const errorMessage = ref<string | null>(null)
const successToast = ref<string | null>(null)

// Active Tab ('overview' | 'comics' | 'pen_names' | 'audit')
const activeTab = ref<'overview' | 'comics' | 'pen_names' | 'audit'>('overview')

// Modals State
const showRevokeModal = ref(false)
const showGrantReapplyModal = ref(false)
const showRevokeReapplyModal = ref(false)

const actionReason = ref('')
const actionError = ref<string | null>(null)
const isSubmittingAction = ref(false)

// Toast
let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(msg: string) {
  if (toastTimer) clearTimeout(toastTimer)
  successToast.value = msg
  toastTimer = setTimeout(() => {
    successToast.value = null
  }, 4000)
}

async function fetchCreatorDetail() {
  isLoading.value = true
  errorMessage.value = null

  try {
    const res = await AdminCreatorService.getCreator(creatorId.value)
    creator.value = res.data
    comics.value = res.comics || []
    penNameHistory.value = res.pen_name_history || []
    auditLogs.value = res.audit_logs || []
    document.title = `${res.data.creator_code} (${res.data.pen_name}) — Chi tiết Creator`
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải thông tin Creator.')
  } finally {
    isLoading.value = false
  }
}

// Modal Openers
function openRevokeModal() {
  actionReason.value = ''
  actionError.value = null
  showRevokeModal.value = true
}

function openGrantReapplyModal() {
  actionReason.value = ''
  actionError.value = null
  showGrantReapplyModal.value = true
}

function openRevokeReapplyModal() {
  actionReason.value = ''
  actionError.value = null
  showRevokeReapplyModal.value = true
}

// Revoke Creator Action
async function confirmRevoke() {
  if (!creator.value) return
  const reason = actionReason.value.trim()
  if (!reason) {
    actionError.value = 'Lý do thu hồi quyền là bắt buộc.'
    return
  }

  isSubmittingAction.value = true
  actionError.value = null

  try {
    await AdminCreatorService.revokeCreator(creator.value.id, { reason })
    showRevokeModal.value = false
    showToast(`Đã thu hồi quyền Creator của ${creator.value.pen_name} (${creator.value.creator_code}).`)
    await fetchCreatorDetail()
  } catch (err: unknown) {
    if (isAxiosError<{ message?: string }>(err) && err.response?.data?.message) {
      actionError.value = err.response.data.message
    } else {
      actionError.value = getErrorMessage(err, 'Không thể thu hồi quyền Creator.')
    }
  } finally {
    isSubmittingAction.value = false
  }
}

// Grant Reapply Action
async function confirmGrantReapply() {
  if (!creator.value) return
  const reason = actionReason.value.trim()
  if (!reason) {
    actionError.value = 'Lý do cấp phép nộp lại là bắt buộc.'
    return
  }

  isSubmittingAction.value = true
  actionError.value = null

  try {
    await AdminCreatorService.grantReapply(creator.value.id, { reason })
    showGrantReapplyModal.value = false
    showToast(`Đã cấp quyền nộp lại hồ sơ cho tác giả ${creator.value.pen_name}.`)
    await fetchCreatorDetail()
  } catch (err: unknown) {
    if (isAxiosError<{ message?: string }>(err) && err.response?.data?.message) {
      actionError.value = err.response.data.message
    } else {
      actionError.value = getErrorMessage(err, 'Không thể cấp phép nộp lại hồ sơ.')
    }
  } finally {
    isSubmittingAction.value = false
  }
}

// Revoke Reapply Action
async function confirmRevokeReapply() {
  if (!creator.value) return
  const reason = actionReason.value.trim()
  if (!reason) {
    actionError.value = 'Lý do hủy quyền nộp lại là bắt buộc.'
    return
  }

  isSubmittingAction.value = true
  actionError.value = null

  try {
    await AdminCreatorService.revokeReapply(creator.value.id, { reason })
    showRevokeReapplyModal.value = false
    showToast(`Đã hủy quyền nộp lại hồ sơ của tác giả ${creator.value.pen_name}.`)
    await fetchCreatorDetail()
  } catch (err: unknown) {
    if (isAxiosError<{ message?: string }>(err) && err.response?.data?.message) {
      actionError.value = err.response.data.message
    } else {
      actionError.value = getErrorMessage(err, 'Không thể hủy quyền nộp lại hồ sơ.')
    }
  } finally {
    isSubmittingAction.value = false
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

function getEventBadgeInfo(eventType: string) {
  switch (eventType) {
    case 'approved':
      return { label: 'Phê duyệt trở thành Creator', badgeClass: 'ev-approved', icon: '🎉' }
    case 'application_rejected':
      return { label: 'Từ chối hồ sơ', badgeClass: 'ev-rejected', icon: '❌' }
    case 'pen_name_changed':
      return { label: 'Đổi bút danh', badgeClass: 'ev-pen-name', icon: '✍️' }
    case 'revoked':
      return { label: 'Thu hồi quyền Creator', badgeClass: 'ev-revoked', icon: '🚫' }
    case 'reapply_granted':
      return { label: 'Cấp phép nộp lại hồ sơ', badgeClass: 'ev-reapply-granted', icon: '🔓' }
    case 'reapplied':
      return { label: 'Nộp lại hồ sơ mới', badgeClass: 'ev-reapplied', icon: '📨' }
    case 'reapply_rejected':
      return { label: 'Từ chối hồ sơ nộp lại', badgeClass: 'ev-reapply-rejected', icon: '⛔' }
    case 'reapply_revoked':
      return { label: 'Hủy quyền nộp lại hồ sơ', badgeClass: 'ev-reapply-revoked', icon: '🔒' }
    default:
      return { label: eventType, badgeClass: 'ev-default', icon: '📌' }
  }
}

function getComicStatusBadge(status: string) {
  switch (status) {
    case 'approved':
      return { label: 'Đã duyệt', cls: 'badge-comic-approved' }
    case 'pending':
      return { label: 'Chờ duyệt', cls: 'badge-comic-pending' }
    case 'rejected':
      return { label: 'Từ chối', cls: 'badge-comic-rejected' }
    default:
      return { label: status, cls: 'badge-comic-default' }
  }
}

onMounted(() => {
  fetchCreatorDetail()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-creator-detail-page">
      <!-- Toast Notification -->
      <Transition name="fade">
        <div v-if="successToast" class="admin-toast success" role="status">
          <span class="toast-icon">✓</span>
          <span>{{ successToast }}</span>
        </div>
      </Transition>

      <!-- Breadcrumbs -->
      <nav class="breadcrumb-nav" aria-label="Đường dẫn điều hướng">
        <RouterLink to="/admin/creators" class="breadcrumb-link">&larr; Danh sách Creator</RouterLink>
        <span class="breadcrumb-sep">/</span>
        <span class="breadcrumb-current">{{ creator?.creator_code || 'Chi tiết Creator' }}</span>
      </nav>

      <!-- Error State -->
      <div v-if="errorMessage" class="error-banner" role="alert">
        <span class="error-icon">⚠️</span>
        <div class="error-content">
          <div class="error-text">{{ errorMessage }}</div>
          <button type="button" class="btn-retry" @click="fetchCreatorDetail">Thử lại</button>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="isLoading" class="loading-state">
        <div class="spinner"></div>
        <p>Đang tải chi tiết Creator...</p>
      </div>

      <!-- Main Content -->
      <template v-else-if="creator">
        <!-- Header Card -->
        <div class="header-card">
          <div class="header-top">
            <div class="header-identity">
              <div class="avatar-box">
                <img
                  v-if="creator.user?.avatar_path"
                  :src="creator.user.avatar_path"
                  :alt="creator.pen_name"
                  class="avatar-image"
                />
                <span v-else class="avatar-placeholder">
                  {{ creator.pen_name.charAt(0).toUpperCase() }}
                </span>
              </div>
              <div class="identity-text">
                <div class="title-row">
                  <h1 class="creator-pen-name">{{ creator.pen_name }}</h1>
                  <span class="creator-code-tag">{{ creator.creator_code }}</span>
                  <span
                    class="status-pill"
                    :class="creator.status === 'active' ? 'pill-active' : 'pill-revoked'"
                  >
                    <span class="pill-dot"></span>
                    {{ creator.status === 'active' ? 'Đang hoạt động' : 'Đã thu hồi quyền' }}
                  </span>
                </div>
                <div class="user-meta-row">
                  <span>Họ tên: <strong>{{ creator.user?.name || '—' }}</strong></span>
                  <span class="dot-sep">•</span>
                  <span>Email: <strong>{{ creator.user?.email || '—' }}</strong></span>
                  <span class="dot-sep">•</span>
                  <span>Ngày duyệt: <strong>{{ formatDate(creator.approved_at) }}</strong></span>
                </div>
              </div>
            </div>

            <!-- Action Buttons -->
            <div class="header-actions">
              <!-- Active Creator: Revoke Button -->
              <button
                v-if="creator.status === 'active'"
                type="button"
                class="btn-revoke"
                @click="openRevokeModal"
              >
                <span class="btn-icon">🚫</span>
                <span>Thu hồi quyền Creator</span>
              </button>

              <!-- Revoked Creator: Grant / Revoke Reapply Buttons -->
              <template v-else>
                <button
                  v-if="!creator.can_reapply"
                  type="button"
                  class="btn-grant-reapply"
                  @click="openGrantReapplyModal"
                >
                  <span class="btn-icon">🔓</span>
                  <span>Cấp quyền đăng ký lại</span>
                </button>
                <button
                  v-else
                  type="button"
                  class="btn-revoke-reapply"
                  @click="openRevokeReapplyModal"
                  title="Hủy quyền đăng ký lại trước khi tác giả gửi hồ sơ"
                >
                  <span class="btn-icon">🔒</span>
                  <span>Hủy quyền đăng ký lại</span>
                </button>
              </template>
            </div>
          </div>

          <!-- Revoked Banner / Reapply Notice -->
          <div v-if="creator.status === 'revoked'" class="revoked-info-banner">
            <div class="banner-title">
              <span class="banner-icon">⚠️</span>
              <strong>Tài khoản Creator đã bị thu hồi quyền vào ngày {{ formatDate(creator.revoked_at) }}</strong>
            </div>
            <div class="banner-desc">
              <span v-if="creator.can_reapply" class="reapply-status-text allowed">
                ✓ Đã được cấp quyền nộp lại hồ sơ (Lý do: <em>"{{ creator.reapply_grant_reason }}"</em> vào {{ formatDate(creator.reapply_granted_at) }}). Chờ tác giả nộp hồ sơ mới.
              </span>
              <span v-else class="reapply-status-text blocked">
                ✕ Hiện chưa được phép nộp lại hồ sơ đăng ký Creator. Bạn có thể nhấn <strong>"Cấp quyền đăng ký lại"</strong> ở trên nếu muốn cho phép tác giả gửi lại đơn xét duyệt.
              </span>
            </div>
          </div>
        </div>

        <!-- Tab Controls -->
        <div class="tabs-nav" role="tablist">
          <button
            type="button"
            class="tab-btn"
            :class="{ active: activeTab === 'overview' }"
            role="tab"
            :aria-selected="activeTab === 'overview'"
            @click="activeTab = 'overview'"
          >
            📋 Thông tin & Thống kê
          </button>
          <button
            type="button"
            class="tab-btn"
            :class="{ active: activeTab === 'comics' }"
            role="tab"
            :aria-selected="activeTab === 'comics'"
            @click="activeTab = 'comics'"
          >
            📚 Danh sách truyện ({{ comics.length }})
          </button>
          <button
            type="button"
            class="tab-btn"
            :class="{ active: activeTab === 'pen_names' }"
            role="tab"
            :aria-selected="activeTab === 'pen_names'"
            @click="activeTab = 'pen_names'"
          >
            ✍️ Lịch sử bút danh ({{ penNameHistory.length }})
          </button>
          <button
            type="button"
            class="tab-btn"
            :class="{ active: activeTab === 'audit' }"
            role="tab"
            :aria-selected="activeTab === 'audit'"
            @click="activeTab = 'audit'"
          >
            🕒 Timeline Audit ({{ auditLogs.length }})
          </button>
        </div>

        <!-- TAB 1: Overview -->
        <div v-if="activeTab === 'overview'" class="tab-pane-content">
          <div class="overview-grid">
            <!-- Left: Profile Details -->
            <div class="detail-card">
              <h2 class="card-title">Thông tin Creator</h2>
              <dl class="meta-list">
                <div class="meta-item">
                  <dt class="meta-label">Mã Creator:</dt>
                  <dd class="meta-value font-mono font-bold">{{ creator.creator_code }}</dd>
                </div>
                <div class="meta-item">
                  <dt class="meta-label">Bút danh hiện tại:</dt>
                  <dd class="meta-value font-bold text-accent">{{ creator.pen_name }}</dd>
                </div>
                <div class="meta-item">
                  <dt class="meta-label">User ID liên kết:</dt>
                  <dd class="meta-value">#{{ creator.user_id }} ({{ creator.user?.name }})</dd>
                </div>
                <div class="meta-item">
                  <dt class="meta-label">Email tài khoản:</dt>
                  <dd class="meta-value">{{ creator.user?.email || '—' }}</dd>
                </div>
                <div class="meta-item">
                  <dt class="meta-label">Giới thiệu (Bio):</dt>
                  <dd class="meta-value bio-text">{{ creator.bio || '(Chưa cập nhật tiểu sử)' }}</dd>
                </div>
                <div class="meta-item">
                  <dt class="meta-label">Ngày duyệt cấp quyền:</dt>
                  <dd class="meta-value">{{ formatDate(creator.approved_at) }}</dd>
                </div>
                <div v-if="creator.revoked_at" class="meta-item">
                  <dt class="meta-label">Ngày thu hồi:</dt>
                  <dd class="meta-value text-danger">{{ formatDate(creator.revoked_at) }}</dd>
                </div>
                <div v-if="creator.reapply_granted_at" class="meta-item">
                  <dt class="meta-label">Cấp phép nộp lại lúc:</dt>
                  <dd class="meta-value text-info">{{ formatDate(creator.reapply_granted_at) }}</dd>
                </div>
                <div v-if="creator.reapply_grant_reason" class="meta-item">
                  <dt class="meta-label">Lý do cấp nộp lại:</dt>
                  <dd class="meta-value">{{ creator.reapply_grant_reason }}</dd>
                </div>
              </dl>
            </div>

            <!-- Right: Statistics Cards -->
            <div class="stats-column">
              <div class="stat-box-card">
                <h3 class="stat-box-title">Thống kê tác phẩm</h3>
                <div class="stat-numbers-grid">
                  <div class="stat-number-box">
                    <span class="stat-val">{{ creator.comics_count ?? comics.length }}</span>
                    <span class="stat-lbl">Tổng số truyện</span>
                  </div>
                  <div class="stat-number-box">
                    <span class="stat-val text-success">{{ creator.approved_comics_count ?? '—' }}</span>
                    <span class="stat-lbl">Truyện đã duyệt</span>
                  </div>
                  <div class="stat-number-box">
                    <span class="stat-val text-warning">{{ creator.pending_comics_count ?? '—' }}</span>
                    <span class="stat-lbl">Truyện chờ duyệt</span>
                  </div>
                  <div class="stat-number-box">
                    <span class="stat-val text-danger">{{ creator.rejected_comics_count ?? '—' }}</span>
                    <span class="stat-lbl">Truyện bị từ chối</span>
                  </div>
                </div>
              </div>

              <!-- Quick Guidelines -->
              <div class="guidelines-card">
                <h3 class="guideline-title">📌 Quy định quản lý Creator</h3>
                <ul class="guideline-list">
                  <li><strong>Thu hồi quyền:</strong> Đưa role tài khoản về <code>reader</code> và vô hiệu hóa quyền truy cập Creator Studio. Các truyện đã xuất bản vẫn được bảo lưu.</li>
                  <li><strong>Cấp phép đăng ký lại:</strong> Cho phép Creator bị thu hồi quyền được gửi đơn đăng ký mới <em>1 lần duy nhất</em>. Không tự động cấp lại role.</li>
                  <li><strong>Hủy quyền đăng ký lại:</strong> Admin có thể hủy quyền nộp lại trước khi tác giả gửi hồ sơ mới nếu phát hiện thông tin không chính xác.</li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 2: Comics List -->
        <div v-else-if="activeTab === 'comics'" class="tab-pane-content">
          <div class="table-card">
            <div v-if="comics.length === 0" class="empty-tab-state">
              <span class="empty-tab-icon">📚</span>
              <p>Tác giả này chưa đăng truyện nào trên hệ thống.</p>
            </div>
            <div v-else class="table-responsive">
              <table class="data-table" aria-label="Danh sách truyện của Creator">
                <thead>
                  <tr>
                    <th scope="col" style="width: 80px;">ID</th>
                    <th scope="col">Tên truyện</th>
                    <th scope="col">Đường dẫn (Slug)</th>
                    <th scope="col">Trạng thái duyệt</th>
                    <th scope="col">Ngày tạo</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="comic in comics" :key="comic.id">
                    <td class="font-mono">#{{ comic.id }}</td>
                    <td>
                      <strong>{{ comic.title }}</strong>
                    </td>
                    <td class="font-mono text-muted">{{ comic.slug }}</td>
                    <td>
                      <span
                        class="badge-comic"
                        :class="getComicStatusBadge(comic.approval_status).cls"
                      >
                        {{ getComicStatusBadge(comic.approval_status).label }}
                      </span>
                    </td>
                    <td class="text-muted">{{ formatDate(comic.created_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 3: Pen Name History -->
        <div v-else-if="activeTab === 'pen_names'" class="tab-pane-content">
          <div class="table-card">
            <div v-if="penNameHistory.length === 0" class="empty-tab-state">
              <span class="empty-tab-icon">✍️</span>
              <p>Tác giả chưa thay đổi bút danh lần nào. Bút danh ban đầu vẫn là <strong>{{ creator.pen_name }}</strong>.</p>
            </div>
            <div v-else class="table-responsive">
              <table class="data-table" aria-label="Lịch sử thay đổi bút danh">
                <thead>
                  <tr>
                    <th scope="col" style="width: 80px;">STT</th>
                    <th scope="col">Bút danh cũ</th>
                    <th scope="col">Bút danh mới</th>
                    <th scope="col">Thời gian thay đổi</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(item, idx) in penNameHistory" :key="item.id">
                    <td>{{ idx + 1 }}</td>
                    <td class="text-danger font-medium">{{ item.old_pen_name }}</td>
                    <td class="text-success font-bold">{{ item.new_pen_name }}</td>
                    <td class="text-muted">{{ formatDate(item.created_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 4: Audit Logs Timeline -->
        <div v-else-if="activeTab === 'audit'" class="tab-pane-content">
          <div class="audit-timeline-container">
            <div v-if="auditLogs.length === 0" class="empty-tab-state">
              <span class="empty-tab-icon">🕒</span>
              <p>Chưa có sự kiện audit nào được ghi nhận cho tác giả này.</p>
            </div>
            <div v-else class="timeline-list">
              <div
                v-for="log in auditLogs"
                :key="log.id"
                class="timeline-item"
              >
                <!-- Icon marker -->
                <div class="timeline-marker">
                  <span class="marker-icon">{{ getEventBadgeInfo(log.event_type).icon }}</span>
                  <div class="marker-line"></div>
                </div>

                <!-- Event Content -->
                <div class="timeline-content-card">
                  <div class="timeline-header">
                    <div class="event-title-wrap">
                      <span
                        class="event-badge"
                        :class="getEventBadgeInfo(log.event_type).badgeClass"
                      >
                        {{ getEventBadgeInfo(log.event_type).label }}
                      </span>
                      <span v-if="log.application_code" class="log-app-code">
                        Hồ sơ: <code>{{ log.application_code }}</code>
                      </span>
                    </div>
                    <time class="event-time">{{ formatDate(log.created_at) }}</time>
                  </div>

                  <!-- Actor Info -->
                  <div class="event-actor-row">
                    <span class="actor-label">Thực hiện bởi:</span>
                    <strong v-if="log.actor" class="actor-name">
                      {{ log.actor.name }} ({{ log.actor.email }})
                    </strong>
                    <span v-else class="actor-system">Hệ thống</span>
                  </div>

                  <!-- Reason Box -->
                  <div v-if="log.reason" class="event-reason-box">
                    <span class="reason-label">Lý do:</span>
                    <p class="reason-text">"{{ log.reason }}"</p>
                  </div>

                  <!-- Diff Values if present -->
                  <div
                    v-if="log.old_values || log.new_values"
                    class="event-diff-box"
                  >
                    <details class="diff-details">
                      <summary class="diff-summary">Chi tiết thay đổi dữ liệu</summary>
                      <div class="diff-body">
                        <div v-if="log.old_values" class="diff-col">
                          <span class="col-lbl">Giá trị cũ:</span>
                          <pre class="json-pre">{{ JSON.stringify(log.old_values, null, 2) }}</pre>
                        </div>
                        <div v-if="log.new_values" class="diff-col">
                          <span class="col-lbl">Giá trị mới:</span>
                          <pre class="json-pre">{{ JSON.stringify(log.new_values, null, 2) }}</pre>
                        </div>
                      </div>
                    </details>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- MODAL: Revoke Creator -->
      <div
        v-if="showRevokeModal"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="revoke-modal-title"
      >
        <div class="modal-card modal-danger">
          <div class="modal-header">
            <h3 id="revoke-modal-title" class="modal-title">Thu hồi quyền Creator</h3>
            <button
              type="button"
              class="modal-close"
              aria-label="Đóng"
              @click="showRevokeModal = false"
            >
              ✕
            </button>
          </div>

          <div class="modal-body">
            <p class="modal-warning-text">
              Bạn đang chuẩn bị thu hồi quyền Creator của <strong>{{ creator?.pen_name }}</strong> (Mã: <code>{{ creator?.creator_code }}</code>).
              Tài khoản người dùng sẽ chuyển về vai trò <code>reader</code> và bị khóa quyền truy cập Creator Studio.
            </p>

            <div class="form-group">
              <label for="revoke-reason" class="form-label required">
                Lý do thu hồi quyền:
              </label>
              <textarea
                id="revoke-reason"
                v-model="actionReason"
                rows="4"
                class="form-textarea"
                placeholder="Nhập lý do cụ thể (bắt buộc, ví dụ: vi phạm chính sách bản quyền, nội dung không phù hợp...)"
              ></textarea>
              <p v-if="actionError" class="input-error-msg">{{ actionError }}</p>
            </div>
          </div>

          <div class="modal-footer">
            <button
              type="button"
              class="btn-modal-cancel"
              :disabled="isSubmittingAction"
              @click="showRevokeModal = false"
            >
              Hủy bỏ
            </button>
            <button
              type="button"
              class="btn-modal-danger"
              :disabled="isSubmittingAction"
              @click="confirmRevoke"
            >
              <span v-if="isSubmittingAction" class="spinner-small" aria-hidden="true"></span>
              <span>{{ isSubmittingAction ? 'Đang thu hồi...' : 'Xác nhận thu hồi quyền' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- MODAL: Grant Reapply -->
      <div
        v-if="showGrantReapplyModal"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="grant-reapply-title"
      >
        <div class="modal-card">
          <div class="modal-header">
            <h3 id="grant-reapply-title" class="modal-title">Cấp quyền đăng ký lại Creator</h3>
            <button
              type="button"
              class="modal-close"
              aria-label="Đóng"
              @click="showGrantReapplyModal = false"
            >
              ✕
            </button>
          </div>

          <div class="modal-body">
            <p class="modal-info-text">
              Thao tác này cho phép tác giả <strong>{{ creator?.pen_name }}</strong> được nộp lại hồ sơ xét duyệt Creator <strong>1 lần duy nhất</strong>.
              Thao tác này <em>không tự động khôi phục quyền Creator</em> mà chỉ mở khóa form nộp hồ sơ.
            </p>

            <div class="form-group">
              <label for="grant-reason" class="form-label required">
                Lý do cấp phép nộp lại:
              </label>
              <textarea
                id="grant-reason"
                v-model="actionReason"
                rows="4"
                class="form-textarea"
                placeholder="Nhập lý do cấp phép (bắt buộc, ví dụ: đã giải quyết tranh chấp bản quyền, hoàn thành cải chính...)"
              ></textarea>
              <p v-if="actionError" class="input-error-msg">{{ actionError }}</p>
            </div>
          </div>

          <div class="modal-footer">
            <button
              type="button"
              class="btn-modal-cancel"
              :disabled="isSubmittingAction"
              @click="showGrantReapplyModal = false"
            >
              Hủy bỏ
            </button>
            <button
              type="button"
              class="btn-modal-primary"
              :disabled="isSubmittingAction"
              @click="confirmGrantReapply"
            >
              <span v-if="isSubmittingAction" class="spinner-small" aria-hidden="true"></span>
              <span>{{ isSubmittingAction ? 'Đang xử lý...' : 'Xác nhận cấp quyền nộp lại' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- MODAL: Revoke Reapply -->
      <div
        v-if="showRevokeReapplyModal"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="revoke-reapply-title"
      >
        <div class="modal-card modal-warning">
          <div class="modal-header">
            <h3 id="revoke-reapply-title" class="modal-title">Hủy quyền đăng ký lại Creator</h3>
            <button
              type="button"
              class="modal-close"
              aria-label="Đóng"
              @click="showRevokeReapplyModal = false"
            >
              ✕
            </button>
          </div>

          <div class="modal-body">
            <p class="modal-warning-text">
              Bạn đang chuẩn bị hủy quyền đăng ký lại của tác giả <strong>{{ creator?.pen_name }}</strong> trước khi họ gửi hồ sơ.
              Sau khi hủy, tác giả sẽ không thể truy cập form đăng ký lại trừ khi Admin cấp phép lần tiếp theo.
            </p>

            <div class="form-group">
              <label for="revoke-reapply-reason" class="form-label required">
                Lý do hủy quyền đăng ký lại:
              </label>
              <textarea
                id="revoke-reapply-reason"
                v-model="actionReason"
                rows="4"
                class="form-textarea"
                placeholder="Nhập lý do hủy quyền nộp lại (bắt buộc)..."
              ></textarea>
              <p v-if="actionError" class="input-error-msg">{{ actionError }}</p>
            </div>
          </div>

          <div class="modal-footer">
            <button
              type="button"
              class="btn-modal-cancel"
              :disabled="isSubmittingAction"
              @click="showRevokeReapplyModal = false"
            >
              Đóng
            </button>
            <button
              type="button"
              class="btn-modal-danger"
              :disabled="isSubmittingAction"
              @click="confirmRevokeReapply"
            >
              <span v-if="isSubmittingAction" class="spinner-small" aria-hidden="true"></span>
              <span>{{ isSubmittingAction ? 'Đang xử lý...' : 'Xác nhận hủy quyền nộp lại' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-creator-detail-page {
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

/* Breadcrumb */
.breadcrumb-nav {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: #94a3b8;
}

.breadcrumb-link {
  color: #38bdf8;
  text-decoration: none;
  font-weight: 500;
}

.breadcrumb-link:hover {
  text-decoration: underline;
}

.breadcrumb-sep {
  color: #475569;
}

.breadcrumb-current {
  color: #cbd5e1;
  font-weight: 600;
}

/* Header Card */
.header-card {
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.header-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.header-identity {
  display: flex;
  align-items: center;
  gap: 1.25rem;
}

.avatar-box {
  width: 64px;
  height: 64px;
  border-radius: 12px;
  overflow: hidden;
  background-color: #334155;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 2px solid #475569;
}

.avatar-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-placeholder {
  font-size: 1.75rem;
  font-weight: 700;
  color: #38bdf8;
}

.identity-text {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.creator-pen-name {
  margin: 0;
  font-size: 1.625rem;
  font-weight: 700;
  color: #f1f5f9;
  letter-spacing: -0.02em;
}

.creator-code-tag {
  font-family: monospace;
  font-weight: 700;
  font-size: 0.875rem;
  padding: 0.25rem 0.5rem;
  background-color: #0f172a;
  color: #38bdf8;
  border: 1px solid #334155;
  border-radius: 6px;
}

.user-meta-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: #94a3b8;
  flex-wrap: wrap;
}

.dot-sep {
  color: #475569;
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

/* Action Buttons */
.header-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.btn-revoke {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.125rem;
  background-color: rgba(239, 68, 68, 0.15);
  color: #ef4444;
  border: 1px solid rgba(239, 68, 68, 0.4);
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-revoke:hover {
  background-color: #ef4444;
  color: #ffffff;
}

.btn-grant-reapply {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.125rem;
  background-color: #0284c7;
  color: #ffffff;
  border: none;
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.2s;
}

.btn-grant-reapply:hover {
  background-color: #0369a1;
}

.btn-revoke-reapply {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.125rem;
  background-color: rgba(245, 158, 11, 0.15);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.4);
  border-radius: 8px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-revoke-reapply:hover {
  background-color: #f59e0b;
  color: #0f172a;
}

/* Revoked Info Banner */
.revoked-info-banner {
  background-color: #0f172a;
  border: 1px solid #334155;
  border-left: 4px solid #ef4444;
  border-radius: 6px;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.banner-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #fca5a5;
  font-size: 0.875rem;
}

.banner-desc {
  font-size: 0.8125rem;
  color: #94a3b8;
}

.reapply-status-text.allowed {
  color: #38bdf8;
}

.reapply-status-text.blocked {
  color: #f87171;
}

/* Tabs Navigation */
.tabs-nav {
  display: flex;
  gap: 0.5rem;
  border-bottom: 1px solid #334155;
  padding-bottom: 0.5rem;
  flex-wrap: wrap;
}

.tab-btn {
  padding: 0.625rem 1.125rem;
  background-color: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: #94a3b8;
  font-size: 0.9375rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.tab-btn:hover {
  color: #f1f5f9;
}

.tab-btn.active {
  color: #38bdf8;
  border-bottom-color: #38bdf8;
}

/* Tab Pane 1: Overview Grid */
.overview-grid {
  display: grid;
  grid-template-columns: 1.3fr 1fr;
  gap: 1.5rem;
}

@media (max-width: 992px) {
  .overview-grid {
    grid-template-columns: 1fr;
  }
}

.detail-card {
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 1.5rem;
}

.card-title {
  margin: 0 0 1.25rem;
  font-size: 1.125rem;
  font-weight: 700;
  color: #f1f5f9;
}

.meta-list {
  display: flex;
  flex-direction: column;
  gap: 0.875rem;
  margin: 0;
}

.meta-item {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: 1rem;
  font-size: 0.875rem;
  border-bottom: 1px solid rgba(51, 65, 85, 0.4);
  padding-bottom: 0.75rem;
}

.meta-label {
  color: #94a3b8;
  font-weight: 500;
}

.meta-value {
  margin: 0;
  color: #e2e8f0;
}

.bio-text {
  white-space: pre-line;
  line-height: 1.5;
  color: #cbd5e1;
}

.stats-column {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.stat-box-card {
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 1.5rem;
}

.stat-box-title {
  margin: 0 0 1rem;
  font-size: 1rem;
  font-weight: 700;
  color: #f1f5f9;
}

.stat-numbers-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.stat-number-box {
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.stat-val {
  font-size: 1.5rem;
  font-weight: 700;
  color: #f1f5f9;
}

.stat-lbl {
  font-size: 0.75rem;
  color: #94a3b8;
  margin-top: 0.25rem;
}

.guidelines-card {
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 1.25rem;
}

.guideline-title {
  margin: 0 0 0.75rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: #38bdf8;
}

.guideline-list {
  margin: 0;
  padding-left: 1.25rem;
  font-size: 0.8125rem;
  color: #94a3b8;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  line-height: 1.4;
}

/* Tab Pane 2 & 3: Table Card */
.table-card {
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  overflow: hidden;
}

.empty-tab-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3.5rem 2rem;
  text-align: center;
  color: #94a3b8;
}

.empty-tab-icon {
  font-size: 2.5rem;
  margin-bottom: 0.5rem;
}

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
  padding: 0.875rem 1rem;
  border-bottom: 1px solid #334155;
  color: #cbd5e1;
  vertical-align: middle;
}

.badge-comic {
  display: inline-flex;
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
}

.badge-comic-approved {
  background-color: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.badge-comic-pending {
  background-color: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}

.badge-comic-rejected {
  background-color: rgba(239, 68, 68, 0.15);
  color: #f87171;
}

.badge-comic-default {
  background-color: #334155;
  color: #cbd5e1;
}

/* TAB 4: Timeline */
.audit-timeline-container {
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 10px;
  padding: 1.5rem;
}

.timeline-list {
  display: flex;
  flex-direction: column;
}

.timeline-item {
  display: flex;
  gap: 1.25rem;
}

.timeline-marker {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-shrink: 0;
  width: 32px;
}

.marker-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background-color: #0f172a;
  border: 2px solid #334155;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  z-index: 2;
}

.marker-line {
  flex: 1;
  width: 2px;
  background-color: #334155;
  margin: 0.25rem 0;
}

.timeline-item:last-child .marker-line {
  display: none;
}

.timeline-content-card {
  flex: 1;
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
  padding: 1rem 1.25rem;
  margin-bottom: 1.25rem;
}

.timeline-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 0.5rem;
}

.event-title-wrap {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.event-badge {
  display: inline-flex;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 700;
}

.ev-approved {
  background-color: rgba(16, 185, 129, 0.2);
  color: #34d399;
}

.ev-rejected {
  background-color: rgba(239, 68, 68, 0.2);
  color: #f87171;
}

.ev-pen-name {
  background-color: rgba(56, 189, 248, 0.2);
  color: #38bdf8;
}

.ev-revoked {
  background-color: rgba(239, 68, 68, 0.25);
  color: #ef4444;
}

.ev-reapply-granted {
  background-color: rgba(16, 185, 129, 0.25);
  color: #10b981;
}

.ev-reapplied {
  background-color: rgba(139, 92, 246, 0.25);
  color: #a78bfa;
}

.ev-reapply-rejected {
  background-color: rgba(239, 68, 68, 0.2);
  color: #f87171;
}

.ev-reapply-revoked {
  background-color: rgba(245, 158, 11, 0.25);
  color: #fbbf24;
}

.ev-default {
  background-color: #334155;
  color: #cbd5e1;
}

.log-app-code {
  font-size: 0.75rem;
  color: #94a3b8;
}

.event-time {
  font-size: 0.75rem;
  color: #64748b;
}

.event-actor-row {
  font-size: 0.8125rem;
  color: #94a3b8;
  margin-bottom: 0.5rem;
}

.actor-label {
  margin-right: 0.375rem;
}

.actor-name {
  color: #e2e8f0;
}

.actor-system {
  font-style: italic;
  color: #64748b;
}

.event-reason-box {
  background-color: rgba(51, 65, 85, 0.4);
  border-left: 3px solid #38bdf8;
  padding: 0.625rem 0.875rem;
  border-radius: 4px;
  margin-top: 0.5rem;
}

.reason-label {
  display: block;
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.reason-text {
  margin: 0;
  font-size: 0.8125rem;
  color: #f1f5f9;
  font-style: italic;
}

.event-diff-box {
  margin-top: 0.625rem;
}

.diff-details summary {
  font-size: 0.75rem;
  color: #38bdf8;
  cursor: pointer;
  user-select: none;
}

.diff-body {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  margin-top: 0.5rem;
}

.diff-col {
  display: flex;
  flex-direction: column;
}

.col-lbl {
  font-size: 0.6875rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.json-pre {
  margin: 0;
  padding: 0.5rem;
  background-color: #0b1120;
  border: 1px solid #1e293b;
  border-radius: 4px;
  font-size: 0.6875rem;
  font-family: monospace;
  color: #cbd5e1;
  max-height: 150px;
  overflow: auto;
}

/* Modals */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 1.5rem;
}

.modal-card {
  width: 100%;
  max-width: 520px;
  background-color: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.25rem 1.5rem;
  background-color: #0f172a;
  border-bottom: 1px solid #334155;
}

.modal-title {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 700;
  color: #f1f5f9;
}

.modal-close {
  background: transparent;
  border: none;
  color: #94a3b8;
  font-size: 1.125rem;
  cursor: pointer;
}

.modal-body {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.modal-warning-text {
  margin: 0;
  font-size: 0.875rem;
  color: #fca5a5;
  line-height: 1.5;
}

.modal-info-text {
  margin: 0;
  font-size: 0.875rem;
  color: #cbd5e1;
  line-height: 1.5;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.form-label {
  font-size: 0.8125rem;
  font-weight: 600;
  color: #e2e8f0;
}

.form-label.required::after {
  content: ' *';
  color: #ef4444;
}

.form-textarea {
  width: 100%;
  padding: 0.75rem;
  background-color: #0f172a;
  border: 1px solid #334155;
  border-radius: 6px;
  color: #f1f5f9;
  font-size: 0.875rem;
  outline: none;
  resize: vertical;
  box-sizing: border-box;
}

.form-textarea:focus {
  border-color: #38bdf8;
}

.input-error-msg {
  margin: 0;
  font-size: 0.75rem;
  color: #ef4444;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
  background-color: #0f172a;
  border-top: 1px solid #334155;
}

.btn-modal-cancel {
  padding: 0.5rem 1rem;
  background-color: #334155;
  color: #f1f5f9;
  border: none;
  border-radius: 6px;
  font-size: 0.875rem;
  cursor: pointer;
}

.btn-modal-primary {
  padding: 0.5rem 1.25rem;
  background-color: #0284c7;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-modal-danger {
  padding: 0.5rem 1.25rem;
  background-color: #ef4444;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
}

/* General Utilities */
.loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  color: #94a3b8;
}

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

.font-mono {
  font-family: monospace;
}

.font-bold {
  font-weight: 700;
}

.font-medium {
  font-weight: 500;
}

.text-accent {
  color: #38bdf8;
}

.text-success {
  color: #10b981;
}

.text-warning {
  color: #f59e0b;
}

.text-danger {
  color: #ef4444;
}

.text-info {
  color: #38bdf8;
}

.text-muted {
  color: #64748b;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid rgba(56, 189, 248, 0.2);
  border-top-color: #38bdf8;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 0.75rem;
}

.spinner-small {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #ffffff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  display: inline-block;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
