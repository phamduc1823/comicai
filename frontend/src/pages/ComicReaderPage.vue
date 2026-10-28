<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { CatalogService } from '@/services/catalogService'
import { ReaderHistoryService } from '@/services/readerHistoryService'
import { getErrorMessage, isNotFoundError } from '@/services/httpClient'
import type { ChapterReader, ChapterSummary } from '@/types/catalog'
import { formatChapterNumber, buildChapterUrl, compareChapterNumbers } from '@/utils/chapter'

const route = useRoute()
const router = useRouter()

// Data states
const chapter = ref<ChapterReader | null>(null)
const isLoading = ref(true)
const isNotFound = ref(false)
const notFoundMessage = ref('')
const errorMessage = ref<string | null>(null)
const failedPages = ref<Record<number, boolean>>({})

// Chapter Selector State
const selectorRef = ref<HTMLElement | null>(null)
const dropdownRef = ref<HTMLElement | null>(null)
const isSelectorOpen = ref(false)
const chapterList = ref<ChapterSummary[]>([])
const cachedComicSlug = ref<string>('')
const isLoadingChapters = ref(false)
const chapterListError = ref<string | null>(null)

let latestRequestId = 0

// Sorted images by page_number ascending
const sortedImages = computed(() => {
  if (!chapter.value?.images || chapter.value.images.length === 0) return []
  return [...chapter.value.images].sort((a, b) => a.page_number - b.page_number)
})

// Sorted published chapter list for selector (ascending order)
const sortedChapterList = computed(() => {
  return [...chapterList.value].sort((a, b) =>
    compareChapterNumbers(a.chapter_number, b.chapter_number)
  )
})

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
 * Fetch chapter list for quick selector, with caching per comic slug.
 */
async function fetchChapterList(slug: string, force = false) {
  if (!slug) return
  if (!force && cachedComicSlug.value === slug && chapterList.value.length > 0) {
    return
  }

  isLoadingChapters.value = true
  chapterListError.value = null

  try {
    const response = await CatalogService.getChapters(slug)
    chapterList.value = response.data || []
    cachedComicSlug.value = slug
  } catch (error: unknown) {
    chapterListError.value = getErrorMessage(
      error,
      'Không thể tải danh sách chương truyện.'
    )
  } finally {
    isLoadingChapters.value = false
  }
}

/**
 * Check if a chapter number matches the current chapter being read.
 */
function isCurrentChapter(chapterNum: string | number): boolean {
  if (!chapter.value?.chapter_number) return false
  return compareChapterNumbers(chapterNum, chapter.value.chapter_number) === 0
}

/**
 * Toggle chapter selector dropdown
 */
function toggleSelector() {
  isSelectorOpen.value = !isSelectorOpen.value
  if (isSelectorOpen.value) {
    const slug = chapter.value?.comic?.slug || String(route.params.slug || '')
    if (slug) {
      fetchChapterList(slug)
    }
    // Scroll active item into view on open
    nextTick(() => {
      const activeEl = dropdownRef.value?.querySelector('.chapter-option-item.is-active')
      if (activeEl) {
        activeEl.scrollIntoView({ block: 'nearest' })
      }
    })
  }
}

/**
 * Select a chapter from the dropdown
 */
function onSelectChapter(chapterNum: string | number) {
  isSelectorOpen.value = false
  if (isCurrentChapter(chapterNum)) return
  if (!chapter.value?.comic?.slug) return
  navigateToChapter(chapterNum)
}

/**
 * Navigate to a specific chapter and instantly reset scroll
 */
function navigateToChapter(chapterNumber: string | number) {
  if (!chapter.value?.comic?.slug) return
  isSelectorOpen.value = false
  const targetUrl = buildChapterUrl(chapter.value.comic.slug, chapterNumber)
  router.push(targetUrl)
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

      // Instantly reset reader scroll position to top
      window.scrollTo(0, 0)

      // Update document title
      const numLabel = formatChapterNumber(response.data.chapter_number)
      const chapName = response.data.title ? ` - ${response.data.title}` : ''
      document.title = `Chương ${numLabel}${chapName} • ${response.data.comic.title} — BlueToon`

      // Save reading history for this comic upon verified successful load
      if (response.data?.comic?.slug && response.data?.chapter_number) {
        ReaderHistoryService.saveLastRead(
          response.data.comic.slug,
          response.data.chapter_number
        )
      }

      // Prefetch chapters list in background if not already cached for this comic
      if (cachedComicSlug.value !== response.data.comic.slug) {
        fetchChapterList(response.data.comic.slug)
      }
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

function handleClickOutside(event: MouseEvent) {
  if (
    isSelectorOpen.value &&
    selectorRef.value &&
    !selectorRef.value.contains(event.target as Node)
  ) {
    isSelectorOpen.value = false
  }
}

function handleKeyDown(event: KeyboardEvent) {
  // If dropdown is open and user presses Escape, close it
  if (isSelectorOpen.value && event.key === 'Escape') {
    isSelectorOpen.value = false
    return
  }

  // Ignore if user is interacting with form controls or contenteditable elements
  const target = event.target as HTMLElement | null
  const activeEl = document.activeElement as HTMLElement | null
  const activeTag = (activeEl?.tagName || '').toLowerCase()
  const targetTag = (target?.tagName || '').toLowerCase()

  const isInteractive =
    activeTag === 'input' ||
    activeTag === 'textarea' ||
    activeTag === 'select' ||
    targetTag === 'input' ||
    targetTag === 'textarea' ||
    targetTag === 'select' ||
    Boolean(activeEl?.isContentEditable) ||
    Boolean(target?.isContentEditable)

  if (isInteractive) {
    return
  }

  // If selector dropdown is open, do not trigger arrow navigation
  if (isSelectorOpen.value) {
    return
  }

  if (event.key === 'ArrowLeft' && chapter.value?.prev_chapter) {
    event.preventDefault()
    navigateToChapter(chapter.value.prev_chapter.chapter_number)
  } else if (event.key === 'ArrowRight' && chapter.value?.next_chapter) {
    event.preventDefault()
    navigateToChapter(chapter.value.next_chapter.chapter_number)
  }
}

// Watch for slug or chapterNumber route parameter changes
watch(
  () => [route.params.slug, route.params.chapterNumber],
  ([newSlug, newChapter]) => {
    if (newSlug && newChapter) {
      isSelectorOpen.value = false
      window.scrollTo(0, 0)
      loadChapterReader(String(newSlug), String(newChapter))
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
  window.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
  window.removeEventListener('click', handleClickOutside)
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

          <!-- Chapter Info & Quick Selector -->
          <div ref="selectorRef" class="top-chapter-selector-wrapper">
            <button
              type="button"
              class="chapter-selector-trigger"
              :class="{ 'is-open': isSelectorOpen }"
              :aria-expanded="isSelectorOpen"
              aria-haspopup="listbox"
              aria-label="Chọn chương nhanh"
              @click.stop="toggleSelector"
            >
              <span class="selector-icon" aria-hidden="true">📖</span>
              <span class="selector-current-label">
                Chương {{ formatChapterNumber(chapter.chapter_number) }}
              </span>
              <span v-if="chapter.title" class="selector-current-title">
                : {{ chapter.title }}
              </span>
              <span class="selector-chevron" aria-hidden="true">{{ isSelectorOpen ? '▲' : '▼' }}</span>
            </button>

            <!-- Dropdown Popover List -->
            <transition name="dropdown-fade">
              <div
                v-if="isSelectorOpen"
                ref="dropdownRef"
                class="chapter-dropdown-menu"
                role="listbox"
                :aria-label="'Danh sách chương ' + chapter.comic.title"
              >
                <div class="dropdown-header">
                  <span class="dropdown-header-title">Danh sách chương</span>
                  <span class="dropdown-header-count">({{ sortedChapterList.length }})</span>
                </div>

                <!-- Loading State -->
                <div v-if="isLoadingChapters" class="dropdown-state-item" role="status">
                  <span class="dropdown-state-icon" aria-hidden="true">⏳</span>
                  <span>Đang tải danh sách chương...</span>
                </div>

                <!-- Error State -->
                <div v-else-if="chapterListError" class="dropdown-state-item error" role="alert">
                  <span class="dropdown-state-icon" aria-hidden="true">⚠️</span>
                  <span>{{ chapterListError }}</span>
                  <button
                    type="button"
                    class="btn-retry-mini"
                    @click.stop="fetchChapterList(chapter.comic.slug, true)"
                  >
                    Thử lại
                  </button>
                </div>

                <!-- Empty State -->
                <div v-else-if="sortedChapterList.length === 0" class="dropdown-state-item">
                  <span class="dropdown-state-icon" aria-hidden="true">📭</span>
                  <span>Chưa có chương nào xuất bản.</span>
                </div>

                <!-- Chapter Options List -->
                <div v-else class="chapter-options-list">
                  <button
                    v-for="chapItem in sortedChapterList"
                    :key="chapItem.id"
                    type="button"
                    role="option"
                    :aria-selected="isCurrentChapter(chapItem.chapter_number)"
                    class="chapter-option-item"
                    :class="{ 'is-active': isCurrentChapter(chapItem.chapter_number) }"
                    @click="onSelectChapter(chapItem.chapter_number)"
                  >
                    <div class="option-left">
                      <span class="option-num">Chương {{ formatChapterNumber(chapItem.chapter_number) }}</span>
                      <span v-if="chapItem.title" class="option-title">{{ chapItem.title }}</span>
                    </div>
                    <span v-if="isCurrentChapter(chapItem.chapter_number)" class="option-badge">
                      Đang đọc
                    </span>
                  </button>
                </div>
              </div>
            </transition>
          </div>

          <!-- Top Navigation Controls -->
          <nav class="top-nav-controls" aria-label="Điều hướng chương truyện">
            <!-- Prev Chapter Link -->
            <RouterLink
              v-if="chapter.prev_chapter"
              :to="buildChapterUrl(chapter.comic.slug, chapter.prev_chapter.chapter_number)"
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
              :to="buildChapterUrl(chapter.comic.slug, chapter.next_chapter.chapter_number)"
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
            v-for="img in sortedImages"
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
                :to="buildChapterUrl(chapter.comic.slug, chapter.prev_chapter.chapter_number)"
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
                :to="buildChapterUrl(chapter.comic.slug, chapter.next_chapter.chapter_number)"
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

/* Chapter Selector in Top Bar */
.top-chapter-selector-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  min-width: 0;
  padding: 0 var(--spacing-xs);
}

.chapter-selector-trigger {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  height: 32px;
  padding: 0 var(--spacing-sm);
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-family: inherit;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.chapter-selector-trigger:hover,
.chapter-selector-trigger.is-open {
  border-color: var(--color-primary);
  background-color: var(--color-bg-elevated);
}

.selector-icon {
  font-size: 13px;
  flex-shrink: 0;
}

.selector-current-label {
  white-space: nowrap;
  flex-shrink: 0;
}

.selector-current-title {
  color: var(--color-text-secondary);
  font-weight: var(--font-weight-normal);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.selector-chevron {
  font-size: 8px;
  color: var(--color-text-muted);
  flex-shrink: 0;
  margin-left: 2px;
}

/* Dropdown Menu */
.chapter-dropdown-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  width: 320px;
  max-width: 90vw;
  max-height: 360px;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg), 0 0 20px rgba(0, 0, 0, 0.4);
  z-index: 50;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.dropdown-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-xs) var(--spacing-sm);
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  font-weight: var(--font-weight-semibold);
}

.dropdown-header-count {
  font-size: 11px;
  color: var(--color-text-muted);
}

.dropdown-state-item {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: var(--spacing-lg) var(--spacing-sm);
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  text-align: center;
}

.dropdown-state-item.error {
  color: var(--color-error);
  flex-direction: column;
}

.btn-retry-mini {
  display: inline-flex;
  align-items: center;
  height: 26px;
  padding: 0 10px;
  border-radius: var(--radius-sm);
  background: var(--color-primary);
  color: #fff;
  border: none;
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
  margin-top: 4px;
}

.chapter-options-list {
  overflow-y: auto;
  max-height: 300px;
  padding: 4px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.chapter-option-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 8px 10px;
  background: transparent;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--color-text-primary);
  font-family: inherit;
  font-size: var(--font-size-xs);
  text-align: left;
  cursor: pointer;
  transition: background var(--transition-fast);
}

.chapter-option-item:hover {
  background-color: var(--color-bg-elevated);
  color: var(--color-primary);
}

.chapter-option-item.is-active {
  background-color: rgba(99, 102, 241, 0.12);
  color: var(--color-primary);
  font-weight: var(--font-weight-semibold);
}

.option-left {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.option-num {
  font-weight: var(--font-weight-medium);
}

.option-title {
  font-size: 11px;
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.option-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  background-color: var(--color-primary);
  color: #ffffff;
  flex-shrink: 0;
  margin-left: 8px;
}

/* Dropdown Animation */
.dropdown-fade-enter-active,
.dropdown-fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.dropdown-fade-enter-from,
.dropdown-fade-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(-6px);
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
    max-width: 90px;
  }
  .selector-current-title {
    display: none;
  }
  .chapter-dropdown-menu {
    width: calc(100vw - 20px);
    max-width: 320px;
  }
  .bottom-actions {
    flex-direction: column;
  }
  .bottom-btn {
    width: 100%;
  }
}
</style>
