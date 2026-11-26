<script setup lang="ts">
import { ref, computed, toRef, watch, onMounted, onUnmounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import { useWalletStore } from '@/stores/wallet'
import { useNotificationStore } from '@/stores/notification'
import { useChatEcho } from '@/composables/useChatEcho'
import { useNotificationRealtime } from '@/composables/useNotificationRealtime'
import { CreatorApplicationService } from '@/services/creatorApplicationService'
import { getErrorMessage } from '@/services/httpClient'
import type { CreatorApplication } from '@/types/creatorApplication'
import NotificationBell from '@/components/NotificationBell.vue'
import logoUrl from '@/assets/logo.png'

const router = useRouter()
const authStore = useAuthStore()
const chatStore = useChatStore()
const walletStore = useWalletStore()
const notificationStore = useNotificationStore()

// Subscribe user channel for real-time unread badge updates across entire app
useChatEcho({
  userRef: toRef(authStore, 'user'),
})

// Subscribe user channel for real-time notifications & fallback sync
useNotificationRealtime(toRef(authStore, 'user'))

// Dropdown State
const isDropdownOpen = ref(false)
const dropdownContainerRef = ref<HTMLElement | null>(null)
let closeTimeout: ReturnType<typeof setTimeout> | null = null

// Creator application tracking
const creatorApp = ref<CreatorApplication | null>(null)

async function fetchCreatorStatus() {
  if (!authStore.isAuthenticated) {
    creatorApp.value = null
    return
  }
  try {
    const res = await CreatorApplicationService.getMyApplication()
    creatorApp.value = res.application
  } catch {
    // Non-critical background query failure
  }
}

// Fetch initial unread count, notification count, wallet & creator status when user becomes authenticated
watch(
  () => authStore.isAuthenticated,
  (isAuth) => {
    if (isAuth) {
      chatStore.fetchUnreadCount()
      notificationStore.fetchUnreadCount()
      walletStore.fetchWallet(true)
      fetchCreatorStatus()
    } else {
      walletStore.reset()
      notificationStore.$reset()
      creatorApp.value = null
      closeDropdown()
    }
  },
  { immediate: true }
)

// Also re-check creator status when role or notification unread count changes
watch(
  () => authStore.userRole,
  () => {
    fetchCreatorStatus()
  }
)

const isLoggingOut = ref(false)
const logoutError = ref<string | null>(null)
const avatarLoadError = ref(false)

const userAvatarSrc = computed(() => {
  return authStore.user?.avatar_url || authStore.user?.avatar_path || null
})

watch(
  () => userAvatarSrc.value,
  () => {
    avatarLoadError.value = false
  }
)

const userInitials = computed(() => {
  const name = authStore.user?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'U'
})

const roleLabel = computed(() => {
  switch (authStore.userRole) {
    case 'admin':
      return 'Quản trị viên'
    case 'creator':
      return 'Tác giả'
    case 'reader':
    default:
      return 'Độc giả'
  }
})

// Dropdown mouse and keyboard interactions
function handleMouseEnter() {
  if (closeTimeout) {
    clearTimeout(closeTimeout)
    closeTimeout = null
  }
  isDropdownOpen.value = true
}

function handleMouseLeave() {
  if (closeTimeout) {
    clearTimeout(closeTimeout)
  }
  closeTimeout = setTimeout(() => {
    isDropdownOpen.value = false
    closeTimeout = null
  }, 220)
}

function toggleDropdown() {
  if (closeTimeout) {
    clearTimeout(closeTimeout)
    closeTimeout = null
  }
  isDropdownOpen.value = !isDropdownOpen.value
}

function closeDropdown() {
  if (closeTimeout) {
    clearTimeout(closeTimeout)
    closeTimeout = null
  }
  isDropdownOpen.value = false
}

function handleClickOutside(e: MouseEvent) {
  if (dropdownContainerRef.value && !dropdownContainerRef.value.contains(e.target as Node)) {
    closeDropdown()
  }
}

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isDropdownOpen.value) {
    closeDropdown()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
  if (closeTimeout) {
    clearTimeout(closeTimeout)
  }
})

async function handleLogout() {
  if (isLoggingOut.value) return
  logoutError.value = null
  isLoggingOut.value = true

  try {
    await authStore.logout()
    walletStore.reset()
    notificationStore.$reset()
    creatorApp.value = null
    closeDropdown()
    await router.push('/')
  } catch (err: unknown) {
    // On failure, preserve authenticated state and display error
    logoutError.value = getErrorMessage(err, 'Đăng xuất không thành công. Vui lòng thử lại.')
  } finally {
    isLoggingOut.value = false
  }
}

async function handleDropdownLogout() {
  closeDropdown()
  await handleLogout()
}
</script>

<template>
  <header class="app-header">
    <div class="header-container">
      <div class="header-main-nav">
        <div class="brand">
          <RouterLink to="/" class="brand-link" aria-label="BlueToon - Trang chủ">
            <img :src="logoUrl" alt="BlueToon" class="brand-logo-img" />
          </RouterLink>
        </div>

        <nav class="nav-links" aria-label="Điều hướng chính">
          <RouterLink to="/" class="nav-link" active-class="active">Trang chủ</RouterLink>
          <RouterLink to="/community" class="nav-link" active-class="active">Cộng đồng</RouterLink>
          <RouterLink to="/library" class="nav-link" active-class="active">Tủ sách</RouterLink>
          <RouterLink
            v-if="authStore.isAuthenticated"
            to="/chat"
            class="nav-link nav-link-chat"
            active-class="active"
          >
            <span>Tin nhắn</span>
            <span
              v-if="chatStore.totalUnreadCount > 0"
              class="header-unread-badge"
              :aria-label="chatStore.totalUnreadCount + ' tin nhắn chưa đọc'"
            >
              {{ chatStore.totalUnreadCount > 99 ? '99+' : chatStore.totalUnreadCount }}
            </span>
          </RouterLink>
        </nav>
      </div>

      <!-- Auth section -->
      <div class="header-auth">
        <!-- Error notification if logout failed -->
        <div v-if="logoutError" class="logout-error-tooltip" role="alert">
          <span>{{ logoutError }}</span>
          <button
            type="button"
            class="tooltip-close"
            aria-label="Đóng thông báo"
            @click="logoutError = null"
          >
            ✕
          </button>
        </div>

        <!-- Stable placeholder while session status is initializing -->
        <div
          v-if="!authStore.isInitialized"
          class="auth-skeleton-placeholder"
          aria-hidden="true"
        ></div>

        <!-- Unauthenticated Guest -->
        <div v-else-if="!authStore.isAuthenticated" class="auth-guest-actions">
          <RouterLink to="/login" class="btn-auth login-link">
            Đăng nhập
          </RouterLink>
          <RouterLink to="/register" class="btn-auth btn-register">
            Đăng ký
          </RouterLink>
        </div>

        <!-- Authenticated User -->
        <div v-else class="auth-user-menu">
          <!-- Notification Bell -->
          <NotificationBell />

          <!-- Coin Wallet Badge Shortcut -->
          <RouterLink
            to="/wallet"
            class="btn-wallet-badge"
            title="Ví Coin của bạn — Nhấn để xem lịch sử và nhận Coin miễn phí"
          >
            <span class="coin-icon" aria-hidden="true">🪙</span>
            <span class="coin-balance">{{ walletStore.formattedBalance }}</span>
            <span class="coin-label">Coin</span>
            <span
              v-if="walletStore.canClaimFaucet"
              class="faucet-indicator-dot"
              title="Có lượt nhận Coin miễn phí hôm nay!"
              aria-label="Có lượt nhận Coin miễn phí hôm nay"
            ></span>
          </RouterLink>

          <!-- User Profile Dropdown -->
          <div
            ref="dropdownContainerRef"
            class="user-dropdown-container"
            @mouseenter="handleMouseEnter"
            @mouseleave="handleMouseLeave"
          >
            <!-- Trigger button -->
            <button
              type="button"
              class="user-dropdown-trigger"
              :class="{ 'is-open': isDropdownOpen }"
              aria-haspopup="true"
              :aria-expanded="isDropdownOpen"
              aria-label="Menu tài khoản người dùng"
              @click="toggleDropdown"
            >
              <div class="user-avatar" aria-hidden="true">
                <img
                  v-if="userAvatarSrc && !avatarLoadError"
                  :src="userAvatarSrc"
                  :alt="authStore.user?.name"
                  class="user-avatar-img"
                  @error="avatarLoadError = true"
                />
                <span v-else class="user-avatar-initial">{{ userInitials }}</span>
              </div>
              <div class="user-details">
                <span class="user-name" :title="authStore.user?.name">
                  {{ authStore.user?.name }}
                </span>
                <span class="role-badge" :class="'role-' + authStore.userRole">
                  {{ roleLabel }}
                </span>
              </div>
              <span class="dropdown-chevron" :class="{ 'is-open': isDropdownOpen }" aria-hidden="true">
                ▼
              </span>
            </button>

            <!-- Dropdown Menu Panel -->
            <Transition name="dropdown-pop">
              <div
                v-show="isDropdownOpen"
                class="user-dropdown-menu"
                role="menu"
                aria-orientation="vertical"
              >
                <!-- Header of dropdown -->
                <div class="dropdown-header">
                  <div class="dropdown-header-banner" aria-hidden="true"></div>
                  <div class="dropdown-header-content">
                    <div class="dropdown-avatar-circle">
                      <img
                        v-if="userAvatarSrc && !avatarLoadError"
                        :src="userAvatarSrc"
                        :alt="authStore.user?.name"
                        class="dropdown-avatar-img"
                        @error="avatarLoadError = true"
                      />
                      <span v-else class="dropdown-avatar-initial">{{ userInitials }}</span>
                    </div>
                    <div class="dropdown-user-info">
                      <div class="dropdown-user-name" :title="authStore.user?.name">
                        {{ authStore.user?.name }}
                      </div>
                      <div class="dropdown-role-pill" :class="'role-' + authStore.userRole">
                        {{ roleLabel }}
                      </div>
                      <div class="dropdown-user-email" :title="authStore.user?.email">
                        {{ authStore.user?.email }}
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Menu Items -->
                <div class="dropdown-items-list">
                  <!-- 1. Hồ sơ cá nhân -->
                  <RouterLink
                    to="/profile"
                    class="dropdown-menu-item"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">👤</span>
                    <div class="item-info">
                      <span class="item-title">Hồ sơ cá nhân</span>
                      <span class="item-desc">Xem và chỉnh sửa tài khoản</span>
                    </div>
                  </RouterLink>

                  <!-- 2. Cập nhật thông tin -->
                  <RouterLink
                    to="/profile#settings"
                    class="dropdown-menu-item"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">⚙️</span>
                    <div class="item-info">
                      <span class="item-title">Cập nhật thông tin</span>
                      <span class="item-desc">Thay đổi tên, email, avatar</span>
                    </div>
                  </RouterLink>

                  <!-- 3. Đổi mật khẩu -->
                  <RouterLink
                    to="/profile#password"
                    class="dropdown-menu-item"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">🔒</span>
                    <div class="item-info">
                      <span class="item-title">Đổi mật khẩu</span>
                      <span class="item-desc">Bảo mật tài khoản</span>
                    </div>
                  </RouterLink>

                  <div class="dropdown-separator" role="separator"></div>

                  <!-- 4. Creator Integration -->
                  <!-- 4A. Reader without active application -->
                  <RouterLink
                    v-if="authStore.isReader && (!creatorApp || creatorApp.status === 'canceled')"
                    to="/become-creator"
                    class="dropdown-menu-item dropdown-creator-cta"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">✍️</span>
                    <div class="item-info">
                      <div class="item-title-row">
                        <span class="item-title highlight">Đăng ký trở thành Creator</span>
                        <span class="cta-mini-pill">Mới</span>
                      </div>
                      <span class="item-desc">Đăng truyện & nhận thù lao</span>
                    </div>
                  </RouterLink>

                  <!-- 4B. Reader with pending application -->
                  <RouterLink
                    v-else-if="authStore.isReader && creatorApp?.status === 'pending'"
                    to="/become-creator"
                    class="dropdown-menu-item dropdown-creator-pending"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">⏳</span>
                    <div class="item-info">
                      <div class="item-title-row">
                        <span class="item-title">Hồ sơ Creator: Chờ duyệt</span>
                        <span class="status-mini-pill pending">Pending</span>
                      </div>
                      <span class="item-desc">Mã: {{ creatorApp.application_code }}</span>
                    </div>
                  </RouterLink>

                  <!-- 4C. Reader with rejected application -->
                  <RouterLink
                    v-else-if="authStore.isReader && creatorApp?.status === 'rejected'"
                    to="/become-creator"
                    class="dropdown-menu-item dropdown-creator-rejected"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">⚠️</span>
                    <div class="item-info">
                      <div class="item-title-row">
                        <span class="item-title">Hồ sơ Creator: Từ chối</span>
                        <span class="status-mini-pill rejected">Xem lý do</span>
                      </div>
                      <span class="item-desc">Xem lý do & gửi lại hồ sơ</span>
                    </div>
                  </RouterLink>

                  <!-- 4D. User is Creator -->
                  <RouterLink
                    v-if="authStore.isCreator || authStore.isAdmin"
                    to="/creator/comics"
                    class="dropdown-menu-item"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">🎨</span>
                    <div class="item-info">
                      <span class="item-title">Kênh sáng tác (Studio)</span>
                      <span class="item-desc">Quản lý truyện & chương VIP</span>
                    </div>
                  </RouterLink>

                  <!-- 4E. User is Admin -->
                  <RouterLink
                    v-if="authStore.isAdmin"
                    to="/admin/moderation/comics"
                    class="dropdown-menu-item"
                    role="menuitem"
                    @click="closeDropdown"
                  >
                    <span class="item-icon" aria-hidden="true">🛡️</span>
                    <div class="item-info">
                      <span class="item-title">Trang quản trị (Admin)</span>
                      <span class="item-desc">Kiểm duyệt và báo cáo</span>
                    </div>
                  </RouterLink>

                  <div class="dropdown-separator" role="separator"></div>

                  <!-- 5. Logout -->
                  <button
                    type="button"
                    class="dropdown-menu-item dropdown-logout-btn"
                    role="menuitem"
                    :disabled="isLoggingOut"
                    @click="handleDropdownLogout"
                  >
                    <span class="item-icon" aria-hidden="true">🚪</span>
                    <div class="item-info">
                      <span class="item-title">{{ isLoggingOut ? 'Đang đăng xuất...' : 'Đăng xuất' }}</span>
                    </div>
                    <span v-if="isLoggingOut" class="btn-spinner" aria-hidden="true"></span>
                  </button>
                </div>
              </div>
            </Transition>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  z-index: 50;
  backdrop-filter: blur(12px);
}

.header-container {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 68px;
  width: 100%;
  max-width: 1440px;
  margin-left: auto;
  margin-right: auto;
  padding-left: var(--spacing-lg);
  padding-right: var(--spacing-lg);
  gap: var(--spacing-lg);
}

.header-main-nav {
  display: flex;
  align-items: center;
  gap: 36px;
  flex-shrink: 0;
}

.brand {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.brand-link {
  display: flex;
  align-items: center;
  text-decoration: none;
  transition: opacity var(--transition-fast), transform var(--transition-fast);
}

.brand-link:hover {
  opacity: 0.9;
  transform: translateY(-1px);
}

.brand-logo-img {
  height: 38px;
  width: auto;
  max-width: 140px;
  object-fit: contain;
  display: block;
}

.nav-links {
  display: flex;
  align-items: center;
  gap: 12px;
}

.nav-link {
  font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  font-size: 20px;
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
  padding: 6px 14px;
  border-radius: var(--radius-md);
  background-color: transparent;
  transition: color var(--transition-fast);
  text-decoration: none;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  position: relative;
}

/* Hover Underline Animation */
.nav-link::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: 0;
  width: 100%;
  height: 3px;
  background-color: var(--color-primary);
  border-radius: var(--radius-full);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 300ms cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events: none;
}

.nav-link:hover::after {
  transform: scaleX(1);
}

.nav-link:hover {
  color: var(--color-primary);
  background-color: transparent;
}

.nav-link.active {
  color: var(--color-primary);
  background-color: transparent;
}

.nav-link.active::after {
  transform: scaleX(1);
}

/* Reduced motion preference support */
@media (prefers-reduced-motion: reduce) {
  .nav-link::after {
    transition: none;
  }
}

.nav-link-chat {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.header-unread-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: var(--radius-full);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: 11px;
  font-weight: var(--font-weight-bold);
  line-height: 1;
}

/* Auth Section */
.header-auth {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  flex-shrink: 0;
}

.auth-skeleton-placeholder {
  width: 150px;
  height: 36px;
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
  opacity: 0.5;
}

.auth-guest-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

/* Shared Typography, Alignment and Sizing for Auth Buttons */
.btn-auth,
.btn-logout {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  font-size: 20px;
  font-weight: var(--font-weight-medium);
  line-height: 1.2;
  text-decoration: none;
  white-space: nowrap;
  border-radius: var(--radius-md);
  height: 38px;
  padding: 0 16px;
  transition: all var(--transition-fast);
  box-sizing: border-box;
}

.login-link {
  color: var(--color-text-secondary);
  background-color: transparent;
  position: relative;
}

/* Underline Animation on Login Button */
.login-link::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: 0;
  width: 100%;
  height: 3px;
  background-color: var(--color-primary);
  border-radius: var(--radius-full);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 300ms cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events: none;
}

.login-link:hover::after {
  transform: scaleX(1);
}

.login-link:hover {
  color: var(--color-primary);
  background-color: transparent;
}

.btn-register {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  box-shadow: var(--shadow-sm);
}

.btn-register:hover {
  background-color: var(--color-primary-hover);
  box-shadow: var(--shadow-glow);
  transform: translateY(-1px);
}

/* Authenticated User Menu */
.auth-user-menu {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
}

/* User Dropdown Container */
.user-dropdown-container {
  position: relative;
  display: inline-block;
}

.user-dropdown-trigger {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: 4px 10px 4px 4px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  cursor: pointer;
  transition: all var(--transition-fast);
  color: var(--color-text-main);
}

.user-dropdown-trigger:hover,
.user-dropdown-trigger.is-open {
  background-color: var(--color-bg-surface-raised);
  border-color: var(--color-primary);
  box-shadow: 0 0 12px rgba(56, 139, 253, 0.2);
}

.dropdown-chevron {
  font-size: 9px;
  color: var(--color-text-muted);
  transition: transform var(--transition-fast);
  margin-left: 2px;
}

.dropdown-chevron.is-open {
  transform: rotate(180deg);
  color: var(--color-primary);
}

/* Dropdown Menu Panel */
.user-dropdown-menu {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  width: 290px;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45), 0 0 1px rgba(255, 255, 255, 0.1);
  overflow: hidden;
  z-index: 100;
  backdrop-filter: blur(16px);
}

/* Dropdown Header */
.dropdown-header {
  position: relative;
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-bg-surface-raised);
}

.dropdown-header-banner {
  height: 52px;
  background: linear-gradient(135deg, #093959 0%, #175485 50%, #2076b3 100%);
  opacity: 0.9;
  position: relative;
  z-index: 1;
}

.dropdown-header-content {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px 14px;
  margin-top: -24px;
  position: relative;
  z-index: 10;
}

.dropdown-avatar-circle {
  width: 48px;
  height: 48px;
  border-radius: var(--radius-full);
  border: 3px solid var(--color-bg-surface);
  background-color: var(--color-bg-surface-raised);
  overflow: hidden;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
  position: relative;
  z-index: 20;
}

.dropdown-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.dropdown-avatar-initial {
  font-size: 20px;
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

.dropdown-user-info {
  flex: 1;
  min-width: 0;
  padding-top: 24px;
}

.dropdown-user-name {
  font-size: var(--font-size-sm);
  font-weight: 700;
  color: #fff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dropdown-role-pill {
  display: inline-block;
  font-size: 10px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  margin-top: 2px;
}

.dropdown-role-pill.role-admin {
  background-color: rgba(218, 54, 51, 0.2);
  color: #f85149;
  border: 1px solid rgba(218, 54, 51, 0.35);
}

.dropdown-role-pill.role-creator {
  background-color: rgba(46, 160, 67, 0.2);
  color: #3fb950;
  border: 1px solid rgba(46, 160, 67, 0.35);
}

.dropdown-role-pill.role-reader {
  background-color: rgba(56, 139, 253, 0.2);
  color: #58a6ff;
  border: 1px solid rgba(56, 139, 253, 0.35);
}

.dropdown-user-email {
  font-size: 11px;
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-top: 2px;
}

/* Dropdown Items List */
.dropdown-items-list {
  padding: 6px;
}

.dropdown-menu-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: var(--radius-lg);
  color: var(--color-text-main);
  text-decoration: none;
  background: transparent;
  border: none;
  width: 100%;
  text-align: left;
  cursor: pointer;
  transition: all var(--transition-fast);
  box-sizing: border-box;
}

.dropdown-menu-item:hover {
  background-color: var(--color-bg-surface-raised);
  color: var(--color-primary);
}

.item-icon {
  font-size: 16px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
}

.item-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.item-title {
  font-size: var(--font-size-sm);
  font-weight: 500;
  color: var(--color-text-main);
}

.dropdown-menu-item:hover .item-title {
  color: var(--color-primary);
}

.item-desc {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.item-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.item-title.highlight {
  color: #58a6ff;
  font-weight: 600;
}

/* Creator CTA Special Highlight */
.dropdown-creator-cta {
  background: linear-gradient(135deg, rgba(31, 111, 235, 0.12) 0%, rgba(46, 160, 67, 0.12) 100%);
  border: 1px solid rgba(56, 139, 253, 0.3);
  margin: 4px 0;
}

.dropdown-creator-cta:hover {
  background: linear-gradient(135deg, rgba(31, 111, 235, 0.22) 0%, rgba(46, 160, 67, 0.22) 100%);
  border-color: var(--color-primary);
}

.cta-mini-pill {
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: var(--radius-full);
  background-color: #238636;
  color: #fff;
  text-transform: uppercase;
}

.status-mini-pill {
  font-size: 10px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: var(--radius-full);
}

.status-mini-pill.pending {
  background-color: rgba(210, 153, 34, 0.2);
  color: #d29922;
  border: 1px solid rgba(210, 153, 34, 0.4);
}

.status-mini-pill.rejected {
  background-color: rgba(218, 54, 51, 0.2);
  color: #f85149;
  border: 1px solid rgba(218, 54, 51, 0.4);
}

.dropdown-separator {
  height: 1px;
  background-color: var(--color-border);
  margin: 6px 4px;
}

.dropdown-logout-btn {
  color: #f85149;
}

.dropdown-logout-btn .item-title {
  color: #f85149;
  font-weight: 500;
}

.dropdown-logout-btn:hover {
  background-color: rgba(218, 54, 51, 0.12);
  color: #f85149;
}

.dropdown-logout-btn:hover .item-title {
  color: #ff7b72;
}

/* Transitions */
.dropdown-pop-enter-active,
.dropdown-pop-leave-active {
  transition: opacity 160ms cubic-bezier(0.16, 1, 0.3, 1), transform 160ms cubic-bezier(0.16, 1, 0.3, 1);
}

.dropdown-pop-enter-from,
.dropdown-pop-leave-to {
  opacity: 0;
  transform: translateY(-8px) scale(0.96);
}

.user-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.user-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.user-avatar-initial {
  color: var(--color-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
}

.user-details {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  max-width: 140px;
}

.user-name {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.role-badge {
  font-size: 10px;
  font-weight: var(--font-weight-medium);
  letter-spacing: 0.02em;
}

.role-badge.role-admin {
  color: var(--color-warning);
}

.role-badge.role-creator {
  color: var(--color-info);
}

.role-badge.role-reader {
  color: var(--color-primary);
}

.btn-creator-register-shortcut {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 34px;
  padding: 0 var(--spacing-sm);
  background-color: rgba(34, 197, 94, 0.12);
  color: #4ade80;
  border: 1px solid rgba(74, 222, 128, 0.35);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-creator-register-shortcut:hover {
  background-color: #22c55e;
  color: #ffffff;
  box-shadow: 0 0 10px rgba(34, 197, 94, 0.3);
}

.btn-studio-shortcut {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 34px;
  padding: 0 var(--spacing-sm);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.3);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-studio-shortcut:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  box-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
}

.btn-admin-shortcut {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 34px;
  padding: 0 var(--spacing-sm);
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.35);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-admin-shortcut:hover {
  background-color: var(--color-warning);
  color: var(--color-bg-base);
  box-shadow: 0 0 10px rgba(245, 158, 11, 0.35);
}

.btn-logout {
  gap: 6px;
  background-color: transparent;
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  cursor: pointer;
}

.btn-logout:focus-visible {
  outline: 2px solid var(--color-danger);
  outline-offset: 2px;
}

.btn-wallet-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 12px;
  background-color: rgba(234, 179, 8, 0.08);
  border: 1px solid rgba(234, 179, 8, 0.35);
  border-radius: var(--radius-full);
  color: #eab308;
  font-size: var(--font-size-xs);
  font-weight: 600;
  text-decoration: none;
  position: relative;
  transition: all var(--transition-fast);
}

.btn-wallet-badge:hover {
  background-color: rgba(234, 179, 8, 0.16);
  border-color: #eab308;
  transform: translateY(-1px);
}

.coin-icon {
  font-size: 14px;
}

.coin-balance {
  font-weight: 700;
  color: #eab308;
}

.coin-label {
  font-size: 11px;
  color: var(--color-text-muted);
}

.faucet-indicator-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: #22c55e;
  box-shadow: 0 0 6px #22c55e;
}

.btn-logout:hover:not(:disabled) {
  color: var(--color-danger);
  border-color: var(--color-danger);
  background-color: var(--color-danger-soft);
}

.btn-logout:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-spinner {
  width: 12px;
  height: 12px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.logout-error-tooltip {
  position: absolute;
  top: 100%;
  right: 0;
  margin-top: var(--spacing-xs);
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-sm);
  color: var(--color-danger);
  font-size: var(--font-size-xs);
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  white-space: nowrap;
  box-shadow: var(--shadow-md);
  z-index: 60;
}

.tooltip-close {
  color: var(--color-danger);
  font-size: 10px;
  padding: 0 2px;
}

@media (max-width: 1100px) {
  .header-container {
    padding-left: var(--spacing-md);
    padding-right: var(--spacing-md);
    gap: var(--spacing-md);
  }

  .header-main-nav {
    gap: 20px;
  }

  .nav-link {
    padding: 6px 10px;
    font-size: 17px;
  }

  .btn-auth,
  .btn-logout {
    font-size: 17px;
    height: 36px;
    padding: 0 12px;
  }
}

@media (max-width: 840px) {
  .header-container {
    height: 62px;
    padding-left: var(--spacing-sm);
    padding-right: var(--spacing-sm);
    gap: var(--spacing-sm);
  }

  .header-main-nav {
    gap: 12px;
    min-width: 0;
    flex: 1;
  }

  .brand-logo-img {
    height: 32px;
    max-width: 105px;
  }

  .nav-links {
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding-bottom: 2px;
    gap: 6px;
  }

  .nav-links::-webkit-scrollbar {
    display: none;
  }

  .nav-link {
    padding: 4px 8px;
    font-size: 15px;
  }

  .btn-auth,
  .btn-logout {
    font-size: 15px;
    height: 32px;
    padding: 0 10px;
  }
}

@media (max-width: 640px) {
  .user-details {
    display: none;
  }

  .user-dropdown-trigger {
    padding: 2px;
  }

  .btn-studio-shortcut,
  .btn-admin-shortcut {
    padding: 0 8px;
  }

  .btn-logout {
    padding: 0 8px;
  }
}
</style>
