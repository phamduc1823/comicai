<script setup lang="ts">
import { ref, computed, toRef, watch } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import { useWalletStore } from '@/stores/wallet'
import { useNotificationStore } from '@/stores/notification'
import { useChatEcho } from '@/composables/useChatEcho'
import { useNotificationRealtime } from '@/composables/useNotificationRealtime'
import { getErrorMessage } from '@/services/httpClient'
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

// Fetch initial unread count, notification count & wallet when user becomes authenticated
watch(
  () => authStore.isAuthenticated,
  (isAuth) => {
    if (isAuth) {
      chatStore.fetchUnreadCount()
      notificationStore.fetchUnreadCount()
      walletStore.fetchWallet(true)
    } else {
      walletStore.reset()
      notificationStore.$reset()
    }
  },
  { immediate: true }
)

const isLoggingOut = ref(false)
const logoutError = ref<string | null>(null)
const avatarLoadError = ref(false)

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

async function handleLogout() {
  if (isLoggingOut.value) return
  logoutError.value = null
  isLoggingOut.value = true

  try {
    await authStore.logout()
    walletStore.reset()
    notificationStore.$reset()
    await router.push('/')
  } catch (err: unknown) {
    // On failure, preserve authenticated state and display error
    logoutError.value = getErrorMessage(err, 'Đăng xuất không thành công. Vui lòng thử lại.')
  } finally {
    isLoggingOut.value = false
  }
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

          <div class="user-profile-badge">
            <div class="user-avatar" aria-hidden="true">
              <img
                v-if="authStore.user?.avatar_path && !avatarLoadError"
                :src="authStore.user.avatar_path"
                :alt="authStore.user.name"
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
          </div>

          <RouterLink
            v-if="authStore.isCreator || authStore.isAdmin"
            to="/creator/comics"
            class="btn-studio-shortcut"
            title="Khu vực sáng tác và quản lý truyện"
          >
            <span>Studio ✍️</span>
          </RouterLink>

          <RouterLink
            v-if="authStore.isAdmin"
            to="/admin/moderation/comics"
            class="btn-admin-shortcut"
            title="Khu vực kiểm duyệt quản trị viên"
          >
            <span>Admin 🛡️</span>
          </RouterLink>

          <button
            type="button"
            class="btn-logout"
            :disabled="isLoggingOut"
            :aria-busy="isLoggingOut"
            aria-label="Đăng xuất khỏi hệ thống"
            @click="handleLogout"
          >
            <span v-if="isLoggingOut" class="btn-spinner" aria-hidden="true"></span>
            <span>{{ isLoggingOut ? 'Đang thoát...' : 'Đăng xuất' }}</span>
          </button>
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
.btn-auth {
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

.user-profile-badge {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: 4px var(--spacing-sm) 4px 4px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
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
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 34px;
  padding: 0 var(--spacing-sm);
  background-color: transparent;
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  transition: all var(--transition-fast);
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

  .btn-auth {
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

  .btn-auth {
    font-size: 15px;
    height: 32px;
    padding: 0 10px;
  }
}

@media (max-width: 640px) {
  .user-details {
    display: none;
  }

  .user-profile-badge {
    padding: 0;
    border: none;
    background: transparent;
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
