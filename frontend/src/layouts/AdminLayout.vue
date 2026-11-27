<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getErrorMessage } from '@/services/httpClient'
import { useAdminSidebarScroll } from '@/composables/useAdminSidebarScroll'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const { getSavedScrollTop, setSavedScrollTop } = useAdminSidebarScroll()
const navRef = ref<HTMLElement | null>(null)
let isRestoringScroll = false

const isMobileSidebarOpen = ref(false)
const isLoggingOut = ref(false)
const logoutError = ref<string | null>(null)
const avatarLoadError = ref(false)

const userInitials = computed(() => {
  const name = authStore.user?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'A'
})

const currentSectionTitle = computed(() => {
  if (route.path.startsWith('/admin/dashboard')) return 'Bảng điều khiển Quản trị'
  if (route.path.startsWith('/admin/users')) return 'Quản lý Người dùng'
  if (route.path.startsWith('/admin/comics')) return 'Kho Truyện Tranh'
  if (route.path.startsWith('/admin/chapters')) return 'Kho Chương Webtoon'
  if (route.path.startsWith('/admin/genres-tags')) return 'Quản lý Thể loại & Thẻ truyện'
  if (route.path.startsWith('/admin/wallets')) return 'Sổ Cái & Ví Coin'
  if (route.path.startsWith('/admin/vip-purchases')) return 'Mua VIP & Hoàn Tiền'
  if (route.path.startsWith('/admin/broadcast-notifications')) return 'Thông Báo Hệ Thống'
  if (route.path.startsWith('/admin/creator-applications')) return 'Quản lý Hồ sơ Creator'
  if (route.path.startsWith('/admin/creators')) return 'Quản lý Creator'
  if (route.path.includes('/moderation/chapters')) return 'Kiểm duyệt chương Webtoon'
  if (route.path.includes('/banners')) return 'Quản lý Banner Slider Trang Chủ'
  if (route.path.includes('/reports')) return 'Quản lý Báo cáo vi phạm'
  return 'Kiểm duyệt truyện tranh'
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

function handleNavScroll(event: Event) {
  if (isRestoringScroll) return
  const target = event.target as HTMLElement
  if (target) {
    setSavedScrollTop(target.scrollTop)
  }
}

function saveNavScrollPosition() {
  if (navRef.value) {
    setSavedScrollTop(navRef.value.scrollTop)
  }
}

function closeMobileSidebar() {
  saveNavScrollPosition()
  isMobileSidebarOpen.value = false
}

onMounted(() => {
  const savedTop = getSavedScrollTop()
  if (savedTop > 0 && navRef.value) {
    isRestoringScroll = true
    navRef.value.scrollTop = savedTop

    // Re-assert on next animation frame in case fonts or dynamic layout shift
    requestAnimationFrame(() => {
      if (navRef.value) {
        navRef.value.scrollTop = savedTop
      }
      isRestoringScroll = false
    })
  }
})

onBeforeUnmount(() => {
  saveNavScrollPosition()
})
</script>

<template>
  <div class="admin-layout">
    <!-- Sidebar Backdrop for Mobile -->
    <div
      v-if="isMobileSidebarOpen"
      class="sidebar-backdrop"
      aria-hidden="true"
      @click="closeMobileSidebar"
    ></div>

    <!-- Admin Moderation Sidebar -->
    <aside
      class="admin-sidebar"
      :class="{ 'sidebar-open': isMobileSidebarOpen }"
      aria-label="Điều hướng Quản trị"
    >
      <!-- Sidebar Brand -->
      <div class="sidebar-brand">
        <RouterLink to="/admin/dashboard" class="brand-link" @click="closeMobileSidebar">
          <div class="brand-names">
            <span class="brand-title">ADMIN PAGE</span>
          </div>
        </RouterLink>
      </div>

      <!-- Sidebar Navigation Menu -->
      <nav
        ref="navRef"
        class="sidebar-nav"
        @scroll.passive="handleNavScroll"
      >
        <div class="nav-section-label">Tổng quan</div>

        <RouterLink
          to="/admin/dashboard"
          class="sidebar-nav-item"
          :class="{ active: route.path === '/admin/dashboard' || route.path === '/admin' }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📊</span>
          <span class="nav-label">Bảng điều khiển (Dashboard)</span>
        </RouterLink>

        <div class="nav-section-label nav-section-divider">Hàng đợi kiểm duyệt</div>

        <RouterLink
          to="/admin/moderation/comics"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/moderation/comics') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📚</span>
          <span class="nav-label">Duyệt truyện tranh</span>
        </RouterLink>

        <RouterLink
          to="/admin/moderation/chapters"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/moderation/chapters') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📑</span>
          <span class="nav-label">Duyệt chương Webtoon</span>
        </RouterLink>

        <RouterLink
          to="/admin/reports"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/reports') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">🚩</span>
          <span class="nav-label">Báo cáo vi phạm</span>
        </RouterLink>

        <div class="nav-section-label nav-section-divider">Quản lý nội dung</div>

        <RouterLink
          to="/admin/comics"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/comics') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📖</span>
          <span class="nav-label">Quản lý truyện</span>
        </RouterLink>

        <RouterLink
          to="/admin/genres-tags"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/genres-tags') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">🏷️</span>
          <span class="nav-label">Thể loại & Thẻ truyện</span>
        </RouterLink>

        <RouterLink
          to="/admin/banners"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/banners') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">🖼️</span>
          <span class="nav-label">Quản lý Banner Slider</span>
        </RouterLink>

        <div class="nav-section-label nav-section-divider">Quản lý Creator</div>

        <RouterLink
          to="/admin/creator-applications"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/creator-applications') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📝</span>
          <span class="nav-label">Hồ sơ Creator</span>
        </RouterLink>

        <RouterLink
          to="/admin/creators"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/creators') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">🎨</span>
          <span class="nav-label">Danh sách Creator</span>
        </RouterLink>

        <div class="nav-section-label nav-section-divider">Người dùng & Tài chính</div>

        <RouterLink
          to="/admin/users"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/users') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">👥</span>
          <span class="nav-label">Quản lý Người dùng</span>
        </RouterLink>

        <RouterLink
          to="/admin/wallets"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/wallets') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">👛</span>
          <span class="nav-label">Sổ cái & Ví Coin</span>
        </RouterLink>

        <RouterLink
          to="/admin/vip-purchases"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/vip-purchases') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">💎</span>
          <span class="nav-label">Mua VIP & Hoàn tiền</span>
        </RouterLink>

        <div class="nav-section-label nav-section-divider">Hệ thống</div>

        <RouterLink
          to="/admin/broadcast-notifications"
          class="sidebar-nav-item"
          :class="{ active: route.path.startsWith('/admin/broadcast-notifications') }"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">📢</span>
          <span class="nav-label">Thông báo hệ thống</span>
        </RouterLink>

        <RouterLink
          to="/creator/comics"
          class="sidebar-nav-item secondary"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">✍️</span>
          <span class="nav-label">Creator Studio</span>
        </RouterLink>

        <RouterLink
          to="/"
          class="sidebar-nav-item secondary"
          @click="closeMobileSidebar"
        >
          <span class="nav-icon" aria-hidden="true">🏠</span>
          <span class="nav-label">Trang chủ độc giả</span>
        </RouterLink>
      </nav>

      <!-- Sidebar Footer / Admin Profile -->
      <div class="sidebar-footer">
        <div class="user-profile">
          <div class="avatar-wrap">
            <img
              v-if="authStore.user?.avatar_path && !avatarLoadError"
              :src="authStore.user.avatar_path"
              :alt="authStore.user.name"
              class="user-avatar"
              @error="avatarLoadError = true"
            />
            <span v-else class="avatar-fallback" aria-hidden="true">
              {{ userInitials }}
            </span>
          </div>

          <div class="user-meta">
            <span class="user-name" :title="authStore.user?.name">
              {{ authStore.user?.name || 'Quản trị viên' }}
            </span>
            <span class="role-badge">
              Quản trị viên
            </span>
          </div>
        </div>

        <div v-if="logoutError" class="logout-error" role="alert">
          {{ logoutError }}
        </div>

        <button
          type="button"
          class="btn-logout"
          :disabled="isLoggingOut"
          :aria-busy="isLoggingOut"
          @click="handleLogout"
        >
          <span v-if="isLoggingOut" class="btn-spinner" aria-hidden="true"></span>
          <span v-else aria-hidden="true">🚪</span>
          <span>{{ isLoggingOut ? 'Đang thoát...' : 'Đăng xuất' }}</span>
        </button>
      </div>
    </aside>

    <!-- Main Content Area -->
    <div class="admin-main">
      <!-- Top Bar -->
      <header class="admin-topbar">
        <div class="topbar-left">
          <button
            type="button"
            class="mobile-menu-btn"
            aria-label="Mở menu quản trị"
            @click="isMobileSidebarOpen = true"
          >
            ☰
          </button>
          <div class="topbar-breadcrumb">
            <span class="breadcrumb-root">ADMIN PAGE</span>
            <span class="breadcrumb-separator" aria-hidden="true">/</span>
            <span class="breadcrumb-current">{{ currentSectionTitle }}</span>
          </div>
        </div>

        <div class="topbar-right">
          <RouterLink to="/" class="topbar-link" title="Xem giao diện độc giả">
            <span>Độc giả</span> ↗
          </RouterLink>
          <RouterLink to="/creator/comics" class="topbar-link" title="Khu vực sáng tác">
            <span>Studio</span> ↗
          </RouterLink>
        </div>
      </header>

      <!-- Page Content View -->
      <main class="admin-content" role="main">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.admin-layout {
  display: flex;
  min-height: 100vh;
  background-color: var(--color-bg-base);
  color: var(--color-text-primary);
}

/* Sidebar */
.admin-sidebar {
  width: 260px;
  background-color: var(--color-bg-surface);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  height: 100vh;
  z-index: 100;
  overflow: hidden;
  transition: transform var(--transition-normal);
}

.sidebar-brand {
  padding: var(--spacing-lg) var(--spacing-lg);
  border-bottom: 1px solid var(--color-border);
}

.brand-link {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  text-decoration: none;
  color: inherit;
}

.brand-logo {
  font-size: 24px;
  line-height: 1;
}

.brand-names {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.brand-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: #fff;
  letter-spacing: -0.02em;
}

.brand-badge {
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  background-color: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  border: 1px solid rgba(245, 158, 11, 0.3);
  letter-spacing: 0.5px;
}

.sidebar-nav {
  padding: var(--spacing-md) var(--spacing-sm);
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
  overflow-y: auto;
  /* Hide scrollbar for modern browsers (Firefox, Chrome 121+, Edge) */
  scrollbar-width: none;
  /* Hide scrollbar for IE 10+ and Edge Legacy */
  -ms-overflow-style: none;
}

/* Hide scrollbar for WebKit browsers (Chrome, Safari, Edge, Opera) */
.sidebar-nav::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}

.nav-section-label {
  font-size: 13px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: var(--spacing-sm) var(--spacing-sm) 6px;
}

.nav-section-divider {
  margin-top: var(--spacing-md);
  padding-top: var(--spacing-md);
  border-top: 1px solid var(--color-border);
}

.sidebar-nav-item {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) var(--spacing-md);
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
  background-color: rgba(245, 158, 11, 0.12);
  color: #fbbf24;
  border-left: 3px solid #f59e0b;
}

.sidebar-nav-item.secondary {
  color: var(--color-text-muted);
}

.sidebar-nav-item.secondary:hover {
  color: var(--color-text-secondary);
}

.nav-icon {
  font-size: 16px;
  line-height: 1;
}

.sidebar-footer {
  padding: var(--spacing-md);
  border-top: 1px solid var(--color-border);
  background-color: rgba(0, 0, 0, 0.15);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.user-profile {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.avatar-wrap {
  width: 36px;
  height: 36px;
  border-radius: var(--radius-full);
  overflow: hidden;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.user-avatar {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-fallback {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: #fbbf24;
}

.user-meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.user-name {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.role-badge {
  font-size: 11px;
  color: #fbbf24;
  font-weight: var(--font-weight-medium);
}

.logout-error {
  font-size: 11px;
  color: var(--color-danger);
  padding: 4px;
}

.btn-logout {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  width: 100%;
  padding: var(--spacing-xs) var(--spacing-sm);
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: var(--font-size-xs);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-logout:hover:not(:disabled) {
  background-color: rgba(239, 68, 68, 0.1);
  color: #fca5a5;
  border-color: rgba(239, 68, 68, 0.3);
}

.btn-logout:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* Main Area */
.admin-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

/* Topbar */
.admin-topbar {
  height: 60px;
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 var(--spacing-xl);
  position: sticky;
  top: 0;
  z-index: 90;
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
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

.mobile-menu-btn {
  display: none;
  background: transparent;
  border: none;
  color: var(--color-text-primary);
  font-size: 22px;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
}

.topbar-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin: 0;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
}

.topbar-link {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  text-decoration: none;
  padding: 4px 8px;
  border-radius: var(--radius-xs);
  transition: all var(--transition-fast);
}

.topbar-link:hover {
  color: var(--color-primary);
  background-color: var(--color-bg-elevated);
}

/* Content */
.admin-content {
  flex: 1;
  padding: var(--spacing-xl);
  overflow-y: auto;
}

.btn-spinner {
  width: 12px;
  height: 12px;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  display: inline-block;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Responsive */
@media (max-width: 900px) {
  .admin-sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    transform: translateX(-100%);
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.5);
  }

  .admin-sidebar.sidebar-open {
    transform: translateX(0);
  }

  .mobile-menu-btn {
    display: block;
  }

  .sidebar-backdrop {
    position: fixed;
    inset: 0;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(2px);
    z-index: 95;
  }

  .admin-topbar {
    padding: 0 var(--spacing-md);
  }

  .admin-content {
    padding: var(--spacing-md);
  }
}
</style>
