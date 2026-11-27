<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
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

// Data states
const comics = ref<ComicListItem[]>([])
const genres = ref<Genre[]>([])
const tags = ref<Tag[]>([])
const paginationMeta = ref<PaginationMeta | null>(null)

// UI & Loading states
const isLoading = ref(true)
const isFilterOptionsLoading = ref(true)
const errorMessage = ref<string | null>(null)

// Filter states (bound to UI matching exact screenshot)
const searchInput = ref('')
const searchMode = ref<'normal' | 'ai'>('normal')
const aiFallbackNotice = ref<string | null>(null)
const selectedGenre = ref('')
const selectedTag = ref('')
const selectedStatus = ref<ComicStatus | ''>('')
const selectedSort = ref<ComicSortOption>('latest')
const currentPage = ref(1)

// Emits for HomePage dual-state toggle (State A Discovery vs State B Search/Filter)
const emit = defineEmits<{
  (e: 'search-state-change', isSearchingOrFiltering: boolean): void
}>()

// Track if a search query or filter has actually been executed by user
const isSearchActive = ref(false)

// Computed: whether user has actively applied search query or non-default filter
const hasActiveSearchOrFilter = computed(() => {
  return (
    searchInput.value.trim() !== '' ||
    selectedGenre.value !== '' ||
    selectedTag.value !== '' ||
    selectedStatus.value !== '' ||
    isSearchActive.value
  )
})

// Race-condition guard
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
        submitSearch()
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
        submitSearch()
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
        submitSearch()
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
        submitSearch()
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
 * Handle switching between normal search and AI search mode.
 * Note: Mode change does NOT auto-trigger search.
 */
function onModeChange() {
  aiFallbackNotice.value = null
}

/**
 * Programmatically switch search mode (normal <-> ai).
 */
function switchSearchMode(mode: 'normal' | 'ai') {
  if (searchMode.value === mode) return
  searchMode.value = mode
  onModeChange()
}

/**
 * Fetch comics list from API using current filters.
 */
async function fetchComics(page = currentPage.value) {
  const requestId = ++latestRequestId
  isLoading.value = true
  errorMessage.value = null
  aiFallbackNotice.value = null
  currentPage.value = page

  // Notify parent component about current search/filter state
  emit('search-state-change', hasActiveSearchOrFilter.value)

  // If AI search mode is active and there is a query string, invoke AI Search API
  if (searchMode.value === 'ai' && searchInput.value.trim()) {
    try {
      const response = await CatalogService.aiSearch({
        q: searchInput.value.trim(),
        mode: 'ai',
        genre: selectedGenre.value || undefined,
        tag: selectedTag.value || undefined,
        status: selectedStatus.value || undefined,
        limit: 18,
      })

      if (requestId === latestRequestId) {
        comics.value = response.data || []
        aiFallbackNotice.value = response.meta?.fallback_notice || null
        paginationMeta.value = {
          current_page: 1,
          last_page: 1,
          per_page: 18,
          total: response.meta?.total || response.data.length,
          from: response.data.length > 0 ? 1 : null,
          to: response.data.length,
          links: [],
          path: '/',
        }
      }
    } catch (error: unknown) {
      if (requestId === latestRequestId) {
        errorMessage.value = getErrorMessage(
          error,
          'Không thể hoàn thành tìm kiếm bằng AI. Vui lòng kiểm tra kết nối mạng và thử lại.'
        )
      }
    } finally {
      if (requestId === latestRequestId) {
        isLoading.value = false
      }
    }
    return
  }

  const params: ListComicsParams = {
    page,
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
 * Manual search triggered ONLY by Enter key or Search button click.
 */
function submitSearch() {
  if (isLoading.value) return

  const cleanQuery = searchInput.value.trim()

  // Guard against empty or whitespace-only search
  if (!cleanQuery) {
    // In AI mode, query is strictly required
    if (searchMode.value === 'ai') {
      return
    }
  }

  // Mark search as active if user has entered query or chosen filters
  isSearchActive.value = !!(
    cleanQuery ||
    selectedGenre.value ||
    selectedTag.value ||
    selectedStatus.value
  )

  fetchComics(1)
}

/**
 * Clear search input only, without triggering search query.
 */
function clearSearch() {
  searchInput.value = ''
  if (!selectedGenre.value && !selectedTag.value && !selectedStatus.value) {
    isSearchActive.value = false
    emit('search-state-change', false)
  } else {
    submitSearch()
  }
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
  isSearchActive.value = false
  emit('search-state-change', false)
  fetchComics(1)
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
  fetchComics(page)
  const el = document.getElementById('catalog-section')
  if (el) {
    el.scrollIntoView({ behavior: 'smooth' })
  }
}

onMounted(async () => {
  await loadFilterOptions()
  fetchComics(1)
})
</script>

<template>
  <section id="catalog-section" class="home-catalog-search-section" aria-label="Danh mục và Tìm kiếm truyện tranh">
    <!-- Catalog Hero Section matching exact screenshot -->
    <div class="catalog-hero">
      <div class="container hero-container">
        <h2 class="hero-title">
          Danh mục <span class="highlight">Truyện tranh</span>
        </h2>
        <p class="hero-subtitle">
          Tìm kiếm và lọc các bộ truyện yêu thích theo thể loại, từ khóa và trạng thái phát hành
        </p>

        <!-- Search Box -->
        <div class="search-box-wrapper">
          <form class="search-form" @submit.prevent="submitSearch">
            <!-- Search Mode Selector Dropdown -->
            <div class="search-mode-container">
              <select
                v-model="searchMode"
                class="search-mode-select"
                aria-label="Chế độ tìm kiếm"
                @change="onModeChange"
              >
                <option value="normal">Tìm kiếm thông thường</option>
                <option value="ai">Tìm kiếm bằng AI</option>
              </select>
            </div>

            <span class="search-icon" aria-hidden="true">{{ searchMode === 'ai' ? '✨' : '🔍' }}</span>
            <input
              v-model="searchInput"
              type="text"
              role="searchbox"
              autocomplete="off"
              class="search-input"
              :placeholder="searchMode === 'ai' ? 'Mô tả truyện theo trí nhớ của bạn (nhân vật, sự kiện, chi tiết)...' : 'Tìm theo tên truyện, tác giả, tựa đề khác...'"
              aria-label="Tìm kiếm truyện tranh"
              :maxlength="searchMode === 'ai' ? 300 : 100"
              @keydown.enter="submitSearch"
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
              :class="{ 'btn-search-ai': searchMode === 'ai' }"
              aria-label="Thực hiện tìm kiếm"
              :disabled="isLoading"
            >
              {{ searchMode === 'ai' ? 'Tìm bằng AI' : 'Tìm kiếm' }}
            </button>
          </form>

          <!-- AI Mode Helper/Hint Banner -->
          <div v-if="searchMode === 'ai' && !aiFallbackNotice" class="ai-mode-hint-box" role="note">
            <span class="ai-hint-sparkle" aria-hidden="true">✨</span>
            <span class="ai-hint-desc">
              Chế độ tìm kiếm AI ngữ nghĩa (Hybrid Semantic Search): Tìm theo nhân vật, cốt truyện, hoặc bối cảnh mơ hồ.
            </span>
          </div>

          <!-- AI Loading State -->
          <div v-if="isLoading && searchMode === 'ai'" class="ai-loading-indicator" role="status">
            <span class="ai-sparkle">✨</span> Đang phân tích truy vấn và đối chiếu ngữ nghĩa trong thư viện...
          </div>

          <!-- AI Fallback Alert -->
          <div v-if="aiFallbackNotice && searchMode === 'ai'" class="ai-fallback-alert" role="alert">
            <span class="fallback-icon">ℹ️</span>
            <div class="fallback-content">
              <strong>Thông báo chuyển hướng:</strong> {{ aiFallbackNotice }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content Area: Filters Panel & Comics Grid -->
    <div class="container catalog-body">
      <!-- Filter Bar Panel matching exact screenshot (Always visible by default) -->
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
              @change="submitSearch"
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
              @change="submitSearch"
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
              @change="submitSearch"
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
              @change="submitSearch"
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

        <!-- Active Filter Chips matching exact screenshot -->
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

      <!-- Results Section (Visible only when searching or filtering) -->
      <div v-if="hasActiveSearchOrFilter" class="search-results-wrapper">
        <!-- Results Info Bar -->
        <div class="results-meta-bar">
        <span v-if="paginationMeta" class="results-count">
          <template v-if="searchMode === 'ai' && searchInput.trim()">
            Tìm thấy <strong>{{ paginationMeta.total }}</strong> bộ truyện theo ngữ nghĩa AI
          </template>
          <template v-else>
            Tìm thấy <strong>{{ paginationMeta.total }}</strong> bộ truyện
          </template>
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
        <h3 class="state-title">Đã xảy ra sự cố khi tải dữ liệu</h3>
        <p class="state-desc">{{ errorMessage }}</p>
        <button
          type="button"
          class="btn-retry"
          aria-label="Thử tải lại danh sách truyện"
          @click="fetchComics(currentPage)"
        >
          Thử lại
        </button>
      </div>

      <!-- Empty State -->
      <div v-else-if="comics.length === 0" class="state-container empty-state">
        <span class="state-icon" aria-hidden="true">📚</span>
        <h3 class="state-title">
          {{ searchMode === 'ai' ? 'Chưa tìm thấy truyện phù hợp với mô tả AI' : 'Không tìm thấy truyện phù hợp' }}
        </h3>
        <p class="state-desc">
          <template v-if="searchMode === 'ai'">
            Không có bộ truyện nào đạt ngưỡng phù hợp ngữ nghĩa với mô tả hiện tại. Bạn hãy thử mô tả chi tiết hơn về nhân vật, bối cảnh, hoặc chuyển sang Tìm kiếm thông thường.
          </template>
          <template v-else>
            Không có bộ truyện nào thỏa mãn các tiêu chí tìm kiếm hoặc bộ lọc hiện tại của bạn.
          </template>
        </p>
        <div class="empty-state-actions">
          <button
            v-if="hasActiveFilters"
            type="button"
            class="btn-action-primary"
            aria-label="Xóa tất cả bộ lọc để xem toàn bộ danh mục"
            @click="resetAllFilters"
          >
            Xóa toàn bộ bộ lọc
          </button>
          <button
            v-if="searchMode === 'ai'"
            type="button"
            class="btn-action-secondary"
            aria-label="Chuyển về tìm kiếm thông thường"
            @click="switchSearchMode('normal')"
          >
            Chuyển về tìm kiếm thông thường
          </button>
        </div>
      </div>

      <!-- Comics Grid & Pagination -->
      <div v-else class="results-content">
        <div class="comics-grid">
          <ComicCard
            v-for="comic in comics"
            :key="comic.id"
            :comic="comic"
            :search-mode="searchMode"
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
      </div>
    </div>
  </section>
</template>

<style scoped>
.home-catalog-search-section {
  padding-bottom: var(--spacing-2xl);
  display: flex;
  flex-direction: column;
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

.search-input::-webkit-search-decoration,
.search-input::-webkit-search-cancel-button,
.search-input::-webkit-search-results-button,
.search-input::-webkit-search-results-decoration {
  -webkit-appearance: none;
  appearance: none;
  display: none;
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
  cursor: pointer;
}

.btn-search-submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-search-submit:hover {
  background-color: var(--color-primary-hover);
}

.search-mode-container {
  margin-right: var(--spacing-xs);
  border-right: 1px solid var(--color-border);
  padding-right: var(--spacing-xs);
  display: flex;
  align-items: center;
}

.search-mode-select {
  background: transparent;
  color: var(--color-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  border: none;
  outline: none;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: var(--radius-sm);
}

.search-mode-select option {
  background-color: var(--color-bg-card);
  color: var(--color-text-primary);
}

.btn-search-ai {
  background: linear-gradient(135deg, var(--color-primary) 0%, #a855f7 100%);
  color: #fff;
  box-shadow: 0 2px 10px rgba(168, 85, 247, 0.25);
}

.btn-search-ai:hover {
  filter: brightness(1.1);
}

.ai-mode-hint-box {
  margin-top: var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-md);
  background-color: var(--color-primary-soft);
  border: 1px solid rgba(56, 189, 248, 0.25);
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  font-size: var(--font-size-xs);
  color: var(--color-primary);
  text-align: center;
}

.ai-hint-sparkle {
  font-size: 14px;
}

.ai-hint-desc {
  color: var(--color-text-secondary);
}

.ai-loading-indicator {
  margin-top: var(--spacing-sm);
  font-size: var(--font-size-xs);
  color: var(--color-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
}

.ai-fallback-alert {
  margin-top: var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: rgba(234, 179, 8, 0.12);
  border: 1px solid rgba(234, 179, 8, 0.35);
  border-radius: var(--radius-md);
  font-size: var(--font-size-xs);
  color: #facc15;
  text-align: left;
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
  cursor: pointer;
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
  background: transparent;
  border: none;
  color: inherit;
  font-size: 10px;
  cursor: pointer;
  padding: 0 2px;
  opacity: 0.7;
}

.chip-remove-btn:hover {
  opacity: 1;
}

.btn-clear-all-chips {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 11px;
  cursor: pointer;
  padding: 2px 6px;
  text-decoration: underline;
}

.btn-clear-all-chips:hover {
  color: var(--color-danger);
}

/* Results Meta Bar */
.results-meta-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--spacing-md);
  padding-bottom: var(--spacing-xs);
}

.results-count {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

.results-count strong {
  color: var(--color-text-primary);
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
    gap: var(--spacing-lg);
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
  }
}

/* Skeletons */
.card-skeleton {
  background-color: var(--color-bg-card);
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--color-border);
}

.skeleton-cover {
  width: 100%;
  aspect-ratio: 3 / 4;
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

.skeleton-info {
  padding: var(--spacing-xs);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  height: 12px;
  border-radius: var(--radius-sm);
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

.skeleton-line.title {
  width: 80%;
  height: 14px;
}

.skeleton-line.author {
  width: 50%;
}

.skeleton-line.tags {
  width: 65%;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* State Containers (Empty / Error) */
.state-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-3xl) var(--spacing-md);
  text-align: center;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  margin-bottom: var(--spacing-xl);
}

.state-icon {
  font-size: 48px;
  margin-bottom: var(--spacing-md);
}

.state-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-semibold);
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

.btn-retry,
.btn-action-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-xs) var(--spacing-lg);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-retry:hover,
.btn-action-primary:hover {
  background-color: var(--color-primary-hover);
}

.empty-state-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
  margin-top: var(--spacing-md);
}

.btn-action-secondary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-xs) var(--spacing-md);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-action-secondary:hover {
  background-color: var(--color-bg-card-hover);
  border-color: var(--color-border-subtle);
  color: var(--color-primary);
}

/* Pagination */
.pagination-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-sm);
  margin-top: var(--spacing-2xl);
  padding-top: var(--spacing-md);
}

.btn-page {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 6px 14px;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-page:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
  background-color: var(--color-bg-surface);
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.page-indicator {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  padding: 0 var(--spacing-sm);
}

.page-indicator strong {
  color: var(--color-primary);
}

/* Mobile Responsive */
@media (max-width: 640px) {
  .search-form {
    flex-wrap: wrap;
    border-radius: var(--radius-lg);
    padding: 8px;
    gap: 8px;
  }

  .search-mode-container {
    width: 100%;
    border-right: none;
    border-bottom: 1px solid var(--color-border);
    padding-bottom: 6px;
    margin-right: 0;
  }

  .search-mode-select {
    width: 100%;
  }

  .btn-search-submit {
    width: 100%;
    border-radius: var(--radius-md);
  }

  .filters-row {
    grid-template-columns: 1fr;
  }
}
</style>
