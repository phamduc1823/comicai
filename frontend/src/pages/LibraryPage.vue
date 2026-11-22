<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import ComicCard from '@/components/ComicCard.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import { useAuthStore } from '@/stores/auth'
import { UserLibraryService } from '@/services/userLibraryService'
import { ReadingHistoryService } from '@/services/readingHistoryService'
import {
  getErrorMessage,
  isUnauthorizedError,
} from '@/services/httpClient'
import type { ComicListItem, PaginationMeta } from '@/types/catalog'
import type { ReadingHistoryItem } from '@/types/readingHistory'
import { formatChapterNumber, buildChapterUrl } from '@/utils/chapter'

type LibraryTab = 'follows' | 'bookmarks' | 'history'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const isComponentMounted = ref(true)

// Active tab selection
const activeTab = ref<LibraryTab>(
  route.query.tab === 'bookmarks'
    ? 'bookmarks'
    : route.query.tab === 'history'
    ? 'history'
    : 'follows'
)

// Follows list state
const followsComics = ref<ComicListItem[]>([])
const followsMeta = ref<PaginationMeta | null>(null)
const isFollowsLoading = ref(false)
const followsError = ref<string | null>(null)
const followsCurrentPage = ref(1)
const followsLoaded = ref(false)
let latestFollowsRequestId = 0

// Bookmarks list state
const bookmarksComics = ref<ComicListItem[]>([])
const bookmarksMeta = ref<PaginationMeta | null>(null)
const isBookmarksLoading = ref(false)
const bookmarksError = ref<string | null>(null)
const bookmarksCurrentPage = ref(1)
const bookmarksLoaded = ref(false)
let latestBookmarksRequestId = 0

// Reading History list state
const historyItems = ref<ReadingHistoryItem[]>([])
const historyMeta = ref<PaginationMeta | null>(null)
const isHistoryLoading = ref(false)
const historyError = ref<string | null>(null)
const historyCurrentPage = ref(1)
const historyLoaded = ref(false)
let latestHistoryRequestId = 0

// Deletion & Notice state
const deletingComicId = ref<number | null>(null)
const isClearModalOpen = ref(false)
const isClearingHistory = ref(false)
const actionNotice = ref<{ type: 'success' | 'error'; message: string } | null>(null)
let noticeTimeoutId: ReturnType<typeof setTimeout> | null = null

function showActionNotice(type: 'success' | 'error', message: string) {
  if (noticeTimeoutId) {
    clearTimeout(noticeTimeoutId)
    noticeTimeoutId = null
  }
  actionNotice.value = { type, message }
  noticeTimeoutId = setTimeout(() => {
    actionNotice.value = null
    noticeTimeoutId = null
  }, 4000)
}

// Active tab computed helpers
const currentLoading = computed(() => {
  if (activeTab.value === 'follows') return isFollowsLoading.value
  if (activeTab.value === 'bookmarks') return isBookmarksLoading.value
  return isHistoryLoading.value
})

const currentError = computed(() => {
  if (activeTab.value === 'follows') return followsError.value
  if (activeTab.value === 'bookmarks') return bookmarksError.value
  return historyError.value
})

const currentComics = computed(() => {
  return activeTab.value === 'follows' ? followsComics.value : bookmarksComics.value
})

const currentMeta = computed(() => {
  if (activeTab.value === 'follows') return followsMeta.value
  if (activeTab.value === 'bookmarks') return bookmarksMeta.value
  return historyMeta.value
})

const currentItemsCount = computed(() => {
  if (activeTab.value === 'follows') return followsComics.value.length
  if (activeTab.value === 'bookmarks') return bookmarksComics.value.length
  return historyItems.value.length
})

/**
 * Format relative time for reading history timestamps.
 */
function formatRelativeTime(isoString: string | null | undefined): string {
  if (!isoString) return ''
  const date = new Date(isoString)
  if (isNaN(date.getTime())) return ''
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return 'Vừa xong'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} phút trước`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} giờ trước`
  if (diffInSeconds < 2592000) return `${Math.floor(diffInSeconds / 86400)} ngày trước`
  return date.toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

/**
 * Fetch followed comics with race-condition, stale request, and user identity protection.
 */
async function fetchFollows(page = 1) {
  const requestId = ++latestFollowsRequestId
  const initiatedUserId = authStore.user?.id ?? null
  isFollowsLoading.value = true
  followsError.value = null
  followsCurrentPage.value = page

  try {
    const res = await UserLibraryService.getFollows({ page, per_page: 15 })
    if (
      isComponentMounted.value &&
      requestId === latestFollowsRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      followsComics.value = res.data || []
      followsMeta.value = res.meta || null
      followsLoaded.value = true
    }
  } catch (err: unknown) {
    if (
      isComponentMounted.value &&
      requestId === latestFollowsRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      if (isUnauthorizedError(err)) {
        router.push({
          path: '/login',
          query: { redirect: route.fullPath },
        })
        return
      }
      followsError.value = getErrorMessage(
        err,
        'Không thể tải danh sách truyện đang theo dõi. Vui lòng thử lại sau.'
      )
    }
  } finally {
    if (
      isComponentMounted.value &&
      requestId === latestFollowsRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      isFollowsLoading.value = false
    }
  }
}

/**
 * Fetch bookmarked comics with race-condition, stale request, and user identity protection.
 */
async function fetchBookmarks(page = 1) {
  const requestId = ++latestBookmarksRequestId
  const initiatedUserId = authStore.user?.id ?? null
  isBookmarksLoading.value = true
  bookmarksError.value = null
  bookmarksCurrentPage.value = page

  try {
    const res = await UserLibraryService.getBookmarks({ page, per_page: 15 })
    if (
      isComponentMounted.value &&
      requestId === latestBookmarksRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      bookmarksComics.value = res.data || []
      bookmarksMeta.value = res.meta || null
      bookmarksLoaded.value = true
    }
  } catch (err: unknown) {
    if (
      isComponentMounted.value &&
      requestId === latestBookmarksRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      if (isUnauthorizedError(err)) {
        router.push({
          path: '/login',
          query: { redirect: route.fullPath },
        })
        return
      }
      bookmarksError.value = getErrorMessage(
        err,
        'Không thể tải danh sách truyện đã lưu. Vui lòng thử lại sau.'
      )
    }
  } finally {
    if (
      isComponentMounted.value &&
      requestId === latestBookmarksRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      isBookmarksLoading.value = false
    }
  }
}

/**
 * Fetch paginated reading history from Backend with race-condition, stale request, and user identity protection.
 */
async function fetchHistory(page = 1) {
  const requestId = ++latestHistoryRequestId
  const initiatedUserId = authStore.user?.id ?? null
  isHistoryLoading.value = true
  historyError.value = null
  historyCurrentPage.value = page

  try {
    const res = await ReadingHistoryService.getReadingHistory({ page, per_page: 15 })
    if (
      isComponentMounted.value &&
      requestId === latestHistoryRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      historyItems.value = res.data || []
      historyMeta.value = res.meta || null
      historyLoaded.value = true
    }
  } catch (err: unknown) {
    if (
      isComponentMounted.value &&
      requestId === latestHistoryRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      if (isUnauthorizedError(err)) {
        router.push({
          path: '/login',
          query: { redirect: route.fullPath },
        })
        return
      }
      historyError.value = getErrorMessage(
        err,
        'Không thể tải lịch sử đọc truyện. Vui lòng thử lại sau.'
      )
    }
  } finally {
    if (
      isComponentMounted.value &&
      requestId === latestHistoryRequestId &&
      authStore.user?.id === initiatedUserId
    ) {
      isHistoryLoading.value = false
    }
  }
}

/**
 * Delete a single comic reading history entry.
 */
async function handleDeleteHistoryItem(comicId: number) {
  if (deletingComicId.value !== null) return
  deletingComicId.value = comicId

  try {
    await ReadingHistoryService.deleteReadingHistory(comicId)
    historyItems.value = historyItems.value.filter((item) => item.comic.id !== comicId)
    if (historyMeta.value) {
      historyMeta.value.total = Math.max(0, historyMeta.value.total - 1)
    }
    showActionNotice('success', 'Đã xóa truyện khỏi lịch sử đọc.')
    if (historyItems.value.length === 0 && historyCurrentPage.value > 1) {
      fetchHistory(historyCurrentPage.value - 1)
    } else if (historyItems.value.length === 0) {
      fetchHistory(1)
    }
  } catch (err: unknown) {
    showActionNotice(
      'error',
      getErrorMessage(err, 'Không thể xóa lịch sử đọc của truyện này.')
    )
  } finally {
    deletingComicId.value = null
  }
}

function openClearAllModal() {
  isClearModalOpen.value = true
}

function closeClearAllModal() {
  if (isClearingHistory.value) return
  isClearModalOpen.value = false
}

/**
 * Confirm and clear all reading history for authenticated user.
 */
async function handleConfirmClearAll() {
  if (isClearingHistory.value) return
  isClearingHistory.value = true

  try {
    await ReadingHistoryService.clearReadingHistory()
    historyItems.value = []
    if (historyMeta.value) {
      historyMeta.value.total = 0
      historyMeta.value.last_page = 1
    }
    isClearModalOpen.value = false
    showActionNotice('success', 'Đã xóa toàn bộ lịch sử đọc thành công.')
    fetchHistory(1)
  } catch (err: unknown) {
    showActionNotice(
      'error',
      getErrorMessage(err, 'Không thể xóa toàn bộ lịch sử đọc. Vui lòng thử lại.')
    )
  } finally {
    isClearingHistory.value = false
  }
}

/**
 * Switch active library tab and synchronize with URL query.
 */
function switchTab(tab: LibraryTab) {
  if (activeTab.value === tab) return
  activeTab.value = tab

  router.replace({
    query: {
      ...route.query,
      tab: tab === 'follows' ? undefined : tab,
    },
  })

  if (tab === 'follows' && !followsLoaded.value) {
    fetchFollows(followsCurrentPage.value)
  } else if (tab === 'bookmarks' && !bookmarksLoaded.value) {
    fetchBookmarks(bookmarksCurrentPage.value)
  } else if (tab === 'history' && !historyLoaded.value) {
    fetchHistory(historyCurrentPage.value)
  }
}

/**
 * Manual refresh for currently active tab.
 */
function handleRefresh() {
  if (activeTab.value === 'follows') {
    fetchFollows(followsCurrentPage.value)
  } else if (activeTab.value === 'bookmarks') {
    fetchBookmarks(bookmarksCurrentPage.value)
  } else {
    fetchHistory(historyCurrentPage.value)
  }
}

/**
 * Pagination handler.
 */
function handlePageChange(newPage: number) {
  if (activeTab.value === 'follows') {
    if (newPage < 1 || (followsMeta.value && newPage > followsMeta.value.last_page)) return
    fetchFollows(newPage)
  } else if (activeTab.value === 'bookmarks') {
    if (newPage < 1 || (bookmarksMeta.value && newPage > bookmarksMeta.value.last_page)) return
    fetchBookmarks(newPage)
  } else {
    if (newPage < 1 || (historyMeta.value && newPage > historyMeta.value.last_page)) return
    fetchHistory(newPage)
  }
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function handleRetry() {
  if (activeTab.value === 'follows') {
    fetchFollows(followsCurrentPage.value)
  } else if (activeTab.value === 'bookmarks') {
    fetchBookmarks(bookmarksCurrentPage.value)
  } else {
    fetchHistory(historyCurrentPage.value)
  }
}

// Watch for route query changes (e.g. browser Back / Forward)
watch(
  () => route.query.tab,
  (newTab) => {
    const targetTab: LibraryTab =
      newTab === 'bookmarks' ? 'bookmarks' : newTab === 'history' ? 'history' : 'follows'
    if (activeTab.value !== targetTab) {
      activeTab.value = targetTab
      if (targetTab === 'follows' && !followsLoaded.value) {
        fetchFollows(followsCurrentPage.value)
      } else if (targetTab === 'bookmarks' && !bookmarksLoaded.value) {
        fetchBookmarks(bookmarksCurrentPage.value)
      } else if (targetTab === 'history' && !historyLoaded.value) {
        fetchHistory(historyCurrentPage.value)
      }
    }
  }
)

// Watch user account changes: invalidate in-flight requests, clear previous user data and re-fetch for new account
watch(
  () => authStore.user?.id,
  (newUserId, oldUserId) => {
    if (newUserId !== oldUserId) {
      // Invalidate all pending asynchronous requests across tabs
      latestFollowsRequestId++
      latestBookmarksRequestId++
      latestHistoryRequestId++

      // Reset data and states of previous account
      followsComics.value = []
      followsMeta.value = null
      followsLoaded.value = false
      followsCurrentPage.value = 1
      isFollowsLoading.value = false
      followsError.value = null

      bookmarksComics.value = []
      bookmarksMeta.value = null
      bookmarksLoaded.value = false
      bookmarksCurrentPage.value = 1
      isBookmarksLoading.value = false
      bookmarksError.value = null

      historyItems.value = []
      historyMeta.value = null
      historyLoaded.value = false
      historyCurrentPage.value = 1
      isHistoryLoading.value = false
      historyError.value = null

      if (authStore.isAuthenticated) {
        if (activeTab.value === 'follows') {
          fetchFollows(1)
        } else if (activeTab.value === 'bookmarks') {
          fetchBookmarks(1)
        } else {
          fetchHistory(1)
        }
      }
    }
  }
)

onMounted(() => {
  if (route.query.tab === 'bookmarks') {
    activeTab.value = 'bookmarks'
    fetchBookmarks(1)
  } else if (route.query.tab === 'history') {
    activeTab.value = 'history'
    fetchHistory(1)
  } else {
    activeTab.value = 'follows'
    fetchFollows(1)
  }
})

onUnmounted(() => {
  isComponentMounted.value = false
  latestFollowsRequestId++
  latestBookmarksRequestId++
  latestHistoryRequestId++
  if (noticeTimeoutId) {
    clearTimeout(noticeTimeoutId)
    noticeTimeoutId = null
  }
})
</script>

<template>
  <div class="library-page">
    <!-- Hero / Header Section -->
    <section class="library-hero" aria-label="Đầu trang Tủ sách">
      <div class="container hero-content">
        <div class="hero-titles">
          <div class="title-with-icon">
            <span class="hero-icon" aria-hidden="true">📚</span>
            <h1 class="page-title">Tủ sách của tôi</h1>
          </div>
          <p class="page-subtitle">Quản lý những bộ truyện bạn đang theo dõi, đã lưu và lịch sử đọc</p>
        </div>

        <div class="hero-actions">
          <button
            type="button"
            class="btn-refresh"
            :disabled="currentLoading"
            aria-label="Tải lại danh sách truyện"
            @click="handleRefresh"
          >
            <span class="refresh-icon" :class="{ 'is-spinning': currentLoading }" aria-hidden="true">↻</span>
            <span>Làm mới</span>
          </button>
        </div>
      </div>
    </section>

    <!-- Tabs Navigation Bar -->
    <nav class="library-tabs-nav" aria-label="Chọn danh mục tủ sách">
      <div class="container tabs-container" role="tablist">
        <!-- Follows Tab -->
        <button
          type="button"
          class="tab-btn"
          :class="{ 'is-active': activeTab === 'follows' }"
          :aria-selected="activeTab === 'follows'"
          role="tab"
          @click="switchTab('follows')"
        >
          <span class="tab-icon" aria-hidden="true">⭐</span>
          <span class="tab-label">Đang theo dõi</span>
          <span v-if="followsMeta" class="tab-count-badge">
            {{ followsMeta.total }}
          </span>
        </button>

        <!-- Bookmarks Tab -->
        <button
          type="button"
          class="tab-btn"
          :class="{ 'is-active': activeTab === 'bookmarks' }"
          :aria-selected="activeTab === 'bookmarks'"
          role="tab"
          @click="switchTab('bookmarks')"
        >
          <span class="tab-icon" aria-hidden="true">🔖</span>
          <span class="tab-label">Đã lưu</span>
          <span v-if="bookmarksMeta" class="tab-count-badge">
            {{ bookmarksMeta.total }}
          </span>
        </button>

        <!-- Reading History Tab -->
        <button
          type="button"
          class="tab-btn"
          :class="{ 'is-active': activeTab === 'history' }"
          :aria-selected="activeTab === 'history'"
          role="tab"
          @click="switchTab('history')"
        >
          <span class="tab-icon" aria-hidden="true">🕒</span>
          <span class="tab-label">Lịch sử đọc</span>
          <span v-if="historyMeta" class="tab-count-badge">
            {{ historyMeta.total }}
          </span>
        </button>
      </div>
    </nav>

    <!-- Main Content Area -->
    <main class="container library-main">
      <!-- Action Notification Banner -->
      <transition name="notice-fade">
        <div
          v-if="actionNotice"
          class="action-notice-bar"
          :class="`notice-${actionNotice.type}`"
          role="status"
        >
          <span class="notice-icon" aria-hidden="true">
            {{ actionNotice.type === 'success' ? '✓' : '⚠️' }}
          </span>
          <span class="notice-text">{{ actionNotice.message }}</span>
          <button
            type="button"
            class="btn-close-notice"
            aria-label="Đóng thông báo"
            @click="actionNotice = null"
          >
            ✕
          </button>
        </div>
      </transition>

      <!-- Loading Skeleton State -->
      <div v-if="currentLoading" class="comics-grid" aria-busy="true" aria-label="Đang tải danh sách truyện">
        <div v-for="n in 10" :key="'skeleton-' + n" class="card-skeleton">
          <div class="skeleton-cover"></div>
          <div class="skeleton-info">
            <div class="skeleton-line title"></div>
            <div class="skeleton-line author"></div>
            <div class="skeleton-line tags"></div>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="currentError" class="state-container error-state" role="alert">
        <span class="state-icon" aria-hidden="true">⚠️</span>
        <h2 class="state-title">Không thể tải dữ liệu</h2>
        <p class="state-desc">{{ currentError }}</p>
        <button
          type="button"
          class="btn-action-primary"
          aria-label="Thử tải lại danh sách"
          @click="handleRetry"
        >
          Thử lại
        </button>
      </div>

      <!-- Empty State: Follows -->
      <div
        v-else-if="activeTab === 'follows' && followsComics.length === 0"
        class="state-container empty-state"
      >
        <span class="state-icon" aria-hidden="true">⭐</span>
        <h2 class="state-title">Bạn chưa theo dõi bộ truyện nào</h2>
        <p class="state-desc">
          Hãy theo dõi những bộ truyện bạn yêu thích để nhận thông báo và dễ dàng cập nhật các chương mới nhất.
        </p>
        <RouterLink to="/comics" class="btn-action-primary">
          Khám phá truyện ngay
        </RouterLink>
      </div>

      <!-- Empty State: Bookmarks -->
      <div
        v-else-if="activeTab === 'bookmarks' && bookmarksComics.length === 0"
        class="state-container empty-state"
      >
        <span class="state-icon" aria-hidden="true">🔖</span>
        <h2 class="state-title">Tủ sách của bạn chưa có truyện nào</h2>
        <p class="state-desc">
          Lưu những bộ truyện tâm đắc vào tủ sách cá nhân để đọc lại và quản lý thuận tiện bất cứ lúc nào.
        </p>
        <RouterLink to="/comics" class="btn-action-primary">
          Khám phá truyện ngay
        </RouterLink>
      </div>

      <!-- Empty State: History -->
      <div
        v-else-if="activeTab === 'history' && historyItems.length === 0"
        class="state-container empty-state"
      >
        <span class="state-icon" aria-hidden="true">📖</span>
        <h2 class="state-title">Lịch sử đọc của bạn đang trống</h2>
        <p class="state-desc">
          Khi bạn đọc các chương truyện trên BlueToon, lịch sử đọc sẽ được tự động ghi nhận tại đây để bạn có thể tiếp tục bất cứ lúc nào.
        </p>
        <RouterLink to="/comics" class="btn-action-primary">
          Khám phá truyện ngay
        </RouterLink>
      </div>

      <!-- Content Views & Pagination -->
      <div v-else class="results-content">
        <!-- Results Meta Bar -->
        <div class="results-meta-bar">
          <span v-if="currentMeta" class="results-count">
            Hiển thị <strong>{{ currentItemsCount }}</strong> / <strong>{{ currentMeta.total }}</strong> bộ truyện {{
              activeTab === 'follows'
                ? 'đang theo dõi'
                : activeTab === 'bookmarks'
                ? 'đã lưu'
                : 'đã đọc'
            }}
          </span>

          <!-- Clear All History Action -->
          <button
            v-if="activeTab === 'history' && historyItems.length > 0"
            type="button"
            class="btn-clear-all"
            :disabled="isClearingHistory"
            aria-label="Xóa toàn bộ lịch sử đọc"
            @click="openClearAllModal"
          >
            <span class="clear-icon" aria-hidden="true">🗑️</span>
            <span>Xóa toàn bộ lịch sử</span>
          </button>
        </div>

        <!-- Tab 3: History List View -->
        <div v-if="activeTab === 'history'" class="history-grid">
          <article
            v-for="item in historyItems"
            :key="item.id"
            class="history-card"
          >
            <!-- Cover Thumbnail Link -->
            <RouterLink
              :to="'/comics/' + item.comic.slug"
              class="history-cover-link"
              :aria-label="'Truyện ' + item.comic.title"
            >
              <img
                v-if="item.comic.cover_url"
                :src="item.comic.cover_url"
                :alt="'Bìa truyện ' + item.comic.title"
                class="history-cover-img"
                loading="lazy"
              />
              <div v-else class="history-cover-fallback" aria-hidden="true">
                <span>📖</span>
              </div>
            </RouterLink>

            <!-- Details Body -->
            <div class="history-body">
              <div class="history-main-info">
                <RouterLink
                  :to="'/comics/' + item.comic.slug"
                  class="history-title-link"
                  :title="item.comic.title"
                >
                  <h3 class="history-comic-title">{{ item.comic.title }}</h3>
                </RouterLink>
                <p v-if="item.comic.author_name" class="history-author">
                  Tác giả: {{ item.comic.author_name }}
                </p>

                <!-- Last Read Chapter badge & time -->
                <div class="history-chapter-info">
                  <span class="history-chap-badge">
                    <span class="chap-badge-icon" aria-hidden="true">🔖</span>
                    <span v-if="item.last_read_chapter">
                      Đang đọc: <strong>Chương {{ formatChapterNumber(item.last_read_chapter.chapter_number) }}</strong>
                      <span v-if="item.last_read_chapter.title" class="chap-name">: {{ item.last_read_chapter.title }}</span>
                    </span>
                    <span v-else>Chương đọc gần nhất không khả dụng</span>
                  </span>
                  <span class="history-read-time" :title="item.updated_at">
                    🕒 {{ formatRelativeTime(item.updated_at) }}
                  </span>
                </div>
              </div>

              <!-- History Card Action Controls -->
              <div class="history-actions">
                <RouterLink
                  v-if="item.last_read_chapter"
                  :to="buildChapterUrl(item.comic.slug, item.last_read_chapter.chapter_number)"
                  class="btn-continue-read"
                  :aria-label="'Đọc tiếp Chương ' + formatChapterNumber(item.last_read_chapter.chapter_number) + ' của truyện ' + item.comic.title"
                >
                  <span>Đọc tiếp</span>
                  <span class="btn-arrow" aria-hidden="true">›</span>
                </RouterLink>
                <RouterLink
                  v-else
                  :to="'/comics/' + item.comic.slug"
                  class="btn-continue-read"
                >
                  <span>Chi tiết</span>
                </RouterLink>

                <!-- Single Item Deletion Button -->
                <button
                  type="button"
                  class="btn-delete-item"
                  :disabled="deletingComicId === item.comic.id"
                  :aria-label="'Xóa lịch sử đọc truyện ' + item.comic.title"
                  title="Xóa khỏi lịch sử đọc"
                  @click="handleDeleteHistoryItem(item.comic.id)"
                >
                  <span v-if="deletingComicId === item.comic.id" class="delete-spinner" aria-hidden="true">⏳</span>
                  <span v-else class="delete-icon" aria-hidden="true">🗑️</span>
                </button>
              </div>
            </div>
          </article>
        </div>

        <!-- Tab 1 & 2: Follows & Bookmarks Cards Grid -->
        <div v-else class="comics-grid">
          <ComicCard
            v-for="comic in currentComics"
            :key="comic.id"
            :comic="comic"
          />
        </div>

        <!-- Shared Pagination Bar -->
        <nav
          v-if="currentMeta && currentMeta.last_page > 1"
          class="pagination-nav"
          aria-label="Điều hướng phân trang"
        >
          <button
            type="button"
            class="btn-page btn-prev"
            :disabled="currentMeta.current_page <= 1"
            aria-label="Trang trước"
            @click="handlePageChange(currentMeta.current_page - 1)"
          >
            ‹ Trang trước
          </button>

          <div class="page-numbers">
            <span class="page-indicator">
              Trang <strong>{{ currentMeta.current_page }}</strong> / {{ currentMeta.last_page }}
            </span>
          </div>

          <button
            type="button"
            class="btn-page btn-next"
            :disabled="currentMeta.current_page >= currentMeta.last_page"
            aria-label="Trang sau"
            @click="handlePageChange(currentMeta.current_page + 1)"
          >
            Trang sau ›
          </button>
        </nav>
      </div>
    </main>

    <!-- Confirmation Modal for Clear All Reading History -->
    <ConfirmModal
      :is-open="isClearModalOpen"
      title="Xóa toàn bộ lịch sử đọc"
      message="Bạn có chắc chắn muốn xóa toàn bộ lịch sử đọc truyện? Hành động này sẽ xóa dữ liệu trên hệ thống và không thể hoàn tác."
      type="danger"
      confirm-text="Xóa toàn bộ"
      cancel-text="Hủy bỏ"
      :is-loading="isClearingHistory"
      @confirm="handleConfirmClearAll"
      @close="closeClearAllModal"
    />
  </div>
</template>

<style scoped>
.library-page {
  padding-bottom: var(--spacing-3xl);
  min-height: 80vh;
}

/* Hero Section */
.library-hero {
  padding: var(--spacing-2xl) 0 var(--spacing-xl);
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
}

.hero-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-lg);
  flex-wrap: wrap;
}

.hero-titles {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.title-with-icon {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.hero-icon {
  font-size: 2rem;
}

.page-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
}

@media (min-width: 768px) {
  .page-title {
    font-size: var(--font-size-3xl);
  }
}

.page-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  margin: 0;
}

.btn-refresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 40px;
  padding: 0 var(--spacing-md);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-card);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
  user-select: none;
}

.btn-refresh:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
  transform: translateY(-1px);
}

.btn-refresh:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.refresh-icon {
  font-size: 16px;
  display: inline-block;
  transition: transform 0.3s ease;
}

.refresh-icon.is-spinning {
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Tabs Navigation Bar */
.library-tabs-nav {
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  z-index: 10;
  backdrop-filter: blur(12px);
}

.tabs-container {
  display: flex;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) 0;
  overflow-x: auto;
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px var(--spacing-lg);
  border-radius: var(--radius-md);
  border: 1px solid transparent;
  background-color: transparent;
  color: var(--color-text-secondary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
  user-select: none;
  white-space: nowrap;
}

.tab-btn:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-card);
}

.tab-btn.is-active {
  background-color: var(--color-primary-soft);
  border-color: var(--color-primary-glow);
  color: var(--color-primary);
  font-weight: var(--font-weight-bold);
}

.tab-icon {
  font-size: 15px;
}

.tab-count-badge {
  font-size: 11px;
  font-weight: var(--font-weight-bold);
  padding: 2px 8px;
  border-radius: var(--radius-full);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  line-height: 1.2;
}

.tab-btn.is-active .tab-count-badge {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border-color: var(--color-primary);
}

/* Main Area */
.library-main {
  padding-top: var(--spacing-xl);
}

/* Action Notice Banner */
.action-notice-bar {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: 10px var(--spacing-md);
  border-radius: var(--radius-md);
  margin-bottom: var(--spacing-lg);
  font-size: var(--font-size-sm);
}

.notice-success {
  background-color: rgba(34, 197, 94, 0.12);
  border: 1px solid rgba(34, 197, 94, 0.3);
  color: #4ade80;
}

.notice-error {
  background-color: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #f87171;
}

.notice-icon {
  font-size: 1rem;
}

.notice-text {
  flex: 1;
}

.btn-close-notice {
  background: none;
  border: none;
  color: inherit;
  font-size: 14px;
  cursor: pointer;
  opacity: 0.7;
  padding: 2px 6px;
}

.btn-close-notice:hover {
  opacity: 1;
}

.notice-fade-enter-active,
.notice-fade-leave-active {
  transition: all 0.25s ease;
}

.notice-fade-enter-from,
.notice-fade-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* Results Meta Bar */
.results-meta-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--spacing-md);
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.results-meta-bar strong {
  color: var(--color-primary);
}

.btn-clear-all {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px var(--spacing-md);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-card);
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-clear-all:hover:not(:disabled) {
  border-color: #ef4444;
  color: #ef4444;
  background-color: rgba(239, 68, 68, 0.08);
}

.btn-clear-all:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* History Grid & Cards */
.history-grid {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.history-card {
  display: flex;
  gap: var(--spacing-md);
  padding: var(--spacing-md);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  transition: all var(--transition-fast);
  align-items: center;
}

.history-card:hover {
  border-color: var(--color-border-hover);
  box-shadow: var(--shadow-sm);
}

.history-cover-link {
  flex-shrink: 0;
  width: 72px;
  aspect-ratio: 3 / 4;
  border-radius: var(--radius-md);
  overflow: hidden;
  background-color: var(--color-bg-elevated);
  display: block;
}

.history-cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform var(--transition-fast);
}

.history-card:hover .history-cover-img {
  transform: scale(1.04);
}

.history-cover-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  color: var(--color-text-muted);
}

.history-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

@media (min-width: 640px) {
  .history-body {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

.history-main-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.history-title-link {
  text-decoration: none;
  color: inherit;
}

.history-comic-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  transition: color var(--transition-fast);
}

.history-title-link:hover .history-comic-title {
  color: var(--color-primary);
}

.history-author {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
  margin: 0;
}

.history-chapter-info {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
  margin-top: 2px;
}

.history-chap-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: var(--radius-sm);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
}

.history-chap-badge strong {
  font-weight: var(--font-weight-bold);
}

.chap-badge-icon {
  font-size: 11px;
}

.chap-name {
  color: var(--color-text-secondary);
}

.history-read-time {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.history-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  flex-shrink: 0;
}

.btn-continue-read {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 36px;
  padding: 0 var(--spacing-lg);
  border-radius: var(--radius-md);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-continue-read:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

.btn-arrow {
  font-size: 14px;
  font-weight: bold;
}

.btn-delete-item {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  font-size: 14px;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-delete-item:hover:not(:disabled) {
  border-color: #ef4444;
  color: #ef4444;
  background-color: rgba(239, 68, 68, 0.1);
}

.btn-delete-item:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

@media (min-width: 900px) {
  .comics-grid {
    grid-template-columns: repeat(4, 1fr);
    gap: var(--spacing-lg);
  }
}

@media (min-width: 1200px) {
  .comics-grid {
    grid-template-columns: repeat(5, 1fr);
  }
}

/* Skeleton Cards */
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
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
}

.skeleton-info {
  padding: var(--spacing-md);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.skeleton-line {
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.8s infinite;
  border-radius: var(--radius-sm);
}

.skeleton-line.title {
  width: 80%;
  height: 18px;
}

.skeleton-line.author {
  width: 50%;
  height: 14px;
}

.skeleton-line.tags {
  width: 65%;
  height: 14px;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* State Containers (Empty & Error) */
.state-container {
  min-height: 400px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-md);
  margin: var(--spacing-xl) auto;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
}

.state-icon {
  font-size: 3.5rem;
  margin-bottom: var(--spacing-sm);
}

.state-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: var(--spacing-xs);
}

.state-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 480px;
  line-height: var(--line-height-relaxed);
  margin-bottom: var(--spacing-lg);
}

.btn-action-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 42px;
  padding: 0 var(--spacing-xl);
  border-radius: var(--radius-md);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  border: none;
  cursor: pointer;
  text-decoration: none;
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
}

.btn-action-primary:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
}

/* Pagination Bar */
.pagination-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-md);
  margin-top: var(--spacing-2xl);
  padding: var(--spacing-md) 0;
}

.btn-page {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  padding: 0 var(--spacing-lg);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-card);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-page:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
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
</style>
