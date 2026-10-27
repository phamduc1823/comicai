<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useAuthStore } from '@/stores/auth'
import { getErrorMessage } from '@/services/httpClient'

const router = useRouter()
const appStore = useAppStore()
const authStore = useAuthStore()

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
    <div class="container header-container">
      <div class="brand">
        <RouterLink to="/" class="brand-link" aria-label="BlueToon - Trang chủ">
          <span class="brand-logo" aria-hidden="true">📖</span>
          <span class="brand-name">{{ appStore.appInfo.name }}</span>
        </RouterLink>
        <span class="brand-badge">Scaffold v{{ appStore.appInfo.version }}</span>
      </div>

      <nav class="nav-links" aria-label="Điều hướng chính">
        <RouterLink to="/" class="nav-link" active-class="active">Trang chủ</RouterLink>
      </nav>

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
          <RouterLink to="/login" class="nav-link login-link">
            Đăng nhập
          </RouterLink>
          <RouterLink to="/register" class="btn-register">
            Đăng ký
          </RouterLink>
        </div>

        <!-- Authenticated User -->
        <div v-else class="auth-user-menu">
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
  height: 64px;
  gap: var(--spacing-md);
}

.brand {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-shrink: 0;
}

.brand-link {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  text-decoration: none;
}

.brand-logo {
  font-size: var(--font-size-2xl);
  line-height: 1;
}

.brand-name {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
  letter-spacing: -0.02em;
}

.brand-badge {
  font-size: var(--font-size-xs);
  padding: 2px 8px;
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border-radius: var(--radius-full);
  border: 1px solid var(--color-border-subtle);
  font-weight: var(--font-weight-medium);
}

.nav-links {
  display: flex;
  gap: var(--spacing-sm);
}

.nav-link {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
  padding: var(--spacing-xs) var(--spacing-sm);
  border-radius: var(--radius-sm);
  transition: all var(--transition-fast);
  text-decoration: none;
}

.nav-link:hover,
.nav-link.active {
  color: var(--color-primary);
  background-color: var(--color-primary-soft);
}

/* Auth Section */
.header-auth {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
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

.login-link {
  color: var(--color-text-primary);
}

.btn-register {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 36px;
  padding: 0 var(--spacing-md);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  text-decoration: none;
  box-shadow: var(--shadow-sm);
  transition: all var(--transition-fast);
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

@media (max-width: 640px) {
  .brand-badge {
    display: none;
  }
  .user-details {
    display: none;
  }
  .user-profile-badge {
    padding: 0;
    border: none;
    background: transparent;
  }
}
</style>
