<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage } from '@/services/httpClient'
import type { Banner } from '@/types/catalog'

const banners = ref<Banner[]>([])
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)
const currentIndex = ref(0)
const isPaused = ref(false)
const failedImages = ref<Record<number, boolean>>({})

let autoplayTimer: ReturnType<typeof setInterval> | null = null

async function fetchBanners() {
  isLoading.value = true
  errorMessage.value = null

  try {
    const response = await CatalogService.getBanners()
    banners.value = response.data || []
    currentIndex.value = 0
  } catch (error: unknown) {
    errorMessage.value = getErrorMessage(error, 'Không thể tải danh sách banner nổi bật.')
  } finally {
    isLoading.value = false
  }
}

function nextSlide() {
  if (banners.value.length <= 1) return
  currentIndex.value = (currentIndex.value + 1) % banners.value.length
}

function prevSlide() {
  if (banners.value.length <= 1) return
  currentIndex.value = (currentIndex.value - 1 + banners.value.length) % banners.value.length
}

function goToSlide(index: number) {
  if (index >= 0 && index < banners.value.length) {
    currentIndex.value = index
  }
}

function handleImageError(bannerId: number) {
  failedImages.value[bannerId] = true
}

function startAutoplay() {
  stopAutoplay()
  if (banners.value.length > 1) {
    autoplayTimer = setInterval(() => {
      if (!isPaused.value) {
        nextSlide()
      }
    }, 5000)
  }
}

function stopAutoplay() {
  if (autoplayTimer) {
    clearInterval(autoplayTimer)
    autoplayTimer = null
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'ArrowLeft') {
    prevSlide()
  } else if (event.key === 'ArrowRight') {
    nextSlide()
  }
}

onMounted(async () => {
  await fetchBanners()
  startAutoplay()
})

onUnmounted(() => {
  stopAutoplay()
})
</script>

<template>
  <div
    class="banner-slider"
    tabindex="0"
    role="region"
    aria-label="Truyện tranh nổi bật"
    @keydown="handleKeyDown"
    @mouseenter="isPaused = true"
    @mouseleave="isPaused = false"
    @focus="isPaused = true"
    @blur="isPaused = false"
  >
    <!-- Loading Skeleton -->
    <div v-if="isLoading" class="slider-skeleton" aria-busy="true" aria-label="Đang tải banner">
      <div class="skeleton-content">
        <div class="skeleton-pill"></div>
        <div class="skeleton-title"></div>
        <div class="skeleton-desc"></div>
        <div class="skeleton-btn"></div>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="errorMessage" class="slider-error" role="alert">
      <div class="error-content">
        <span class="error-icon" aria-hidden="true">⚠️</span>
        <p class="error-text">{{ errorMessage }}</p>
        <button
          type="button"
          class="btn-retry"
          aria-label="Thử tải lại danh sách banner"
          @click="fetchBanners"
        >
          Thử lại
        </button>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="banners.length === 0" class="slider-empty">
      <div class="empty-content">
        <span class="empty-icon" aria-hidden="true">✨</span>
        <h2 class="empty-title">Khám phá thế giới truyện tranh BlueToon</h2>
        <p class="empty-desc">
          Hàng ngàn bộ truyện đặc sắc đang chờ bạn khám phá. Cập nhật liên tục mỗi ngày với hình ảnh sắc nét.
        </p>
      </div>
    </div>

    <!-- Carousel View -->
    <div v-else class="slider-wrapper">
      <div
        v-for="(banner, index) in banners"
        :key="banner.id"
        class="slide-item"
        :class="{ active: index === currentIndex }"
        :aria-hidden="index !== currentIndex"
      >
        <!-- Background Banner Picture / Fallback -->
        <div class="slide-media">
          <picture v-if="!failedImages[banner.id] && (banner.desktop_banner_url || banner.mobile_banner_url)">
            <source
              v-if="banner.mobile_banner_url"
              media="(max-width: 640px)"
              :srcset="banner.mobile_banner_url"
            />
            <img
              :src="banner.desktop_banner_url || banner.mobile_banner_url || ''"
              :alt="banner.title_override || banner.comic.title"
              class="slide-img"
              @error="handleImageError(banner.id)"
            />
          </picture>
          <div v-else class="slide-img-fallback">
            <div class="fallback-pattern" aria-hidden="true"></div>
            <span class="fallback-icon" aria-hidden="true">📖</span>
          </div>
          <div class="slide-overlay" aria-hidden="true"></div>
        </div>

        <!-- Banner Info Content -->
        <div class="container slide-content-container">
          <div class="slide-content">
            <span class="slide-badge">Truyện nổi bật</span>
            <h2 class="slide-title">
              {{ banner.title_override || banner.comic.title }}
            </h2>
            <p v-if="banner.comic.title && banner.title_override" class="slide-subtitle">
              {{ banner.comic.title }}
            </p>
            <div class="slide-actions">
              <!-- Destination route /comics/:slug dependency on CAI-019 -->
              <RouterLink
                :to="'/comics/' + banner.comic.slug"
                class="btn-cta"
                :aria-label="'Đọc truyện ' + (banner.title_override || banner.comic.title)"
              >
                <span>Đọc ngay</span>
                <span class="btn-arrow" aria-hidden="true">→</span>
              </RouterLink>
            </div>
          </div>
        </div>
      </div>

      <!-- Controls: Navigation Arrows (shown if > 1 banner) -->
      <template v-if="banners.length > 1">
        <button
          type="button"
          class="slider-arrow slider-arrow-prev"
          aria-label="Banner trước"
          @click="prevSlide"
        >
          ‹
        </button>
        <button
          type="button"
          class="slider-arrow slider-arrow-next"
          aria-label="Banner tiếp theo"
          @click="nextSlide"
        >
          ›
        </button>

        <!-- Pagination Indicators -->
        <div class="slider-indicators" role="tablist" aria-label="Danh sách các banner">
          <button
            v-for="(banner, index) in banners"
            :key="'indicator-' + banner.id"
            type="button"
            role="tab"
            :aria-selected="index === currentIndex"
            :aria-label="'Chuyển đến banner ' + (index + 1) + ': ' + (banner.title_override || banner.comic.title)"
            class="indicator-dot"
            :class="{ active: index === currentIndex }"
            @click="goToSlide(index)"
          ></button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.banner-slider {
  position: relative;
  width: 100%;
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  overflow: hidden;
  outline: none;
}

.banner-slider:focus-visible {
  box-shadow: inset 0 0 0 2px var(--color-primary);
}

/* Skeleton State */
.slider-skeleton {
  min-height: 380px;
  display: flex;
  align-items: center;
  background: linear-gradient(90deg, var(--color-bg-surface) 25%, var(--color-bg-card) 50%, var(--color-bg-surface) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
  padding: var(--spacing-2xl) 0;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

.skeleton-content {
  max-width: var(--container-max-width);
  margin: 0 auto;
  padding: 0 var(--spacing-lg);
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.skeleton-pill {
  width: 120px;
  height: 24px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-full);
}

.skeleton-title {
  width: 60%;
  max-width: 480px;
  height: 40px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-md);
}

.skeleton-desc {
  width: 40%;
  max-width: 320px;
  height: 20px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-sm);
}

.skeleton-btn {
  width: 140px;
  height: 42px;
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-md);
  margin-top: var(--spacing-sm);
}

/* Error State */
.slider-error,
.slider-empty {
  min-height: 280px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-2xl) var(--spacing-md);
  text-align: center;
  background: linear-gradient(180deg, var(--color-bg-surface) 0%, var(--color-bg) 100%);
}

.error-content,
.empty-content {
  max-width: 540px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--spacing-xs);
}

.error-icon,
.empty-icon {
  font-size: var(--font-size-3xl);
  margin-bottom: var(--spacing-xs);
}

.error-text {
  color: var(--color-text-secondary);
  font-size: var(--font-size-sm);
}

.btn-retry {
  margin-top: var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-lg);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid var(--color-primary-glow);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  transition: all var(--transition-fast);
}

.btn-retry:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.empty-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  line-height: var(--line-height-relaxed);
}

/* Slider Wrapper */
.slider-wrapper {
  position: relative;
  min-height: 380px;
  height: clamp(380px, 46vw, 480px);
}

.slide-item {
  position: absolute;
  inset: 0;
  opacity: 0;
  visibility: hidden;
  transition: opacity 600ms cubic-bezier(0.4, 0, 0.2, 1), visibility 600ms;
  display: flex;
  align-items: center;
}

.slide-item.active {
  opacity: 1;
  visibility: visible;
  z-index: 1;
}

/* Slide Media */
.slide-media {
  position: absolute;
  inset: 0;
  z-index: 0;
}

.slide-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
}

.slide-img-fallback {
  width: 100%;
  height: 100%;
  background: radial-gradient(circle at 70% 30%, #1e293b 0%, #0b0f19 80%);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.fallback-pattern {
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(56, 189, 248, 0.08) 1px, transparent 1px);
  background-size: 24px 24px;
}

.fallback-icon {
  font-size: 4rem;
  opacity: 0.25;
  filter: grayscale(1);
}

.slide-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    90deg,
    rgba(11, 15, 25, 0.95) 0%,
    rgba(11, 15, 25, 0.75) 45%,
    rgba(11, 15, 25, 0.25) 100%
  );
}

@media (max-width: 768px) {
  .slide-overlay {
    background: linear-gradient(
      180deg,
      rgba(11, 15, 25, 0.3) 0%,
      rgba(11, 15, 25, 0.9) 80%,
      rgba(11, 15, 25, 0.98) 100%
    );
  }
}

/* Slide Content */
.slide-content-container {
  position: relative;
  z-index: 2;
  width: 100%;
}

.slide-content {
  max-width: 580px;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
  padding: var(--spacing-xl) 0;
}

.slide-badge {
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
  padding: 4px var(--spacing-sm);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid var(--color-primary-glow);
  border-radius: var(--radius-full);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  backdrop-filter: blur(8px);
}

.slide-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.6);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

@media (min-width: 768px) {
  .slide-title {
    font-size: var(--font-size-4xl);
  }
}

.slide-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
}

.slide-actions {
  margin-top: var(--spacing-sm);
}

.btn-cta {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  height: 42px;
  padding: 0 var(--spacing-lg);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  text-decoration: none;
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
}

.btn-cta:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
  box-shadow: 0 0 28px rgba(56, 189, 248, 0.4);
}

.btn-arrow {
  transition: transform var(--transition-fast);
}

.btn-cta:hover .btn-arrow {
  transform: translateX(3px);
}

/* Arrows */
.slider-arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 5;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-full);
  background-color: rgba(17, 24, 39, 0.6);
  border: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: var(--font-size-2xl);
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(8px);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.slider-arrow:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border-color: var(--color-primary);
  box-shadow: var(--shadow-glow);
}

.slider-arrow-prev {
  left: var(--spacing-md);
}

.slider-arrow-next {
  right: var(--spacing-md);
}

/* Indicators */
.slider-indicators {
  position: absolute;
  bottom: var(--spacing-md);
  left: 50%;
  transform: translateX(-50%);
  z-index: 5;
  display: flex;
  gap: var(--spacing-xs);
  padding: 6px 12px;
  background-color: rgba(17, 24, 39, 0.65);
  backdrop-filter: blur(8px);
  border-radius: var(--radius-full);
  border: 1px solid var(--color-border);
}

.indicator-dot {
  width: 8px;
  height: 8px;
  border-radius: var(--radius-full);
  background-color: var(--color-text-muted);
  border: none;
  cursor: pointer;
  transition: all var(--transition-fast);
  padding: 0;
}

.indicator-dot:hover {
  background-color: var(--color-text-secondary);
}

.indicator-dot.active {
  width: 22px;
  background-color: var(--color-primary);
  box-shadow: 0 0 8px var(--color-primary);
}

@media (max-width: 640px) {
  .slider-arrow {
    display: none;
  }
}
</style>
