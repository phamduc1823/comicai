<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { CatalogService } from '@/services/catalogService'
import { ReaderHistoryService } from '@/services/readerHistoryService'
import { ReadingHistoryService } from '@/services/readingHistoryService'
import { UserLibraryService } from '@/services/userLibraryService'
import {
  getErrorMessage,
  isNotFoundError,
  isUnauthorizedError,
  isForbiddenError,
  isValidationError,
  isRateLimitError,
  getRetryAfterSeconds,
} from '@/services/httpClient'
import type { ComicDetail, ChapterSummary } from '@/types/catalog'
import {
  formatChapterNumber,
  compareChapterNumbers,
  getFirstChapter,
  getLatestChapter,
  buildChapterUrl,
} from '@/utils/chapter'
import CommentSection from '@/components/community/CommentSection.vue'
import ReportModal from '@/components/common/ReportModal.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

// Data states
const comic = ref<ComicDetail | null>(null)
const chapters = ref<ChapterSummary[]>([])
const isLoading = ref(true)
const isChaptersLoading = ref(false)
const isNotFound = ref(false)
const notFoundMessage = ref('')
const errorMessage = ref<string | null>(null)

// Interaction states (Package 9.5.4)
const isFollowLoading = ref(false)
const isBookmarkLoading = ref(false)
const isReportModalOpen = ref(false)
const actionError = ref<string | null>(null)
let errorTimeoutId: ReturnType<typeof setTimeout> | null = null

const isCreatorOfComic = computed(() => {
  return authStore.isAuthenticated && comic.value?.creator?.id === authStore.user?.id
})

// UI states
const coverImageError = ref(false)
const bannerImageError = ref(false)
const creatorAvatarError = ref(false)
const isDescriptionExpanded = ref(false)
const sortDescending = ref(false) // default ASC

let latestComicRequestId = 0
let latestHistoryRequestId = 0
const isComponentMounted = ref(true)
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

// Backend Reading History state for authenticated users
interface BackendLastReadInfo {
  chapterId: number
  chapterNumber: string
}
type BackendHistoryStatus = 'idle' | 'loading' | 'loaded-with-history' | 'loaded-empty' | 'error'

const backendLastReadChapter = ref<BackendLastReadInfo | null>(null)
const backendHistoryStatus = ref<BackendHistoryStatus>('idle')

/**
 * Fetch backend reading history for this specific comic using dedicated Comic ID endpoint.
 * Runs asynchronously in background with independent request counter and user tracking.
 */
async function fetchBackendReadingHistory(comicId: number, comicSlug: string) {
  if (!authStore.isAuthenticated) {
    backendLastReadChapter.value = null
    backendHistoryStatus.value = 'idle'
    return
  }

  const requestId = ++latestHistoryRequestId
  const initiatedUserId = authStore.user?.id ?? null
  backendHistoryStatus.value = 'loading'

  try {
    const res = await ReadingHistoryService.getComicReadingHistory(comicId)

    // Strict concurrency & freshness guards
    if (
      isComponentMounted.value &&
      requestId === latestHistoryRequestId &&
      authStore.user?.id === initiatedUserId &&
      comic.value?.id === comicId &&
      comic.value?.slug === comicSlug
    ) {
      if (res.data) {
        backendLastReadChapter.value = {
          chapterId: res.data.chapter_id,
          chapterNumber: res.data.chapter_number,
        }
        backendHistoryStatus.value = 'loaded-with-history'
      } else {
        backendLastReadChapter.value = null
        backendHistoryStatus.value = 'loaded-empty'
      }
      historyTick.value++
    }
  } catch {
    // Network or server error: transition to 'error' status for fallback to localStorage
    if (
      isComponentMounted.value &&
      requestId === latestHistoryRequestId &&
      authStore.user?.id === initiatedUserId &&
      comic.value?.id === comicId &&
      comic.value?.slug === comicSlug
    ) {
      backendLastReadChapter.value = null
      backendHistoryStatus.value = 'error'
      historyTick.value++
    }
  }
}

// Check reading history: prioritizes authoritative Backend for authenticated users, strictly scoped localStorage for guests
const lastReadChapter = computed<ChapterSummary | null>(() => {
  void historyTick.value
  // When session is being initialized/restored, wait until identity is confirmed
  if (!authStore.isInitialized) return null
  if (!comic.value || !chapters.value || chapters.value.length === 0) return null

  // 1. Authenticated user: Prioritize authoritative backend reading history
  if (authStore.isAuthenticated) {
    // If backend returned valid reading history for this comic
    if (backendHistoryStatus.value === 'loaded-with-history' && backendLastReadChapter.value) {
      const found = chapters.value.find(
        (c) =>
          c.id === backendLastReadChapter.value?.chapterId ||
          compareChapterNumbers(c.chapter_number, backendLastReadChapter.value?.chapterNumber) === 0
      )
      if (found) return found
      // If chapter was unpublished or removed from catalog, return null (falls back to first published chapter)
      return null
    }

    // If backend confirmed user has no reading history for this comic (data === null)
    if (backendHistoryStatus.value === 'loaded-empty') {
      return null
    }

    // While backend request is in-flight (loading/idle) or if API failed (error):
    // Fallback safely to localStorage of that specific user identity
    if (
      backendHistoryStatus.value === 'error' ||
      backendHistoryStatus.value === 'loading' ||
      backendHistoryStatus.value === 'idle'
    ) {
      const currentUserId = authStore.user?.id ?? null
      const localHistory = ReaderHistoryService.getLastRead(comic.value.slug, currentUserId)
      if (localHistory) {
        return (
          chapters.value.find(
            (c) => compareChapterNumbers(c.chapter_number, localHistory.chapterNumber) === 0
          ) || null
        )
      }
      return null
    }

    return null
  }

  // 2. Guest user: strictly use guest localStorage without calling authenticated API
  const localHistory = ReaderHistoryService.getLastRead(comic.value.slug, null)
  if (!localHistory) return null
  return (
    chapters.value.find(
      (c) => compareChapterNumbers(c.chapter_number, localHistory.chapterNumber) === 0
    ) || null
  )
})

// Trigger re-computation and background sync when auth state or user identity changes
watch(
  () => [authStore.user?.id, authStore.isAuthenticated, authStore.isInitialized],
  ([, isAuth]) => {
    latestHistoryRequestId++
    backendLastReadChapter.value = null
    backendHistoryStatus.value = 'idle'
    historyTick.value++
    if (isAuth && comic.value) {
      fetchBackendReadingHistory(comic.value.id, comic.value.slug)
    }
  }
)

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
 * Notification helper for follow / bookmark interaction errors.
 * Automatically clears after 5 seconds or when dismissed by user.
 */
function setActionError(message: string) {
  if (errorTimeoutId) {
    clearTimeout(errorTimeoutId)
    errorTimeoutId = null
  }
  actionError.value = message
  errorTimeoutId = setTimeout(() => {
    actionError.value = null
    errorTimeoutId = null
  }, 5000)
}

/**
 * Handle API error from follow or bookmark action.
 * Maps status codes (401, 403, 404, 422, 429, 500) to human-friendly Vietnamese notices.
 */
function handleActionError(err: unknown, defaultMessage: string) {
  if (isUnauthorizedError(err)) {
    setActionError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.')
    router.push({
      path: '/login',
      query: { redirect: route.fullPath },
    })
    return
  }

  if (isForbiddenError(err)) {
    setActionError('Tài khoản của bạn không được phép thực hiện thao tác này.')
    return
  }

  if (isNotFoundError(err)) {
    setActionError('Bộ truyện không tồn tại hoặc chưa được duyệt phát hành.')
    return
  }

  if (isRateLimitError(err)) {
    const retrySeconds = getRetryAfterSeconds(err)
    setActionError(
      retrySeconds
        ? `Bạn thao tác quá nhanh. Vui lòng thử lại sau ${retrySeconds} giây.`
        : 'Bạn thao tác quá nhanh. Vui lòng thử lại trong giây lát.'
    )
    return
  }

  if (isValidationError(err)) {
    setActionError('Dữ liệu yêu cầu không hợp lệ.')
    return
  }

  setActionError(getErrorMessage(err, defaultMessage))
}

/**
 * Handle Follow / Unfollow comic toggle with optimistic update and rollback.
 * - Redirects guests to login with redirect query to current comic detail.
 * - Prevents double-clicks while in-flight.
 * - Optimistically flips is_followed and adjusts follows_count.
 * - Reverts to initial values on failure.
 * - Protects against stale responses across comic route transitions.
 */
async function handleToggleFollow() {
  if (!authStore.isAuthenticated) {
    router.push({
      path: '/login',
      query: { redirect: route.fullPath },
    })
    return
  }

  if (isFollowLoading.value) return
  if (!comic.value) return

  const targetId = comic.value.id
  const prevFollowed = Boolean(comic.value.is_followed)
  const prevCount = Number(comic.value.follows_count) || 0

  // Optimistic update
  comic.value.is_followed = !prevFollowed
  comic.value.follows_count = Math.max(0, prevCount + (prevFollowed ? -1 : 1))

  isFollowLoading.value = true
  actionError.value = null

  try {
    const response = await UserLibraryService.toggleFollow(targetId)
    // Synchronize with authoritative server response if user is still on the same comic
    if (comic.value && comic.value.id === targetId) {
      comic.value.is_followed = response.data.is_followed
      comic.value.follows_count = response.data.follows_count
    }
  } catch (err: unknown) {
    // Rollback to previous state on failure if user is still on the same comic
    if (comic.value && comic.value.id === targetId) {
      comic.value.is_followed = prevFollowed
      comic.value.follows_count = prevCount
    }
    handleActionError(err, 'Không thể cập nhật trạng thái theo dõi.')
  } finally {
    isFollowLoading.value = false
  }
}

/**
 * Handle Bookmark / Unbookmark comic toggle with optimistic update and rollback.
 * - Redirects guests to login with redirect query to current comic detail.
 * - Prevents double-clicks while in-flight.
 * - Optimistically flips is_bookmarked.
 * - Reverts to initial values on failure.
 * - Protects against stale responses across comic route transitions.
 */
async function handleToggleBookmark() {
  if (!authStore.isAuthenticated) {
    router.push({
      path: '/login',
      query: { redirect: route.fullPath },
    })
    return
  }

  if (isBookmarkLoading.value) return
  if (!comic.value) return

  const targetId = comic.value.id
  const prevBookmarked = Boolean(comic.value.is_bookmarked)

  // Optimistic update
  comic.value.is_bookmarked = !prevBookmarked

  isBookmarkLoading.value = true
  actionError.value = null

  try {
    const response = await UserLibraryService.toggleBookmark(targetId)
    // Synchronize with authoritative server response if user is still on the same comic
    if (comic.value && comic.value.id === targetId) {
      comic.value.is_bookmarked = response.data.is_bookmarked
    }
  } catch (err: unknown) {
    // Rollback to previous state on failure if user is still on the same comic
    if (comic.value && comic.value.id === targetId) {
      comic.value.is_bookmarked = prevBookmarked
    }
    handleActionError(err, 'Không thể cập nhật tủ sách cá nhân.')
  } finally {
    isBookmarkLoading.value = false
  }
}

/**
 * Fetch comic details and its published chapters.
 */
async function loadComicData(slug: string) {
  const requestId = ++latestComicRequestId
  // Invalidate any in-flight reading history request from previous comic
  latestHistoryRequestId++
  backendLastReadChapter.value = null
  backendHistoryStatus.value = 'idle'

  isLoading.value = true
  comic.value = null
  chapters.value = []
  errorMessage.value = null
  isNotFound.value = false
  notFoundMessage.value = ''
  coverImageError.value = false
  bannerImageError.value = false
  creatorAvatarError.value = false
  isDescriptionExpanded.value = false
  isFollowLoading.value = false
  isBookmarkLoading.value = false
  actionError.value = null
  if (errorTimeoutId) {
    clearTimeout(errorTimeoutId)
    errorTimeoutId = null
  }

  try {
    const response = await CatalogService.getComicDetail(slug)
    if (isComponentMounted.value && requestId === latestComicRequestId) {
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

      // For authenticated users, initiate background fetch for backend reading history using dedicated endpoint
      if (authStore.isAuthenticated) {
        fetchBackendReadingHistory(response.data.id, response.data.slug)
      }
    }
  } catch (error: unknown) {
    if (isComponentMounted.value && requestId === latestComicRequestId) {
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
    if (isComponentMounted.value && requestId === latestComicRequestId) {
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
    if (isComponentMounted.value && requestId === latestComicRequestId) {
      chapters.value = response.data || []
      historyTick.value++
    }
  } catch {
    // Non-fatal, use whatever chapters were in detail resource
  } finally {
    if (isComponentMounted.value && requestId === latestComicRequestId) {
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

onUnmounted(() => {
  isComponentMounted.value = false
  latestComicRequestId++
  latestHistoryRequestId++
  if (errorTimeoutId) {
    clearTimeout(errorTimeoutId)
    errorTimeoutId = null
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
            <div class="skeleton-btn sm"></div>
            <div class="skeleton-btn sm"></div>
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

                <RouterLink
                  v-if="comic.creator"
                  :to="'/users/' + comic.creator.id"
                  class="meta-item creator-item creator-link"
                  :aria-label="'Xem hồ sơ người đăng ' + comic.creator.name"
                >
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
                </RouterLink>
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

              <!-- Action Call to Reads & Interactions -->
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

                <!-- User Library Actions: Follow & Bookmark -->
                <div class="user-library-actions">
                  <!-- Follow Button -->
                  <button
                    type="button"
                    class="btn-interact btn-follow"
                    :class="{ 'is-active': comic.is_followed, 'is-loading': isFollowLoading }"
                    :disabled="isFollowLoading"
                    :aria-pressed="comic.is_followed"
                    :aria-busy="isFollowLoading"
                    :aria-label="comic.is_followed ? 'Bỏ theo dõi bộ truyện này' : 'Theo dõi bộ truyện này'"
                    @click="handleToggleFollow"
                  >
                    <span class="interact-icon-wrap" aria-hidden="true">
                      <!-- Active Filled Star -->
                      <svg
                        v-if="comic.is_followed"
                        class="interact-icon star-filled"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        stroke="currentColor"
                        stroke-width="1.5"
                      >
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                      </svg>
                      <!-- Inactive Outline Star -->
                      <svg
                        v-else
                        class="interact-icon star-outline"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                      </svg>
                    </span>
                    <span class="interact-label">
                      {{ comic.is_followed ? 'Đang theo dõi' : 'Theo dõi' }}
                    </span>
                    <span class="interact-badge" :title="comic.follows_count + ' người theo dõi'">
                      {{ formatViews(comic.follows_count) }}
                    </span>
                    <span v-if="isFollowLoading" class="interact-spinner" aria-hidden="true"></span>
                  </button>

                  <!-- Bookmark Button -->
                  <button
                    type="button"
                    class="btn-interact btn-bookmark"
                    :class="{ 'is-active': comic.is_bookmarked, 'is-loading': isBookmarkLoading }"
                    :disabled="isBookmarkLoading"
                    :aria-pressed="comic.is_bookmarked"
                    :aria-busy="isBookmarkLoading"
                    :aria-label="comic.is_bookmarked ? 'Xóa khỏi tủ sách cá nhân' : 'Lưu vào tủ sách cá nhân'"
                    @click="handleToggleBookmark"
                  >
                    <span class="interact-icon-wrap" aria-hidden="true">
                      <!-- Active Filled Bookmark -->
                      <svg
                        v-if="comic.is_bookmarked"
                        class="interact-icon bookmark-filled"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        stroke="currentColor"
                        stroke-width="1.5"
                      >
                        <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                      </svg>
                      <!-- Inactive Outline Bookmark -->
                      <svg
                        v-else
                        class="interact-icon bookmark-outline"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                      </svg>
                    </span>
                    <span class="interact-label">
                      {{ comic.is_bookmarked ? 'Đã lưu' : 'Lưu tủ sách' }}
                    </span>
                    <span v-if="isBookmarkLoading" class="interact-spinner" aria-hidden="true"></span>
                  </button>

                  <!-- Report Comic Button -->
                  <button
                    v-if="!isCreatorOfComic"
                    type="button"
                    class="btn-interact btn-report"
                    title="Báo cáo bộ truyện này vi phạm"
                    aria-label="Báo cáo vi phạm bộ truyện này"
                    @click="isReportModalOpen = true"
                  >
                    <span class="interact-icon-wrap" aria-hidden="true">🚩</span>
                    <span class="interact-label">Báo cáo</span>
                  </button>
                </div>
              </div>

              <!-- Action Feedback Alert (if any) -->
              <transition name="alert-slide">
                <div v-if="actionError" class="action-alert" role="alert">
                  <span class="alert-icon" aria-hidden="true">⚠️</span>
                  <span class="alert-text">{{ actionError }}</span>
                  <button
                    type="button"
                    class="alert-close-btn"
                    aria-label="Đóng thông báo lỗi"
                    @click="actionError = null"
                  >
                    ✕
                  </button>
                </div>
              </transition>
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
                    v-if="chap.is_vip"
                    class="badge-vip"
                    :title="'Chương VIP: ' + (chap.price || 0) + ' Coin'"
                  >
                    👑 VIP ({{ chap.price || 0 }}C)
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

        <!-- Comments Section (Package 9.4B) -->
        <CommentSection
          target-type="comic"
          :target-id="comic.id"
        />
      </main>
    </div>

    <!-- Report Comic Modal -->
    <ReportModal
      v-if="comic"
      :is-open="isReportModalOpen"
      reportable-type="comic"
      :reportable-id="comic.id"
      :target-title="`Bộ truyện: ${comic.title}`"
      @close="isReportModalOpen = false"
    />
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

.skeleton-btn.sm {
  width: 96px;
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

.creator-link {
  text-decoration: none;
  color: inherit;
  transition: all var(--transition-fast, 0.15s ease);
  cursor: pointer;
}

.creator-link:hover {
  border-color: var(--color-primary);
}

.creator-link:hover .creator-name {
  color: var(--color-primary);
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

/* User Library Actions (Follow & Bookmark) */
.user-library-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
}

.btn-interact {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 44px;
  padding: 0 var(--spacing-md);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-card);
  color: var(--color-text-primary);
  transition: all var(--transition-fast);
  user-select: none;
  white-space: nowrap;
}

.btn-interact:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
  transform: translateY(-2px);
}

.btn-interact.btn-report:hover:not(:disabled) {
  border-color: #ef4444;
  color: #ef4444;
}

.btn-interact:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}

.btn-interact:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
}

/* Follow active styling */
.btn-follow.is-active {
  background-color: rgba(245, 158, 11, 0.12);
  border-color: rgba(245, 158, 11, 0.45);
  color: #fbbf24;
}

.btn-follow.is-active:hover:not(:disabled) {
  background-color: rgba(245, 158, 11, 0.2);
  border-color: #f59e0b;
  color: #fef08a;
}

.interact-icon.star-filled {
  color: #f59e0b;
}

.interact-icon.star-outline {
  color: currentColor;
}

/* Bookmark active styling */
.btn-bookmark.is-active {
  background-color: rgba(16, 185, 129, 0.12);
  border-color: rgba(16, 185, 129, 0.45);
  color: #34d399;
}

.btn-bookmark.is-active:hover:not(:disabled) {
  background-color: rgba(16, 185, 129, 0.2);
  border-color: #10b981;
  color: #a7f3d0;
}

.interact-icon.bookmark-filled {
  color: #10b981;
}

.interact-icon.bookmark-outline {
  color: currentColor;
}

/* Shared icon & badge */
.interact-icon-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.interact-icon {
  width: 17px;
  height: 17px;
  flex-shrink: 0;
  transition: transform var(--transition-fast);
}

.btn-interact:hover:not(:disabled) .interact-icon {
  transform: scale(1.12);
}

.interact-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
}

.interact-badge {
  font-size: 11px;
  font-weight: var(--font-weight-bold);
  padding: 2px 7px;
  border-radius: var(--radius-full);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  line-height: 1.2;
}

.btn-follow.is-active .interact-badge {
  background-color: rgba(245, 158, 11, 0.2);
  color: #fef08a;
  border-color: rgba(245, 158, 11, 0.4);
}

.interact-spinner {
  width: 13px;
  height: 13px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: interact-spin 0.7s linear infinite;
  margin-left: 2px;
}

@keyframes interact-spin {
  to {
    transform: rotate(360deg);
  }
}

/* Action Error Alert */
.action-alert {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: 8px var(--spacing-md);
  background-color: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.35);
  border-radius: var(--radius-md);
  color: #fca5a5;
  font-size: var(--font-size-xs);
  margin-top: var(--spacing-xs);
  width: 100%;
}

.alert-icon {
  font-size: 14px;
  flex-shrink: 0;
}

.alert-text {
  flex: 1;
}

.alert-close-btn {
  background: none;
  border: none;
  color: inherit;
  font-size: 12px;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  opacity: 0.7;
  transition: opacity var(--transition-fast);
}

.alert-close-btn:hover {
  opacity: 1;
}

.alert-slide-enter-active,
.alert-slide-leave-active {
  transition: all 0.2s ease-out;
}

.alert-slide-enter-from,
.alert-slide-leave-to {
  opacity: 0;
  transform: translateY(-6px);
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

.badge-vip {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: 700;
  background-color: rgba(234, 179, 8, 0.16);
  color: #eab308;
  border: 1px solid rgba(234, 179, 8, 0.35);
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
