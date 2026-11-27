<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ComicCard from '@/components/ComicCard.vue'
import { CatalogService } from '@/services/catalogService'
import type { ComicListItem } from '@/types/catalog'

// Discovery Sections Data
const latestUpdatedComics = ref<ComicListItem[]>([])
const completedComics = ref<ComicListItem[]>([])
const weeklyTopComics = ref<ComicListItem[]>([])
const newComics = ref<ComicListItem[]>([])
const featuredComics = ref<ComicListItem[]>([])

// Loading and Error States
const isLoading = ref(true)
const loadError = ref<string | null>(null)

/**
 * Deduplicate comics by ID and limit to maximum items (defaults to 6)
 */
function dedupeAndLimit(items: ComicListItem[], limit = 6): ComicListItem[] {
  const seen = new Set<number>()
  const result: ComicListItem[] = []
  for (const item of items) {
    if (!seen.has(item.id)) {
      seen.add(item.id)
      result.push(item)
      if (result.length >= limit) break
    }
  }
  return result
}

/**
 * Format chapter number as clean integer
 */
function formatChapterNumber(val: string | number | null | undefined): string {
  if (val === null || val === undefined || val === '') return ''
  const num = typeof val === 'number' ? val : parseFloat(String(val))
  if (isNaN(num)) return String(val)
  return Math.round(num).toString()
}

/**
 * Format relative time or readable date (e.g. "Vừa xong", "1 giờ trước", "Hôm qua")
 */
function formatTimeAgo(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMinutes = Math.floor(diffMs / (1000 * 60))
  const diffHours = Math.floor(diffMinutes / 60)
  const diffDays = Math.floor(diffHours / 24)

  if (diffMinutes < 1) return 'Vừa xong'
  if (diffMinutes < 60) return `${diffMinutes} phút trước`
  if (diffHours < 24) return `${diffHours} giờ trước`
  if (diffDays === 1) return 'Hôm qua'
  if (diffDays < 7) return `${diffDays} ngày trước`

  return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' })
}

defineExpose({
  formatChapterNumber,
  formatTimeAgo,
})

async function loadDiscoverySections() {
  isLoading.value = true
  loadError.value = null

  try {
    const [
      updatedRes,
      completedRes,
      weeklyTopRes,
      newRes,
      featuredRes,
    ] = await Promise.allSettled([
      // 1. Truyện mới cập nhật (Tối đa 6 truyện có chương mới nhất)
      CatalogService.getComics({ sort: 'updated', per_page: 6 }),
      // 2. Truyện hoàn thành (Tối đa 6 truyện)
      CatalogService.getComics({ status: 'completed', per_page: 6 }),
      // 3. Top truyện tuần (Tối đa 6 truyện có lượt xem cao)
      CatalogService.getComics({ sort: 'weekly_views', per_page: 6 }),
      // 4. Truyện mới đăng (Tối đa 6 truyện mới nhất)
      CatalogService.getComics({ sort: 'latest', per_page: 6 }),
      // 5. Truyện nổi bật (Tối đa 6 truyện is_featured = 1)
      CatalogService.getComics({ is_featured: 1, per_page: 6 }),
    ])

    if (updatedRes.status === 'fulfilled') {
      latestUpdatedComics.value = dedupeAndLimit(updatedRes.value.data || [], 6)
    }
    if (completedRes.status === 'fulfilled') {
      completedComics.value = dedupeAndLimit(completedRes.value.data || [], 6)
    }
    if (weeklyTopRes.status === 'fulfilled') {
      weeklyTopComics.value = dedupeAndLimit(weeklyTopRes.value.data || [], 6)
    }
    if (newRes.status === 'fulfilled') {
      newComics.value = dedupeAndLimit(newRes.value.data || [], 6)
    }
    if (featuredRes.status === 'fulfilled') {
      featuredComics.value = dedupeAndLimit(featuredRes.value.data || [], 6)
    }
  } catch (err: unknown) {
    loadError.value = 'Không thể tải đầy đủ các khu vực khám phá truyện.'
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadDiscoverySections()
})
</script>

<template>
  <div class="home-discovery-sections">
    <div class="container discovery-container">
      <!-- Error notification if discovery fails -->
      <div v-if="loadError" class="discovery-error-banner" role="alert">
        <span>⚠️ {{ loadError }}</span>
        <button type="button" class="btn-retry-discovery" @click="loadDiscoverySections">
          Thử lại
        </button>
      </div>

      <!-- Loading Skeletons -->
      <div v-if="isLoading" class="discovery-loading">
        <div v-for="s in 3" :key="'sec-skel-' + s" class="section-skeleton">
          <div class="skeleton-header"></div>
          <div class="comics-grid">
            <div v-for="n in 5" :key="'card-skel-' + n" class="card-skeleton">
              <div class="skeleton-cover"></div>
              <div class="skeleton-info">
                <div class="skeleton-line title"></div>
                <div class="skeleton-line author"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template v-else>
        <!-- ========================================== -->
        <!-- 1. KHU VỰC: TRUYỆN MỚI CẬP NHẬT            -->
        <!-- ========================================== -->
        <section class="discovery-section section-latest-updated" aria-labelledby="heading-latest-updated">
          <div class="section-header">
            <div class="section-title-wrap">
              <span class="section-icon" aria-hidden="true">🔄</span>
              <h2 id="heading-latest-updated" class="section-title">
                Truyện mới cập nhật
              </h2>
            </div>
            <RouterLink to="/comics?sort=updated" class="btn-view-all">
              <span>Xem tất cả</span>
              <span aria-hidden="true">→</span>
            </RouterLink>
          </div>

          <!-- Empty state -->
          <div v-if="latestUpdatedComics.length === 0" class="section-empty">
            <span>Chưa có truyện mới cập nhật chương.</span>
          </div>

          <!-- Comics Grid -->
          <div v-else class="comics-grid">
            <ComicCard
              v-for="comic in latestUpdatedComics"
              :key="'updated-' + comic.id"
              :comic="comic"
            />
          </div>
        </section>

        <!-- ========================================== -->
        <!-- 2. KHU VỰC: TRUYỆN HOÀN THÀNH              -->
        <!-- ========================================== -->
        <section class="discovery-section section-completed" aria-labelledby="heading-completed">
          <div class="section-header">
            <div class="section-title-wrap">
              <span class="section-icon" aria-hidden="true">🏆</span>
              <h2 id="heading-completed" class="section-title">
                Truyện hoàn thành
              </h2>
            </div>
            <RouterLink to="/comics?status=completed" class="btn-view-all">
              <span>Xem tất cả</span>
              <span aria-hidden="true">→</span>
            </RouterLink>
          </div>

          <div v-if="completedComics.length === 0" class="section-empty">
            <span>Chưa có tác phẩm nào ở trạng thái hoàn thành.</span>
          </div>

          <div v-else class="comics-grid">
            <ComicCard
              v-for="comic in completedComics"
              :key="'completed-' + comic.id"
              :comic="comic"
            />
          </div>
        </section>

        <!-- ========================================== -->
        <!-- 3. KHU VỰC: TOP TRUYỆN TUẦN                -->
        <!-- ========================================== -->
        <section class="discovery-section section-weekly-top" aria-labelledby="heading-weekly-top">
          <div class="section-header">
            <div class="section-title-wrap">
              <span class="section-icon" aria-hidden="true">🔥</span>
              <h2 id="heading-weekly-top" class="section-title">
                Top truyện tuần
              </h2>
            </div>
            <RouterLink to="/comics?sort=views" class="btn-view-all">
              <span>Xem tất cả</span>
              <span aria-hidden="true">→</span>
            </RouterLink>
          </div>

          <div v-if="weeklyTopComics.length === 0" class="section-empty">
            <span>Chưa có dữ liệu xếp hạng top tuần.</span>
          </div>

          <!-- Weekly Top Grid (Max 6 with Rank 1 to 6) -->
          <div v-else class="weekly-top-grid">
            <div
              v-for="(comic, index) in weeklyTopComics"
              :key="'weekly-' + comic.id"
              class="weekly-top-card"
              :class="`rank-card-${index + 1}`"
            >
              <div class="rank-badge" :class="`badge-top-${index + 1}`">
                <span class="rank-number">{{ index + 1 }}</span>
                <span v-if="index === 0" class="rank-crown" aria-hidden="true">👑</span>
              </div>
              <div class="weekly-card-content">
                <ComicCard :comic="comic" />
              </div>
            </div>
          </div>
        </section>

        <!-- ========================================== -->
        <!-- 4. KHU VỰC: TRUYỆN MỚI ĐĂNG                 -->
        <!-- ========================================== -->
        <section class="discovery-section section-new-comics" aria-labelledby="heading-new-comics">
          <div class="section-header">
            <div class="section-title-wrap">
              <span class="section-icon" aria-hidden="true">✨</span>
              <h2 id="heading-new-comics" class="section-title">
                Truyện mới đăng
              </h2>
            </div>
            <RouterLink to="/comics?sort=latest" class="btn-view-all">
              <span>Xem tất cả</span>
              <span aria-hidden="true">→</span>
            </RouterLink>
          </div>

          <div v-if="newComics.length === 0" class="section-empty">
            <span>Chưa có truyện mới được xuất bản.</span>
          </div>

          <div v-else class="comics-grid">
            <ComicCard
              v-for="comic in newComics"
              :key="'new-' + comic.id"
              :comic="comic"
            />
          </div>
        </section>

        <!-- ========================================== -->
        <!-- 5. KHU VỰC: TRUYỆN NỔI BẬT                 -->
        <!-- ========================================== -->
        <section class="discovery-section section-featured-comics" aria-labelledby="heading-featured">
          <div class="section-header">
            <div class="section-title-wrap">
              <span class="section-icon" aria-hidden="true">⭐</span>
              <h2 id="heading-featured" class="section-title">
                Truyện nổi bật
              </h2>
            </div>
            <RouterLink to="/comics?sort=views" class="btn-view-all">
              <span>Xem tất cả</span>
              <span aria-hidden="true">→</span>
            </RouterLink>
          </div>

          <div v-if="featuredComics.length === 0" class="section-empty">
            <span>Hiện chưa có tác phẩm nổi bật được đánh dấu.</span>
          </div>

          <!-- Featured Comics Row / Grid (Max 5 items, special highlight) -->
          <div v-else class="featured-comics-grid">
            <div
              v-for="comic in featuredComics"
              :key="'featured-' + comic.id"
              class="featured-card-wrap"
            >
              <div class="featured-glow-border">
                <ComicCard :comic="comic" />
              </div>
            </div>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.home-discovery-sections {
  padding-top: var(--spacing-md);
  padding-bottom: var(--spacing-2xl);
}

.discovery-container {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-2xl);
}

.discovery-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: var(--spacing-xs);
  border-bottom: 1px solid var(--color-border);
}

.section-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
}

.section-icon {
  font-size: 22px;
}

.section-title {
  font-size: var(--font-size-xl, 20px);
  font-weight: 700;
  color: var(--color-text-primary, #fff);
  margin: 0;
  letter-spacing: -0.01em;
}

.btn-view-all {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-xs, 12px);
  font-weight: 600;
  color: var(--color-primary, #388bfd);
  text-decoration: none;
  padding: 4px 10px;
  border-radius: var(--radius-sm, 6px);
  background-color: rgba(56, 139, 253, 0.08);
  border: 1px solid rgba(56, 139, 253, 0.2);
  transition: all 0.2s ease;
}

.btn-view-all:hover {
  background-color: var(--color-primary, #388bfd);
  color: #fff;
  transform: translateX(2px);
}

.section-empty {
  padding: var(--spacing-xl);
  text-align: center;
  color: var(--color-text-muted, #8b949e);
  background-color: var(--color-bg-card, #161b22);
  border: 1px dashed var(--color-border, #30363d);
  border-radius: var(--radius-lg, 12px);
  font-size: var(--font-size-sm, 13px);
}

/* Comics Grid matching existing layout (up to 6 items per row) */
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

@media (min-width: 900px) {
  .comics-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

@media (min-width: 1200px) {
  .comics-grid {
    grid-template-columns: repeat(6, 1fr);
  }
}

/* Latest chapter sub-badge on updated section */
.comic-card-wrapper {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.latest-chapter-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 8px;
  margin-top: 6px;
  min-height: 28px;
  height: 28px;
  box-sizing: border-box;
  background-color: var(--color-bg-surface-raised, #161b22);
  border: 1px solid var(--color-border, #30363d);
  border-radius: var(--radius-sm, 6px);
  font-size: 11px;
}

.chapter-label {
  color: var(--color-primary, #388bfd);
  font-weight: 600;
}

.chapter-time {
  color: var(--color-text-muted, #8b949e);
  font-size: 10px;
}

/* Weekly Top Grid (6 items) */
.weekly-top-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--spacing-md);
}

@media (min-width: 640px) {
  .weekly-top-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (min-width: 1024px) {
  .weekly-top-grid {
    grid-template-columns: repeat(6, 1fr);
  }
}

.weekly-top-card {
  position: relative;
  display: flex;
  flex-direction: column;
}

.rank-badge {
  position: absolute;
  top: -8px;
  left: -8px;
  z-index: 10;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 14px;
  color: #fff;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
  border: 2px solid var(--color-bg-surface, #0d1117);
}

.badge-top-1 {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  width: 36px;
  height: 36px;
  font-size: 16px;
}

.badge-top-2 {
  background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
}

.badge-top-3 {
  background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
}

.badge-top-4,
.badge-top-5,
.badge-top-6 {
  background-color: var(--color-bg-elevated, #21262d);
  color: var(--color-text-muted, #8b949e);
}

.rank-crown {
  position: absolute;
  top: -14px;
  font-size: 14px;
}

/* Featured Comics Row (Max 6 items) */
.featured-comics-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--spacing-md);
}

@media (min-width: 640px) {
  .featured-comics-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (min-width: 1024px) {
  .featured-comics-grid {
    grid-template-columns: repeat(6, 1fr);
  }
}

.featured-glow-border {
  border-radius: var(--radius-lg, 12px);
  padding: 2px;
  background: linear-gradient(135deg, rgba(234, 179, 8, 0.3) 0%, rgba(56, 189, 248, 0.3) 100%);
  transition: all 0.3s ease;
}

.featured-glow-border:hover {
  background: linear-gradient(135deg, rgba(234, 179, 8, 0.7) 0%, rgba(56, 189, 248, 0.7) 100%);
  box-shadow: 0 4px 20px rgba(234, 179, 8, 0.2);
}

/* Skeleton Loading */
.discovery-loading {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xl);
}

.section-skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.skeleton-header {
  height: 28px;
  width: 220px;
  background-color: var(--color-bg-surface-raised, #21262d);
  border-radius: var(--radius-sm, 6px);
  animation: pulse 1.5s infinite ease-in-out;
}

.card-skeleton {
  aspect-ratio: 2 / 3;
  background-color: var(--color-bg-surface-raised, #21262d);
  border-radius: var(--radius-md, 8px);
  animation: pulse 1.5s infinite ease-in-out;
}

@keyframes pulse {
  0% { opacity: 0.6; }
  50% { opacity: 0.3; }
  100% { opacity: 0.6; }
}

.discovery-error-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  background-color: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: var(--radius-md, 8px);
  color: #f87171;
  font-size: var(--font-size-sm, 13px);
}

.btn-retry-discovery {
  background-color: transparent;
  border: 1px solid currentColor;
  color: inherit;
  border-radius: var(--radius-sm, 4px);
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
</style>
