<script setup lang="ts">
import BannerSlider from '@/components/BannerSlider.vue'
import HomeSearchSection from '@/components/HomeSearchSection.vue'
</script>

<template>
  <div class="home-page">
    <!-- Hero Banner Slider -->
    <BannerSlider />

    <!-- Main Comic Catalog Search & Filter Section (Exact UI from screenshot) -->
    <HomeSearchSection />

    <!-- Platform Highlights Section -->
    <section class="platform-features" aria-label="Điểm nổi bật của nền tảng">
      <div class="container">
        <div class="features-grid">
          <div class="feature-card">
            <span class="feature-icon" aria-hidden="true">⚡</span>
            <h3 class="feature-title">Đọc mượt mà, không quảng cáo rác</h3>
            <p class="feature-desc">Trải nghiệm đọc truyện tối ưu, tốc độ tải ảnh tức thì và giao diện thân thiện với mọi thiết bị.</p>
          </div>
          <div class="feature-card">
            <span class="feature-icon" aria-hidden="true">🤖</span>
            <h3 class="feature-title">Tích hợp AI tóm tắt nội dung</h3>
            <p class="feature-desc">Hệ thống trợ lý AI hỗ trợ tóm tắt cốt truyện và gợi ý các tác phẩm phù hợp với sở thích của bạn.</p>
          </div>
          <div class="feature-card">
            <span class="feature-icon" aria-hidden="true">🔒</span>
            <h3 class="feature-title">Bảo mật &amp; Bản quyền</h3>
            <p class="feature-desc">Tác phẩm được bảo hộ bản quyền tác giả, hỗ trợ trực tiếp các nhà sáng tạo truyện tranh trực tuyến.</p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.home-page {
  padding-bottom: var(--spacing-3xl);
  display: flex;
  flex-direction: column;
}

/* Platform Features Section */
.platform-features {
  padding: var(--spacing-3xl) 0;
  background-color: var(--color-bg-surface);
  border-top: 1px solid var(--color-border);
  border-bottom: 1px solid var(--color-border);
  margin-top: var(--spacing-2xl);
}

.features-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--spacing-lg);
}

@media (min-width: 768px) {
  .features-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.feature-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: var(--spacing-xl);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  transition: all var(--transition-fast);
}

.feature-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
  border-color: var(--color-primary-soft);
}

.feature-icon {
  font-size: 36px;
  margin-bottom: var(--spacing-sm);
}

.feature-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin-bottom: var(--spacing-xs);
}

.feature-desc {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  line-height: var(--line-height-relaxed);
}
</style>
