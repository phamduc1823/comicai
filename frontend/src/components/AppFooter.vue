<script setup lang="ts">
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
const currentYear = new Date().getFullYear()
</script>

<template>
  <footer class="app-footer">
    <div class="container footer-container">
      <div class="footer-info">
        <p class="footer-title">{{ appStore.appInfo.name }} — {{ appStore.appInfo.tagline }}</p>
        <p class="footer-subtitle">Đồ án tốt nghiệp đại học • Monorepo Frontend Scaffold</p>
      </div>

      <div class="footer-meta">
        <span class="meta-tag">Vue 3 + TypeScript</span>
        <span class="meta-tag">Vite</span>
        <span class="meta-tag">Vanilla CSS Tokens</span>
        <span class="copyright">© {{ currentYear }} BlueToon</span>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.app-footer {
  background-color: var(--color-bg-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--spacing-lg) 0;
  margin-top: auto;
}

.footer-container {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
  align-items: center;
  text-align: center;
}

@media (min-width: 768px) {
  .footer-container {
    flex-direction: row;
    justify-content: space-between;
    text-align: left;
  }
}

.footer-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.footer-subtitle {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-top: 2px;
}

.footer-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-xs);
  align-items: center;
  justify-content: center;
}

.meta-tag {
  font-size: var(--font-size-xs);
  padding: 2px 6px;
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border-radius: var(--radius-xs);
  border: 1px solid var(--color-border);
}

.copyright {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-left: var(--spacing-xs);
}
</style>
