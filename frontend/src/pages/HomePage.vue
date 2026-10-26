<script setup lang="ts">
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
</script>

<template>
  <div class="home-page">
    <section class="hero-section">
      <div class="container hero-container">
        <div class="status-indicator">
          <span class="status-dot"></span>
          <span>Nền tảng Frontend đã khởi tạo thành công (CAI-006)</span>
        </div>

        <h1 class="hero-title">
          Chào mừng đến với <span class="highlight">{{ appStore.appInfo.name }}</span>
        </h1>
        <p class="hero-description">
          {{ appStore.appInfo.tagline }}. Bộ khung giao diện Single Page Application (SPA) đã được thiết lập sẵn sàng kết nối với Backend API Laravel 13.
        </p>

        <div class="hero-meta">
          <div class="meta-pill">
            <span class="meta-label">Môi trường:</span>
            <span class="meta-val">{{ appStore.appInfo.environment }}</span>
          </div>
          <div class="meta-pill">
            <span class="meta-label">API Base URL:</span>
            <span class="meta-val"><code>{{ appStore.appInfo.apiBaseUrl }}</code></span>
          </div>
          <div class="meta-pill">
            <span class="meta-label">Design System:</span>
            <span class="meta-val">Vanilla CSS Tokens (Dark Theme)</span>
          </div>
        </div>
      </div>
    </section>

    <section class="stack-section">
      <div class="container">
        <div class="section-header">
          <h2 class="section-title">Công nghệ Frontend đã thiết lập</h2>
          <p class="section-subtitle">
            Khung công nghệ chuẩn hiện đại cho SPA truyện tranh, đảm bảo type safety, hiệu năng tải trang và trải nghiệm mượt mà.
          </p>
        </div>

        <div class="stack-grid">
          <div v-for="item in appStore.techStack" :key="item.name" class="stack-card">
            <div class="card-header">
              <span class="card-category">{{ item.category }}</span>
              <span class="card-status">{{ item.status }}</span>
            </div>
            <h3 class="card-title">{{ item.name }}</h3>
            <span class="card-version">{{ item.version }}</span>
            <p class="card-notes">{{ item.notes }}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="info-section">
      <div class="container">
        <div class="info-card">
          <div class="info-header">
            <span class="info-icon">⚡</span>
            <div>
              <h3 class="info-title">Trạng thái tích hợp Monorepo</h3>
              <p class="info-desc">Kiến trúc kết nối giữa Frontend SPA và Backend API</p>
            </div>
          </div>
          <div class="info-grid">
            <div class="info-item">
              <span class="info-key">Backend API:</span>
              <span class="info-value">Laravel Framework 13.38.0 (Đã nghiệm thu CAI-005)</span>
            </div>
            <div class="info-item">
              <span class="info-key">Xác thực:</span>
              <span class="info-value">Laravel Sanctum v4.3.3 (SPA Cookie / Stateful Session)</span>
            </div>
            <div class="info-item">
              <span class="info-key">Dev Proxy:</span>
              <span class="info-value">Vite chuyển tiếp <code>/api</code> và <code>/sanctum</code> sang backend</span>
            </div>
            <div class="info-item">
              <span class="info-key">Mã nguồn & Git:</span>
              <span class="info-value">Monorepo cô lập, <code>node_modules</code> và <code>dist</code> được bảo vệ nghiêm ngặt</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.home-page {
  padding-bottom: var(--spacing-3xl);
}

/* Hero Section */
.hero-section {
  padding: var(--spacing-2xl) 0 var(--spacing-xl);
  background: linear-gradient(180deg, var(--color-bg-surface) 0%, var(--color-bg) 100%);
  border-bottom: 1px solid var(--color-border);
}

.hero-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.status-indicator {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-xs) var(--spacing-md);
  background-color: var(--color-primary-soft);
  border: 1px solid var(--color-primary-glow);
  border-radius: var(--radius-full);
  color: var(--color-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  margin-bottom: var(--spacing-lg);
}

.status-dot {
  width: 8px;
  height: 8px;
  background-color: var(--color-success);
  border-radius: var(--radius-full);
  box-shadow: 0 0 8px var(--color-success);
}

.hero-title {
  font-size: var(--font-size-3xl);
  font-weight: var(--font-weight-bold);
  line-height: var(--line-height-tight);
  color: var(--color-text-primary);
  max-width: 800px;
  margin-bottom: var(--spacing-md);
}

@media (min-width: 768px) {
  .hero-title {
    font-size: var(--font-size-4xl);
  }
}

.hero-title .highlight {
  color: var(--color-primary);
  text-shadow: 0 0 24px var(--color-primary-glow);
}

.hero-description {
  font-size: var(--font-size-base);
  color: var(--color-text-secondary);
  max-width: 680px;
  line-height: var(--line-height-relaxed);
  margin-bottom: var(--spacing-lg);
}

@media (min-width: 768px) {
  .hero-description {
    font-size: var(--font-size-lg);
  }
}

.hero-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-sm);
  justify-content: center;
}

.meta-pill {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  background-color: var(--color-bg-card);
  padding: var(--spacing-xs) var(--spacing-sm);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  font-size: var(--font-size-xs);
}

.meta-label {
  color: var(--color-text-muted);
}

.meta-val {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.meta-val code {
  color: var(--color-primary);
}

/* Stack Section */
.stack-section {
  padding: var(--spacing-2xl) 0 var(--spacing-xl);
}

.section-header {
  text-align: center;
  margin-bottom: var(--spacing-xl);
}

.section-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: var(--spacing-xs);
}

.section-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 600px;
  margin: 0 auto;
}

.stack-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--spacing-md);
}

@media (min-width: 640px) {
  .stack-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (min-width: 1024px) {
  .stack-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.stack-card {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-lg);
  transition: all var(--transition-normal);
  display: flex;
  flex-direction: column;
}

.stack-card:hover {
  background-color: var(--color-bg-card-hover);
  border-color: var(--color-border-accent);
  box-shadow: var(--shadow-glow);
  transform: translateY(-2px);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--spacing-xs);
}

.card-category {
  font-size: var(--font-size-xs);
  color: var(--color-primary);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: var(--font-weight-semibold);
}

.card-status {
  font-size: var(--font-size-xs);
  padding: 2px 6px;
  background-color: var(--color-success-soft);
  color: var(--color-success);
  border-radius: var(--radius-xs);
  font-weight: var(--font-weight-medium);
}

.card-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: 2px;
}

.card-version {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-family: var(--font-family-mono);
  margin-bottom: var(--spacing-sm);
}

.card-notes {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  line-height: var(--line-height-normal);
  margin-top: auto;
}

/* Info Section */
.info-section {
  padding-top: var(--spacing-md);
}

.info-card {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-xl);
}

.info-header {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  margin-bottom: var(--spacing-lg);
  padding-bottom: var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
}

.info-icon {
  font-size: var(--font-size-3xl);
  line-height: 1;
}

.info-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.info-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  margin-top: 2px;
}

.info-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--spacing-md);
}

@media (min-width: 768px) {
  .info-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.info-key {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
}

.info-value {
  font-size: var(--font-size-sm);
  color: var(--color-text-primary);
}

.info-value code {
  color: var(--color-primary);
  font-size: var(--font-size-xs);
}
</style>
