<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { CatalogService } from '@/services/catalogService'
import { ReaderHistoryService } from '@/services/readerHistoryService'
import { getErrorMessage, isNotFoundError } from '@/services/httpClient'
import type { ComicDetail, ChapterSummary } from '@/types/catalog'
import {
  formatChapterNumber,
  compareChapterNumbers,
  getFirstChapter,
  getLatestChapter,
  buildChapterUrl,
} from '@/utils/chapter'

const route = useRoute()

// Data states
const comic = ref<ComicDetail | null>(null)
const chapters = ref<ChapterSummary[]>([])
const isLoading = ref(true)
const isChaptersLoading = ref(false)
const isNotFound = ref(false)
const notFoundMessage = ref('')
const errorMessage = ref<string | null>(null)

// UI states
const coverImageError = ref(false)
const bannerImageError = ref(false)
const creatorAvatarError = ref(false)
const isDescriptionExpanded = ref(false)
const sortDescending = ref(false) // default ASC

let latestRequestId = 0
const historyTick = ref(0)

// Computed chapters list sorted numerically
const sortedChapters = computed(() => {
  if (!chapters.value || chapters.value.length === 0) return []
  const sorted = [...chapters.value].sort((a, b) =>
    compareChapterNumbers(a.chapter_number, b.chapter_number)
  )
  return sortDescending.value ? sorted.reverse() : sorted
})

// First and latest chapters computed mathematically by numeric chapter number
const firstChapter = computed(() => {
  return getFirstChapter(chapters.value)
})

const latestChapter = computed(() => {
  return getLatestChapter(chapters.value)
})

// Check local reading history and verify that the chapter is still in published chapters
const lastReadChapter = computed<ChapterSummary | null>(() => {
  void historyTick.value
  if (!comic.value || !chapters.value || chapters.value.length === 0) return null
  const history = ReaderHistoryService.getLastRead(comic.value.slug)
  if (!history) return null
  return (
    chapters.value.find(
      (c) => compareChapterNumbers(c.chapter_number, history.chapterNumber) === 0
    ) || null
  )
})

// Primary action target: continues from last read if valid, otherwise starts from first published chapter
const primaryChapter = computed<ChapterSummary | null>(() => {
  return lastReadChapter.value || firstChapter.value
})

// Whether to show the secondary "latest chapter" button
const showLatestButton = computed<boolean>(() => {
  if (!latestChapter.value || !primaryChapter.value) return false
  // Avoid duplicate button if only 1 chapter exists or primary button already points to latest chapter
  return latestChapter.value.id !== primaryChapter.value.id
})

// Computed status display
const statusBadge = computed(() => {
  if (!comic.value) return { label: '', className: '' }
  switch (comic.value.status) {
    case 'completed':
      return { label: 'Đã hoàn thành', className: 'status-completed' }
    case 'hiatus':
      return { label: 'Tạm ngưng', className: 'status-hiatus' }
    case 'ongoing':
    default:
      return { label: 'Đang tiến hành', className: 'status-ongoing' }
  }
})

// Format helpers
function formatViews(views: number): string {
  if (!views) return '0'
  if (views >= 1000000) return (views / 1000000).toFixed(1) + 'M'
  if (views >= 1000) return (views / 1000).toFixed(1) + 'k'
  return views.toLocaleString('vi-VN')
}



function formatDate(isoString: string | null): string {
  if (!isoString) return ''
  try {
    const d = new Date(isoString)
    return d.toLocaleDateString('vi-VN', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  } catch {
    return ''
  }
}

/**
 * Fetch comic details and its published chapters.
 */
async function loadComicData(slug: string) {
  const requestId = ++latestRequestId
  isLoading.value = true
  errorMessage.value = null
  isNotFound.value = false
  notFoundMessage.value = ''
  coverImageError.value = false
  bannerImageError.value = false
  creatorAvatarError.value = false
  isDescriptionExpanded.value = false

  try {
    const response = await CatalogService.getComicDetail(slug)
    if (requestId === latestRequestId) {
      comic.value = response.data
      document.title = `${response.data.title} — BlueToon`

      // If chapters are bundled in comic detail resource
      if (response.data.chapters && response.data.chapters.length > 0) {
        chapters.value = response.data.chapters
      } else {
        // Fallback: fetch chapters via dedicated endpoint
        await fetchChapters(slug, requestId)
      }
      historyTick.value++
    }
  } catch (error: unknown) {
    if (requestId === latestRequestId) {
      if (isNotFoundError(error)) {
        isNotFound.value = true
        notFoundMessage.value = getErrorMessage(
          error,
          'Truyện không tồn tại hoặc chưa được phát hành công khai.'
        )
      } else {
        errorMessage.value = getErrorMessage(
          error,
          'Không thể tải thông tin truyện. Vui lòng thử lại sau.'
        )
      }
    }
  } finally {
    if (requestId === latestRequestId) {
      isLoading.value = false
    }
  }
}

/**
 * Fetch published chapters list using CatalogService.getChapters().
 */
async function fetchChapters(slug: string, requestId: number) {
  isChaptersLoading.value = true
  try {
    const response = await CatalogService.getChapters(slug)
    if (requestId === latestRequestId) {
      chapters.value = response.data || []
      historyTick.value++
    }
  } catch {
    // Non-fatal, use whatever chapters were in detail resource
  } finally {
    if (requestId === latestRequestId) {
      isChaptersLoading.value = false
    }
  }
}

function handleRetry() {
  const slug = String(route.params.slug || '')
  if (slug) {
    loadComicData(slug)
  }
}

// Watch for route slug changes (e.g. user navigates between comics)
watch(
  () => route.params.slug,
  (newSlug) => {
    if (newSlug) {
      loadComicData(String(newSlug))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }
)

onMounted(() => {
  const slug = String(route.params.slug || '')
  if (slug) {
    loadComicData(slug)
  }
})
</script>

<template>
  <div class="comic-detail-page">
    <!-- 404 Not Found State -->
    <div v-if="isNotFound" class="container state-container not-found-state" role="alert">
      <span class="state-icon" aria-hidden="true">🔍</span>
      <h1 class="state-title">Truyện không tồn tại</h1>
      <p class="state-desc">
        {{ notFoundMessage || 'Bộ truyện bạn đang tìm kiếm không tồn tại hoặc chưa được duyệt phát hành công khai.' }}
      </p>
      <div class="state-actions">
        <RouterLink to="/comics" class="btn-primary-action">
          ← Quay lại danh mục truyện
        </RouterLink>
      </div>
    </div>

    <!-- Generic Error State -->
    <div v-else-if="errorMessage" class="container state-container error-state" role="alert">
      <span class="state-icon" aria-hidden="true">⚠️</span>
      <h1 class="state-title">Không thể tải truyện</h1>
      <p class="state-desc">{{ errorMessage }}</p>
      <div class="state-actions">
        <button
          type="button"
          class="btn-primary-action"
          aria-label="Thử tải lại thông tin truyện"
          @click="handleRetry"
        >
          Thử lại
        </button>
        <RouterLink to="/comics" class="btn-secondary-action">
          Về danh mục
        </RouterLink>
      </div>
    </div>

    <!-- Loading Skeleton State -->
    <div v-else-if="isLoading" class="container skeleton-container" aria-busy="true" aria-label="Đang tải thông tin truyện">
      <div class="skeleton-hero">
        <div class="skeleton-cover"></div>
        <div class="skeleton-meta">
          <div class="skeleton-line title"></div>
          <div class="skeleton-line subtitle"></div>
          <div class="skeleton-line tags"></div>
          <div class="skeleton-line stats"></div>
          <div class="skeleton-btn-row">
            <div class="skeleton-btn"></div>
            <div class="skeleton-btn"></div>
          </div>
        </div>
      </div>
      <div class="skeleton-body">
        <div class="skeleton-line block"></div>
        <div class="skeleton-line block"></div>
      </div>
    </div>

    <!-- Comic Detail Content -->
    <div v-else-if="comic" class="comic-content">
      <!-- Backdrop Banner Header -->
      <section class="banner-hero" aria-label="Hình nền truyện">
        <div class="banner-media">
          <img
            v-if="comic.banner_url && !bannerImageError"
            :src="comic.banner_url"
            :alt="'Banner truyện ' + comic.title"
            class="banner-img"
            @error="bannerImageError = true"
          />
          <div class="banner-overlay" aria-hidden="true"></div>
        </div>

        <div class="container hero-content">
          <!-- Breadcrumb Navigation -->
          <nav class="breadcrumb-nav" aria-label="Đường dẫn điều hướng">
            <RouterLink to="/" class="breadcrumb-link">Trang chủ</RouterLink>
            <span class="breadcrumb-separator">/</span>
            <RouterLink to="/comics" class="breadcrumb-link">Danh mục</RouterLink>
            <span class="breadcrumb-separator">/</span>
            <span class="breadcrumb-current">{{ comic.title }}</span>
          </nav>

          <!-- Main Comic Presentation -->
          <div class="presentation-grid">
            <!-- Left: Comic Cover -->
            <div class="cover-column">
              <div class="cover-card">
                <img
                  v-if="comic.cover_url && !coverImageError"
                  :src="comic.cover_url"
                  :alt="'Bìa truyện ' + comic.title"
                  class="comic-cover"
                  @error="coverImageError = true"
                />
                <div v-else class="comic-cover-fallback" aria-hidden="true">
                  <div class="fallback-glow"></div>
                  <span class="fallback-icon">📖</span>
                  <span class="fallback-title">{{ comic.title }}</span>
                </div>

                <!-- Status Badge -->
                <span class="badge-status" :class="statusBadge.className">
                  {{ statusBadge.label }}
                </span>

                <!-- Age Rating Badge -->
                <span v-if="comic.age_rating" class="badge-age">
                  {{ comic.age_rating.toUpperCase() }}
                </span>
              </div>
            </div>

            <!-- Right: Comic Metadata & Actions -->
            <div class="info-column">
              <h1 class="comic-title">{{ comic.title }}</h1>

              <p v-if="comic.alternative_titles" class="comic-alt-titles">
                <span class="alt-label">Tên khác:</span>
                <span>{{ comic.alternative_titles }}</span>
              </p>

              <!-- Author & Creator -->
              <div class="creator-meta">
                <div v-if="comic.author_name" class="meta-item author-item">
                  <span class="meta-icon" aria-hidden="true">✍</span>
                  <span class="meta-label">Tác giả:</span>
                  <strong class="meta-value">{{ comic.author_name }}</strong>
                </div>

                <div v-if="comic.creator" class="meta-item creator-item">
                  <div class="creator-avatar">
                    <img
                      v-if="comic.creator.avatar_url && !creatorAvatarError"
                      :src="comic.creator.avatar_url"
                      :alt="comic.creator.name"
                      class="avatar-img"
                      @error="creatorAvatarError = true"
                    />
                    <span v-else class="avatar-initial">{{ comic.creator.name.charAt(0).toUpperCase() }}</span>
                  </div>
                  <span class="meta-label">Người đăng:</span>
                  <span class="creator-name">{{ comic.creator.name }}</span>
                </div>
              </div>

              <!-- Stat Pills -->
              <div class="stats-pills">
                <div class="stat-pill" :title="comic.views_count + ' lượt xem'">
                  <span class="stat-icon" aria-hidden="true">👁</span>
                  <span class="stat-val">{{ formatViews(comic.views_count) }}</span>
                  <span class="stat-lbl">Lượt xem</span>
                </div>

                <div class="stat-pill" :title="comic.follows_count + ' người theo dõi'">
                  <span class="stat-icon" aria-hidden="true">⭐</span>
                  <span class="stat-val">{{ formatViews(comic.follows_count) }}</span>
                  <span class="stat-lbl">Theo dõi</span>
                </div>

                <div class="stat-pill">
                  <span class="stat-icon" aria-hidden="true">📑</span>
                  <span class="stat-val">{{ comic.published_chapters_count || chapters.length }}</span>
                  <span class="stat-lbl">Chương</span>
                </div>
              </div>

              <!-- Genres & Tags Links -->
              <div class="taxonomy-section">
                <!-- Genres -->
                <div v-if="comic.genres && comic.genres.length > 0" class="taxonomy-group">
                  <span class="taxonomy-label">Thể loại:</span>
                  <div class="taxonomy-chips">
                    <RouterLink
                      v-for="genre in comic.genres"
                      :key="genre.id"
                      :to="'/comics?genre=' + genre.slug"
                      class="genre-chip"
                      :aria-label="'Xem truyện thể loại ' + genre.name"
                    >
                      {{ genre.name }}
                    </RouterLink>
                  </div>
                </div>

                <!-- Tags -->
                <div v-if="comic.tags && comic.tags.length > 0" class="taxonomy-group">
                  <span class="taxonomy-label">Tags:</span>
                  <div class="taxonomy-chips">
                    <RouterLink
                      v-for="tag in comic.tags"
                      :key="tag.id"
                      :to="'/comics?tag=' + tag.slug"
                      class="tag-chip"
                      :aria-label="'Xem truyện có tag ' + tag.name"
                    >
                      #{{ tag.name }}
                    </RouterLink>
                  </div>
                </div>
              </div>

              <!-- Action Call to Reads -->
              <div class="cta-actions">
                <template v-if="primaryChapter">
                  <!-- Primary Action: Đọc tiếp if history exists, else Đọc ngay -->
                  <RouterLink
                    :to="buildChapterUrl(comic.slug, primaryChapter.chapter_number)"
                    class="btn-read-first"
                    :class="{ 'btn-read-continue': !!lastReadChapter }"
                    :aria-label="
                      (lastReadChapter ? 'Đọc tiếp' : 'Đọc ngay') +
                      ': Chương ' +
                      formatChapterNumber(primaryChapter.chapter_number)
                    "
                  >
                    <span class="btn-icon" aria-hidden="true">{{ lastReadChapter ? '🔖' : '📖' }}</span>
                    <span>
                      {{ lastReadChapter ? 'Đọc tiếp' : 'Đọc ngay' }}
                      (C.{{ formatChapterNumber(primaryChapter.chapter_number) }})
                    </span>
                  </RouterLink>

                  <!-- Secondary Action: Đọc chương mới nhất only if different from primary target -->
                  <RouterLink
                    v-if="latestChapter && showLatestButton"
                    :to="buildChapterUrl(comic.slug, latestChapter.chapter_number)"
                    class="btn-read-latest"
                    :aria-label="'Đọc chương mới nhất: Chương ' + formatChapterNumber(latestChapter.chapter_number)"
                  >
                    <span>Đọc chương mới nhất (C.{{ formatChapterNumber(latestChapter.chapter_number) }})</span>
                  </RouterLink>
                </template>

                <div v-else class="no-chapter-notice">
                  <span>Chưa có chương nào được phát hành công khai.</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Main Body Container -->
      <main class="container detail-body">
        <!-- AI Summary (if available) -->
        <section v-if="comic.ai_summary" class="ai-summary-card" aria-label="Tóm tắt thông minh bởi AI">
          <div class="ai-header">
            <span class="ai-sparkle-icon" aria-hidden="true">✨</span>
            <div class="ai-title-wrap">
              <h2 class="ai-title">Tóm tắt nội dung thông minh</h2>
              <span class="ai-badge">Trợ lý AI ComicAI</span>
            </div>
          </div>
          <p class="ai-text">{{ comic.ai_summary }}</p>
        </section>

        <!-- Story Description / Synopsis -->
        <section v-if="comic.description" class="synopsis-section" aria-label="Nội dung truyện">
          <h2 class="section-heading">Giới thiệu truyện</h2>
          <div class="synopsis-content" :class="{ 'is-clamped': !isDescriptionExpanded }">
            <p class="synopsis-text">{{ comic.description }}</p>
          </div>
          <button
            type="button"
            class="btn-expand-desc"
            :aria-expanded="isDescriptionExpanded"
            @click="isDescriptionExpanded = !isDescriptionExpanded"
          >
            {{ isDescriptionExpanded ? 'Thu gọn ▲' : 'Xem thêm nội dung ▼' }}
          </button>
        </section>

        <!-- Chapters List Section -->
        <section id="chapters-section" class="chapters-section" aria-label="Danh sách chương">
          <div class="chapters-header">
            <div class="header-titles">
              <h2 class="section-heading">Danh sách chương</h2>
              <span class="chapters-count-badge">{{ chapters.length }} chương</span>
            </div>

            <!-- Sort Toggle Button -->
            <button
              v-if="chapters.length > 1"
              type="button"
              class="btn-sort-chapters"
              aria-label="Thay đổi thứ tự danh sách chương"
              @click="sortDescending = !sortDescending"
            >
              <span class="sort-icon" aria-hidden="true">⇅</span>
              <span>{{ sortDescending ? 'Mới nhất trước' : 'Cũ nhất trước' }}</span>
            </button>
          </div>

          <!-- Empty Chapters Notice -->
          <div v-if="chapters.length === 0" class="empty-chapters-card">
            <span class="empty-icon" aria-hidden="true">📚</span>
            <h3 class="empty-title">Chưa có chương truyện</h3>
            <p class="empty-desc">
              Tác phẩm này đang được tác giả chuẩn bị bản dịch và biên tập. Hãy quay lại sau nhé!
            </p>
          </div>

          <!-- Chapters Grid / List -->
          <div v-else class="chapters-grid">
            <RouterLink
              v-for="chap in sortedChapters"
              :key="chap.id"
              :to="buildChapterUrl(comic.slug, chap.chapter_number)"
              class="chapter-card"
              :class="{ 'chapter-card-last-read': lastReadChapter && lastReadChapter.id === chap.id }"
              :aria-label="'Đọc Chương ' + formatChapterNumber(chap.chapter_number) + (chap.title ? ': ' + chap.title : '')"
            >
              <div class="chapter-info">
                <div class="chapter-num-row">
                  <span class="chapter-num">
                    Chương {{ formatChapterNumber(chap.chapter_number) }}
                  </span>
                  <span
                    v-if="lastReadChapter && lastReadChapter.id === chap.id"
                    class="badge-last-read"
                    title="Chương bạn vừa đọc gần nhất"
                  >
                    Đang đọc
                  </span>
                </div>
                <span v-if="chap.title" class="chapter-name" :title="chap.title">
                  {{ chap.title }}
                </span>
              </div>

              <div class="chapter-meta">
                <span v-if="chap.views_count !== undefined && chap.views_count !== null" class="chap-views" :title="chap.views_count + ' lượt xem'">
                  👁 {{ formatViews(chap.views_count) }}
                </span>
                <span v-if="chap.created_at" class="chap-date">
                  {{ formatDate(chap.created_at) }}
                </span>
              </div>
            </RouterLink>
          </div>
        </section>
      </main>
    </div>
  </div>
</template>

<style scoped>
.comic-detail-page {
  padding-bottom: var(--spacing-3xl);
  min-height: 80vh;
}

/* State Containers (404, Error) */
.state-container {
  min-height: 480px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-md);
  margin: var(--spacing-2xl) auto;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
}

.state-icon {
  font-size: 4rem;
  margin-bottom: var(--spacing-sm);
}

.state-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: var(--spacing-xs);
}

.state-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 520px;
  line-height: var(--line-height-relaxed);
  margin-bottom: var(--spacing-lg);
}

.state-actions {
  display: flex;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
  justify-content: center;
}

.btn-primary-action {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  height: 42px;
  padding: 0 var(--spacing-xl);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
}

.btn-primary-action:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
}

.btn-secondary-action {
  display: inline-flex;
  align-items: center;
  height: 42px;
  padding: 0 var(--spacing-lg);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  transition: all var(--transition-fast);
}

.btn-secondary-action:hover {
  border-color: var(--color-primary);
}

/* Skeleton Loading */
.skeleton-container {
  padding-top: var(--spacing-2xl);
}

.skeleton-hero {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xl);
  margin-bottom: var(--spacing-2xl);
}

@media (min-width: 768px) {
  .skeleton-hero {
    flex-direction: row;
  }
}

.skeleton-cover {
  width: 220px;
  aspect-ratio: 3 / 4;
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
  border-radius: var(--radius-lg);
  flex-shrink: 0;
}

.skeleton-meta {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.skeleton-line {
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
  border-radius: var(--radius-sm);
}

.skeleton-line.title {
  width: 60%;
  height: 36px;
}

.skeleton-line.subtitle {
  width: 40%;
  height: 18px;
}

.skeleton-line.tags {
  width: 70%;
  height: 24px;
}

.skeleton-line.stats {
  width: 50%;
  height: 32px;
}

.skeleton-btn-row {
  display: flex;
  gap: var(--spacing-sm);
  margin-top: var(--spacing-md);
}

.skeleton-btn {
  width: 140px;
  height: 44px;
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
}

.skeleton-body {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.skeleton-line.block {
  width: 100%;
  height: 120px;
  border-radius: var(--radius-lg);
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* Banner Hero Presentation */
.banner-hero {
  position: relative;
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  overflow: hidden;
  padding: var(--spacing-xl) 0 var(--spacing-2xl);
}

.banner-media {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
}

.banner-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  filter: blur(24px) brightness(0.35);
  transform: scale(1.1);
}

.banner-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    180deg,
    rgba(11, 15, 25, 0.7) 0%,
    rgba(11, 15, 25, 0.95) 100%
  );
}

.hero-content {
  position: relative;
  z-index: 1;
}

/* Breadcrumbs */
.breadcrumb-nav {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  font-size: var(--font-size-xs);
  margin-bottom: var(--spacing-lg);
  flex-wrap: wrap;
}

.breadcrumb-link {
  color: var(--color-text-secondary);
  transition: color var(--transition-fast);
}

.breadcrumb-link:hover {
  color: var(--color-primary);
}

.breadcrumb-separator {
  color: var(--color-text-muted);
}

.breadcrumb-current {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
  max-width: 300px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Presentation Grid */
.presentation-grid {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xl);
  align-items: center;
}

@media (min-width: 768px) {
  .presentation-grid {
    flex-direction: row;
    align-items: flex-start;
  }
}

/* Cover Column */
.cover-column {
  flex-shrink: 0;
  width: 220px;
}

@media (min-width: 1024px) {
  .cover-column {
    width: 260px;
  }
}

.cover-card {
  position: relative;
  width: 100%;
  aspect-ratio: 3 / 4;
  border-radius: var(--radius-lg);
  overflow: hidden;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-lg), var(--shadow-glow);
}

.comic-cover {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.comic-cover-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  text-align: center;
  position: relative;
}

.fallback-glow {
  position: absolute;
  width: 140px;
  height: 140px;
  background: var(--color-primary-glow);
  border-radius: 50%;
  filter: blur(32px);
}

.fallback-icon {
  font-size: 3.5rem;
  margin-bottom: var(--spacing-xs);
  z-index: 1;
}

.fallback-title {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-secondary);
  z-index: 1;
}

/* Cover Badges */
.badge-status {
  position: absolute;
  top: var(--spacing-xs);
  left: var(--spacing-xs);
  padding: 3px 8px;
  border-radius: var(--radius-xs);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  backdrop-filter: blur(8px);
  z-index: 2;
  box-shadow: var(--shadow-sm);
}

.status-ongoing {
  background-color: rgba(56, 189, 248, 0.9);
  color: #0b0f19;
}

.status-completed {
  background-color: rgba(16, 185, 129, 0.9);
  color: #ffffff;
}

.status-hiatus {
  background-color: rgba(245, 158, 11, 0.9);
  color: #0b0f19;
}

.badge-age {
  position: absolute;
  top: var(--spacing-xs);
  right: var(--spacing-xs);
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  background-color: rgba(11, 15, 25, 0.85);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  z-index: 2;
}

/* Info Column */
.info-column {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.comic-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
}

@media (min-width: 768px) {
  .comic-title {
    font-size: var(--font-size-3xl);
  }
}

.comic-alt-titles {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  display: flex;
  gap: var(--spacing-xs);
}

.alt-label {
  color: var(--color-text-muted);
}

/* Creator Meta */
.creator-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-md);
  align-items: center;
  padding: var(--spacing-xs) 0;
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-xs);
}

.meta-icon {
  font-size: 13px;
}

.meta-label {
  color: var(--color-text-muted);
}

.meta-value {
  color: var(--color-text-primary);
}

.creator-item {
  background-color: rgba(31, 41, 55, 0.6);
  padding: 3px 8px 3px 4px;
  border-radius: var(--radius-full);
  border: 1px solid var(--color-border);
}

.creator-avatar {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-initial {
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
}

.creator-name {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

/* Stat Pills */
.stats-pills {
  display: flex;
  flex-wrap: wrap;
  gap: var(--spacing-sm);
}

.stat-pill {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px var(--spacing-md);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
}

.stat-icon {
  font-size: 13px;
}

.stat-val {
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
}

.stat-lbl {
  color: var(--color-text-muted);
}

/* Taxonomy */
.taxonomy-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.taxonomy-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-wrap: wrap;
}

.taxonomy-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  width: 60px;
  flex-shrink: 0;
}

.taxonomy-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.genre-chip,
.tag-chip {
  font-size: 11px;
  padding: 3px 10px;
  border-radius: var(--radius-full);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.genre-chip {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid var(--color-primary-glow);
}

.genre-chip:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
}

.tag-chip {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

.tag-chip:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

/* CTA Actions */
.cta-actions {
  display: flex;
  gap: var(--spacing-md);
  flex-wrap: wrap;
  margin-top: var(--spacing-xs);
}

.btn-read-first {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  height: 44px;
  padding: 0 var(--spacing-xl);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
  text-decoration: none;
}

.btn-read-first:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
}

.btn-read-latest {
  display: inline-flex;
  align-items: center;
  height: 44px;
  padding: 0 var(--spacing-lg);
  background-color: var(--color-bg-card);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border-radius: var(--radius-md);
  transition: all var(--transition-fast);
  text-decoration: none;
}

.btn-read-latest:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.no-chapter-notice {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  padding: var(--spacing-xs) 0;
}

/* Detail Body */
.detail-body {
  padding-top: var(--spacing-2xl);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-2xl);
}

/* AI Summary Card */
.ai-summary-card {
  background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(56, 189, 248, 0.05) 100%);
  border: 1px solid rgba(6, 182, 212, 0.3);
  border-radius: var(--radius-xl);
  padding: var(--spacing-lg);
  position: relative;
  overflow: hidden;
}

.ai-header {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  margin-bottom: var(--spacing-xs);
}

.ai-sparkle-icon {
  font-size: 18px;
}

.ai-title-wrap {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-wrap: wrap;
}

.ai-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-info);
}

.ai-badge {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: var(--radius-full);
  background-color: var(--color-info-soft);
  color: var(--color-info);
  border: 1px solid rgba(6, 182, 212, 0.4);
  font-weight: var(--font-weight-medium);
}

.ai-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-primary);
  line-height: var(--line-height-relaxed);
}

/* Synopsis Section */
.synopsis-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.section-heading {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.synopsis-content {
  position: relative;
}

.synopsis-content.is-clamped {
  max-height: 120px;
  overflow: hidden;
  mask-image: linear-gradient(180deg, #000 60%, transparent 100%);
}

.synopsis-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  line-height: var(--line-height-relaxed);
  white-space: pre-line;
}

.btn-expand-desc {
  align-self: flex-start;
  font-size: var(--font-size-xs);
  color: var(--color-primary);
  font-weight: var(--font-weight-semibold);
  padding: var(--spacing-2xs) 0;
}

.btn-expand-desc:hover {
  text-decoration: underline;
}

/* Chapters Section */
.chapters-section {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.chapters-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: var(--spacing-xs);
  border-bottom: 1px solid var(--color-border);
}

.header-titles {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.chapters-count-badge {
  font-size: var(--font-size-xs);
  padding: 2px 8px;
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border-radius: var(--radius-full);
  border: 1px solid var(--color-border);
}

.btn-sort-chapters {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px var(--spacing-sm);
  background-color: var(--color-bg-card);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  transition: all var(--transition-fast);
}

.btn-sort-chapters:hover {
  color: var(--color-primary);
  border-color: var(--color-primary);
}

.sort-icon {
  font-size: 13px;
}

/* Empty Chapters */
.empty-chapters-card {
  padding: var(--spacing-2xl) var(--spacing-md);
  text-align: center;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--spacing-xs);
}

.empty-icon {
  font-size: 2.5rem;
}

.empty-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.empty-desc {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  max-width: 420px;
}

/* Chapters Grid */
.chapters-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--spacing-xs);
}

@media (min-width: 640px) {
  .chapters-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (min-width: 1024px) {
  .chapters-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.chapter-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: all var(--transition-fast);
  color: inherit;
  outline: none;
}

.chapter-card:hover {
  background-color: var(--color-bg-card-hover);
  border-color: var(--color-border-accent);
  transform: translateX(2px);
}

.chapter-card:focus-visible {
  box-shadow: inset 0 0 0 2px var(--color-primary);
}

.chapter-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
  padding-right: var(--spacing-xs);
}

.chapter-num-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.chapter-card-last-read {
  border-color: var(--color-primary);
  background-color: rgba(99, 102, 241, 0.08);
  box-shadow: 0 0 10px rgba(99, 102, 241, 0.15);
}

.badge-last-read {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  background-color: var(--color-primary);
  color: #ffffff;
  letter-spacing: 0.02em;
  line-height: 1.4;
}

.chapter-num {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.chapter-card:hover .chapter-num {
  color: var(--color-primary);
}

.chapter-name {
  font-size: 11px;
  color: var(--color-text-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chapter-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  flex-shrink: 0;
  font-size: 10px;
  color: var(--color-text-muted);
}
</style>
