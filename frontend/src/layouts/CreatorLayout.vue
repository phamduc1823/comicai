<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'

const route = useRoute()

const isMobileSidebarOpen = ref(false)

const currentSectionTitle = computed(() => {
  if (route.path === '/creator/comics/create') return 'Đăng truyện mới'
  if (route.path.includes('/edit')) return 'Chỉnh sửa truyện'
  if (route.path.includes('/images')) return 'Quản lý ảnh chương'
  if (route.path.includes('/chapters')) return 'Quản lý chương'
  return 'Truyện của tôi'
})

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
          <div class="brand-names">
            <span class="brand-title">CREATOR STUDIO</span>
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
            <span class="breadcrumb-root">CREATOR STUDIO</span>
            <span class="breadcrumb-separator" aria-hidden="true">/</span>
            <span class="breadcrumb-current">{{ currentSectionTitle }}</span>
          </div>
        </div>
      </header>

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
  color: #fff;
  letter-spacing: -0.02em;
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
  font-size: 13px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: var(--spacing-sm) var(--spacing-sm) 6px;
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
