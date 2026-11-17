<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { Post, PostSortOption, PaginationMeta } from '@/types/community'
import type { ComicListItem } from '@/types/catalog'
import { CommunityService } from '@/services/communityService'
import { CatalogService } from '@/services/catalogService'
import { useAuthStore } from '@/stores/auth'
import { getErrorMessage } from '@/services/httpClient'
import PostCard from '@/components/community/PostCard.vue'
import PostFormModal from '@/components/community/PostFormModal.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// Request synchronization & lifecycle guard
let activeFetchId = 0
let isComponentMounted = true

// State
const posts = ref<Post[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

// Filters & Sorting
const currentSort = ref<PostSortOption>('latest')
const selectedComicId = ref<number | null>(null)
const currentPage = ref(1)
const perPage = 15

// Available comics for filter
const availableComics = ref<ComicListItem[]>([])
const isLoadingComics = ref(false)

// Modal State
const isModalOpen = ref(false)
const editingPost = ref<Post | null>(null)

// Permissions
const canCreatePost = computed(() => {
  return authStore.isAuthenticated && (authStore.isCreator || authStore.isAdmin)
})

const hasActiveFilter = computed(() => {
  return selectedComicId.value !== null
})

// Load approved comics for the filter dropdown
async function loadFilterComics() {
  isLoadingComics.value = true
  try {
    const res = await CatalogService.getComics({ per_page: 50 })
    if (isComponentMounted) {
      availableComics.value = res.data || []
    }
  } catch (err) {
    console.error('Không thể tải danh sách truyện cho bộ lọc:', err)
  } finally {
    if (isComponentMounted) {
      isLoadingComics.value = false
    }
  }
}

// Synchronize comic_id filter with URL query parameters
function syncUrlQuery() {
  const nextQuery: Record<string, string> = {}

  // Preserve all other query parameters
  for (const [key, value] of Object.entries(route.query)) {
    if (key !== 'comic_id' && value !== undefined && value !== null) {
      nextQuery[key] = String(value)
    }
  }

  if (selectedComicId.value !== null) {
    nextQuery.comic_id = String(selectedComicId.value)
  }

  const currentParam = route.query.comic_id ? String(route.query.comic_id) : undefined
  const nextParam = nextQuery.comic_id

  if (currentParam !== nextParam) {
    router.replace({ query: nextQuery })
  }
}

// Fetch posts according to current sort, filters and page with Race Condition protection
async function fetchPosts() {
  const fetchId = ++activeFetchId
  isLoading.value = true
  errorMessage.value = null

  try {
    const params: {
      sort: PostSortOption
      page: number
      per_page: number
      comic_id?: number
    } = {
      sort: currentSort.value,
      page: currentPage.value,
      per_page: perPage,
    }

    if (selectedComicId.value !== null) {
      params.comic_id = selectedComicId.value
    }

    const res = await CommunityService.getPosts(params)

    // Discard response if superseded by a newer request or if unmounted
    if (!isComponentMounted || fetchId !== activeFetchId) {
      return
    }

    posts.value = res.data
    paginationMeta.value = res.meta
  } catch (err) {
    // Discard error if superseded by a newer request or if unmounted
    if (!isComponentMounted || fetchId !== activeFetchId) {
      return
    }

    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách bài viết. Vui lòng thử lại.')
    posts.value = []
    paginationMeta.value = null
  } finally {
    // Only turn off loading state when the latest request finishes
    if (isComponentMounted && fetchId === activeFetchId) {
      isLoading.value = false
    }
  }
}

function handleTabChange(sort: PostSortOption) {
  if (currentSort.value === sort) return
  currentSort.value = sort
  currentPage.value = 1
  fetchPosts()
}

function handleComicFilterChange() {
  currentPage.value = 1
  syncUrlQuery()
  fetchPosts()
}

function clearFilter() {
  selectedComicId.value = null
  currentPage.value = 1
  syncUrlQuery()
  fetchPosts()
}

function goToPage(page: number) {
  if (
    page < 1 ||
    (paginationMeta.value && page > paginationMeta.value.last_page) ||
    page === currentPage.value
  ) {
    return
  }
  currentPage.value = page
  fetchPosts()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// Post Modal Handlers
function openCreateModal() {
  editingPost.value = null
  isModalOpen.value = true
}

function handleEditPost(post: Post) {
  editingPost.value = post
  isModalOpen.value = true
}

function handlePostSaved(savedPost: Post) {
  if (editingPost.value) {
    // Update existing post in list
    const index = posts.value.findIndex((p) => p.id === savedPost.id)
    if (index !== -1) {
      posts.value[index] = savedPost
    }
  } else {
    // New post created
    // If on latest tab and not filtered (or matching filter), place at the top
    if (
      currentSort.value === 'latest' &&
      (selectedComicId.value === null || savedPost.comic?.id === selectedComicId.value)
    ) {
      posts.value.unshift(savedPost)
      if (paginationMeta.value) {
        paginationMeta.value.total += 1
      }
    } else {
      // Refresh to align with sorting or filters
      currentPage.value = 1
      fetchPosts()
    }
  }
}

function handlePostDeleted(postId: number) {
  posts.value = posts.value.filter((p) => p.id !== postId)
  if (paginationMeta.value) {
    paginationMeta.value.total = Math.max(0, paginationMeta.value.total - 1)
  }
}

// Watch route queries for browser Back/Forward or deep linking
watch(
  () => route.query.comic_id,
  (newComicId) => {
    let parsedId: number | null = null
    if (newComicId) {
      const parsed = parseInt(String(newComicId), 10)
      if (!isNaN(parsed) && parsed > 0) {
        parsedId = parsed
      }
    }

    if (parsedId !== selectedComicId.value) {
      selectedComicId.value = parsedId
      currentPage.value = 1
      fetchPosts()
    }
  }
)

onMounted(() => {
  if (route.query.comic_id) {
    const parsed = parseInt(String(route.query.comic_id), 10)
    if (!isNaN(parsed) && parsed > 0) {
      selectedComicId.value = parsed
    }
  }

  loadFilterComics()
  fetchPosts()
})

onUnmounted(() => {
  isComponentMounted = false
})
</script>

<template>
  <div class="community-page">
    <div class="container community-container">
      <!-- Community Banner Header -->
      <section class="community-header">
        <div class="header-text-group">
          <div class="header-badge">
            <span class="badge-icon" aria-hidden="true">🌐</span>
            <span>BlueToon Community</span>
          </div>
          <h1 class="header-title">Bảng tin Cộng đồng</h1>
          <p class="header-subtitle">
            Không gian giao lưu, chia sẻ bài viết, cảm nghĩ và cập nhật thông báo mới nhất từ các tác giả truyện tranh.
          </p>
        </div>

        <!-- Create Post Button for Creator / Admin -->
        <div v-if="canCreatePost" class="header-action-group">
          <button
            type="button"
            class="btn-create-post"
            aria-label="Tạo bài viết mới"
            @click="openCreateModal"
          >
            <span class="btn-icon" aria-hidden="true">✍️</span>
            <span>Đăng bài viết</span>
          </button>
        </div>
      </section>

      <!-- Navigation & Filters Bar -->
      <section class="controls-bar" aria-label="Bộ lọc và sắp xếp bài đăng">
        <!-- Sort Tabs -->
        <div class="sort-tabs" role="tablist">
          <button
            type="button"
            role="tab"
            class="tab-btn"
            :class="{ active: currentSort === 'latest' }"
            :aria-selected="currentSort === 'latest'"
            @click="handleTabChange('latest')"
          >
            <span class="tab-icon" aria-hidden="true">🕒</span>
            <span>Mới nhất</span>
          </button>
          <button
            type="button"
            role="tab"
            class="tab-btn"
            :class="{ active: currentSort === 'popular' }"
            :aria-selected="currentSort === 'popular'"
            @click="handleTabChange('popular')"
          >
            <span class="tab-icon" aria-hidden="true">🔥</span>
            <span>Phổ biến nhất</span>
          </button>
        </div>

        <!-- Filter by Comic -->
        <div class="filter-group">
          <label for="filter-comic-select" class="filter-label">Lọc theo truyện:</label>
          <select
            id="filter-comic-select"
            v-model="selectedComicId"
            class="filter-select"
            :disabled="isLoadingComics"
            @change="handleComicFilterChange"
          >
            <option :value="null">Tất cả truyện</option>
            <option
              v-for="comic in availableComics"
              :key="comic.id"
              :value="comic.id"
            >
              {{ comic.title }}
            </option>
          </select>
          <button
            v-if="hasActiveFilter"
            type="button"
            class="btn-clear-filter"
            title="Xóa bộ lọc truyện"
            aria-label="Xóa bộ lọc truyện"
            @click="clearFilter"
          >
            ✕
          </button>
        </div>
      </section>

      <!-- Results Count Info -->
      <div v-if="paginationMeta && !isLoading" class="results-info-row">
        <span class="results-text">
          Hiển thị <strong>{{ posts.length }}</strong> trên tổng số <strong>{{ paginationMeta.total }}</strong> bài viết
        </span>
      </div>

      <!-- Loading State -->
      <section v-if="isLoading" class="loading-state" aria-busy="true">
        <div class="spinner-large" aria-hidden="true"></div>
        <p class="loading-text">Đang tải bài viết cộng đồng...</p>
      </section>

      <!-- Error State -->
      <section v-else-if="errorMessage" class="error-state" role="alert">
        <div class="error-icon" aria-hidden="true">⚠️</div>
        <h2 class="error-title">Không thể tải dữ liệu</h2>
        <p class="error-message">{{ errorMessage }}</p>
        <button
          type="button"
          class="btn-retry"
          @click="fetchPosts"
        >
          Thử lại
        </button>
      </section>

      <!-- Empty State -->
      <section v-else-if="posts.length === 0" class="empty-state">
        <div class="empty-icon" aria-hidden="true">💬</div>
        <h2 class="empty-title">Chưa có bài viết nào</h2>
        <p class="empty-desc">
          {{ hasActiveFilter ? 'Không tìm thấy bài viết nào gắn kèm bộ truyện này.' : 'Chưa có bài viết nào được đăng tải trên bảng tin cộng đồng.' }}
        </p>
        <div class="empty-actions">
          <button
            v-if="hasActiveFilter"
            type="button"
            class="btn-secondary"
            @click="clearFilter"
          >
            Xem tất cả bài viết
          </button>
          <button
            v-if="canCreatePost"
            type="button"
            class="btn-action-primary"
            @click="openCreateModal"
          >
            Tạo bài viết đầu tiên
          </button>
        </div>
      </section>

      <!-- Posts List -->
      <section v-else class="posts-feed">
        <div class="posts-list">
          <PostCard
            v-for="post in posts"
            :key="post.id"
            :post="post"
            @edit="handleEditPost"
            @deleted="handlePostDeleted"
          />
        </div>

        <!-- Pagination Bar -->
        <nav
          v-if="paginationMeta && paginationMeta.last_page > 1"
          class="pagination-nav"
          aria-label="Điều hướng phân trang"
        >
          <button
            type="button"
            class="btn-page btn-prev"
            :disabled="paginationMeta.current_page <= 1"
            aria-label="Trang trước"
            @click="goToPage(paginationMeta.current_page - 1)"
          >
            ‹ Trang trước
          </button>

          <div class="page-indicator">
            Trang <strong>{{ paginationMeta.current_page }}</strong> / {{ paginationMeta.last_page }}
          </div>

          <button
            type="button"
            class="btn-page btn-next"
            :disabled="paginationMeta.current_page >= paginationMeta.last_page"
            aria-label="Trang sau"
            @click="goToPage(paginationMeta.current_page + 1)"
          >
            Trang sau ›
          </button>
        </nav>
      </section>
    </div>

    <!-- Create / Edit Post Modal -->
    <PostFormModal
      :is-open="isModalOpen"
      :post="editingPost"
      :initial-comic-id="selectedComicId"
      @close="isModalOpen = false"
      @saved="handlePostSaved"
    />
  </div>
</template>

<style scoped>
.community-page {
  padding: var(--spacing-xl) 0;
  min-height: calc(100vh - 64px - 100px);
}

.community-container {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xl);
  max-width: 860px;
}

/* Header */
.community-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-lg);
  padding: var(--spacing-xl);
  background: linear-gradient(135deg, rgba(17, 24, 39, 0.95), rgba(31, 41, 55, 0.6));
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
}

.header-text-group {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.header-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-primary);
  background-color: var(--color-primary-soft);
  padding: 3px 10px;
  border-radius: var(--radius-full);
  width: fit-content;
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.header-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
  letter-spacing: -0.02em;
}

.header-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  line-height: var(--line-height-relaxed);
  margin: 0;
}

.header-action-group {
  flex-shrink: 0;
}

.btn-create-post {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 42px;
  padding: 0 var(--spacing-lg);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  border: none;
  cursor: pointer;
  box-shadow: 0 0 16px rgba(56, 189, 248, 0.35);
  transition: all var(--transition-fast);
}

.btn-create-post:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

/* Controls Bar */
.controls-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.sort-tabs {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: transparent;
  border: none;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.tab-btn:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.tab-btn.active {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  font-weight: var(--font-weight-semibold);
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.filter-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.filter-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  white-space: nowrap;
}

.filter-select {
  height: 36px;
  padding: 0 var(--spacing-sm);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-xs);
  max-width: 220px;
  cursor: pointer;
}

.filter-select:focus {
  outline: none;
  border-color: var(--color-primary);
}

.btn-clear-filter {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  width: 28px;
  height: 28px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

.btn-clear-filter:hover {
  color: var(--color-danger);
  border-color: var(--color-danger);
}

.results-info-row {
  display: flex;
  justify-content: flex-end;
}

.results-text {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* States */
.loading-state,
.error-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-3xl) var(--spacing-lg);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  text-align: center;
  gap: var(--spacing-md);
}

.spinner-large {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(56, 189, 248, 0.2);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.loading-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

.error-icon,
.empty-icon {
  font-size: 44px;
}

.error-title,
.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
}

.error-message,
.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 420px;
  margin: 0;
}

.btn-retry,
.btn-action-primary {
  padding: 8px 20px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border: none;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-retry:hover,
.btn-action-primary:hover {
  background-color: var(--color-primary-hover);
}

.btn-secondary {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  background-color: transparent;
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-secondary:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.empty-actions {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

/* Feed */
.posts-feed {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xl);
}

.posts-list {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

/* Pagination */
.pagination-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-md);
  padding: var(--spacing-md);
  border-top: 1px solid var(--color-border);
}

.btn-page {
  padding: 6px 14px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-page:hover:not(:disabled) {
  color: var(--color-primary);
  border-color: var(--color-primary);
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.page-indicator {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
}

@media (max-width: 640px) {
  .community-header {
    flex-direction: column;
    padding: var(--spacing-lg);
  }

  .header-action-group {
    width: 100%;
  }

  .btn-create-post {
    width: 100%;
    justify-content: center;
  }

  .controls-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .sort-tabs {
    width: 100%;
  }

  .tab-btn {
    flex: 1;
    justify-content: center;
  }

  .filter-group {
    width: 100%;
  }

  .filter-select {
    flex: 1;
    max-width: none;
  }
}
</style>
