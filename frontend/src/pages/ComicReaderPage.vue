<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage, isNotFoundError } from '@/services/httpClient'
import type { ChapterReader } from '@/types/catalog'

const route = useRoute()
const router = useRouter()

// Data states
const chapter = ref<ChapterReader | null>(null)
const isLoading = ref(true)
const isNotFound = ref(false)
const notFoundMessage = ref('')
const errorMessage = ref<string | null>(null)
const failedPages = ref<Record<number, boolean>>({})

let latestRequestId = 0

// Formatting helpers
function formatChapterNumber(rawNumber: string): string {
  const parsed = parseFloat(rawNumber)
  if (!isNaN(parsed)) {
    return Number.isInteger(parsed) ? parsed.toString() : parsed.toFixed(2)
  }
  return rawNumber
}

// Display chapter title label
const chapterDisplayTitle = computed(() => {
  if (!chapter.value) return ''
  const numStr = `Chương ${formatChapterNumber(chapter.value.chapter_number)}`
  return chapter.value.title ? `${numStr}: ${chapter.value.title}` : numStr
})

function handlePageError(imageId: number) {
  failedPages.value[imageId] = true
}

/**
 * Fetch chapter reader view data by slug and canonical chapter number string.
 */
async function loadChapterReader(slug: string, chapterNumber: string) {
  const requestId = ++latestRequestId
  isLoading.value = true
  errorMessage.value = null
  isNotFound.value = false
  notFoundMessage.value = ''
  failedPages.value = {}

  try {
    const response = await CatalogService.getChapterReader(slug, chapterNumber)
    if (requestId === latestRequestId) {
      chapter.value = response.data

      // Update document title
      const numLabel = formatChapterNumber(response.data.chapter_number)
      const chapName = response.data.title ? ` - ${response.data.title}` : ''
      document.title = `Chương ${numLabel}${chapName} • ${response.data.comic.title} — BlueToon`
    }
  } catch (error: unknown) {
    if (requestId === latestRequestId) {
      if (isNotFoundError(error)) {
        isNotFound.value = true
        notFoundMessage.value = getErrorMessage(
          error,
          'Chương truyện không tồn tại hoặc chưa được phát hành công khai.'
        )
      } else {
        errorMessage.value = getErrorMessage(
          error,
          'Không thể tải nội dung chương truyện. Vui lòng thử lại sau.'
        )
      }
    }
  } finally {
    if (requestId === latestRequestId) {
      isLoading.value = false
    }
  }
}

function handleRetry() {
  const slug = String(route.params.slug || '')
  const chapterNumber = String(route.params.chapterNumber || '')
  if (slug && chapterNumber) {
    loadChapterReader(slug, chapterNumber)
  }
}

function handleKeyDown(event: KeyboardEvent) {
  // Ignore if user is interacting with form controls
  const activeTag = (document.activeElement?.tagName || '').toLowerCase()
  if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select') {
    return
  }

  if (event.key === 'ArrowLeft' && chapter.value?.prev_chapter) {
    router.push(
      `/comics/${chapter.value.comic.slug}/chapters/${chapter.value.prev_chapter.chapter_number}`
    )
  } else if (event.key === 'ArrowRight' && chapter.value?.next_chapter) {
    router.push(
      `/comics/${chapter.value.comic.slug}/chapters/${chapter.value.next_chapter.chapter_number}`
    )
  }
}

// Watch for slug or chapterNumber route parameter changes
watch(
  () => [route.params.slug, route.params.chapterNumber],
  ([newSlug, newChapter]) => {
    if (newSlug && newChapter) {
      loadChapterReader(String(newSlug), String(newChapter))
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }
)

onMounted(() => {
  const slug = String(route.params.slug || '')
  const chapterNumber = String(route.params.chapterNumber || '')
  if (slug && chapterNumber) {
    loadChapterReader(slug, chapterNumber)
  }
  window.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
})
</script>

<template>
  <div class="comic-reader-page">
    <!-- 404 Not Found State -->
    <div v-if="isNotFound" class="container reader-state-container not-found-state" role="alert">
      <span class="state-icon" aria-hidden="true">🚫</span>
      <h1 class="state-title">Chương truyện không tồn tại</h1>
      <p class="state-desc">
        {{ notFoundMessage || 'Chương truyện này không tồn tại hoặc chưa được xuất bản công khai.' }}
      </p>
      <div class="state-actions">
        <RouterLink
          v-if="route.params.slug"
          :to="'/comics/' + route.params.slug"
          class="btn-primary-action"
        >
          ← Xem chi tiết truyện
        </RouterLink>
        <RouterLink to="/comics" class="btn-secondary-action">
          Danh mục truyện
        </RouterLink>
      </div>
    </div>

    <!-- Generic Error State -->
    <div v-else-if="errorMessage" class="container reader-state-container error-state" role="alert">
      <span class="state-icon" aria-hidden="true">⚠️</span>
      <h1 class="state-title">Lỗi tải chương truyện</h1>
      <p class="state-desc">{{ errorMessage }}</p>
      <div class="state-actions">
        <button
          type="button"
          class="btn-primary-action"
          aria-label="Thử tải lại chương truyện"
          @click="handleRetry"
        >
          Thử lại
        </button>
        <RouterLink
          v-if="route.params.slug"
          :to="'/comics/' + route.params.slug"
          class="btn-secondary-action"
        >
          Về trang truyện
        </RouterLink>
      </div>
    </div>

    <!-- Loading Skeleton State -->
    <div v-else-if="isLoading" class="container reader-skeleton-container" aria-busy="true" aria-label="Đang tải chương truyện">
      <div class="skeleton-top-bar">
        <div class="skeleton-pill"></div>
        <div class="skeleton-title"></div>
        <div class="skeleton-nav"></div>
      </div>
      <div class="skeleton-pages">
        <div v-for="n in 3" :key="'page-skel-' + n" class="skeleton-page-block"></div>
      </div>
    </div>

    <!-- Reader Content View -->
    <div v-else-if="chapter" class="reader-view">
      <!-- Sticky Top Navigation Bar -->
      <header class="reader-top-bar">
        <div class="container top-bar-container">
          <!-- Back Link to Comic Detail -->
          <RouterLink
            :to="'/comics/' + chapter.comic.slug"
            class="top-back-link"
            :aria-label="'Quay về trang truyện ' + chapter.comic.title"
          >
            <span class="back-arrow" aria-hidden="true">‹</span>
            <span class="back-text">{{ chapter.comic.title }}</span>
          </RouterLink>

          <!-- Chapter Info -->
          <div class="top-chapter-info">
            <h1 class="top-chapter-title" :title="chapterDisplayTitle">
              {{ chapterDisplayTitle }}
            </h1>
          </div>

          <!-- Top Navigation Controls -->
          <nav class="top-nav-controls" aria-label="Điều hướng chương truyện">
            <!-- Prev Chapter Link -->
            <RouterLink
              v-if="chapter.prev_chapter"
              :to="'/comics/' + chapter.comic.slug + '/chapters/' + chapter.prev_chapter.chapter_number"
              class="nav-btn btn-prev"
              :aria-label="'Chương trước: Chương ' + formatChapterNumber(chapter.prev_chapter.chapter_number)"
              title="Chương trước (Phím mũi tên trái ←)"
            >
              ‹ Trước
            </RouterLink>
            <span v-else class="nav-btn btn-disabled" aria-disabled="true">
              ‹ Trước
            </span>

            <!-- Next Chapter Link -->
            <RouterLink
              v-if="chapter.next_chapter"
              :to="'/comics/' + chapter.comic.slug + '/chapters/' + chapter.next_chapter.chapter_number"
              class="nav-btn btn-next active"
              :aria-label="'Chương tiếp theo: Chương ' + formatChapterNumber(chapter.next_chapter.chapter_number)"
              title="Chương sau (Phím mũi tên phải →)"
            >
              Tiếp ›
            </RouterLink>
            <span v-else class="nav-btn btn-disabled" aria-disabled="true">
              Tiếp ›
            </span>
          </nav>
        </div>
      </header>

      <!-- Main Reading Area (Vertical Scroll) -->
      <main class="reader-canvas" aria-label="Nội dung trang truyện">
        <!-- Empty Pages Notice -->
        <div v-if="!chapter.images || chapter.images.length === 0" class="empty-pages-notice">
          <span class="empty-icon" aria-hidden="true">🖼️</span>
          <h2 class="empty-title">Chương này chưa có hình ảnh</h2>
          <p class="empty-desc">
            Nội dung hình ảnh của chương truyện đang được cập nhật. Bạn có thể chuyển sang chương tiếp theo để tiếp tục đọc nhé!
          </p>
        </div>

        <!-- Sequential Reading Pages -->
        <div v-else class="pages-wrapper">
          <div
            v-for="img in chapter.images"
            :key="img.id"
            class="reading-page-block"
          >
            <img
              v-if="img.image_url && !failedPages[img.id]"
              :src="img.image_url"
              :alt="'Trang ' + img.page_number"
              class="page-image"
              loading="lazy"
              @error="handlePageError(img.id)"
            />
            <!-- Fallback when image fails to load -->
            <div v-else class="page-fallback-block" :aria-label="'Lỗi tải trang ' + img.page_number">
              <span class="page-fallback-icon" aria-hidden="true">⚠️</span>
              <span class="page-fallback-title">Không thể tải trang {{ img.page_number }}</span>
              <span class="page-fallback-sub">Vui lòng kiểm tra kết nối mạng hoặc thử lại sau</span>
            </div>
          </div>
        </div>
      </main>

      <!-- Bottom Navigation Footer Bar -->
      <footer class="reader-bottom-bar" aria-label="Điều hướng cuối chương">
        <div class="container bottom-bar-container">
          <div class="chapter-end-card">
            <span class="end-icon" aria-hidden="true">🎉</span>
            <h2 class="end-title">Bạn đã đọc hết {{ chapterDisplayTitle }}</h2>
            <p class="end-subtitle">
              Sử dụng các phím mũi tên ← / → trên bàn phím để chuyển chương nhanh
            </p>

            <div class="bottom-actions">
              <!-- Prev Chapter -->
              <RouterLink
                v-if="chapter.prev_chapter"
                :to="'/comics/' + chapter.comic.slug + '/chapters/' + chapter.prev_chapter.chapter_number"
                class="bottom-btn btn-prev-large"
                :aria-label="'Đọc Chương ' + formatChapterNumber(chapter.prev_chapter.chapter_number)"
              >
                ‹ Chương trước
              </RouterLink>

              <!-- Return to Comic Detail / Table of Contents -->
              <RouterLink
                :to="'/comics/' + chapter.comic.slug"
                class="bottom-btn btn-catalog-return"
                :aria-label="'Quay về mục lục truyện ' + chapter.comic.title"
              >
                📑 Mục lục chương
              </RouterLink>

              <!-- Next Chapter -->
              <RouterLink
                v-if="chapter.next_chapter"
                :to="'/comics/' + chapter.comic.slug + '/chapters/' + chapter.next_chapter.chapter_number"
                class="bottom-btn btn-next-large"
                :aria-label="'Đọc tiếp Chương ' + formatChapterNumber(chapter.next_chapter.chapter_number)"
              >
                Chương tiếp theo ›
              </RouterLink>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.comic-reader-page {
  background-color: #060911;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* State Containers (404, Error) */
.reader-state-container {
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
  max-width: 500px;
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
  height: 42px;
  padding: 0 var(--spacing-xl);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
  text-decoration: none;
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
  text-decoration: none;
}

.btn-secondary-action:hover {
  border-color: var(--color-primary);
}

/* Skeleton Loading */
.reader-skeleton-container {
  padding-top: var(--spacing-xl);
  max-width: 820px;
}

.skeleton-top-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--spacing-md);
  background-color: var(--color-bg-surface);
  border-radius: var(--radius-lg);
  margin-bottom: var(--spacing-lg);
  border: 1px solid var(--color-border);
}

.skeleton-pill {
  width: 140px;
  height: 20px;
  border-radius: var(--radius-sm);
  background-color: var(--color-bg-elevated);
}

.skeleton-title {
  width: 220px;
  height: 24px;
  border-radius: var(--radius-sm);
  background-color: var(--color-bg-elevated);
}

.skeleton-nav {
  width: 120px;
  height: 32px;
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
}

.skeleton-pages {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.skeleton-page-block {
  width: 100%;
  aspect-ratio: 9 / 14;
  background: linear-gradient(90deg, var(--color-bg-surface) 25%, var(--color-bg-card) 50%, var(--color-bg-surface) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
  border-radius: var(--radius-md);
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* Sticky Top Navigation Bar */
.reader-top-bar {
  position: sticky;
  top: 64px; /* below app-header */
  z-index: 40;
  background-color: rgba(11, 15, 25, 0.92);
  border-bottom: 1px solid var(--color-border);
  backdrop-filter: blur(12px);
  padding: 8px 0;
}

.top-bar-container {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-sm);
}

.top-back-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  transition: color var(--transition-fast);
  flex-shrink: 0;
}

.top-back-link:hover {
  color: var(--color-primary);
}

.back-arrow {
  font-size: var(--font-size-base);
}

.back-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.top-chapter-info {
  flex: 1;
  text-align: center;
  padding: 0 var(--spacing-xs);
}

.top-chapter-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.top-nav-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.nav-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 32px;
  padding: 0 var(--spacing-sm);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-prev {
  background-color: var(--color-bg-card);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

.btn-prev:hover {
  color: var(--color-primary);
  border-color: var(--color-primary);
}

.btn-next.active {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-weight: var(--font-weight-semibold);
  box-shadow: var(--shadow-sm);
}

.btn-next.active:hover {
  background-color: var(--color-primary-hover);
}

.btn-disabled {
  background-color: rgba(31, 41, 55, 0.4);
  color: var(--color-text-muted);
  border: 1px solid transparent;
  cursor: not-allowed;
  opacity: 0.5;
}

/* Reader Canvas */
.reader-canvas {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: var(--spacing-lg) 0;
}

.empty-pages-notice {
  max-width: 520px;
  margin: var(--spacing-3xl) auto;
  text-align: center;
  padding: var(--spacing-2xl) var(--spacing-md);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
}

.empty-icon {
  font-size: 3.5rem;
  margin-bottom: var(--spacing-xs);
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: 4px;
}

.empty-desc {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  line-height: var(--line-height-relaxed);
}

/* Sequential Pages Wrapper */
.pages-wrapper {
  width: 100%;
  max-width: 820px;
  display: flex;
  flex-direction: column;
  align-items: center;
  margin: 0 auto;
}

.reading-page-block {
  width: 100%;
  display: flex;
  justify-content: center;
  background-color: #0b0f19;
}

.page-image {
  width: 100%;
  height: auto;
  display: block;
  object-fit: contain;
}

.page-fallback-block {
  width: 100%;
  aspect-ratio: 9 / 13;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background-color: var(--color-bg-card);
  border: 1px dashed var(--color-border);
  padding: var(--spacing-lg);
  text-align: center;
  gap: var(--spacing-2xs);
}

.page-fallback-icon {
  font-size: 2rem;
  margin-bottom: 4px;
}

.page-fallback-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.page-fallback-sub {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* Bottom Bar */
.reader-bottom-bar {
  background: linear-gradient(180deg, transparent 0%, #060911 100%);
  padding: var(--spacing-2xl) 0 var(--spacing-3xl);
  border-top: 1px solid var(--color-border);
  margin-top: var(--spacing-xl);
}

.chapter-end-card {
  max-width: 600px;
  margin: 0 auto;
  text-align: center;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--spacing-xl) var(--spacing-md);
  display: flex;
  flex-direction: column;
  align-items: center;
  box-shadow: var(--shadow-md);
}

.end-icon {
  font-size: 2.5rem;
  margin-bottom: var(--spacing-2xs);
}

.end-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: 2px;
}

.end-subtitle {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-bottom: var(--spacing-lg);
}

.bottom-actions {
  display: flex;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
  justify-content: center;
  width: 100%;
}

.bottom-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 42px;
  padding: 0 var(--spacing-lg);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-prev-large {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
}

.btn-prev-large:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.btn-catalog-return {
  background-color: var(--color-bg-surface);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

.btn-catalog-return:hover {
  color: var(--color-text-primary);
  border-color: var(--color-text-secondary);
}

.btn-next-large {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  box-shadow: var(--shadow-glow);
}

.btn-next-large:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
}

@media (max-width: 640px) {
  .top-back-link {
    max-width: 120px;
  }
  .bottom-actions {
    flex-direction: column;
  }
  .bottom-btn {
    width: 100%;
  }
}
</style>
