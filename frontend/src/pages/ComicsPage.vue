<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ComicCard from '@/components/ComicCard.vue'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage } from '@/services/httpClient'
import type {
  ComicListItem,
  ComicSortOption,
  ComicStatus,
  Genre,
  Tag,
  PaginationMeta,
  ListComicsParams,
} from '@/types/catalog'

const route = useRoute()
const router = useRouter()

// Data states
const comics = ref<ComicListItem[]>([])
const genres = ref<Genre[]>([])
const tags = ref<Tag[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)

// UI & Loading states
const isLoading = ref(true)
const isFilterOptionsLoading = ref(true)
const errorMessage = ref<string | null>(null)

// Filter states (bound to UI)
const searchInput = ref('')
const selectedGenre = ref('')
const selectedTag = ref('')
const selectedStatus = ref<ComicStatus | ''>('')
const selectedSort = ref<ComicSortOption>('latest')
const currentPage = ref(1)

// Search debounce timer & race-condition guard
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null
let latestRequestId = 0

// Sort options definition
const sortOptions: { value: ComicSortOption; label: string }[] = [
  { value: 'latest', label: 'Mới cập nhật' },
  { value: 'views', label: 'Xem nhiều nhất' },
  { value: 'follows', label: 'Theo dõi nhiều nhất' },
]

// Status options definition
const statusOptions: { value: ComicStatus | ''; label: string }[] = [
  { value: '', label: 'Tất cả trạng thái' },
  { value: 'ongoing', label: 'Đang tiến hành' },
  { value: 'completed', label: 'Đã hoàn thành' },
  { value: 'hiatus', label: 'Tạm ngưng' },
]

// Computed: has any active non-default filter
const hasActiveFilters = computed(() => {
  return (
    searchInput.value.trim() !== '' ||
    selectedGenre.value !== '' ||
    selectedTag.value !== '' ||
    selectedStatus.value !== '' ||
    selectedSort.value !== 'latest' ||
    currentPage.value > 1
  )
})

// Computed: Active filter chips for quick removal
const activeFilterChips = computed(() => {
  const chips: { key: string; label: string; onRemove: () => void }[] = []

  if (searchInput.value.trim()) {
    chips.push({
      key: 'q',
      label: `Tìm: "${searchInput.value.trim()}"`,
      onRemove: () => {
        searchInput.value = ''
        applyFilters({ resetPage: true })
      },
    })
  }

  if (selectedGenre.value) {
    const g = genres.value.find((item) => item.slug === selectedGenre.value)
    chips.push({
      key: 'genre',
      label: `Thể loại: ${g ? g.name : selectedGenre.value}`,
      onRemove: () => {
        selectedGenre.value = ''
        applyFilters({ resetPage: true })
      },
    })
  }

  if (selectedTag.value) {
    const t = tags.value.find((item) => item.slug === selectedTag.value)
    chips.push({
      key: 'tag',
      label: `Tag: ${t ? t.name : selectedTag.value}`,
      onRemove: () => {
        selectedTag.value = ''
        applyFilters({ resetPage: true })
      },
    })
  }

  if (selectedStatus.value) {
    const opt = statusOptions.find((item) => item.value === selectedStatus.value)
    chips.push({
      key: 'status',
      label: `Trạng thái: ${opt ? opt.label : selectedStatus.value}`,
      onRemove: () => {
        selectedStatus.value = ''
        applyFilters({ resetPage: true })
      },
    })
  }

  return chips
})

/**
 * Fetch filter options (genres and tags) without blocking comics list.
 */
async function loadFilterOptions() {
  isFilterOptionsLoading.value = true
  try {
    const [genresRes, tagsRes] = await Promise.allSettled([
      CatalogService.getGenres(),
      CatalogService.getTags(),
    ])

    if (genresRes.status === 'fulfilled') {
      genres.value = genresRes.value.data || []
    }
    if (tagsRes.status === 'fulfilled') {
      tags.value = tagsRes.value.data || []
    }
  } finally {
    isFilterOptionsLoading.value = false
  }
}

/**
 * Parse and sanitize query parameters from URL route.
 */
function parseRouteQuery() {
  const q = typeof route.query.q === 'string' ? route.query.q.trim() : ''
  const genre = typeof route.query.genre === 'string' ? route.query.genre.trim() : ''
  const tag = typeof route.query.tag === 'string' ? route.query.tag.trim() : ''
  const statusRaw = typeof route.query.status === 'string' ? route.query.status.trim() : ''
  const sortRaw = typeof route.query.sort === 'string' ? route.query.sort.trim() : ''
  const pageRaw = parseInt(String(route.query.page), 10)

  // Validate status
  const validStatus: (ComicStatus | '')[] = ['ongoing', 'completed', 'hiatus']
  const status = validStatus.includes(statusRaw as ComicStatus) ? (statusRaw as ComicStatus) : ''

  // Validate sort
  const validSort: ComicSortOption[] = ['latest', 'views', 'follows']
  const sort = validSort.includes(sortRaw as ComicSortOption) ? (sortRaw as ComicSortOption) : 'latest'

  // Validate page
  const page = !isNaN(pageRaw) && pageRaw > 0 ? pageRaw : 1

  searchInput.value = q
  selectedGenre.value = genre
  selectedTag.value = tag
  selectedStatus.value = status
  selectedSort.value = sort
  currentPage.value = page
}

/**
 * Fetch comics list from API using current route query parameters.
 */
async function fetchComics() {
  const requestId = ++latestRequestId
  isLoading.value = true
  errorMessage.value = null

  const params: ListComicsParams = {
    page: currentPage.value,
    per_page: 18,
  }

  if (searchInput.value.trim()) {
    params.q = searchInput.value.trim()
  }
  if (selectedGenre.value) {
    params.genre = selectedGenre.value
  }
  if (selectedTag.value) {
    params.tag = selectedTag.value
  }
  if (selectedStatus.value) {
    params.status = selectedStatus.value as ComicStatus
  }
  if (selectedSort.value && selectedSort.value !== 'latest') {
    params.sort = selectedSort.value
  }

  try {
    const response = await CatalogService.getComics(params)
    // Only apply if this is still the latest request
    if (requestId === latestRequestId) {
      comics.value = response.data || []
      paginationMeta.value = response.meta || null
    }
  } catch (error: unknown) {
    if (requestId === latestRequestId) {
      errorMessage.value = getErrorMessage(
        error,
        'Không thể tải danh sách truyện. Vui lòng kiểm tra kết nối mạng và thử lại.'
      )
    }
  } finally {
    if (requestId === latestRequestId) {
      isLoading.value = false
    }
  }
}

/**
 * Build clean query object and sync with Vue Router URL.
 */
function applyFilters(options: { resetPage?: boolean } = {}) {
  const newPage = options.resetPage ? 1 : currentPage.value
  currentPage.value = newPage

  const query: Record<string, string> = {}

  const cleanQ = searchInput.value.trim()
  if (cleanQ) query.q = cleanQ

  if (selectedGenre.value) query.genre = selectedGenre.value
  if (selectedTag.value) query.tag = selectedTag.value
  if (selectedStatus.value) query.status = selectedStatus.value
  if (selectedSort.value && selectedSort.value !== 'latest') query.sort = selectedSort.value
  if (newPage > 1) query.page = String(newPage)

  // Compare with current query to avoid duplicate navigation
  const currentQuery = { ...route.query }
  const isQueryEqual =
    Object.keys(query).length === Object.keys(currentQuery).length &&
    Object.keys(query).every((k) => query[k] === currentQuery[k])

  if (!isQueryEqual) {
    router.push({ path: '/comics', query })
  } else {
    // If query didn't change (e.g. initial or identical), fetch directly
    fetchComics()
  }
}

/**
 * Handle debounced search input typing.
 */
function onSearchInput() {
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
  }
  searchDebounceTimer = setTimeout(() => {
    applyFilters({ resetPage: true })
  }, 400)
}

/**
 * Instant search on Enter key press or Search button click.
 */
function submitSearch() {
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }
  applyFilters({ resetPage: true })
}

/**
 * Clear search input and re-apply filters.
 */
function clearSearch() {
  searchInput.value = ''
  submitSearch()
}

/**
 * Reset all filters to default.
 */
function resetAllFilters() {
  searchInput.value = ''
  selectedGenre.value = ''
  selectedTag.value = ''
  selectedStatus.value = ''
  selectedSort.value = 'latest'
  currentPage.value = 1
  applyFilters({ resetPage: true })
}

/**
 * Navigate to a specific page.
 */
function goToPage(page: number) {
  if (
    page < 1 ||
    (paginationMeta.value && page > paginationMeta.value.last_page) ||
    page === currentPage.value
  ) {
    return
  }
  currentPage.value = page
  applyFilters({ resetPage: false })

  // Scroll to top of results
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// Watch for URL query changes (browser Back/Forward navigation)
watch(
  () => route.query,
  () => {
    parseRouteQuery()
    fetchComics()
  }
)

onMounted(async () => {
  parseRouteQuery()
  await loadFilterOptions()
  fetchComics()
})

onUnmounted(() => {
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }
})
</script>

<template>
  <div class="comics-page">
    <!-- Page Header & Search Bar -->
    <header class="catalog-hero">
      <div class="container hero-container">
        <h1 class="hero-title">
          Danh mục <span class="highlight">Truyện tranh</span>
        </h1>
        <p class="hero-subtitle">
          Tìm kiếm và lọc các bộ truyện yêu thích theo thể loại, từ khóa và trạng thái phát hành
        </p>

        <!-- Search Box -->
        <div class="search-box-wrapper">
          <form class="search-form" @submit.prevent="submitSearch">
            <span class="search-icon" aria-hidden="true">🔍</span>
            <input
              v-model="searchInput"
              type="search"
              class="search-input"
              placeholder="Tìm theo tên truyện, tác giả, tựa đề khác..."
              aria-label="Tìm kiếm truyện tranh"
              maxlength="100"
              @input="onSearchInput"
            />
            <button
              v-if="searchInput"
              type="button"
              class="btn-clear-search"
              aria-label="Xóa từ khóa tìm kiếm"
              @click="clearSearch"
            >
              ✕
            </button>
            <button
              type="submit"
              class="btn-search-submit"
              aria-label="Thực hiện tìm kiếm"
            >
              Tìm kiếm
            </button>
          </form>
        </div>
      </div>
    </header>

    <!-- Main Content Area -->
    <main class="container catalog-body">
      <!-- Filter Bar -->
      <section class="filters-panel" aria-label="Bộ lọc truyện tranh">
        <div class="filters-row">
          <!-- Genre Select -->
          <div class="filter-group">
            <label for="filter-genre" class="filter-label">Thể loại</label>
            <select
              id="filter-genre"
              v-model="selectedGenre"
              class="filter-select"
              aria-label="Lọc theo thể loại"
              @change="applyFilters({ resetPage: true })"
            >
              <option value="">Tất cả thể loại</option>
              <option
                v-for="genre in genres"
                :key="genre.id"
                :value="genre.slug"
              >
                {{ genre.name }}
              </option>
            </select>
          </div>

          <!-- Tag Select -->
          <div class="filter-group">
            <label for="filter-tag" class="filter-label">Tag chủ đề</label>
            <select
              id="filter-tag"
              v-model="selectedTag"
              class="filter-select"
              aria-label="Lọc theo tag"
              @change="applyFilters({ resetPage: true })"
            >
              <option value="">Tất cả tags</option>
              <option
                v-for="tag in tags"
                :key="tag.id"
                :value="tag.slug"
              >
                {{ tag.name }}
              </option>
            </select>
          </div>

          <!-- Status Select -->
          <div class="filter-group">
            <label for="filter-status" class="filter-label">Trạng thái</label>
            <select
              id="filter-status"
              v-model="selectedStatus"
              class="filter-select"
              aria-label="Lọc theo trạng thái truyện"
              @change="applyFilters({ resetPage: true })"
            >
              <option
                v-for="opt in statusOptions"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </option>
            </select>
          </div>

          <!-- Sort Select -->
          <div class="filter-group">
            <label for="filter-sort" class="filter-label">Sắp xếp</label>
            <select
              id="filter-sort"
              v-model="selectedSort"
              class="filter-select"
              aria-label="Sắp xếp danh sách truyện"
              @change="applyFilters({ resetPage: true })"
            >
              <option
                v-for="opt in sortOptions"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </option>
            </select>
          </div>

          <!-- Reset Filter Button -->
          <div v-if="hasActiveFilters" class="filter-group reset-group">
            <button
              type="button"
              class="btn-reset-filters"
              aria-label="Xóa tất cả bộ lọc và đặt lại mặc định"
              @click="resetAllFilters"
            >
              <span class="reset-icon" aria-hidden="true">↺</span>
              <span>Đặt lại</span>
            </button>
          </div>
        </div>

        <!-- Active Filter Chips -->
        <div v-if="activeFilterChips.length > 0" class="active-chips-row">
          <span class="chips-label">Đang lọc:</span>
          <div class="chips-list">
            <span
              v-for="chip in activeFilterChips"
              :key="chip.key"
              class="filter-chip"
            >
              <span>{{ chip.label }}</span>
              <button
                type="button"
                class="chip-remove-btn"
                :aria-label="'Xóa ' + chip.label"
                @click="chip.onRemove"
              >
                ✕
              </button>
            </span>
            <button
              type="button"
              class="btn-clear-all-chips"
              @click="resetAllFilters"
            >
              Xóa tất cả
            </button>
          </div>
        </div>
      </section>

      <!-- Results Info Bar -->
      <div class="results-meta-bar">
        <span v-if="paginationMeta" class="results-count">
          Tìm thấy <strong>{{ paginationMeta.total }}</strong> bộ truyện
        </span>
        <span v-else-if="!isLoading" class="results-count">
          Đang cập nhật danh sách truyện
        </span>
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
        <h2 class="state-title">Không thể tải danh sách truyện</h2>
        <p class="state-desc">{{ errorMessage }}</p>
        <button
          type="button"
          class="btn-action-primary"
          aria-label="Thử tải lại danh sách"
          @click="fetchComics"
        >
          Thử lại
        </button>
      </div>

      <!-- Empty State -->
      <div v-else-if="comics.length === 0" class="state-container empty-state">
        <span class="state-icon" aria-hidden="true">🔍</span>
        <h2 class="state-title">Không tìm thấy truyện phù hợp</h2>
        <p class="state-desc">
          Không có bộ truyện nào thỏa mãn các tiêu chí tìm kiếm hoặc bộ lọc hiện tại của bạn.
        </p>
        <button
          v-if="hasActiveFilters"
          type="button"
          class="btn-action-primary"
          aria-label="Xóa tất cả bộ lọc để xem toàn bộ danh mục"
          @click="resetAllFilters"
        >
          Xóa toàn bộ bộ lọc
        </button>
      </div>

      <!-- Comics Grid & Pagination -->
      <div v-else class="results-content">
        <div class="comics-grid">
          <ComicCard
            v-for="comic in comics"
            :key="comic.id"
            :comic="comic"
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

          <div class="page-numbers">
            <span class="page-indicator">
              Trang <strong>{{ paginationMeta.current_page }}</strong> / {{ paginationMeta.last_page }}
            </span>
          </div>

          <button
            type="button"
            class="btn-page btn-next"
            :disabled="paginationMeta.current_page >= paginationMeta.last_page"
            aria-label="Trang tiếp theo"
            @click="goToPage(paginationMeta.current_page + 1)"
          >
            Trang sau ›
          </button>
        </nav>
      </div>
    </main>
  </div>
</template>

<style scoped>
.comics-page {
  padding-bottom: var(--spacing-3xl);
  min-height: 80vh;
}

/* Catalog Hero Section */
.catalog-hero {
  padding: var(--spacing-2xl) 0 var(--spacing-xl);
  background: linear-gradient(180deg, var(--color-bg-surface) 0%, var(--color-bg) 100%);
  border-bottom: 1px solid var(--color-border);
}

.hero-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.hero-title {
  font-size: var(--font-size-3xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: var(--spacing-xs);
  letter-spacing: -0.02em;
}

@media (min-width: 768px) {
  .hero-title {
    font-size: var(--font-size-4xl);
  }
}

.highlight {
  color: var(--color-primary);
  text-shadow: 0 0 24px var(--color-primary-glow);
}

.hero-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 580px;
  line-height: var(--line-height-relaxed);
  margin-bottom: var(--spacing-lg);
}

/* Search Box */
.search-box-wrapper {
  width: 100%;
  max-width: 640px;
}

.search-form {
  position: relative;
  display: flex;
  align-items: center;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-full);
  padding: 4px 6px 4px var(--spacing-md);
  transition: all var(--transition-fast);
  box-shadow: var(--shadow-sm);
}

.search-form:focus-within {
  border-color: var(--color-primary);
  box-shadow: var(--shadow-glow);
}

.search-icon {
  font-size: var(--font-size-sm);
  opacity: 0.6;
  margin-right: var(--spacing-xs);
}

.search-input {
  flex: 1;
  background: transparent;
  border: none;
  outline: none;
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  padding: var(--spacing-xs) 0;
}

.search-input::placeholder {
  color: var(--color-text-muted);
}

.btn-clear-search {
  padding: 4px 8px;
  color: var(--color-text-muted);
  font-size: 11px;
  border-radius: var(--radius-full);
  margin-right: 4px;
}

.btn-clear-search:hover {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-search-submit {
  height: 38px;
  padding: 0 var(--spacing-lg);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-full);
  transition: all var(--transition-fast);
  flex-shrink: 0;
}

.btn-search-submit:hover {
  background-color: var(--color-primary-hover);
}

/* Catalog Body */
.catalog-body {
  padding-top: var(--spacing-xl);
}

/* Filters Panel */
.filters-panel {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-md);
  margin-bottom: var(--spacing-lg);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.filters-row {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--spacing-sm);
  align-items: flex-end;
}

@media (min-width: 640px) {
  .filters-row {
    grid-template-columns: repeat(4, 1fr) auto;
  }
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-label {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-secondary);
}

.filter-select {
  height: 38px;
  padding: 0 var(--spacing-sm);
  background-color: var(--color-bg-surface);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  outline: none;
  cursor: pointer;
  transition: border-color var(--transition-fast);
}

.filter-select:focus-visible {
  border-color: var(--color-primary);
}

.filter-select option {
  background-color: var(--color-bg-surface);
  color: var(--color-text-primary);
}

.btn-reset-filters {
  height: 38px;
  padding: 0 var(--spacing-md);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.btn-reset-filters:hover {
  color: var(--color-danger);
  border-color: var(--color-danger);
  background-color: var(--color-danger-soft);
}

.reset-icon {
  font-size: 13px;
}

/* Active Chips */
.active-chips-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding-top: var(--spacing-xs);
  border-top: 1px solid var(--color-border);
  flex-wrap: wrap;
}

.chips-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.chips-list {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid var(--color-primary-glow);
  border-radius: var(--radius-full);
  font-size: 11px;
  font-weight: var(--font-weight-medium);
}

.chip-remove-btn {
  font-size: 10px;
  color: var(--color-primary);
  padding: 0 2px;
}

.chip-remove-btn:hover {
  color: var(--color-text-primary);
}

.btn-clear-all-chips {
  font-size: 11px;
  color: var(--color-text-muted);
  text-decoration: underline;
  padding: 0 4px;
}

.btn-clear-all-chips:hover {
  color: var(--color-danger);
}

/* Results Meta Bar */
.results-meta-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--spacing-md);
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
}

.results-count strong {
  color: var(--color-primary);
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

/* Skeleton Grid */
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
  min-height: 340px;
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
  font-size: 3.5rem;
  margin-bottom: var(--spacing-sm);
}

.state-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: 6px;
}

.state-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 460px;
  line-height: var(--line-height-relaxed);
  margin-bottom: var(--spacing-md);
}

.btn-action-primary {
  padding: var(--spacing-xs) var(--spacing-xl);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-glow);
  transition: all var(--transition-fast);
}

.btn-action-primary:hover {
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
  background-color: var(--color-bg-card);
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
</style>
