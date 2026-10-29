<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getErrorMessage } from '@/services/httpClient'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const isMobileSidebarOpen = ref(false)
const isLoggingOut = ref(false)
const logoutError = ref<string | null>(null)
const avatarLoadError = ref(false)

const userInitials = computed(() => {
  const name = authStore.user?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'C'
})

const roleBadgeLabel = computed(() => {
  return authStore.isAdmin ? 'Quản trị viên' : 'Tác giả'
})

const currentSectionTitle = computed(() => {
  if (route.path === '/creator/comics/create') return 'Đăng truyện mới'
  if (route.path.includes('/edit')) return 'Chỉnh sửa truyện'
  if (route.path.includes('/images')) return 'Quản lý ảnh chương'
  if (route.path.includes('/chapters')) return 'Quản lý chương'
  return 'Truyện của tôi'
})

async function handleLogout() {
  if (isLoggingOut.value) return
  logoutError.value = null
  isLoggingOut.value = true
  try {
    await authStore.logout()
    await router.push('/')
  } catch (err: unknown) {
    logoutError.value = getErrorMessage(err, 'Đăng xuất không thành công.')
  } finally {
    isLoggingOut.value = false
  }
}

function closeMobileSidebar() {
  isMobileSidebarOpen.value = false
}
</script>

<template>
  <div class="creator-layout">
    <!-- Sidebar Backdrop for Mobile -->
    <div
      v-if="isMobileSidebarOpen"
      class="sidebar-backdrop"
      aria-hidden="true"
      @click="closeMobileSidebar"
    ></div>

    <!-- Creator Studio Sidebar -->
    <aside
      class="studio-sidebar"
      :class="{ 'sidebar-open': isMobileSidebarOpen }"
      aria-label="Điều hướng Studio"
    >
      <!-- Sidebar Brand -->
      <div class="sidebar-brand">
        <RouterLink to="/creator/comics" class="brand-link" @click="closeMobileSidebar">
          <span class="brand-logo" aria-hidden="true">📖</span>
          <div class="brand-names">
            <span class="brand-title">BlueToon</span>
            <span class="brand-studio-badge">STUDIO</span>
          </div>
        </RouterLink>
      </div>

      <!-- Sidebar Navigation Menu -->
      <nav class="sidebar-nav">
        <div class="nav-section-label">Quản lý tác phẩm</div>
        <RouterLink
          to="/creator/comics"
          class="sidebar-nav-item"
          :class="{ active: route.path === '/creator/comics' }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📚</span>
          <span class="nav-label">Truyện của tôi</span>
        </RouterLink>

        <RouterLink
          to="/creator/comics/create"
          class="sidebar-nav-item"
          :class="{ active: route.path === '/creator/comics/create' }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">➕</span>
          <span class="nav-label">Đăng truyện mới</span>
        </RouterLink>
      </nav>

      <!-- Sidebar Bottom Action -->
      <div class="sidebar-footer">
        <RouterLink to="/" class="sidebar-footer-link" @click="closeMobileSidebar">
          <span class="footer-icon" aria-hidden="true">←</span>
          <span>Về trang đọc truyện</span>
        </RouterLink>
      </div>
    </aside>

    <!-- Studio Main Content Area -->
    <div class="studio-main-wrapper">
      <!-- Studio Topbar -->
      <header class="studio-topbar">
        <div class="topbar-left">
          <button
            type="button"
            class="mobile-menu-btn"
            aria-label="Mở menu quản trị"
            @click="isMobileSidebarOpen = !isMobileSidebarOpen"
          >
            ☰
          </button>
          <div class="topbar-breadcrumb">
            <span class="breadcrumb-root">Creator Studio</span>
            <span class="breadcrumb-separator" aria-hidden="true">/</span>
            <span class="breadcrumb-current">{{ currentSectionTitle }}</span>
          </div>
        </div>

        <div class="topbar-right">
          <!-- User Profile Display -->
          <div class="topbar-user">
            <div class="user-avatar" aria-hidden="true">
              <img
                v-if="authStore.user?.avatar_path && !avatarLoadError"
                :src="authStore.user.avatar_path"
                :alt="authStore.user.name"
                class="avatar-img"
                @error="avatarLoadError = true"
              />
              <span v-else class="avatar-initial">{{ userInitials }}</span>
            </div>
            <div class="user-meta">
              <span class="user-name">{{ authStore.user?.name }}</span>
              <span class="user-role-badge">{{ roleBadgeLabel }}</span>
            </div>
          </div>

          <!-- Quick Logout Button -->
          <button
            type="button"
            class="btn-topbar-logout"
            :disabled="isLoggingOut"
            :aria-busy="isLoggingOut"
            title="Đăng xuất"
            @click="handleLogout"
          >
            <span>{{ isLoggingOut ? '...' : 'Đăng xuất' }}</span>
          </button>
        </div>
      </header>

      <!-- Logout Error Banner -->
      <div v-if="logoutError" class="logout-error-banner" role="alert">
        <span class="banner-icon" aria-hidden="true">⚠️</span>
        <span class="banner-text">{{ logoutError }}</span>
        <button
          type="button"
          class="banner-close-btn"
          aria-label="Đóng thông báo"
          @click="logoutError = null"
        >
          ✕
        </button>
      </div>

      <!-- Page Content -->
      <main class="studio-content">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.creator-layout {
  min-height: 100vh;
  display: flex;
  background-color: var(--color-bg);
  color: var(--color-text-primary);
}

/* Sidebar Backdrop (Mobile) */
.sidebar-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 90;
  background-color: rgba(5, 9, 18, 0.75);
  backdrop-filter: blur(4px);
}

/* Studio Sidebar */
.studio-sidebar {
  width: 250px;
  background-color: var(--color-bg-surface);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  height: 100vh;
  z-index: 100;
  transition: transform var(--transition-normal);
}

.sidebar-brand {
  padding: var(--spacing-lg) var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
}

.brand-link {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  text-decoration: none;
}

.brand-logo {
  font-size: 24px;
}

.brand-names {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.brand-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  letter-spacing: -0.02em;
}

.brand-studio-badge {
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  letter-spacing: 0.05em;
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.sidebar-nav {
  flex: 1;
  padding: var(--spacing-md) var(--spacing-xs);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-2xs);
  overflow-y: auto;
}

.nav-section-label {
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: var(--spacing-xs) var(--spacing-sm);
}

.sidebar-nav-item {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: 10px var(--spacing-sm);
  border-radius: var(--radius-md);
  color: var(--color-text-secondary);
  text-decoration: none;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  transition: all var(--transition-fast);
}

.sidebar-nav-item:hover {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
}

.sidebar-nav-item.active {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  font-weight: var(--font-weight-semibold);
  border-left: 3px solid var(--color-primary);
}

.nav-icon {
  font-size: 16px;
}

.sidebar-footer {
  padding: var(--spacing-md);
  border-top: 1px solid var(--color-border);
}

.sidebar-footer-link {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-muted);
  text-decoration: none;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  transition: color var(--transition-fast);
}

.sidebar-footer-link:hover {
  color: var(--color-primary);
}

/* Studio Main Area */
.studio-main-wrapper {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.studio-topbar {
  height: 60px;
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--spacing-lg);
  position: sticky;
  top: 0;
  z-index: 40;
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
}

.mobile-menu-btn {
  display: none;
  background: none;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text-primary);
  font-size: 18px;
  padding: 4px 8px;
  cursor: pointer;
}

.topbar-breadcrumb {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: var(--font-size-sm);
}

.breadcrumb-root {
  color: var(--color-text-muted);
}

.breadcrumb-separator {
  color: var(--color-border);
}

.breadcrumb-current {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
}

.topbar-user {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.user-avatar {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: var(--font-weight-bold);
  overflow: hidden;
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.user-meta {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.user-name {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.user-role-badge {
  font-size: 10px;
  color: var(--color-primary);
}

.btn-topbar-logout {
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  background-color: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  font-size: var(--font-size-xs);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-topbar-logout:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
  border-color: var(--color-border-subtle);
}

/* Logout Error Banner */
.logout-error-banner {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  background-color: rgba(239, 68, 68, 0.12);
  border-bottom: 1px solid rgba(239, 68, 68, 0.35);
  color: #f87171;
  padding: var(--spacing-sm) var(--spacing-lg);
  font-size: var(--font-size-sm);
}

.logout-error-banner .banner-icon {
  font-size: 14px;
}

.logout-error-banner .banner-text {
  flex: 1;
}

.logout-error-banner .banner-close-btn {
  background: transparent;
  border: none;
  color: #f87171;
  cursor: pointer;
  padding: 2px 6px;
  font-size: var(--font-size-sm);
  border-radius: var(--radius-sm);
  transition: background-color var(--transition-fast);
}

.logout-error-banner .banner-close-btn:hover {
  background-color: rgba(239, 68, 68, 0.2);
}

/* Content Area */
.studio-content {
  flex: 1;
  padding: var(--spacing-lg);
  max-width: 1300px;
  width: 100%;
  margin: 0 auto;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .sidebar-backdrop {
    display: block;
  }

  .studio-sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    transform: translateX(-100%);
  }

  .studio-sidebar.sidebar-open {
    transform: translateX(0);
  }

  .mobile-menu-btn {
    display: block;
  }

  .topbar-breadcrumb {
    font-size: var(--font-size-xs);
  }

  .studio-content {
    padding: var(--spacing-sm);
  }
}
</style>
