<script setup lang="ts">
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
</script>

<template>
  <header class="app-header">
    <div class="container header-container">
      <div class="brand">
        <span class="brand-logo">📖</span>
        <span class="brand-name">{{ appStore.appInfo.name }}</span>
        <span class="brand-badge">Scaffold v{{ appStore.appInfo.version }}</span>
      </div>

      <nav class="nav-links">
        <RouterLink to="/" class="nav-link active">Khởi động</RouterLink>
      </nav>
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
}

.brand {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
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
  gap: var(--spacing-md);
}

.nav-link {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
  padding: var(--spacing-xs) var(--spacing-sm);
  border-radius: var(--radius-sm);
  transition: all var(--transition-fast);
}

.nav-link:hover,
.nav-link.active {
  color: var(--color-primary);
  background-color: var(--color-primary-soft);
}
</style>
