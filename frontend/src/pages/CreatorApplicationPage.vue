<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { CreatorApplicationService } from '@/services/creatorApplicationService'
import { AuthService } from '@/services/authService'
import { getErrorMessage, isAxiosError } from '@/services/httpClient'
import type {
  CreatorApplication,
  CreatorProfileInfo,
  SubmitApplicationPayload,
} from '@/types/creatorApplication'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// State
const isLoading = ref(true)
const isSubmitting = ref(false)
const isCancelling = ref(false)
const isSendingVerification = ref(false)
const generalError = ref<string | null>(null)
const successMessage = ref<string | null>(null)

// Application & Profile data
const application = ref<CreatorApplication | null>(null)
const profile = ref<CreatorProfileInfo | null>(null)

interface CreatorApplicationFormState {
  pen_name: string
  bio: string
  reason: string
  sample_works: string[]
}

// Form State
const isEditing = ref(false) // When true, shows form even if a rejected application exists
const form = ref<CreatorApplicationFormState>({
  pen_name: '',
  bio: '',
  reason: '',
  sample_works: [''],
})
const formErrors = ref<Record<string, string>>({})

// Cancel Modal State
const showCancelModal = ref(false)

// History accordion toggle
const showHistories = ref(false)

// Computed Properties
const isAuthenticated = computed(() => authStore.isAuthenticated)
const isEmailVerified = computed(() => !!authStore.user?.email_verified_at)
const isAlreadyCreator = computed(() => authStore.isCreator || profile.value?.status === 'active')
const isRevoked = computed(() => profile.value?.status === 'revoked')
const canReapply = computed(() => isRevoked.value && !!profile.value?.can_reapply)

const hasActivePending = computed(() => application.value?.status === 'pending')
const isRejected = computed(() => application.value?.status === 'rejected')
const isCanceled = computed(() => application.value?.status === 'canceled')
const isApproved = computed(() => application.value?.status === 'approved')

// Pen name regex matching backend validator: Unicode letters, digits, spaces, . _ - (3 to 50 chars)
const penNameRegex = /^[\p{L}\p{N} ._-]{3,50}$/u

// Load current application & profile
async function fetchApplicationData() {
  if (!isAuthenticated.value) {
    isLoading.value = false
    return
  }

  isLoading.value = true
  generalError.value = null

  try {
    const res = await CreatorApplicationService.getMyApplication()
    application.value = res.application
    profile.value = res.profile

    // If no active or rejected application, or if canceled, or if revoked and granted reapply (with no pending app yet), prepare clean form
    if (!application.value || application.value.status === 'canceled' || (canReapply.value && application.value.status !== 'pending')) {
      isEditing.value = true
      resetForm()
    } else if (application.value.status === 'rejected') {
      // Keep tracking view by default, but populate form in case user clicks edit
      populateFormFromApplication(application.value)
      isEditing.value = false
    } else {
      isEditing.value = false
    }
  } catch (err: unknown) {
    generalError.value = getErrorMessage(err, 'Không thể tải thông tin hồ sơ Creator. Vui lòng thử lại.')
  } finally {
    isLoading.value = false
  }
}

function resetForm() {
  form.value = {
    pen_name: authStore.user?.name || '',
    bio: '',
    reason: '',
    sample_works: [''],
  }
  formErrors.value = {}
}

function populateFormFromApplication(app: CreatorApplication) {
  form.value = {
    pen_name: app.pen_name || '',
    bio: app.bio || '',
    reason: app.reason || '',
    sample_works: app.sample_works && app.sample_works.length > 0 ? [...app.sample_works] : [''],
  }
  formErrors.value = {}
}

// Form Handlers
function addSampleWorkLink() {
  if (!form.value.sample_works) {
    form.value.sample_works = []
  }
  if (form.value.sample_works.length < 3) {
    form.value.sample_works.push('')
  }
}

function removeSampleWorkLink(index: number) {
  if (form.value.sample_works && form.value.sample_works.length > index) {
    form.value.sample_works.splice(index, 1)
  }
}

function validateClient(): boolean {
  formErrors.value = {}
  let isValid = true

  const penName = form.value.pen_name?.trim() || ''
  if (!penName) {
    formErrors.value.pen_name = 'Bút danh là bắt buộc.'
    isValid = false
  } else if (penName.length < 3) {
    formErrors.value.pen_name = 'Bút danh phải có tối thiểu 3 ký tự.'
    isValid = false
  } else if (penName.length > 50) {
    formErrors.value.pen_name = 'Bút danh không được vượt quá 50 ký tự.'
    isValid = false
  } else if (!penNameRegex.test(penName)) {
    formErrors.value.pen_name =
      'Bút danh chỉ được gồm chữ cái, chữ số, khoảng trắng và các ký tự . _ -'
    isValid = false
  }

  const bio = form.value.bio?.trim() || ''
  if (!bio) {
    formErrors.value.bio = 'Giới thiệu bản thân là bắt buộc.'
    isValid = false
  } else if (bio.length > 1000) {
    formErrors.value.bio = 'Giới thiệu bản thân không được vượt quá 1000 ký tự.'
    isValid = false
  }

  const reason = form.value.reason?.trim() || ''
  if (!reason) {
    formErrors.value.reason = 'Lý do đăng ký Creator là bắt buộc.'
    isValid = false
  } else if (reason.length > 1000) {
    formErrors.value.reason = 'Lý do đăng ký không được vượt quá 1000 ký tự.'
    isValid = false
  }

  // Validate sample works URLs
  if (form.value.sample_works) {
    form.value.sample_works.forEach((url, idx) => {
      const trimmed = url?.trim() || ''
      if (trimmed.length > 0) {
        try {
          const parsed = new URL(trimmed)
          if (!['http:', 'https:'].includes(parsed.protocol)) {
            formErrors.value[`sample_works.${idx}`] = 'Liên kết phải bắt đầu bằng http:// hoặc https://'
            isValid = false
          }
        } catch {
          formErrors.value[`sample_works.${idx}`] = 'Đường dẫn liên kết không đúng định dạng URL.'
          isValid = false
        }
      }
    })
  }

  return isValid
}

async function handleSubmit() {
  generalError.value = null
  successMessage.value = null

  if (!validateClient()) {
    return
  }

  // Clean empty links
  const cleanedLinks = (form.value.sample_works || [])
    .map((l) => l.trim())
    .filter((l) => l.length > 0)

  const payload: SubmitApplicationPayload = {
    pen_name: form.value.pen_name.trim(),
    bio: form.value.bio.trim(),
    reason: form.value.reason.trim(),
    sample_works: cleanedLinks.length > 0 ? cleanedLinks : undefined,
  }

  isSubmitting.value = true

  try {
    if (isRejected.value && application.value) {
      // Resubmit flow
      const res = await CreatorApplicationService.resubmitApplication(application.value.id, payload)
      application.value = res.data
      successMessage.value = 'Đã gửi lại hồ sơ thành công! Vui lòng chờ Ban Quản Trị xét duyệt.'
    } else {
      // Initial submit or reapply
      const res = await CreatorApplicationService.submitApplication(payload)
      application.value = res.data
      successMessage.value = 'Hồ sơ đăng ký Creator đã được gửi thành công! Vui lòng chờ Ban Quản Trị xét duyệt.'
    }
    isEditing.value = false
    await fetchApplicationData()
  } catch (err: unknown) {
    handleApiError(err)
  } finally {
    isSubmitting.value = false
  }
}

async function handleCancelApplication() {
  if (!application.value) return

  isCancelling.value = true
  generalError.value = null
  successMessage.value = null

  try {
    const res = await CreatorApplicationService.cancelApplication(application.value.id)
    application.value = res.data
    showCancelModal.value = false
    successMessage.value = 'Đã hủy hồ sơ đăng ký thành công. Bút danh đã được giải phóng.'
    await fetchApplicationData()
  } catch (err: unknown) {
    handleApiError(err)
  } finally {
    isCancelling.value = false
  }
}

function handleApiError(err: unknown) {
  if (isAxiosError<{ message?: string; errors?: Record<string, string[]>; error_code?: string }>(err)) {
    const data = err.response?.data
    if (data?.errors) {
      // Map field errors
      const mapped: Record<string, string> = {}
      for (const [key, messages] of Object.entries(data.errors)) {
        if (Array.isArray(messages) && messages.length > 0) {
          mapped[key] = messages[0]
        }
      }
      formErrors.value = mapped
    }

    if (data?.message) {
      generalError.value = data.message
    } else {
      generalError.value = 'Đã xảy ra lỗi khi gửi yêu cầu. Vui lòng kiểm tra lại.'
    }
  } else {
    generalError.value = getErrorMessage(err, 'Đã xảy ra lỗi không xác định.')
  }
}

function startEditing() {
  if (application.value) {
    populateFormFromApplication(application.value)
  }
  isEditing.value = true
  generalError.value = null
  successMessage.value = null
}

function cancelEditing() {
  isEditing.value = false
  generalError.value = null
  if (application.value) {
    populateFormFromApplication(application.value)
  }
}

function formatDate(isoString: string | null | undefined): string {
  if (!isoString) return '—'
  const date = new Date(isoString)
  if (isNaN(date.getTime())) return isoString
  return date.toLocaleString('vi-VN', {
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
      return 'Đã phê duyệt'
    case 'rejected':
      return 'Bị từ chối'
    case 'canceled':
      return 'Đã hủy'
    default:
      return status || '—'
  }
}

function getActionTypeLabel(actionType: string | undefined): string {
  switch (actionType) {
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
      return actionType || 'Thao tác'
  }
}

async function handleSendVerification() {
  if (isSendingVerification.value) return
  isSendingVerification.value = true
  generalError.value = null
  successMessage.value = null

  try {
    const res = await AuthService.sendVerificationNotification()
    successMessage.value = res.message || 'Đã gửi liên kết xác minh vào email của bạn. Vui lòng kiểm tra hộp thư.'
  } catch (err: unknown) {
    handleApiError(err)
  } finally {
    isSendingVerification.value = false
  }
}

onMounted(async () => {
  document.title = 'Đăng ký Creator — BlueToon'
  if (route.query.verified === '1') {
    try {
      await authStore.initialize()
      successMessage.value = 'Email của bạn đã được xác minh thành công! Bạn có thể bắt đầu nộp hồ sơ Creator.'
      router.replace({ query: {} })
    } catch {
      // Proceed silently
    }
  }
  await fetchApplicationData()
})
</script>

<template>
  <div class="creator-app-page">
    <div class="app-container">
      <!-- 1. Hero / Introduction Section -->
      <section class="hero-section">
        <div class="hero-badge">BlueToon Creator Studio</div>
        <h1 class="hero-title">Trở thành Tác giả trên ComicAI</h1>
        <p class="hero-subtitle">
          Xuất bản tác phẩm của bạn, kết nối cùng hàng vạn độc giả và phát triển sự nghiệp sáng tác truyện tranh trực tuyến.
        </p>

        <div class="benefits-grid">
          <div class="benefit-card">
            <div class="benefit-icon" aria-hidden="true">✍️</div>
            <h2 class="benefit-title">Tự do Xuất bản</h2>
            <p class="benefit-desc">Quản lý truyện, đăng tải chương mới và chỉnh sửa tác phẩm thuận tiện trong Creator Studio.</p>
          </div>
          <div class="benefit-card">
            <div class="benefit-icon" aria-hidden="true">🪙</div>
            <h2 class="benefit-title">Thu nhập từ Coin</h2>
            <p class="benefit-desc">Mở khóa chương trả phí (VIP), nhận Coin từ độc giả ủng hộ và theo dõi doanh thu minh bạch.</p>
          </div>
          <div class="benefit-card">
            <div class="benefit-icon" aria-hidden="true">🤖</div>
            <h2 class="benefit-title">Trợ lực bởi AI</h2>
            <p class="benefit-desc">Tự động tóm tắt nội dung, gợi ý nhãn thể loại và tối ưu tìm kiếm ngữ nghĩa tự nhiên cho truyện.</p>
          </div>
          <div class="benefit-card">
            <div class="benefit-icon" aria-hidden="true">💬</div>
            <h2 class="benefit-title">Gắn kết Độc giả</h2>
            <p class="benefit-desc">Giao lưu trực tiếp qua bình luận, đăng bài trên Diễn đàn Cộng đồng và tin nhắn thời gian thực.</p>
          </div>
        </div>
      </section>

      <!-- Loading State -->
      <div v-if="isLoading" class="loading-state" aria-busy="true">
        <div class="spinner" aria-hidden="true"></div>
        <p>Đang tải thông tin hồ sơ tác giả...</p>
      </div>

      <!-- Main Content when Loaded -->
      <div v-else class="content-wrapper">
        <!-- Global Feedback Messages -->
        <div v-if="successMessage" class="alert alert-success" role="alert">
          <span class="alert-icon">✓</span>
          <span>{{ successMessage }}</span>
          <button type="button" class="alert-close" aria-label="Đóng" @click="successMessage = null">✕</button>
        </div>

        <div v-if="generalError" class="alert alert-danger" role="alert">
          <span class="alert-icon">⚠️</span>
          <span>{{ generalError }}</span>
          <button type="button" class="alert-close" aria-label="Đóng" @click="generalError = null">✕</button>
        </div>

        <!-- 2. Eligibility & State Guards -->

        <!-- Case A: Guest (Not Authenticated) -->
        <div v-if="!isAuthenticated" class="guard-card guard-guest">
          <div class="guard-icon">🔐</div>
          <h2 class="guard-title">Yêu cầu Đăng nhập</h2>
          <p class="guard-desc">
            Bạn cần có tài khoản BlueToon và đăng nhập để nộp hồ sơ đăng ký làm Creator.
          </p>
          <div class="guard-actions">
            <RouterLink to="/login?redirect=/become-creator" class="btn btn-primary">Đăng nhập</RouterLink>
            <RouterLink to="/register" class="btn btn-outline">Tạo tài khoản mới</RouterLink>
          </div>
        </div>

        <!-- Case B: Email Not Verified -->
        <div v-else-if="!isEmailVerified" class="guard-card guard-unverified">
          <div class="guard-icon">✉️</div>
          <h2 class="guard-title">Email Chưa Được Xác Minh</h2>
          <p class="guard-desc">
            Để bảo đảm an toàn bản quyền tác phẩm và thông tin liên hệ, bạn cần xác minh địa chỉ email <strong>{{ authStore.user?.email }}</strong> trước khi gửi hồ sơ đăng ký tác giả.
          </p>
          <p class="guard-note">
            Vui lòng kiểm tra hộp thư đến hoặc thư mục spam để hoàn tất xác minh email của bạn. Trong môi trường local development, email được ghi nhận tại file log máy chủ.
          </p>
          <div class="guard-actions">
            <button
              type="button"
              class="btn btn-primary"
              :disabled="isSendingVerification"
              @click="handleSendVerification"
            >
              <span v-if="isSendingVerification" class="spinner-small" aria-hidden="true"></span>
              {{ isSendingVerification ? 'Đang gửi email...' : 'Gửi lại email xác minh ✉️' }}
            </button>
          </div>
        </div>

        <!-- Case C: Already a Creator -->
        <div v-else-if="isAlreadyCreator" class="guard-card guard-creator">
          <div class="guard-icon">🎉</div>
          <h2 class="guard-title">Bạn Đã Là Tác Giả Chính Thức</h2>
          <p class="guard-desc">
            Tài khoản của bạn đã có quyền Creator trên BlueToon.
          </p>
          <div class="creator-badge-info">
            <span class="info-label">Mã tác giả:</span>
            <strong class="info-val creator-code">{{ profile?.creator_code || 'CR-CREATOR' }}</strong>
            <span class="info-label">Bút danh:</span>
            <strong class="info-val">{{ profile?.pen_name || authStore.user?.name }}</strong>
          </div>
          <div class="guard-actions">
            <RouterLink to="/creator/comics" class="btn btn-primary">Truy cập Creator Studio ✍️</RouterLink>
            <RouterLink to="/creator/comics" class="btn btn-outline">Quản lý Truyện</RouterLink>
          </div>
        </div>

        <!-- Case D: Revoked and NOT granted reapply (and has NO active pending re-application) -->
        <div v-else-if="isRevoked && !canReapply && !hasActivePending" class="guard-card guard-revoked">
          <div class="guard-icon">⛔</div>
          <h2 class="guard-title">Quyền Creator Đã Bị Thu Hồi</h2>
          <p class="guard-desc">
            Tài khoản của bạn đã bị Ban Quản Trị thu hồi quyền tác giả và hiện chưa được phép đăng ký lại.
          </p>
          <p class="guard-note">
            Nếu bạn cho rằng có sự nhầm lẫn, vui lòng liên hệ Ban Quản Trị để được hỗ trợ giải quyết khiếu nại.
          </p>
        </div>

        <!-- Case E: Revoked WITH Reapply Granted (Notice Banner) -->
        <div v-else-if="isRevoked && canReapply" class="reapply-notice-banner">
          <div class="reapply-badge">Được phép nộp lại</div>
          <h3>Admin đã cấp phép cho bạn đăng ký lại Creator</h3>
          <p v-if="profile?.reapply_grant_reason" class="reapply-reason">
            <strong>Lý do cấp phép:</strong> "{{ profile.reapply_grant_reason }}"
          </p>
          <p class="reapply-sub">
            Quyền nộp lại này chỉ áp dụng 1 lần. Khi bạn gửi hồ sơ thành công, quyền sẽ được tiêu thụ.
          </p>
        </div>

        <!-- 3. Application Tracking View (When application exists and NOT in edit mode) -->
        <div
          v-if="isAuthenticated && isEmailVerified && !isAlreadyCreator && !(isRevoked && !canReapply && !hasActivePending) && application && !isEditing"
          class="application-card"
        >
          <div class="app-card-header">
            <div class="header-left">
              <span class="app-code">{{ application.application_code }}</span>
              <span class="app-version">Phiên bản v{{ application.version }}</span>
            </div>
            <div class="header-right">
              <span class="status-badge" :class="getStatusBadgeClass(application.status)">
                {{ getStatusLabel(application.status) }}
              </span>
            </div>
          </div>

          <!-- Status Specific Banner -->

          <!-- Status: PENDING -->
          <div v-if="hasActivePending" class="status-info-banner info-pending">
            <div class="status-icon">⏳</div>
            <div class="status-text">
              <h4>Hồ sơ đang chờ xét duyệt</h4>
              <p>
                Hồ sơ của bạn đã được gửi đến Ban Quản Trị và đang trong hàng đợi kiểm duyệt. Quá trình xét duyệt thường diễn ra trong 24–48 giờ.
              </p>
              <p class="sub-hint">
                Để bảo đảm tính nhất quán, bạn không thể chỉnh sửa hồ sơ trong lúc đang chờ duyệt. Nếu cần sửa đổi thông tin, bạn có thể hủy hồ sơ này để tạo hồ sơ mới.
              </p>
            </div>
          </div>

          <!-- Status: REJECTED -->
          <div v-else-if="isRejected" class="status-info-banner info-rejected">
            <div class="status-icon">❌</div>
            <div class="status-text">
              <h4>Hồ sơ chưa được phê duyệt</h4>
              <div v-if="application.rejection_reason" class="rejection-box">
                <span class="rejection-title">Lý do từ chối từ Ban Quản Trị:</span>
                <p class="rejection-content">{{ application.rejection_reason }}</p>
              </div>
              <p class="sub-hint">
                Bạn có thể bổ sung thông tin theo yêu cầu của Ban Quản Trị và nộp lại chính hồ sơ này mà không bị giới hạn số lần.
              </p>
            </div>
          </div>

          <!-- Status: CANCELED -->
          <div v-else-if="isCanceled" class="status-info-banner info-canceled">
            <div class="status-icon">🗑️</div>
            <div class="status-text">
              <h4>Hồ sơ đã được hủy</h4>
              <p>
                Hồ sơ này đã được hủy vào lúc {{ formatDate(application.canceled_at) }}. Bút danh đã được giải phóng để bạn hoặc người khác có thể sử dụng.
              </p>
            </div>
          </div>

          <!-- Status: APPROVED -->
          <div v-else-if="isApproved" class="status-info-banner info-approved">
            <div class="status-icon">🎉</div>
            <div class="status-text">
              <h4>Hồ sơ đã được phê duyệt!</h4>
              <p>
                Chúc mừng bạn đã trở thành Tác giả trên BlueToon vào ngày {{ formatDate(application.reviewed_at) }}.
              </p>
            </div>
          </div>

          <!-- Application Details Display -->
          <div class="app-details-grid">
            <div class="detail-row">
              <span class="detail-label">Bút danh đăng ký:</span>
              <span class="detail-val font-semibold">{{ application.pen_name }}</span>
            </div>
            <div class="detail-row">
              <span class="detail-label">Ngày nộp đơn:</span>
              <span class="detail-val">{{ formatDate(application.created_at) }}</span>
            </div>
            <div v-if="application.reviewed_at" class="detail-row">
              <span class="detail-label">Ngày xét duyệt:</span>
              <span class="detail-val">{{ formatDate(application.reviewed_at) }}</span>
            </div>
            <div class="detail-section">
              <span class="detail-label">Giới thiệu bản thân:</span>
              <p class="detail-block-text">{{ application.bio }}</p>
            </div>
            <div class="detail-section">
              <span class="detail-label">Lý do đăng ký:</span>
              <p class="detail-block-text">{{ application.reason }}</p>
            </div>
            <div v-if="application.sample_works && application.sample_works.length > 0" class="detail-section">
              <span class="detail-label">Tác phẩm mẫu ({{ application.sample_works.length }} liên kết):</span>
              <ul class="sample-links-list">
                <li v-for="(link, lIdx) in application.sample_works" :key="lIdx">
                  <a :href="link" target="_blank" rel="noopener noreferrer" class="sample-link">
                    🔗 {{ link }}
                  </a>
                </li>
              </ul>
            </div>
          </div>

          <!-- Actions Bar -->
          <div class="app-card-actions">
            <!-- If PENDING: allow Cancel -->
            <button
              v-if="hasActivePending"
              type="button"
              class="btn btn-danger-outline"
              @click="showCancelModal = true"
            >
              Hủy hồ sơ đăng ký
            </button>

            <!-- If REJECTED: allow Resubmit edit -->
            <button
              v-if="isRejected"
              type="button"
              class="btn btn-primary"
              @click="startEditing"
            >
              Chỉnh sửa & Gửi lại hồ sơ ✏️
            </button>

            <!-- If CANCELED: allow starting new -->
            <button
              v-if="isCanceled"
              type="button"
              class="btn btn-primary"
              @click="startEditing"
            >
              Tạo hồ sơ đăng ký mới ✍️
            </button>

            <!-- If APPROVED: link to Studio -->
            <RouterLink
              v-if="isApproved"
              to="/creator/comics"
              class="btn btn-primary"
            >
              Đến Creator Studio ✍️
            </RouterLink>

            <!-- Toggle Histories Accordion if histories exist -->
            <button
              v-if="application.histories && application.histories.length > 1"
              type="button"
              class="btn btn-ghost"
              @click="showHistories = !showHistories"
            >
              {{ showHistories ? 'Ẩn lịch sử xét duyệt' : `Xem lịch sử (${application.histories.length} mốc)` }}
            </button>
          </div>

          <!-- Histories Accordion -->
          <div v-if="showHistories && application.histories" class="histories-timeline">
            <h3 class="timeline-title">Lịch sử các phiên bản hồ sơ</h3>
            <div class="timeline-items">
              <div
                v-for="h in application.histories"
                :key="h.id"
                class="timeline-item"
              >
                <div class="timeline-badge" :class="'t-badge-' + h.action_type"></div>
                <div class="timeline-content">
                  <div class="timeline-header">
                    <span class="timeline-action">{{ getActionTypeLabel(h.action_type) }}</span>
                    <span class="timeline-version">Phiên bản v{{ h.version }}</span>
                    <span class="timeline-date">{{ formatDate(h.action_at || h.created_at) }}</span>
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
          </div>
        </div>

        <!-- 4. Registration / Resubmission Form -->
        <div
          v-if="isAuthenticated && isEmailVerified && !isAlreadyCreator && !(isRevoked && !canReapply && !hasActivePending) && (isEditing || !application)"
          class="form-card"
        >
          <div class="form-card-header">
            <h2 class="form-title">
              {{ isRejected ? `Chỉnh sửa & Gửi lại hồ sơ (${application?.application_code})` : 'Thông tin đăng ký Creator' }}
            </h2>
            <p class="form-subtitle">
              Vui lòng điền đầy đủ và chính xác thông tin. Ban Quản Trị sẽ xem xét và phản hồi trong thời gian sớm nhất.
            </p>
          </div>

          <form @submit.prevent="handleSubmit">
            <!-- Pen Name -->
            <div class="form-group" :class="{ 'has-error': !!formErrors.pen_name }">
              <div class="label-row">
                <label for="pen_name" class="form-label">
                  Bút danh tác giả <span class="required">*</span>
                </label>
                <span class="char-count" :class="{ 'text-danger': (form.pen_name?.length || 0) > 50 }">
                  {{ form.pen_name?.length || 0 }}/50
                </span>
              </div>
              <input
                id="pen_name"
                v-model="form.pen_name"
                type="text"
                class="form-control"
                placeholder="Ví dụ: Nguyễn Văn A, Tác Giả 01..."
                maxlength="50"
                autocomplete="off"
                :disabled="isSubmitting"
              />
              <p class="field-hint">
                Từ 3 đến 50 ký tự. Hỗ trợ chữ cái Unicode (tiếng Việt có dấu), chữ số, khoảng trắng và các ký tự . _ -
              </p>
              <p v-if="formErrors.pen_name" class="error-msg" role="alert">
                {{ formErrors.pen_name }}
              </p>
            </div>

            <!-- Bio -->
            <div class="form-group" :class="{ 'has-error': !!formErrors.bio }">
              <div class="label-row">
                <label for="bio" class="form-label">
                  Giới thiệu bản thân <span class="required">*</span>
                </label>
                <span class="char-count" :class="{ 'text-danger': (form.bio?.length || 0) > 1000 }">
                  {{ form.bio?.length || 0 }}/1000
                </span>
              </div>
              <textarea
                id="bio"
                v-model="form.bio"
                class="form-control textarea-control"
                rows="4"
                placeholder="Mô tả ngắn gọn kinh nghiệm sáng tác, thể loại sở trường, phong cách vẽ hoặc viết truyện của bạn..."
                maxlength="1000"
                :disabled="isSubmitting"
              ></textarea>
              <p v-if="formErrors.bio" class="error-msg" role="alert">
                {{ formErrors.bio }}
              </p>
            </div>

            <!-- Reason -->
            <div class="form-group" :class="{ 'has-error': !!formErrors.reason }">
              <div class="label-row">
                <label for="reason" class="form-label">
                  Lý do đăng ký Creator <span class="required">*</span>
                </label>
                <span class="char-count" :class="{ 'text-danger': (form.reason?.length || 0) > 1000 }">
                  {{ form.reason?.length || 0 }}/1000
                </span>
              </div>
              <textarea
                id="reason"
                v-model="form.reason"
                class="form-control textarea-control"
                rows="4"
                placeholder="Mục tiêu xuất bản, kế hoạch truyện tranh bạn muốn mang đến cho độc giả trên BlueToon..."
                maxlength="1000"
                :disabled="isSubmitting"
              ></textarea>
              <p v-if="formErrors.reason" class="error-msg" role="alert">
                {{ formErrors.reason }}
              </p>
            </div>

            <!-- Sample Works Links -->
            <div class="form-group">
              <div class="label-row">
                <label class="form-label">
                  Liên kết tác phẩm mẫu <span class="optional">(Không bắt buộc, tối đa 3 links)</span>
                </label>
                <span class="char-count">
                  {{ (form.sample_works || []).length }}/3
                </span>
              </div>
              <p class="field-hint">
                Link portfolio, Google Drive, tranh mẫu hoặc truyện cá nhân trên các nền tảng trực tuyến.
              </p>

              <div class="sample-inputs-list">
                <div
                  v-for="(_, sIdx) in form.sample_works"
                  :key="sIdx"
                  class="sample-input-row"
                  :class="{ 'has-error': !!formErrors[`sample_works.${sIdx}`] }"
                >
                  <div class="input-with-action">
                    <span class="input-prefix">🔗</span>
                    <input
                      v-model="form.sample_works[sIdx]"
                      type="url"
                      class="form-control sample-control"
                      placeholder="https://..."
                      :disabled="isSubmitting"
                    />
                    <button
                      type="button"
                      class="btn-remove-link"
                      title="Xóa liên kết này"
                      aria-label="Xóa liên kết này"
                      :disabled="isSubmitting"
                      @click="removeSampleWorkLink(sIdx)"
                    >
                      ✕
                    </button>
                  </div>
                  <p v-if="formErrors[`sample_works.${sIdx}`]" class="error-msg" role="alert">
                    {{ formErrors[`sample_works.${sIdx}`] }}
                  </p>
                </div>
              </div>

              <button
                v-if="(form.sample_works || []).length < 3"
                type="button"
                class="btn-add-sample"
                :disabled="isSubmitting"
                @click="addSampleWorkLink"
              >
                + Thêm liên kết tác phẩm
              </button>
            </div>

            <!-- Form Action Buttons -->
            <div class="form-actions">
              <button
                type="submit"
                class="btn btn-primary btn-submit"
                :disabled="isSubmitting"
              >
                <span v-if="isSubmitting" class="spinner-sm" aria-hidden="true"></span>
                <span>{{ isSubmitting ? 'Đang gửi hồ sơ...' : (isRejected ? 'Gửi lại hồ sơ' : 'Gửi hồ sơ đăng ký') }}</span>
              </button>

              <button
                v-if="application && isEditing && isRejected"
                type="button"
                class="btn btn-outline"
                :disabled="isSubmitting"
                @click="cancelEditing"
              >
                Hủy chỉnh sửa
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- 5. Confirmation Modal for Application Cancellation -->
    <div
      v-if="showCancelModal"
      class="modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-labelledby="cancel-modal-title"
    >
      <div class="modal-card">
        <div class="modal-header">
          <h3 id="cancel-modal-title" class="modal-title">Xác nhận hủy hồ sơ</h3>
          <button
            type="button"
            class="modal-close"
            aria-label="Đóng"
            :disabled="isCancelling"
            @click="showCancelModal = false"
          >
            ✕
          </button>
        </div>
        <div class="modal-body">
          <p>
            Bạn có chắc chắn muốn hủy hồ sơ đăng ký <strong>{{ application?.application_code }}</strong>?
          </p>
          <div class="warning-callout">
            <p><strong>Lưu ý:</strong> Khi hủy, quyền giữ chỗ bút danh "<strong>{{ application?.pen_name }}</strong>" sẽ được giải phóng. Bạn có thể nộp lại hồ sơ mới bất cứ lúc nào.</p>
          </div>
        </div>
        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-outline"
            :disabled="isCancelling"
            @click="showCancelModal = false"
          >
            Không hủy
          </button>
          <button
            type="button"
            class="btn btn-danger"
            :disabled="isCancelling"
            @click="handleCancelApplication"
          >
            <span v-if="isCancelling" class="spinner-sm" aria-hidden="true"></span>
            <span>{{ isCancelling ? 'Đang hủy...' : 'Xác nhận hủy hồ sơ' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.creator-app-page {
  min-height: calc(100vh - 70px);
  background-color: #0d1117;
  color: #e6edf3;
  padding: 40px 16px 80px;
}

.app-container {
  max-width: 900px;
  margin: 0 auto;
}

/* Hero Section */
.hero-section {
  text-align: center;
  margin-bottom: 40px;
}

.hero-badge {
  display: inline-block;
  padding: 4px 12px;
  background-color: rgba(56, 139, 253, 0.15);
  color: #58a6ff;
  border: 1px solid rgba(56, 139, 253, 0.3);
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 16px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.hero-title {
  font-size: 32px;
  font-weight: 700;
  color: #f0f6fc;
  margin: 0 0 12px;
}

.hero-subtitle {
  font-size: 16px;
  color: #8b949e;
  max-width: 680px;
  margin: 0 auto 32px;
  line-height: 1.6;
}

/* Benefits Grid */
.benefits-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-top: 24px;
  text-align: left;
}

.benefit-card {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 8px;
  padding: 20px;
  transition: transform 0.2s ease, border-color 0.2s ease;
}

.benefit-card:hover {
  border-color: #58a6ff;
  transform: translateY(-2px);
}

.benefit-icon {
  font-size: 28px;
  margin-bottom: 12px;
}

.benefit-title {
  font-size: 16px;
  font-weight: 600;
  color: #f0f6fc;
  margin: 0 0 8px;
}

.benefit-desc {
  font-size: 13px;
  color: #8b949e;
  margin: 0;
  line-height: 1.5;
}

/* Loading State */
.loading-state {
  text-align: center;
  padding: 60px 0;
  color: #8b949e;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 3px solid rgba(88, 166, 255, 0.2);
  border-top-color: #58a6ff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto 16px;
}

.spinner-sm {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-right: 8px;
  vertical-align: middle;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Alerts */
.alert {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 18px;
  border-radius: 8px;
  margin-bottom: 24px;
  font-size: 14px;
  line-height: 1.5;
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

.alert-icon {
  font-size: 18px;
  flex-shrink: 0;
}

.alert-close {
  margin-left: auto;
  background: transparent;
  border: none;
  color: inherit;
  font-size: 16px;
  cursor: pointer;
  padding: 0 4px;
}

/* Guard Cards */
.guard-card {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 12px;
  padding: 40px 24px;
  text-align: center;
  margin-bottom: 32px;
}

.guard-icon {
  font-size: 48px;
  margin-bottom: 16px;
}

.guard-title {
  font-size: 22px;
  font-weight: 700;
  color: #f0f6fc;
  margin: 0 0 12px;
}

.guard-desc {
  font-size: 15px;
  color: #8b949e;
  max-width: 560px;
  margin: 0 auto 24px;
  line-height: 1.6;
}

.guard-note {
  font-size: 13px;
  color: #e3b341;
  background-color: rgba(227, 179, 65, 0.1);
  border: 1px dashed rgba(227, 179, 65, 0.3);
  padding: 10px 16px;
  border-radius: 6px;
  display: inline-block;
  margin: 0 auto;
}

.guard-actions {
  display: flex;
  justify-content: center;
  gap: 16px;
  flex-wrap: wrap;
}

.creator-badge-info {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;
  font-size: 14px;
}

.creator-code {
  color: #58a6ff;
  font-family: monospace;
  font-size: 16px;
}

/* Reapply Notice */
.reapply-notice-banner {
  background-color: rgba(56, 139, 253, 0.1);
  border: 1px solid rgba(56, 139, 253, 0.3);
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 24px;
}

.reapply-badge {
  display: inline-block;
  padding: 2px 8px;
  background-color: #1f6feb;
  color: #fff;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 8px;
}

.reapply-notice-banner h3 {
  font-size: 16px;
  font-weight: 600;
  color: #f0f6fc;
  margin: 0 0 6px;
}

.reapply-reason {
  font-size: 14px;
  color: #58a6ff;
  margin: 0 0 6px;
}

.reapply-sub {
  font-size: 12px;
  color: #8b949e;
  margin: 0;
}

/* Application Card */
.application-card {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 12px;
  padding: 28px;
  margin-bottom: 32px;
}

.app-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #30363d;
  padding-bottom: 16px;
  margin-bottom: 20px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.app-code {
  font-family: monospace;
  font-size: 18px;
  font-weight: 700;
  color: #58a6ff;
}

.app-version {
  font-size: 13px;
  color: #8b949e;
  background-color: #21262d;
  padding: 2px 8px;
  border-radius: 12px;
}

.status-badge {
  font-size: 13px;
  font-weight: 600;
  padding: 4px 12px;
  border-radius: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.badge-pending {
  background-color: rgba(227, 179, 65, 0.2);
  color: #e3b341;
  border: 1px solid rgba(227, 179, 65, 0.4);
}

.badge-approved {
  background-color: rgba(46, 160, 67, 0.2);
  color: #3fb950;
  border: 1px solid rgba(46, 160, 67, 0.4);
}

.badge-rejected {
  background-color: rgba(248, 81, 73, 0.2);
  color: #f85149;
  border: 1px solid rgba(248, 81, 73, 0.4);
}

.badge-canceled {
  background-color: rgba(139, 148, 158, 0.2);
  color: #8b949e;
  border: 1px solid rgba(139, 148, 158, 0.4);
}

/* Status Info Banners */
.status-info-banner {
  display: flex;
  gap: 16px;
  padding: 16px 20px;
  border-radius: 8px;
  margin-bottom: 24px;
}

.status-info-banner .status-icon {
  font-size: 28px;
  flex-shrink: 0;
}

.status-info-banner h4 {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 6px;
}

.status-info-banner p {
  font-size: 14px;
  margin: 0 0 6px;
  line-height: 1.5;
}

.status-info-banner .sub-hint {
  font-size: 12px;
  opacity: 0.85;
  margin: 0;
}

.info-pending {
  background-color: rgba(227, 179, 65, 0.12);
  border: 1px solid rgba(227, 179, 65, 0.3);
  color: #e3b341;
}

.info-rejected {
  background-color: rgba(248, 81, 73, 0.12);
  border: 1px solid rgba(248, 81, 73, 0.3);
  color: #f85149;
}

.info-approved {
  background-color: rgba(46, 160, 67, 0.12);
  border: 1px solid rgba(46, 160, 67, 0.3);
  color: #3fb950;
}

.info-canceled {
  background-color: rgba(139, 148, 158, 0.12);
  border: 1px solid rgba(139, 148, 158, 0.3);
  color: #8b949e;
}

.rejection-box {
  background-color: rgba(0, 0, 0, 0.25);
  border-left: 3px solid #f85149;
  padding: 10px 14px;
  border-radius: 0 6px 6px 0;
  margin: 8px 0;
}

.rejection-title {
  font-size: 13px;
  font-weight: 600;
  display: block;
  margin-bottom: 4px;
}

.rejection-content {
  font-size: 14px;
  color: #f0f6fc;
  margin: 0;
}

/* App Details Grid */
.app-details-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 24px;
}

.detail-row {
  display: flex;
  gap: 16px;
  font-size: 14px;
}

.detail-label {
  color: #8b949e;
  min-width: 150px;
}

.detail-val {
  color: #f0f6fc;
}

.font-semibold {
  font-weight: 600;
}

.detail-section {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.detail-block-text {
  font-size: 14px;
  color: #c9d1d9;
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  padding: 12px 14px;
  white-space: pre-wrap;
  margin: 0;
  line-height: 1.5;
}

.sample-links-list {
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
  font-size: 14px;
  word-break: break-all;
}

.sample-link:hover {
  text-decoration: underline;
}

/* App Card Actions */
.app-card-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  border-top: 1px solid #30363d;
  padding-top: 20px;
}

/* Histories Accordion Timeline */
.histories-timeline {
  margin-top: 24px;
  border-top: 1px dashed #30363d;
  padding-top: 20px;
}

.timeline-title {
  font-size: 16px;
  font-weight: 600;
  color: #f0f6fc;
  margin: 0 0 16px;
}

.timeline-items {
  position: relative;
  padding-left: 20px;
}

.timeline-items::before {
  content: '';
  position: absolute;
  top: 6px;
  bottom: 6px;
  left: 6px;
  width: 2px;
  background-color: #30363d;
}

.timeline-item {
  position: relative;
  margin-bottom: 16px;
}

.timeline-badge {
  position: absolute;
  left: -20px;
  top: 4px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background-color: #21262d;
  border: 2px solid #58a6ff;
}

.t-badge-submitted {
  border-color: #58a6ff;
}
.t-badge-resubmitted {
  border-color: #e3b341;
}
.t-badge-rejected {
  border-color: #f85149;
}
.t-badge-approved {
  border-color: #3fb950;
}
.t-badge-canceled {
  border-color: #8b949e;
}

.timeline-content {
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  padding: 10px 14px;
  font-size: 13px;
}

.timeline-header {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 6px;
}

.timeline-action {
  font-weight: 600;
  color: #f0f6fc;
}

.timeline-version {
  color: #8b949e;
}

.timeline-date {
  color: #8b949e;
  margin-left: auto;
  font-size: 12px;
}

.timeline-body p {
  margin: 2px 0;
  color: #c9d1d9;
}

.text-danger {
  color: #f85149 !important;
}

/* Form Card */
.form-card {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 12px;
  padding: 32px;
}

.form-card-header {
  margin-bottom: 24px;
  border-bottom: 1px solid #30363d;
  padding-bottom: 16px;
}

.form-title {
  font-size: 22px;
  font-weight: 700;
  color: #f0f6fc;
  margin: 0 0 8px;
}

.form-subtitle {
  font-size: 14px;
  color: #8b949e;
  margin: 0;
  line-height: 1.5;
}

.form-group {
  margin-bottom: 24px;
}

.label-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 8px;
}

.form-label {
  font-size: 14px;
  font-weight: 600;
  color: #f0f6fc;
}

.required {
  color: #f85149;
}

.optional {
  font-weight: normal;
  font-size: 12px;
  color: #8b949e;
}

.char-count {
  font-size: 12px;
  color: #8b949e;
}

.form-control {
  width: 100%;
  padding: 10px 14px;
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  color: #e6edf3;
  font-size: 14px;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
  box-sizing: border-box;
}

.form-control:focus {
  outline: none;
  border-color: #58a6ff;
  box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.2);
}

.textarea-control {
  resize: vertical;
  line-height: 1.5;
}

.field-hint {
  font-size: 12px;
  color: #8b949e;
  margin: 6px 0 0;
}

.error-msg {
  font-size: 12px;
  color: #f85149;
  margin: 6px 0 0;
}

.has-error .form-control {
  border-color: #f85149;
}

.has-error .form-control:focus {
  box-shadow: 0 0 0 3px rgba(248, 81, 73, 0.2);
}

/* Sample links inputs */
.sample-inputs-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 8px;
}

.sample-input-row {
  display: flex;
  flex-direction: column;
}

.input-with-action {
  display: flex;
  align-items: center;
  position: relative;
}

.input-prefix {
  position: absolute;
  left: 12px;
  color: #8b949e;
  pointer-events: none;
}

.sample-control {
  padding-left: 36px;
  padding-right: 36px;
}

.btn-remove-link {
  position: absolute;
  right: 8px;
  background: transparent;
  border: none;
  color: #8b949e;
  cursor: pointer;
  padding: 4px 8px;
  font-size: 14px;
  border-radius: 4px;
}

.btn-remove-link:hover {
  color: #f85149;
  background-color: rgba(248, 81, 73, 0.1);
}

.btn-add-sample {
  margin-top: 10px;
  background: transparent;
  border: 1px dashed #30363d;
  color: #58a6ff;
  padding: 8px 14px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-add-sample:hover {
  border-color: #58a6ff;
  background-color: rgba(88, 166, 255, 0.05);
}

.form-actions {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-top: 32px;
  border-top: 1px solid #30363d;
  padding-top: 24px;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 9px 18px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 6px;
  cursor: pointer;
  text-decoration: none;
  transition: all 0.2s ease;
  border: 1px solid transparent;
}

.btn-primary {
  background-color: #238636;
  color: #ffffff;
}

.btn-primary:hover:not(:disabled) {
  background-color: #2ea043;
}

.btn-outline {
  background-color: transparent;
  border-color: #30363d;
  color: #c9d1d9;
}

.btn-outline:hover:not(:disabled) {
  border-color: #8b949e;
  background-color: #21262d;
}

.btn-danger {
  background-color: #da3633;
  color: #ffffff;
}

.btn-danger:hover:not(:disabled) {
  background-color: #b62324;
}

.btn-danger-outline {
  background-color: transparent;
  border-color: rgba(248, 81, 73, 0.4);
  color: #f85149;
}

.btn-danger-outline:hover:not(:disabled) {
  background-color: rgba(248, 81, 73, 0.1);
  border-color: #f85149;
}

.btn-ghost {
  background: transparent;
  border: none;
  color: #58a6ff;
  font-size: 13px;
  padding: 6px 10px;
}

.btn-ghost:hover {
  text-decoration: underline;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Modal */
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
  border-radius: 12px;
  width: 100%;
  max-width: 480px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  animation: modalEnter 0.2s ease-out;
}

@keyframes modalEnter {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 18px 20px;
  border-bottom: 1px solid #30363d;
}

.modal-title {
  font-size: 18px;
  font-weight: 600;
  color: #f0f6fc;
  margin: 0;
}

.modal-close {
  background: transparent;
  border: none;
  color: #8b949e;
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
}

.modal-body {
  padding: 20px;
  font-size: 14px;
  color: #c9d1d9;
  line-height: 1.5;
}

.warning-callout {
  background-color: rgba(227, 179, 65, 0.1);
  border-left: 3px solid #e3b341;
  padding: 10px 14px;
  margin-top: 14px;
  border-radius: 0 6px 6px 0;
  font-size: 13px;
  color: #e3b341;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
  border-top: 1px solid #30363d;
}

/* Responsive */
@media (max-width: 768px) {
  .hero-title {
    font-size: 26px;
  }
  .app-card-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .detail-row {
    flex-direction: column;
    gap: 4px;
  }
  .detail-label {
    min-width: auto;
  }
  .form-card {
    padding: 20px;
  }
  .application-card {
    padding: 20px;
  }
  .app-card-actions {
    flex-direction: column;
    align-items: stretch;
  }
  .btn {
    width: 100%;
  }
  .form-actions {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
