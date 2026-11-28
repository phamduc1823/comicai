<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { AccountService } from '@/services/accountService'
import { AuthService } from '@/services/authService'
import { CreatorApplicationService } from '@/services/creatorApplicationService'
import { CommunityService } from '@/services/communityService'
import { getErrorMessage, isValidationError, getValidationErrors, isRateLimitError } from '@/services/httpClient'
import type { CreatorApplication, CreatorProfileInfo } from '@/types/creatorApplication'
import type { FollowingUserItem } from '@/types/community'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// Tab navigation: 'profile' | 'following' | 'password' | 'creator'
const activeTab = ref<'profile' | 'following' | 'password' | 'creator'>('profile')

// Sync tab with route hash if present
watch(
  () => route.hash,
  (hash) => {
    if (hash === '#following') {
      activeTab.value = 'following'
    } else if (hash === '#password') {
      activeTab.value = 'password'
    } else if (hash === '#creator') {
      activeTab.value = 'creator'
    } else if (hash === '#info' || hash === '#profile' || hash === '#settings') {
      activeTab.value = 'profile'
    }
  },
  { immediate: true }
)

function setTab(tab: 'profile' | 'following' | 'password' | 'creator') {
  activeTab.value = tab
  const hash =
    tab === 'following'
      ? '#following'
      : tab === 'password'
        ? '#password'
        : tab === 'creator'
          ? '#creator'
          : '#profile'
  if (route.hash !== hash) {
    router.replace({ hash })
  }
}

// ----------------------------------------------------
// 0. Following Management State & Actions (CAI-USER-02)
// ----------------------------------------------------
const followingList = ref<FollowingUserItem[]>([])
const followingTotalCount = ref(0)
const followingSearchQuery = ref('')
const isLoadingFollowingList = ref(false)
const followingFeedbackMessage = ref<string | null>(null)
const followingErrorMessage = ref<string | null>(null)
const isUnfollowingMap = ref<Record<number, boolean>>({})

async function loadFollowingList() {
  isLoadingFollowingList.value = true
  followingErrorMessage.value = null
  try {
    const res = await CommunityService.getFollowingUsers(100)
    followingList.value = res.data || []
    followingTotalCount.value = res.meta?.total ?? followingList.value.length
  } catch (err) {
    followingErrorMessage.value = getErrorMessage(err, 'Không thể tải danh sách người đang theo dõi.')
  } finally {
    isLoadingFollowingList.value = false
  }
}

watch(
  activeTab,
  (tab) => {
    if (tab === 'following' && followingList.value.length === 0 && !isLoadingFollowingList.value) {
      loadFollowingList()
    }
  },
  { immediate: true }
)

const filteredFollowingList = computed(() => {
  const q = followingSearchQuery.value.trim().toLowerCase()
  if (!q) return followingList.value
  return followingList.value.filter((user) => user.name.toLowerCase().includes(q))
})

async function handleProfileUnfollow(userId: number, userName: string) {
  if (isUnfollowingMap.value[userId]) return
  isUnfollowingMap.value[userId] = true
  followingFeedbackMessage.value = null
  followingErrorMessage.value = null

  try {
    await CommunityService.unfollowUser(userId)
    followingList.value = followingList.value.filter((u) => u.id !== userId)
    if (followingTotalCount.value > 0) {
      followingTotalCount.value--
    }
    followingFeedbackMessage.value = `Đã bỏ theo dõi ${userName}.`
    setTimeout(() => {
      if (followingFeedbackMessage.value?.includes(userName)) {
        followingFeedbackMessage.value = null
      }
    }, 4000)
  } catch (err) {
    followingErrorMessage.value = getErrorMessage(err, 'Không thể bỏ theo dõi người dùng này.')
  } finally {
    isUnfollowingMap.value[userId] = false
  }
}

// ----------------------------------------------------
// 1. Profile Information State
// ----------------------------------------------------
const formName = ref(authStore.user?.name || '')
const formEmail = ref(authStore.user?.email || '')
const isUpdatingProfile = ref(false)
const profileSuccessMessage = ref<string | null>(null)
const profileErrorMessage = ref<string | null>(null)
const profileFieldErrors = ref<Record<string, string[]>>({})

// Email verification resend state
const isResendingVerification = ref(false)
const verificationSentMessage = ref<string | null>(null)
const verificationErrorMessage = ref<string | null>(null)

// ----------------------------------------------------
// 2. Avatar Upload & Preview State
// ----------------------------------------------------
const avatarFileInput = ref<HTMLInputElement | null>(null)
const avatarPreviewUrl = ref<string | null>(null)
const selectedAvatarFile = ref<File | null>(null)
const isUploadingAvatar = ref(false)
const isDeletingAvatar = ref(false)
const avatarLoadError = ref(false)
const avatarErrorMessage = ref<string | null>(null)
const avatarSuccessMessage = ref<string | null>(null)

// ----------------------------------------------------
// 3. Password Change State
// ----------------------------------------------------
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)
const isUpdatingPassword = ref(false)
const passwordSuccessMessage = ref<string | null>(null)
const passwordErrorMessage = ref<string | null>(null)
const passwordFieldErrors = ref<Record<string, string[]>>({})

// ----------------------------------------------------
// 4. Creator Status State
// ----------------------------------------------------
const isLoadingCreatorStatus = ref(false)
const creatorApplication = ref<CreatorApplication | null>(null)
const creatorProfile = ref<CreatorProfileInfo | null>(null)

// Computed helpers
const userInitials = computed(() => {
  const name = authStore.user?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'U'
})

const effectiveAvatarSrc = computed(() => {
  if (avatarPreviewUrl.value) return avatarPreviewUrl.value
  return authStore.user?.avatar_url || authStore.user?.avatar_path || null
})

const isEmailVerified = computed(() => {
  return Boolean(authStore.user?.email_verified_at)
})

const roleLabel = computed(() => {
  switch (authStore.userRole) {
    case 'admin':
      return 'Quản trị viên 🛡️'
    case 'creator':
      return 'Tác giả ✍️'
    case 'reader':
    default:
      return 'Độc giả 📖'
  }
})

const formattedJoinDate = computed(() => {
  if (!authStore.user?.created_at) return ''
  const date = new Date(authStore.user.created_at)
  if (isNaN(date.getTime())) return ''
  return date.toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
})

// Sync form inputs when user updates in store
watch(
  () => authStore.user,
  (newUser) => {
    if (newUser) {
      if (!isUpdatingProfile.value) {
        formName.value = newUser.name
        formEmail.value = newUser.email
      }
    }
  },
  { immediate: true }
)

// ----------------------------------------------------
// Avatar Actions
// ----------------------------------------------------
function triggerAvatarFileSelect() {
  avatarErrorMessage.value = null
  avatarSuccessMessage.value = null
  avatarFileInput.value?.click()
}

function handleAvatarFileChange(e: Event) {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return

  avatarErrorMessage.value = null
  avatarSuccessMessage.value = null

  // Validate type
  const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg']
  if (!validTypes.includes(file.type)) {
    avatarErrorMessage.value = 'Chỉ chấp nhận các định dạng ảnh JPEG, PNG, WEBP.'
    target.value = ''
    return
  }

  // Validate size (max 2MB)
  if (file.size > 2 * 1024 * 1024) {
    avatarErrorMessage.value = 'Dung lượng ảnh vượt quá giới hạn 2MB.'
    target.value = ''
    return
  }

  selectedAvatarFile.value = file
  avatarLoadError.value = false
  avatarPreviewUrl.value = URL.createObjectURL(file)
}

function cancelAvatarSelection() {
  selectedAvatarFile.value = null
  if (avatarPreviewUrl.value) {
    URL.revokeObjectURL(avatarPreviewUrl.value)
    avatarPreviewUrl.value = null
  }
  if (avatarFileInput.value) {
    avatarFileInput.value.value = ''
  }
}

async function handleSaveAvatar() {
  if (!selectedAvatarFile.value || isUploadingAvatar.value) return

  isUploadingAvatar.value = true
  avatarErrorMessage.value = null
  avatarSuccessMessage.value = null

  try {
    const res = await AccountService.uploadAvatar(selectedAvatarFile.value)
    authStore.setUser(res.user)
    avatarSuccessMessage.value = 'Cập nhật ảnh đại diện thành công!'
    cancelAvatarSelection()
  } catch (err: unknown) {
    avatarErrorMessage.value = getErrorMessage(err, 'Không thể tải lên ảnh đại diện.')
  } finally {
    isUploadingAvatar.value = false
  }
}

async function handleDeleteAvatar() {
  if (isDeletingAvatar.value) return
  if (!confirm('Bạn có chắc chắn muốn xóa ảnh đại diện hiện tại?')) return

  isDeletingAvatar.value = true
  avatarErrorMessage.value = null
  avatarSuccessMessage.value = null

  try {
    const res = await AccountService.deleteAvatar()
    authStore.setUser(res.user)
    cancelAvatarSelection()
    avatarSuccessMessage.value = 'Đã xóa ảnh đại diện thành công.'
  } catch (err: unknown) {
    avatarErrorMessage.value = getErrorMessage(err, 'Không thể xóa ảnh đại diện.')
  } finally {
    isDeletingAvatar.value = false
  }
}

// ----------------------------------------------------
// Profile Update Actions
// ----------------------------------------------------
async function handleUpdateProfile() {
  if (isUpdatingProfile.value) return

  profileSuccessMessage.value = null
  profileErrorMessage.value = null
  avatarSuccessMessage.value = null
  avatarErrorMessage.value = null
  profileFieldErrors.value = {}
  isUpdatingProfile.value = true

  try {
    // If a new avatar file was selected, upload it as part of updating the profile
    if (selectedAvatarFile.value) {
      const avatarRes = await AccountService.uploadAvatar(selectedAvatarFile.value)
      authStore.setUser(avatarRes.user)
      cancelAvatarSelection()
    }

    const res = await AccountService.updateProfile({
      name: formName.value.trim(),
      email: formEmail.value.trim(),
    })
    authStore.setUser(res.user)
    profileSuccessMessage.value = 'Thông tin cá nhân đã được cập nhật thành công!'
  } catch (err: unknown) {
    if (isValidationError(err)) {
      profileFieldErrors.value = getValidationErrors(err)
    } else {
      profileErrorMessage.value = getErrorMessage(err, 'Không thể cập nhật thông tin cá nhân.')
    }
  } finally {
    isUpdatingProfile.value = false
  }
}

async function handleResendVerification() {
  if (isResendingVerification.value || isEmailVerified.value) return

  isResendingVerification.value = true
  verificationSentMessage.value = null
  verificationErrorMessage.value = null

  try {
    const res = await AuthService.sendVerificationNotification()
    verificationSentMessage.value = res.message || 'Đã gửi liên kết xác minh vào hộp thư của bạn.'
  } catch (err: unknown) {
    if (isRateLimitError(err)) {
      verificationErrorMessage.value = 'Bạn đã gửi yêu cầu quá nhanh. Vui lòng chờ 1 phút trước khi thử lại.'
    } else {
      verificationErrorMessage.value = getErrorMessage(err, 'Không thể gửi email xác minh.')
    }
  } finally {
    isResendingVerification.value = false
  }
}

// ----------------------------------------------------
// Password Update Actions
// ----------------------------------------------------
async function handleUpdatePassword() {
  if (isUpdatingPassword.value) return

  passwordSuccessMessage.value = null
  passwordErrorMessage.value = null
  passwordFieldErrors.value = {}

  if (!currentPassword.value) {
    passwordFieldErrors.value = { current_password: ['Vui lòng nhập mật khẩu hiện tại.'] }
    return
  }
  if (!newPassword.value) {
    passwordFieldErrors.value = { password: ['Vui lòng nhập mật khẩu mới.'] }
    return
  }
  if (newPassword.value.length < 8) {
    passwordFieldErrors.value = { password: ['Mật khẩu mới phải có ít nhất 8 ký tự.'] }
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    passwordFieldErrors.value = { password: ['Xác nhận mật khẩu mới không khớp.'] }
    return
  }
  if (newPassword.value === currentPassword.value) {
    passwordFieldErrors.value = { password: ['Mật khẩu mới không được trùng với mật khẩu hiện tại.'] }
    return
  }

  isUpdatingPassword.value = true

  try {
    const res = await AccountService.updatePassword({
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: confirmPassword.value,
    })
    passwordSuccessMessage.value = res.message || 'Đổi mật khẩu thành công!'
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
  } catch (err: unknown) {
    if (isValidationError(err)) {
      passwordFieldErrors.value = getValidationErrors(err)
    } else {
      passwordErrorMessage.value = getErrorMessage(err, 'Không thể đổi mật khẩu. Vui lòng thử lại.')
    }
  } finally {
    isUpdatingPassword.value = false
  }
}

// ----------------------------------------------------
// Load Creator Application Data
// ----------------------------------------------------
async function fetchCreatorStatus() {
  isLoadingCreatorStatus.value = true
  try {
    const data = await CreatorApplicationService.getMyApplication()
    creatorApplication.value = data.application
    creatorProfile.value = data.profile
  } catch {
    // Non-critical background fetch failure
  } finally {
    isLoadingCreatorStatus.value = false
  }
}

onMounted(() => {
  document.title = 'Quản lý tài khoản — BlueToon'
  fetchCreatorStatus()
})
</script>

<template>
  <div class="account-page">
    <div class="account-container">
      <!-- Page Navigation Header -->
      <nav class="account-breadcrumb" aria-label="Điều hướng">
        <RouterLink to="/" class="breadcrumb-link">Trang chủ</RouterLink>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="breadcrumb-current">Quản lý tài khoản</span>
      </nav>

      <!-- Account Dashboard Hero Card -->
      <section class="account-hero-card">
        <div class="hero-banner-bg" aria-hidden="true"></div>
        <div class="hero-profile-body">
          <div class="hero-avatar-container">
            <div class="hero-avatar-wrapper">
              <img
                v-if="effectiveAvatarSrc && !avatarLoadError"
                :src="effectiveAvatarSrc"
                :alt="authStore.user?.name || 'Ảnh đại diện'"
                class="hero-avatar-img"
                @error="avatarLoadError = true"
              />
              <span v-else class="hero-avatar-initial">{{ userInitials }}</span>
            </div>
            <button
              type="button"
              class="btn-avatar-picker-trigger"
              title="Đổi ảnh đại diện"
              aria-label="Đổi ảnh đại diện"
              @click="triggerAvatarFileSelect"
            >
              📷
            </button>
            <input
              ref="avatarFileInput"
              type="file"
              accept="image/jpeg,image/png,image/webp,image/jpg"
              class="hidden-file-input"
              aria-label="Chọn tệp ảnh đại diện"
              @change="handleAvatarFileChange"
            />
          </div>

          <div class="hero-info-container">
            <div class="hero-title-row">
              <h1 class="hero-user-name">{{ authStore.user?.name }}</h1>
              <span class="hero-role-badge" :class="'role-' + authStore.userRole">
                {{ roleLabel }}
              </span>
            </div>
            <div class="hero-meta-row">
              <span class="hero-email">{{ authStore.user?.email }}</span>
              <span
                class="hero-verify-badge"
                :class="isEmailVerified ? 'verified' : 'unverified'"
              >
                {{ isEmailVerified ? 'Đã xác minh ✅' : 'Chưa xác minh ⚠️' }}
              </span>
              <span v-if="formattedJoinDate" class="hero-join-date">
                Tham gia: {{ formattedJoinDate }}
              </span>
            </div>
          </div>

          <div class="hero-actions-container">
            <RouterLink
              v-if="authStore.user?.id"
              :to="'/users/' + authStore.user.id"
              class="btn-public-profile-link"
            >
              <span>Xem hồ sơ công khai ↗</span>
            </RouterLink>
          </div>
        </div>

        <!-- Preview Bar when an avatar is newly selected -->
        <div v-if="selectedAvatarFile" class="avatar-preview-action-bar">
          <span class="preview-text">
            Ảnh đại diện mới đã chọn: <strong>{{ selectedAvatarFile.name }}</strong>
          </span>
          <div class="preview-btn-group">
            <button
              type="button"
              class="btn-save-avatar"
              :disabled="isUploadingAvatar"
              @click="handleSaveAvatar"
            >
              <span v-if="isUploadingAvatar" class="btn-spinner" aria-hidden="true"></span>
              <span>{{ isUploadingAvatar ? 'Đang tải lên...' : 'Lưu ảnh mới' }}</span>
            </button>
            <button
              type="button"
              class="btn-cancel-avatar"
              :disabled="isUploadingAvatar"
              @click="cancelAvatarSelection"
            >
              Hủy
            </button>
          </div>
        </div>

        <!-- Avatar Feedback Messages -->
        <div v-if="avatarSuccessMessage" class="feedback-banner success" role="alert">
          {{ avatarSuccessMessage }}
        </div>
        <div v-if="avatarErrorMessage" class="feedback-banner error" role="alert">
          {{ avatarErrorMessage }}
        </div>
      </section>

      <!-- Main Tabs Navigation -->
      <div class="account-tabs-nav" role="tablist">
        <button
          type="button"
          class="tab-nav-btn"
          :class="{ active: activeTab === 'profile' }"
          role="tab"
          :aria-selected="activeTab === 'profile'"
          @click="setTab('profile')"
        >
          <span class="tab-icon" aria-hidden="true">👤</span>
          <span>Thông tin cá nhân</span>
        </button>

        <button
          type="button"
          class="tab-nav-btn"
          :class="{ active: activeTab === 'following' }"
          role="tab"
          :aria-selected="activeTab === 'following'"
          @click="setTab('following')"
        >
          <span class="tab-icon" aria-hidden="true">👥</span>
          <span>Đang theo dõi</span>
          <span v-if="followingTotalCount > 0" class="tab-counter-badge following-count">
            {{ followingTotalCount }}
          </span>
        </button>

        <button
          type="button"
          class="tab-nav-btn"
          :class="{ active: activeTab === 'password' }"
          role="tab"
          :aria-selected="activeTab === 'password'"
          @click="setTab('password')"
        >
          <span class="tab-icon" aria-hidden="true">🔒</span>
          <span>Đổi mật khẩu</span>
        </button>

        <button
          type="button"
          class="tab-nav-btn"
          :class="{ active: activeTab === 'creator' }"
          role="tab"
          :aria-selected="activeTab === 'creator'"
          @click="setTab('creator')"
        >
          <span class="tab-icon" aria-hidden="true">✍️</span>
          <span>Hồ sơ Tác giả</span>
          <span
            v-if="creatorApplication?.status === 'pending'"
            class="tab-counter-badge pending"
          >
            Chờ duyệt
          </span>
          <span
            v-else-if="creatorApplication?.status === 'rejected'"
            class="tab-counter-badge rejected"
          >
            Từ chối
          </span>
        </button>
      </div>

      <!-- Tab Content Area -->
      <div class="account-tab-panel">
        <!-- TAB 1: Profile Information -->
        <div v-if="activeTab === 'profile'" class="tab-pane" role="tabpanel">
          <div class="panel-section-header">
            <h2 class="panel-section-title">Cập nhật thông tin cá nhân</h2>
            <p class="panel-section-desc">
              Quản lý thông tin hiển thị và ảnh đại diện tài khoản của bạn trên ComicAI/BlueToon.
            </p>
          </div>

          <form class="account-form" @submit.prevent="handleUpdateProfile">
            <div v-if="profileSuccessMessage" class="feedback-banner success" role="alert">
              {{ profileSuccessMessage }}
            </div>
            <div v-if="profileErrorMessage" class="feedback-banner error" role="alert">
              {{ profileErrorMessage }}
            </div>

            <!-- Avatar Management Subsection -->
            <div class="form-group avatar-management-group">
              <label class="form-label">Ảnh đại diện</label>
              <div class="avatar-controls-inline">
                <div class="inline-avatar-circle">
                  <img
                    v-if="effectiveAvatarSrc && !avatarLoadError"
                    :src="effectiveAvatarSrc"
                    :alt="authStore.user?.name || 'Avatar'"
                    class="inline-avatar-img"
                    @error="avatarLoadError = true"
                  />
                  <span v-else class="inline-avatar-initial">{{ userInitials }}</span>
                </div>
                <div class="inline-avatar-actions">
                  <div class="inline-avatar-btn-row">
                    <button
                      type="button"
                      class="btn-inline-upload"
                      @click="triggerAvatarFileSelect"
                    >
                      Chọn ảnh mới
                    </button>
                    <button
                      v-if="selectedAvatarFile"
                      type="button"
                      class="btn-inline-save-direct"
                      :disabled="isUploadingAvatar"
                      @click="handleSaveAvatar"
                    >
                      <span v-if="isUploadingAvatar" class="btn-spinner" aria-hidden="true"></span>
                      <span>{{ isUploadingAvatar ? 'Đang lưu...' : 'Lưu ảnh ngay 💾' }}</span>
                    </button>
                    <button
                      v-if="selectedAvatarFile"
                      type="button"
                      class="btn-inline-cancel-direct"
                      :disabled="isUploadingAvatar"
                      @click="cancelAvatarSelection"
                    >
                      Hủy
                    </button>
                    <button
                      v-if="authStore.user?.avatar_path && !selectedAvatarFile"
                      type="button"
                      class="btn-inline-delete"
                      :disabled="isDeletingAvatar"
                      @click="handleDeleteAvatar"
                    >
                      {{ isDeletingAvatar ? 'Đang xóa...' : 'Xóa ảnh' }}
                    </button>
                  </div>
                  <div v-if="selectedAvatarFile" class="inline-avatar-staged-notice">
                    <span>Đã chọn: <strong>{{ selectedAvatarFile.name }}</strong> (Nhấn "Lưu ảnh ngay" hoặc "Lưu thay đổi" bên dưới để áp dụng)</span>
                  </div>
                  <span class="inline-avatar-hint">
                    Định dạng JPEG, PNG, WEBP. Dung lượng tối đa 2MB.
                  </span>
                </div>
              </div>
            </div>

            <!-- Full Name -->
            <div class="form-group">
              <label for="profile-name" class="form-label">
                Họ và tên <span class="required">*</span>
              </label>
              <input
                id="profile-name"
                v-model="formName"
                type="text"
                class="form-input"
                :class="{ 'has-error': profileFieldErrors.name }"
                placeholder="Nhập họ và tên của bạn"
                required
                minlength="2"
                maxlength="255"
              />
              <span v-if="profileFieldErrors.name" class="field-error-text">
                {{ profileFieldErrors.name[0] }}
              </span>
            </div>

            <!-- Email Address -->
            <div class="form-group">
              <div class="label-with-badge">
                <label for="profile-email" class="form-label">
                  Địa chỉ email <span v-if="!isEmailVerified" class="required">*</span>
                </label>
                <span
                  class="email-status-pill"
                  :class="isEmailVerified ? 'verified' : 'unverified'"
                >
                  {{ isEmailVerified ? 'Đã xác minh (Đã khóa) 🔒' : 'Chưa xác minh' }}
                </span>
              </div>
              <input
                id="profile-email"
                v-model="formEmail"
                type="email"
                class="form-input"
                :class="{ 'has-error': profileFieldErrors.email, 'is-readonly': isEmailVerified }"
                placeholder="name@example.com"
                :disabled="isEmailVerified"
                :readonly="isEmailVerified"
                required
              />
              <span v-if="profileFieldErrors.email" class="field-error-text">
                {{ profileFieldErrors.email[0] }}
              </span>
              <p v-if="isEmailVerified" class="field-helper-text verified-locked-hint">
                🔒 Địa chỉ email đã được xác minh thành công và được khóa cố định để đảm bảo an toàn tài khoản.
              </p>
              <p v-else class="field-helper-text">
                Lưu ý: Nếu thay đổi địa chỉ email, bạn sẽ cần xác minh lại email mới trước khi sử dụng các tính năng nâng cao.
              </p>

              <!-- Resend verification email CTA -->
              <div v-if="!isEmailVerified" class="resend-verification-box">
                <p class="resend-desc">
                  Tài khoản của bạn chưa được xác minh email. Xác minh để mở khóa tính năng nộp hồ sơ Tác giả và bảo vệ tài khoản.
                </p>
                <button
                  type="button"
                  class="btn-resend-verification"
                  :disabled="isResendingVerification"
                  @click="handleResendVerification"
                >
                  <span v-if="isResendingVerification" class="btn-spinner" aria-hidden="true"></span>
                  <span>{{ isResendingVerification ? 'Đang gửi...' : 'Gửi lại email xác minh ✉️' }}</span>
                </button>
                <span v-if="verificationSentMessage" class="resend-success-text">
                  {{ verificationSentMessage }}
                </span>
                <span v-if="verificationErrorMessage" class="resend-error-text">
                  {{ verificationErrorMessage }}
                </span>
              </div>
            </div>

            <!-- Role (Readonly) -->
            <div class="form-group">
              <label class="form-label">Vai trò tài khoản</label>
              <div class="readonly-badge-container">
                <span class="role-pill" :class="'role-' + authStore.userRole">
                  {{ roleLabel }}
                </span>
                <span class="readonly-note">
                  (Vai trò do hệ thống phân quyền, không thể tự chỉnh sửa)
                </span>
              </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
              <button
                type="submit"
                class="btn-primary-submit"
                :disabled="isUpdatingProfile"
              >
                <span v-if="isUpdatingProfile" class="btn-spinner" aria-hidden="true"></span>
                <span>{{ isUpdatingProfile ? 'Đang lưu...' : 'Lưu thay đổi' }}</span>
              </button>
            </div>
          </form>
        </div>

        <!-- TAB: Following Management (CAI-USER-02) -->
        <div v-else-if="activeTab === 'following'" class="tab-pane" role="tabpanel" aria-labelledby="tab-following">
          <div class="following-manager">
            <div class="panel-section-header">
              <div class="following-header-row">
                <div>
                  <h2 class="panel-section-title">Danh sách người đang theo dõi</h2>
                  <p class="panel-section-desc">
                    Quản lý danh sách tác giả và người dùng mà bạn đang theo dõi trên ComicAI.
                  </p>
                </div>
                <span v-if="followingTotalCount > 0" class="following-total-pill">
                  {{ followingTotalCount }} người đang theo dõi
                </span>
              </div>
            </div>

            <!-- Search Bar in Following List -->
            <div class="following-search-wrapper">
              <span class="search-input-icon" aria-hidden="true">🔍</span>
              <input
                v-model="followingSearchQuery"
                type="text"
                placeholder="Tìm kiếm theo tên trong danh sách theo dõi..."
                class="following-search-field"
                aria-label="Tìm kiếm người đang theo dõi"
              />
              <button
                v-if="followingSearchQuery"
                type="button"
                class="btn-clear-input"
                title="Xóa tìm kiếm"
                aria-label="Xóa tìm kiếm"
                @click="followingSearchQuery = ''"
              >
                ✕
              </button>
            </div>

            <!-- Feedback Alerts -->
            <div v-if="followingFeedbackMessage" class="feedback-banner success" role="alert">
              {{ followingFeedbackMessage }}
            </div>
            <div v-if="followingErrorMessage" class="feedback-banner error" role="alert">
              {{ followingErrorMessage }}
            </div>

            <!-- Loading Skeleton -->
            <div v-if="isLoadingFollowingList" class="following-loading-block" aria-busy="true">
              <div class="spinner" aria-hidden="true"></div>
              <span>Đang tải danh sách người theo dõi...</span>
            </div>

            <!-- Empty: No following users -->
            <div v-else-if="followingList.length === 0" class="following-empty-box">
              <div class="empty-icon-wrap" aria-hidden="true">👥</div>
              <h3 class="empty-heading">Bạn chưa theo dõi người dùng nào</h3>
              <p class="empty-subtext">
                Khám phá các tác giả nổi bật trong Cộng đồng để cập nhật bài viết và hoạt động mới nhất.
              </p>
              <RouterLink to="/community" class="btn-community-link">
                Khám phá Cộng đồng
              </RouterLink>
            </div>

            <!-- Empty: Search no results -->
            <div v-else-if="filteredFollowingList.length === 0" class="following-empty-box">
              <div class="empty-icon-wrap" aria-hidden="true">🔎</div>
              <h3 class="empty-heading">Không tìm thấy người dùng phù hợp</h3>
              <p class="empty-subtext">
                Không có người dùng nào khớp với từ khóa "<strong>{{ followingSearchQuery }}</strong>".
              </p>
              <button type="button" class="btn-clear-filter" @click="followingSearchQuery = ''">
                Xem tất cả người đang theo dõi
              </button>
            </div>

            <!-- Following Grid -->
            <div v-else class="following-users-grid" role="list">
              <div
                v-for="user in filteredFollowingList"
                :key="'following-user-' + user.id"
                class="following-item-card"
                role="listitem"
              >
                <RouterLink :to="`/users/${user.id}`" class="following-item-info">
                  <img
                    v-if="user.avatar_url || user.avatar_path"
                    :src="user.avatar_url || user.avatar_path || ''"
                    :alt="user.name"
                    class="following-avatar"
                  />
                  <div v-else class="following-avatar-fallback">
                    {{ user.name.charAt(0).toUpperCase() }}
                  </div>
                  <div class="following-details">
                    <span class="following-name" :title="user.name">{{ user.name }}</span>
                    <span v-if="user.role === 'creator'" class="badge-role creator">Tác giả</span>
                    <span v-else class="following-post-count">{{ user.posts_count || 0 }} bài viết</span>
                  </div>
                </RouterLink>

                <div class="following-action-area">
                  <button
                    type="button"
                    class="btn-following-toggle"
                    :disabled="isUnfollowingMap[user.id]"
                    :title="'Bấm để bỏ theo dõi ' + user.name"
                    @click="handleProfileUnfollow(user.id, user.name)"
                  >
                    <span v-if="isUnfollowingMap[user.id]">Đang hủy...</span>
                    <span v-else class="btn-label-state">Đang theo dõi</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 2: Change Password -->
        <div v-else-if="activeTab === 'password'" class="tab-pane" role="tabpanel">
          <div class="panel-section-header">
            <h2 class="panel-section-title">Đổi mật khẩu tài khoản</h2>
            <p class="panel-section-desc">
              Để bảo mật tài khoản, vui lòng sử dụng mật khẩu mạnh gồm ít nhất 8 ký tự và không dùng lại mật khẩu cũ.
            </p>
          </div>

          <form class="account-form" @submit.prevent="handleUpdatePassword">
            <div v-if="passwordSuccessMessage" class="feedback-banner success" role="alert">
              {{ passwordSuccessMessage }}
            </div>
            <div v-if="passwordErrorMessage" class="feedback-banner error" role="alert">
              {{ passwordErrorMessage }}
            </div>

            <!-- Current Password -->
            <div class="form-group">
              <label for="current-password" class="form-label">
                Mật khẩu hiện tại <span class="required">*</span>
              </label>
              <div class="password-input-wrapper">
                <input
                  id="current-password"
                  v-model="currentPassword"
                  :type="showCurrentPassword ? 'text' : 'password'"
                  class="form-input"
                  :class="{ 'has-error': passwordFieldErrors.current_password }"
                  placeholder="Nhập mật khẩu hiện tại"
                  required
                />
                <button
                  type="button"
                  class="btn-toggle-password"
                  :aria-label="showCurrentPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                  @click="showCurrentPassword = !showCurrentPassword"
                >
                  {{ showCurrentPassword ? '🙈' : '👁️' }}
                </button>
              </div>
              <span v-if="passwordFieldErrors.current_password" class="field-error-text">
                {{ passwordFieldErrors.current_password[0] }}
              </span>
            </div>

            <!-- New Password -->
            <div class="form-group">
              <label for="new-password" class="form-label">
                Mật khẩu mới <span class="required">*</span>
              </label>
              <div class="password-input-wrapper">
                <input
                  id="new-password"
                  v-model="newPassword"
                  :type="showNewPassword ? 'text' : 'password'"
                  class="form-input"
                  :class="{ 'has-error': passwordFieldErrors.password }"
                  placeholder="Tối thiểu 8 ký tự"
                  required
                  minlength="8"
                />
                <button
                  type="button"
                  class="btn-toggle-password"
                  :aria-label="showNewPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                  @click="showNewPassword = !showNewPassword"
                >
                  {{ showNewPassword ? '🙈' : '👁️' }}
                </button>
              </div>
              <span v-if="passwordFieldErrors.password" class="field-error-text">
                {{ passwordFieldErrors.password[0] }}
              </span>
            </div>

            <!-- Confirm New Password -->
            <div class="form-group">
              <label for="confirm-password" class="form-label">
                Xác nhận mật khẩu mới <span class="required">*</span>
              </label>
              <div class="password-input-wrapper">
                <input
                  id="confirm-password"
                  v-model="confirmPassword"
                  :type="showConfirmPassword ? 'text' : 'password'"
                  class="form-input"
                  placeholder="Nhập lại mật khẩu mới"
                  required
                  minlength="8"
                />
                <button
                  type="button"
                  class="btn-toggle-password"
                  :aria-label="showConfirmPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
                  @click="showConfirmPassword = !showConfirmPassword"
                >
                  {{ showConfirmPassword ? '🙈' : '👁️' }}
                </button>
              </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
              <button
                type="submit"
                class="btn-primary-submit"
                :disabled="isUpdatingPassword"
              >
                <span v-if="isUpdatingPassword" class="btn-spinner" aria-hidden="true"></span>
                <span>{{ isUpdatingPassword ? 'Đang cập nhật...' : 'Cập nhật mật khẩu' }}</span>
              </button>
            </div>
          </form>
        </div>

        <!-- TAB 3: Creator Status -->
        <div v-else-if="activeTab === 'creator'" class="tab-pane" role="tabpanel">
          <div class="panel-section-header">
            <h2 class="panel-section-title">Trạng thái và Quyền Tác giả (Creator)</h2>
            <p class="panel-section-desc">
              Theo dõi hồ sơ đăng ký trở thành người đăng truyện hoặc truy cập Kênh sáng tác của bạn.
            </p>
          </div>

          <!-- Creator Status Cards -->
          <div v-if="isLoadingCreatorStatus" class="creator-loading-card">
            <div class="btn-spinner"></div>
            <p>Đang tải thông tin trạng thái Creator...</p>
          </div>

          <!-- Case 1: Already Creator -->
          <div
            v-else-if="authStore.isCreator || authStore.isAdmin"
            class="creator-status-card approved-card"
          >
            <div class="card-icon" aria-hidden="true">🎉</div>
            <div class="card-content">
              <h3 class="card-title">Bạn đã là Người đăng truyện chính thức!</h3>
              <p class="card-desc">
                Tài khoản của bạn đã được cấp quyền Tác giả. Bạn có thể tạo truyện mới, tải lên các chương truyện và quản lý truyện tranh trong Kênh sáng tác Studio.
              </p>
              <div v-if="creatorProfile" class="creator-profile-snippet">
                <span class="snippet-item">Bút danh: <strong>{{ creatorProfile.pen_name }}</strong></span>
                <span class="snippet-item">Mã Tác giả: <strong>{{ creatorProfile.creator_code }}</strong></span>
              </div>
              <div class="card-actions">
                <RouterLink to="/creator/comics" class="btn-card-primary">
                  Truy cập Creator Studio ✍️
                </RouterLink>
                <RouterLink to="/creator/comics/create" class="btn-card-secondary">
                  + Tạo truyện mới
                </RouterLink>
              </div>
            </div>
          </div>

          <!-- Case 2: Pending Application -->
          <div
            v-else-if="creatorApplication?.status === 'pending'"
            class="creator-status-card pending-card"
          >
            <div class="card-icon" aria-hidden="true">⏳</div>
            <div class="card-content">
              <div class="badge-status-row">
                <span class="status-badge pending">Đang chờ xét duyệt</span>
                <span class="app-code">Mã: {{ creatorApplication.application_code }}</span>
              </div>
              <h3 class="card-title">Hồ sơ đăng ký của bạn đang được Ban Quản Trị xem xét</h3>
              <p class="card-desc">
                Bút danh đăng ký: <strong>{{ creatorApplication.pen_name }}</strong>. Chúng tôi sẽ phản hồi trong thời gian sớm nhất qua hệ thống thông báo.
              </p>
              <div class="card-actions">
                <RouterLink to="/become-creator" class="btn-card-primary">
                  Xem chi tiết hồ sơ ↗
                </RouterLink>
              </div>
            </div>
          </div>

          <!-- Case 3: Rejected Application -->
          <div
            v-else-if="creatorApplication?.status === 'rejected'"
            class="creator-status-card rejected-card"
          >
            <div class="card-icon" aria-hidden="true">⚠️</div>
            <div class="card-content">
              <div class="badge-status-row">
                <span class="status-badge rejected">Hồ sơ bị từ chối</span>
                <span class="app-code">Mã: {{ creatorApplication.application_code }}</span>
              </div>
              <h3 class="card-title">Hồ sơ đăng ký Creator chưa đạt yêu cầu</h3>
              <div v-if="creatorApplication.rejection_reason" class="rejection-reason-box">
                <span class="reason-label">Lý do từ chối từ Ban Quản Trị:</span>
                <p class="reason-text">{{ creatorApplication.rejection_reason }}</p>
              </div>
              <p class="card-desc">
                Bạn có thể điều chỉnh lại thông tin và gửi lại hồ sơ xét duyệt.
              </p>
              <div class="card-actions">
                <RouterLink to="/become-creator" class="btn-card-primary">
                  Chỉnh sửa & Gửi lại hồ sơ ✍️
                </RouterLink>
              </div>
            </div>
          </div>

          <!-- Case 4: Reader not registered yet -->
          <div v-else class="creator-status-card reader-cta-card">
            <div class="card-icon" aria-hidden="true">✨</div>
            <div class="card-content">
              <h3 class="card-title">Trở thành Tác giả trên BlueToon</h3>
              <p class="card-desc">
                Chia sẻ câu chuyện và tác phẩm truyện tranh của bạn với hàng ngàn độc giả. Nhận đặc quyền phát hành truyện, mở khóa chương VIP và tương tác với cộng đồng.
              </p>
              <ul class="creator-benefits-list">
                <li>✍️ Tự do xuất bản truyện tranh và các chương mới</li>
                <li>👑 Kiếm Coin thông qua tính năng mở khóa chương VIP</li>
                <li>💬 Trực tiếp trả lời bình luận và kết nối cùng người hâm mộ</li>
              </ul>
              <div class="card-actions">
                <RouterLink to="/become-creator" class="btn-card-cta">
                  Đăng ký trở thành Creator ngay ✍️
                </RouterLink>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.account-page {
  min-height: calc(100vh - 68px);
  background-color: var(--color-bg-base);
  padding: var(--spacing-xl) var(--spacing-lg);
  color: var(--color-text-main);
}

.account-container {
  max-width: 980px;
  margin: 0 auto;
}

/* Breadcrumb */
.account-breadcrumb {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  font-size: var(--font-size-sm);
  margin-bottom: var(--spacing-lg);
  color: var(--color-text-muted);
}

.breadcrumb-link {
  color: var(--color-text-muted);
  text-decoration: none;
  transition: color var(--transition-fast);
}

.breadcrumb-link:hover {
  color: var(--color-primary);
}

.breadcrumb-separator {
  color: var(--color-border);
}

.breadcrumb-current {
  color: var(--color-text-main);
  font-weight: 500;
}

/* Hero Profile Card */
.account-hero-card {
  position: relative;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  overflow: hidden;
  margin-bottom: var(--spacing-xl);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
}

.hero-banner-bg {
  height: 110px;
  background: linear-gradient(135deg, #093959 0%, #175485 50%, #2076b3 100%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.hero-profile-body {
  display: flex;
  align-items: flex-end;
  padding: 0 var(--spacing-xl) var(--spacing-lg);
  margin-top: -50px;
  gap: var(--spacing-lg);
  flex-wrap: wrap;
}

.hero-avatar-container {
  position: relative;
  flex-shrink: 0;
}

.hero-avatar-wrapper {
  width: 96px;
  height: 96px;
  border-radius: var(--radius-full);
  border: 4px solid var(--color-bg-surface);
  background-color: var(--color-bg-surface-raised);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
}

.hero-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.hero-avatar-initial {
  font-size: 38px;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #1f6feb, #388bfd);
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  text-transform: uppercase;
}

.btn-avatar-picker-trigger {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  background-color: var(--color-bg-surface-raised);
  border: 2px solid var(--color-bg-surface);
  color: var(--color-text-main);
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all var(--transition-fast);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
}

.btn-avatar-picker-trigger:hover {
  background-color: var(--color-primary);
  transform: scale(1.1);
}

.hidden-file-input {
  display: none;
}

.hero-info-container {
  flex: 1;
  min-width: 250px;
}

.hero-title-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
  margin-bottom: 6px;
}

.hero-user-name {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  color: #fff;
  margin: 0;
}

.hero-role-badge {
  font-size: var(--font-size-xs);
  font-weight: 600;
  padding: 4px 10px;
  border-radius: var(--radius-full);
}

.role-admin {
  background-color: rgba(218, 54, 51, 0.18);
  color: #f85149;
  border: 1px solid rgba(218, 54, 51, 0.4);
}

.role-creator {
  background-color: rgba(46, 160, 67, 0.18);
  color: #3fb950;
  border: 1px solid rgba(46, 160, 67, 0.4);
}

.role-reader {
  background-color: rgba(56, 139, 253, 0.18);
  color: #58a6ff;
  border: 1px solid rgba(56, 139, 253, 0.4);
}

.hero-meta-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  flex-wrap: wrap;
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
}

.hero-verify-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
}

.hero-verify-badge.verified {
  background-color: rgba(46, 160, 67, 0.15);
  color: #3fb950;
  border: 1px solid rgba(46, 160, 67, 0.3);
}

.hero-verify-badge.unverified {
  background-color: rgba(210, 153, 34, 0.15);
  color: #d29922;
  border: 1px solid rgba(210, 153, 34, 0.3);
}

.hero-actions-container {
  display: flex;
  align-items: center;
}

.btn-public-profile-link {
  font-size: var(--font-size-sm);
  font-weight: 500;
  color: var(--color-primary);
  text-decoration: none;
  padding: 8px 14px;
  border: 1px solid rgba(56, 139, 253, 0.4);
  border-radius: var(--radius-md);
  background-color: rgba(56, 139, 253, 0.1);
  transition: all var(--transition-fast);
}

.btn-public-profile-link:hover {
  background-color: rgba(56, 139, 253, 0.2);
  border-color: var(--color-primary);
  transform: translateY(-1px);
}

/* Avatar Preview Bar */
.avatar-preview-action-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px var(--spacing-xl);
  background-color: rgba(56, 139, 253, 0.08);
  border-top: 1px solid rgba(56, 139, 253, 0.2);
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.preview-text {
  font-size: var(--font-size-sm);
  color: #58a6ff;
}

.preview-btn-group {
  display: flex;
  gap: var(--spacing-sm);
}

.btn-save-avatar {
  background-color: #238636;
  color: #fff;
  border: none;
  border-radius: var(--radius-md);
  padding: 6px 14px;
  font-size: var(--font-size-sm);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: background-color var(--transition-fast);
}

.btn-save-avatar:hover:not(:disabled) {
  background-color: #2ea043;
}

.btn-cancel-avatar {
  background-color: transparent;
  color: var(--color-text-muted);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 6px 14px;
  font-size: var(--font-size-sm);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-cancel-avatar:hover {
  color: var(--color-text-main);
  border-color: var(--color-text-muted);
}

/* Feedback Banners */
.feedback-banner {
  padding: 12px var(--spacing-lg);
  margin: var(--spacing-md) var(--spacing-xl);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
}

.feedback-banner.success {
  background-color: rgba(46, 160, 67, 0.15);
  border: 1px solid rgba(46, 160, 67, 0.4);
  color: #3fb950;
}

.feedback-banner.error {
  background-color: rgba(218, 54, 51, 0.15);
  border: 1px solid rgba(218, 54, 51, 0.4);
  color: #f85149;
}

/* Tabs Navigation */
.account-tabs-nav {
  display: flex;
  gap: var(--spacing-sm);
  border-bottom: 1px solid var(--color-border);
  margin-bottom: var(--spacing-xl);
  overflow-x: auto;
}

.tab-nav-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 18px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--color-text-muted);
  font-size: var(--font-size-base);
  font-weight: 500;
  cursor: pointer;
  transition: all var(--transition-fast);
  white-space: nowrap;
}

.tab-nav-btn:hover {
  color: var(--color-text-main);
}

.tab-nav-btn.active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
  font-weight: 600;
}

.tab-counter-badge {
  font-size: 11px;
  padding: 2px 6px;
  border-radius: var(--radius-full);
}

.tab-counter-badge.pending {
  background-color: rgba(210, 153, 34, 0.2);
  color: #d29922;
  border: 1px solid rgba(210, 153, 34, 0.4);
}

.tab-counter-badge.rejected {
  background-color: rgba(218, 54, 51, 0.2);
  color: #f85149;
  border: 1px solid rgba(218, 54, 51, 0.4);
}

/* Tab Panel & Forms */
.account-tab-panel {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--spacing-xl);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.panel-section-header {
  margin-bottom: var(--spacing-xl);
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--spacing-md);
}

.panel-section-title {
  font-size: var(--font-size-xl);
  font-weight: 700;
  color: #fff;
  margin: 0 0 6px 0;
}

.panel-section-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0;
}

.account-form {
  max-width: 640px;
}

.form-group {
  margin-bottom: var(--spacing-lg);
}

.form-label {
  display: block;
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-text-main);
  margin-bottom: 6px;
}

.required {
  color: #f85149;
}

.label-with-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}

.email-status-pill {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
}

.email-status-pill.verified {
  background-color: rgba(46, 160, 67, 0.15);
  color: #3fb950;
  border: 1px solid rgba(46, 160, 67, 0.3);
}

.email-status-pill.unverified {
  background-color: rgba(210, 153, 34, 0.15);
  color: #d29922;
  border: 1px solid rgba(210, 153, 34, 0.3);
}

.form-input {
  width: 100%;
  padding: 10px 14px;
  background-color: var(--color-bg-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-main);
  font-size: var(--font-size-base);
  transition: all var(--transition-fast);
}

.form-input:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(56, 139, 253, 0.2);
}

.form-input.has-error {
  border-color: #f85149;
}

.form-input.is-readonly {
  background-color: rgba(255, 255, 255, 0.03);
  border-color: var(--color-border);
  color: var(--color-text-muted);
  cursor: not-allowed;
  user-select: none;
}

.form-input.is-readonly:focus {
  border-color: var(--color-border);
  box-shadow: none;
}

.verified-locked-hint {
  color: #58a6ff !important;
  font-weight: 500;
}

.field-error-text {
  display: block;
  font-size: var(--font-size-xs);
  color: #f85149;
  margin-top: 4px;
}

.field-helper-text {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-top: 6px;
  line-height: 1.4;
}

/* Resend Verification Box */
.resend-verification-box {
  margin-top: var(--spacing-sm);
  padding: 12px;
  background-color: rgba(210, 153, 34, 0.08);
  border: 1px solid rgba(210, 153, 34, 0.3);
  border-radius: var(--radius-md);
}

.resend-desc {
  font-size: var(--font-size-xs);
  color: #d29922;
  margin: 0 0 8px 0;
}

.btn-resend-verification {
  background-color: transparent;
  color: #58a6ff;
  border: 1px solid rgba(56, 139, 253, 0.4);
  border-radius: var(--radius-md);
  padding: 6px 12px;
  font-size: var(--font-size-xs);
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all var(--transition-fast);
}

.btn-resend-verification:hover:not(:disabled) {
  background-color: rgba(56, 139, 253, 0.15);
  border-color: var(--color-primary);
}

.resend-success-text {
  display: block;
  margin-top: 6px;
  font-size: var(--font-size-xs);
  color: #3fb950;
}

.resend-error-text {
  display: block;
  margin-top: 6px;
  font-size: var(--font-size-xs);
  color: #f85149;
}

/* Avatar inline controls in Tab 1 */
.avatar-controls-inline {
  display: flex;
  align-items: center;
  gap: var(--spacing-lg);
  padding: 12px;
  background-color: var(--color-bg-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.inline-avatar-circle {
  width: 64px;
  height: 64px;
  border-radius: var(--radius-full);
  overflow: hidden;
  background-color: var(--color-bg-surface);
  border: 2px solid var(--color-border);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.inline-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.inline-avatar-initial {
  font-size: 26px;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #1f6feb, #388bfd);
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.inline-avatar-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
}

.inline-avatar-btn-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.btn-inline-upload {
  align-self: flex-start;
  padding: 6px 14px;
  font-size: var(--font-size-sm);
  font-weight: 500;
  color: #fff;
  background-color: var(--color-primary);
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: background-color var(--transition-fast);
}

.btn-inline-upload:hover {
  background-color: var(--color-primary-hover);
}

.btn-inline-save-direct {
  padding: 6px 14px;
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: #fff;
  background: linear-gradient(135deg, #238636, #2ea043);
  border: 1px solid #3fb950;
  border-radius: var(--radius-md);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all var(--transition-fast);
  box-shadow: 0 2px 8px rgba(46, 160, 67, 0.25);
}

.btn-inline-save-direct:hover:not(:disabled) {
  background: linear-gradient(135deg, #2ea043, #3fb950);
}

.btn-inline-cancel-direct {
  padding: 6px 12px;
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-inline-cancel-direct:hover:not(:disabled) {
  background-color: rgba(255, 255, 255, 0.05);
  color: var(--color-text-main);
}

.inline-avatar-staged-notice {
  font-size: var(--font-size-xs);
  color: #58a6ff;
  background-color: rgba(56, 139, 253, 0.1);
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  border-left: 3px solid #58a6ff;
}

.btn-inline-delete {
  align-self: flex-start;
  padding: 4px 10px;
  font-size: var(--font-size-xs);
  color: #f85149;
  background: transparent;
  border: 1px solid rgba(218, 54, 51, 0.4);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-inline-delete:hover:not(:disabled) {
  background-color: rgba(218, 54, 51, 0.15);
}

.inline-avatar-hint {
  font-size: 11px;
  color: var(--color-text-muted);
}

/* Password Inputs */
.password-input-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}

.password-input-wrapper .form-input {
  padding-right: 42px;
}

.btn-toggle-password {
  position: absolute;
  right: 10px;
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Readonly fields */
.readonly-badge-container {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: 8px 12px;
  background-color: var(--color-bg-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
}

.readonly-note {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* Form Actions */
.form-actions {
  margin-top: var(--spacing-xl);
  padding-top: var(--spacing-lg);
  border-top: 1px solid var(--color-border);
}

.btn-primary-submit {
  padding: 10px 24px;
  font-size: var(--font-size-base);
  font-weight: 600;
  color: #fff;
  background-color: #238636;
  border: 1px solid rgba(46, 160, 67, 0.4);
  border-radius: var(--radius-lg);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all var(--transition-fast);
}

.btn-primary-submit:hover:not(:disabled) {
  background-color: #2ea043;
  box-shadow: 0 4px 12px rgba(46, 160, 67, 0.35);
  transform: translateY(-1px);
}

/* Creator Status Cards */
.creator-status-card {
  display: flex;
  gap: var(--spacing-lg);
  padding: var(--spacing-xl);
  border-radius: var(--radius-xl);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-surface-raised);
}

.creator-status-card .card-icon {
  font-size: 40px;
  flex-shrink: 0;
}

.creator-status-card .card-content {
  flex: 1;
}

.creator-status-card .card-title {
  font-size: var(--font-size-lg);
  font-weight: 700;
  color: #fff;
  margin: 0 0 8px 0;
}

.creator-status-card .card-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  line-height: 1.6;
  margin: 0 0 var(--spacing-md) 0;
}

.approved-card {
  border-color: rgba(46, 160, 67, 0.4);
  background: linear-gradient(135deg, rgba(46, 160, 67, 0.08) 0%, rgba(22, 27, 34, 0.8) 100%);
}

.pending-card {
  border-color: rgba(210, 153, 34, 0.4);
  background: linear-gradient(135deg, rgba(210, 153, 34, 0.08) 0%, rgba(22, 27, 34, 0.8) 100%);
}

.rejected-card {
  border-color: rgba(218, 54, 51, 0.4);
  background: linear-gradient(135deg, rgba(218, 54, 51, 0.08) 0%, rgba(22, 27, 34, 0.8) 100%);
}

.reader-cta-card {
  border-color: rgba(56, 139, 253, 0.4);
  background: linear-gradient(135deg, rgba(56, 139, 253, 0.08) 0%, rgba(22, 27, 34, 0.8) 100%);
}

.badge-status-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  margin-bottom: 8px;
}

.status-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 4px;
}

.status-badge.pending {
  background-color: rgba(210, 153, 34, 0.2);
  color: #d29922;
  border: 1px solid rgba(210, 153, 34, 0.4);
}

.status-badge.rejected {
  background-color: rgba(218, 54, 51, 0.2);
  color: #f85149;
  border: 1px solid rgba(218, 54, 51, 0.4);
}

.app-code {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.rejection-reason-box {
  padding: 10px 14px;
  background-color: rgba(218, 54, 51, 0.1);
  border-left: 3px solid #f85149;
  border-radius: var(--radius-sm);
  margin-bottom: var(--spacing-md);
}

.reason-label {
  display: block;
  font-size: var(--font-size-xs);
  font-weight: 600;
  color: #f85149;
  margin-bottom: 4px;
}

.reason-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-main);
  margin: 0;
}

.creator-profile-snippet {
  display: flex;
  gap: var(--spacing-lg);
  font-size: var(--font-size-sm);
  color: var(--color-text-main);
  margin-bottom: var(--spacing-lg);
  padding: 8px 12px;
  background-color: rgba(46, 160, 67, 0.1);
  border-radius: var(--radius-md);
}

.creator-benefits-list {
  margin: 0 0 var(--spacing-lg) var(--spacing-lg);
  padding: 0;
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  line-height: 1.8;
}

.card-actions {
  display: flex;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.btn-card-primary {
  padding: 8px 18px;
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: #fff;
  background-color: var(--color-primary);
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-card-primary:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

.btn-card-secondary {
  padding: 8px 18px;
  font-size: var(--font-size-sm);
  font-weight: 500;
  color: var(--color-text-main);
  background-color: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-card-secondary:hover {
  border-color: var(--color-text-muted);
}

.btn-card-cta {
  padding: 10px 22px;
  font-size: var(--font-size-base);
  font-weight: 600;
  color: #fff;
  background: linear-gradient(135deg, #1f6feb 0%, #238636 100%);
  border: 1px solid rgba(56, 139, 253, 0.4);
  border-radius: var(--radius-lg);
  text-decoration: none;
  transition: all var(--transition-fast);
  box-shadow: 0 4px 14px rgba(31, 111, 235, 0.35);
}

.btn-card-cta:hover {
  box-shadow: 0 6px 18px rgba(31, 111, 235, 0.45);
  transform: translateY(-1px);
}

.creator-loading-card {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-md);
  padding: var(--spacing-xl);
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
}

/* Spinner */
.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  display: inline-block;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 768px) {
  .hero-profile-body {
    flex-direction: column;
    align-items: center;
    text-align: center;
    margin-top: -48px;
  }
  .hero-title-row {
    justify-content: center;
  }
  .hero-meta-row {
    justify-content: center;
  }
  .avatar-controls-inline {
    flex-direction: column;
    align-items: flex-start;
  }
}

/* Following Management Styles (CAI-USER-02) */
.tab-counter-badge.following-count {
  background-color: rgba(56, 189, 248, 0.15);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.35);
  font-weight: 700;
}

.following-manager {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

.following-header-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.following-total-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  background: rgba(56, 189, 248, 0.1);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.25);
  border-radius: 9999px;
  font-size: var(--font-size-xs);
  font-weight: 600;
  white-space: nowrap;
}

.following-search-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}

.search-input-icon {
  position: absolute;
  left: 14px;
  color: var(--color-text-muted);
  font-size: 14px;
  pointer-events: none;
}

.following-search-field {
  width: 100%;
  padding: 10px 38px 10px 38px;
  background-color: var(--color-bg-base, #0d1117);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  color: var(--color-text-base);
  font-size: var(--font-size-sm);
  transition: all var(--transition-fast);
}

.following-search-field:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
}

.btn-clear-input {
  position: absolute;
  right: 12px;
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: 4px;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.btn-clear-input:hover {
  color: var(--color-text-base);
}

.following-loading-block {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 48px 16px;
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
}

.following-empty-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 48px 24px;
  background-color: rgba(255, 255, 255, 0.02);
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-xl);
  gap: 10px;
}

.empty-icon-wrap {
  font-size: 36px;
  margin-bottom: 4px;
}

.empty-heading {
  font-size: var(--font-size-base);
  font-weight: 700;
  color: var(--color-text-base);
}

.empty-subtext {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  max-width: 420px;
}

.btn-community-link,
.btn-clear-filter {
  margin-top: 10px;
  padding: 8px 18px;
  background-color: #0284c7;
  color: #fff;
  border: none;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: background-color var(--transition-fast);
}

.btn-community-link:hover,
.btn-clear-filter:hover {
  background-color: #0369a1;
}

.following-users-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
}

.following-item-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  background-color: var(--color-bg-base, #0d1117);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  gap: 12px;
  transition: border-color var(--transition-fast), transform var(--transition-fast);
}

.following-item-card:hover {
  border-color: rgba(56, 189, 248, 0.3);
  transform: translateY(-1px);
}

.following-item-info {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  text-decoration: none;
  color: inherit;
  flex: 1;
}

.following-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid var(--color-border);
}

.following-avatar-fallback {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0284c7, #38bdf8);
  color: #fff;
  font-weight: 700;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.following-details {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 2px;
}

.following-name {
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--color-text-base);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.following-post-count {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.btn-following-toggle {
  padding: 6px 12px;
  border-radius: 9999px;
  font-size: var(--font-size-xs);
  font-weight: 600;
  border: 1px solid var(--color-border);
  background-color: rgba(255, 255, 255, 0.05);
  color: var(--color-text-base);
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: all var(--transition-fast);
}

.btn-following-toggle:hover:not(:disabled) {
  background-color: rgba(239, 68, 68, 0.15);
  border-color: #ef4444;
  color: #ef4444;
}

.btn-following-toggle:hover:not(:disabled) .btn-label-state::after {
  content: ' (Hủy)';
}
</style>
