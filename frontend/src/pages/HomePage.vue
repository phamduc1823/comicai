<script setup lang="ts">
import { ref, onMounted } from 'vue'
import BannerSlider from '@/components/BannerSlider.vue'
import ComicCard from '@/components/ComicCard.vue'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage } from '@/services/httpClient'
import type { ComicListItem, ComicSortOption, PaginationMeta } from '@/types/catalog'

const comics = ref<ComicListItem[]>([])
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)
const currentSort = ref<ComicSortOption>('latest')
const currentPage = ref(1)
const paginationMeta = ref<PaginationMeta | null>(null)

const sortOptions: { value: ComicSortOption; label: string; icon: string }[] = [
  { value: 'latest', label: 'Mới nhất', icon: '🕒' },
  { value: 'views', label: 'Xem nhiều', icon: '🔥' },
  { value: 'follows', label: 'Theo dõi nhiều', icon: '⭐' },
]

async function fetchComics(page = 1, sort = currentSort.value) {
  isLoading.value = true
  errorMessage.value = null
  currentPage.value = page
  currentSort.value = sort

  try {
    const response = await CatalogService.getComics({
      page,
      per_page: 18,
      sort,
    })
    comics.value = response.data || []
    paginationMeta.value = response.meta || null
  } catch (error: unknown) {
    errorMessage.value = getErrorMessage(
      error,
      'Không thể tải danh sách truyện. Vui lòng kiểm tra kết nối mạng và thử lại.'
    )
  } finally {
    isLoading.value = false
  }
}

function handleSortChange(sort: ComicSortOption) {
  if (currentSort.value === sort && !isLoading.value) return
  fetchComics(1, sort)
}

function handlePageChange(page: number) {
  if (page < 1 || (paginationMeta.value && page > paginationMeta.value.last_page)) return
  fetchComics(page, currentSort.value)
  // Smooth scroll to catalog section
  const section = document.getElementById('catalog-section')
  if (section) {
    section.scrollIntoView({ behavior: 'smooth' })
  }
}

onMounted(() => {
  fetchComics()
})
</script>

<template>
  <div class="home-page">
    <!-- Hero Banner Slider -->
    <BannerSlider />

    <!-- Main Catalog Section -->
    <section id="catalog-section" class="catalog-section" aria-label="Danh mục truyện">
      <div class="container">
        <!-- Section Header with Sort Tabs -->
        <div class="catalog-header">
          <div class="header-titles">
            <h1 class="catalog-title">
              <span class="title-highlight">Truyện tranh</span> nổi bật
            </h1>
            <p class="catalog-subtitle">
              Kho truyện tranh đa dạng thể loại, cập nhật chương mới liên tục mỗi ngày
            </p>
          </div>

          <!-- Sort Filter Controls -->
          <div class="sort-tabs" role="tablist" aria-label="Sắp xếp danh sách truyện">
            <button
              v-for="opt in sortOptions"
              :key="opt.value"
              type="button"
              role="tab"
              :aria-selected="currentSort === opt.value"
              class="sort-tab-btn"
              :class="{ active: currentSort === opt.value }"
              :disabled="isLoading"
              @click="handleSortChange(opt.value)"
            >
              <span class="tab-icon" aria-hidden="true">{{ opt.icon }}</span>
              <span>{{ opt.label }}</span>
            </button>
          </div>
        </div>

        <!-- Loading Skeleton Grid -->
        <div v-if="isLoading" class="comics-grid" aria-busy="true" aria-label="Đang tải danh sách truyện">
          <div v-for="n in 12" :key="'skeleton-' + n" class="card-skeleton">
            <div class="skeleton-cover"></div>
            <div class="skeleton-info">
              <div class="skeleton-line title"></div>
              <div class="skeleton-line author"></div>
              <div class="skeleton-line tags"></div>
            </div>
          </div>
        </div>

        <!-- Error State -->
        <div v-else-if="errorMessage" class="state-container error-state" role="alert">
          <span class="state-icon" aria-hidden="true">⚠️</span>
          <h2 class="state-title">Đã xảy ra sự cố khi tải dữ liệu</h2>
          <p class="state-desc">{{ errorMessage }}</p>
          <button
            type="button"
            class="btn-retry"
            aria-label="Thử tải lại danh sách truyện"
            @click="fetchComics(currentPage, currentSort)"
          >
            Thử lại
          </button>
        </div>

        <!-- Empty State -->
        <div v-else-if="comics.length === 0" class="state-container empty-state">
          <span class="state-icon" aria-hidden="true">📚</span>
          <h2 class="state-title">Chưa có truyện tranh nào được phát hành</h2>
          <p class="state-desc">
            Hiện tại các bộ truyện đang được biên tập và cập nhật. Hãy quay lại sau ít phút nhé!
          </p>
          <button
            type="button"
            class="btn-retry"
            aria-label="Tải lại trang"
            @click="fetchComics(1, currentSort)"
          >
            Làm mới danh sách
          </button>
        </div>

        <!-- Comics Grid -->
        <div v-else class="comics-content">
          <div class="comics-grid">
            <ComicCard
              v-for="comic in comics"
              :key="comic.id"
              :comic="comic"
            />
          </div>

          <!-- Pagination Controls -->
          <nav
            v-if="paginationMeta && paginationMeta.last_page > 1"
            class="pagination-nav"
            aria-label="Phân trang danh sách truyện"
          >
            <button
              type="button"
              class="btn-page btn-prev"
              :disabled="paginationMeta.current_page <= 1"
              aria-label="Trang trước"
              @click="handlePageChange(paginationMeta.current_page - 1)"
            >
              ‹ Trang trước
            </button>

            <span class="page-indicator">
              Trang <strong>{{ paginationMeta.current_page }}</strong> / {{ paginationMeta.last_page }}
              <span class="total-count">({{ paginationMeta.total }} truyện)</span>
            </span>

            <button
              type="button"
              class="btn-page btn-next"
              :disabled="paginationMeta.current_page >= paginationMeta.last_page"
              aria-label="Trang tiếp theo"
              @click="handlePageChange(paginationMeta.current_page + 1)"
            >
              Trang sau ›
            </button>
          </nav>
        </div>
      </div>
    </section>

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

/* Catalog Section */
.catalog-section {
  padding: var(--spacing-2xl) 0;
}

.catalog-header {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
  margin-bottom: var(--spacing-xl);
  padding-bottom: var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
}

@media (min-width: 768px) {
  .catalog-header {
    flex-direction: row;
    align-items: flex-end;
    justify-content: space-between;
  }
}

.catalog-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
}

.title-highlight {
  color: var(--color-primary);
  text-shadow: 0 0 20px var(--color-primary-glow);
}

.catalog-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  margin-top: 4px;
}

/* Sort Tabs */
.sort-tabs {
  display: flex;
  gap: var(--spacing-xs);
  background-color: var(--color-bg-surface);
  padding: 4px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  align-self: flex-start;
}

.sort-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
  background-color: transparent;
  border: none;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.sort-tab-btn:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.sort-tab-btn.active {
  color: var(--color-primary);
  background-color: var(--color-primary-soft);
  font-weight: var(--font-weight-semibold);
  box-shadow: inset 0 0 0 1px var(--color-primary-glow);
}

.sort-tab-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.tab-icon {
  font-size: 12px;
}

/* Comics Grid */
.comics-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--spacing-md);
}

@media (min-width: 640px) {
  .comics-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (min-width: 768px) {
  .comics-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

@media (min-width: 1024px) {
  .comics-grid {
    grid-template-columns: repeat(6, 1fr);
    gap: var(--spacing-lg);
  }
}

/* Skeleton Loading Grid */
.card-skeleton {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.skeleton-cover {
  width: 100%;
  aspect-ratio: 3 / 4;
  background: linear-gradient(90deg, var(--color-bg-elevated) 25%, var(--color-bg-surface) 50%, var(--color-bg-elevated) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
}

.skeleton-info {
  padding: var(--spacing-sm);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.skeleton-line {
  background: linear-gradient(90deg, var(--color-bg-elevated) 25%, var(--color-bg-surface) 50%, var(--color-bg-elevated) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
  border-radius: var(--radius-xs);
}

.skeleton-line.title {
  width: 90%;
  height: 14px;
}

.skeleton-line.author {
  width: 60%;
  height: 10px;
}

.skeleton-line.tags {
  width: 40%;
  height: 12px;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* State Containers (Error, Empty) */
.state-container {
  min-height: 320px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-2xl) var(--spacing-md);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  margin: var(--spacing-md) 0;
}

.state-icon {
  font-size: 3rem;
  margin-bottom: var(--spacing-sm);
}

.state-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: 4px;
}

.state-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 480px;
  line-height: var(--line-height-relaxed);
  margin-bottom: var(--spacing-md);
}

.btn-retry {
  padding: var(--spacing-xs) var(--spacing-xl);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
}

.btn-retry:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
}

/* Pagination */
.pagination-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-md);
  margin-top: var(--spacing-2xl);
  padding-top: var(--spacing-lg);
  border-top: 1px solid var(--color-border);
}

.btn-page {
  padding: var(--spacing-xs) var(--spacing-md);
  background-color: var(--color-bg-surface);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  transition: all var(--transition-fast);
}

.btn-page:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
  background-color: var(--color-primary-soft);
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.page-indicator {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

.page-indicator strong {
  color: var(--color-primary);
}

.total-count {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-left: 4px;
}

/* Platform Features */
.platform-features {
  padding: var(--spacing-2xl) 0 var(--spacing-xl);
  margin-top: var(--spacing-xl);
  border-top: 1px solid var(--color-border);
  background: linear-gradient(180deg, transparent 0%, var(--color-bg-surface) 100%);
}

.features-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--spacing-md);
}

@media (min-width: 768px) {
  .features-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.feature-card {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-lg);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
  transition: transform var(--transition-normal);
}

.feature-card:hover {
  transform: translateY(-2px);
  border-color: var(--color-border-accent);
}

.feature-icon {
  font-size: var(--font-size-2xl);
  margin-bottom: 2px;
}

.feature-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.feature-desc {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  line-height: var(--line-height-relaxed);
}
</style>
