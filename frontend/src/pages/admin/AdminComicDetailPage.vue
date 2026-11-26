<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type {
  AdminComicDetail,
  AdminComicChapterItem,
  AdminComicChapterDetail,
} from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

const props = defineProps<{
  comicId?: string | number
  chapterId?: string | number
  defaultTab?: 'overview' | 'chapters'
}>()

const route = useRoute()
const router = useRouter()

// Resolved IDs
const currentComicId = Number(props.comicId || route.params.comicId)
const activeTab = ref<'overview' | 'chapters'>(props.defaultTab || (route.query.tab === 'chapters' ? 'chapters' : 'overview'))

// State: Comic
const comic = ref<AdminComicDetail | null>(null)
const isLoadingComic = ref(false)
const comicErrorMessage = ref<string | null>(null)

// State: Chapters
const chapters = ref<AdminComicChapterItem[]>([])
const isLoadingChapters = ref(false)
const chaptersErrorMessage = ref<string | null>(null)
const searchQuery = ref('')
const statusFilter = ref('all')
const sortOption = ref<'chapter_asc' | 'chapter_desc' | 'created_desc' | 'created_asc'>('chapter_asc')
const currentPage = ref(1)
const perPage = ref(20)
const totalPages = ref(1)
const totalChapters = ref(0)

// Modal: Chapter Detail
const isChapterDetailModalOpen = ref(false)
const selectedChapter = ref<AdminComicChapterDetail | null>(null)
const isLoadingChapterDetail = ref(false)
const failedImageIds = ref<Set<number>>(new Set())
const viewerWidthMode = ref<'standard' | 'wide' | 'full'>('wide')

const viewerMaxWidth = computed(() => {
  if (viewerWidthMode.value === 'standard') return '650px'
  if (viewerWidthMode.value === 'wide') return '820px'
  return '100%'
})

function handleImageError(id: number) {
  failedImageIds.value.add(id)
}

function resolveStorageUrl(pathOrUrl: string | null | undefined): string {
  if (!pathOrUrl) return ''
  if (pathOrUrl.startsWith('http://localhost/storage/')) {
    return pathOrUrl.replace('http://localhost/storage/', '/storage/')
  }
  if (pathOrUrl.startsWith('http://127.0.0.1/storage/')) {
    return pathOrUrl.replace('http://127.0.0.1/storage/', '/storage/')
  }
  if (pathOrUrl.startsWith('http://') || pathOrUrl.startsWith('https://')) {
    return pathOrUrl
  }
  const clean = pathOrUrl.replace(/^\/+/, '')
  return clean.startsWith('storage/') ? `/${clean}` : `/storage/${clean}`
}

function formatChapterNumber(num: string | number | undefined | null): string {
  if (num === undefined || num === null) return ''
  const parsed = parseFloat(String(num))
  return isNaN(parsed) ? String(num) : String(parsed)
}

function openChapterStatusFromDetail() {
  if (!selectedChapter.value) return
  const chap = selectedChapter.value
  isChapterDetailModalOpen.value = false
  openChapterStatusModal(chap)
}

// Modal: Chapter Status
const isChapterStatusModalOpen = ref(false)
const targetChapter = ref<AdminComicChapterItem | null>(null)
const chapterFormStatus = ref<'draft' | 'pending' | 'published' | 'rejected'>('published')
const chapterFormRejectionReason = ref('')
const isUpdatingChapterStatus = ref(false)

// Modal: Comic VIP Edit
const isVipModalOpen = ref(false)
const formIsVip = ref(false)
const formPrice = ref(0)
const isUpdatingVip = ref(false)

// Modal: Comic Status Edit
const isComicStatusModalOpen = ref(false)
const formComicStatus = ref<'ongoing' | 'completed' | 'hiatus'>('ongoing')
const formApprovalStatus = ref<'draft' | 'pending' | 'approved' | 'rejected'>('approved')
const formIsFeatured = ref(false)
const formComicRejectionReason = ref('')
const isUpdatingComicStatus = ref(false)
const pendingChaptersForDetailStatus = ref<AdminComicChapterItem[]>([])
const selectedDetailStatusChapterIds = ref<number[]>([])
const isLoadingPendingDetailChapters = ref(false)

// Toast State
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)
let toastTimer: ReturnType<typeof setTimeout> | null = null

function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

function formatDate(dateStr: string | null | undefined) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// Fetch Comic Detail
async function fetchComic() {
  if (!currentComicId || isNaN(currentComicId)) {
    comicErrorMessage.value = 'Mã bộ truyện không hợp lệ.'
    return
  }
  isLoadingComic.value = true
  comicErrorMessage.value = null
  try {
    const res = await AdminService.getComicDetail(currentComicId)
    comic.value = res.data
    totalChapters.value = res.data.chapters_count || 0
  } catch (err: unknown) {
    comicErrorMessage.value = getErrorMessage(err, 'Không thể tải thông tin chi tiết bộ truyện.')
  } finally {
    isLoadingComic.value = false
  }
}

// Fetch Chapters List with pagination
async function fetchChapters() {
  if (!currentComicId || isNaN(currentComicId)) return
  isLoadingChapters.value = true
  chaptersErrorMessage.value = null

  // Map sortOption
  let sort_by = 'chapter_number'
  let sort_dir = 'asc'
  if (sortOption.value === 'chapter_desc') {
    sort_by = 'chapter_number'
    sort_dir = 'desc'
  } else if (sortOption.value === 'created_desc') {
    sort_by = 'created_at'
    sort_dir = 'desc'
  } else if (sortOption.value === 'created_asc') {
    sort_by = 'created_at'
    sort_dir = 'asc'
  }

  try {
    const res = await AdminService.getComicChapters(currentComicId, {
      q: searchQuery.value || undefined,
      status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      sort_by,
      sort_dir,
      page: currentPage.value,
      per_page: perPage.value,
    })
    chapters.value = res.data || []
    if (res.meta) {
      totalPages.value = res.meta.last_page
      totalChapters.value = res.meta.total
      if (comic.value) {
        comic.value.chapters_count = res.meta.total
      }
    }
  } catch (err: unknown) {
    chaptersErrorMessage.value = getErrorMessage(err, 'Không thể tải danh sách chương của bộ truyện.')
  } finally {
    isLoadingChapters.value = false
  }
}

function handleChaptersSearch() {
  currentPage.value = 1
  fetchChapters()
}

function handlePageChange(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  fetchChapters()
}

function switchTab(tab: 'overview' | 'chapters') {
  activeTab.value = tab
  router.replace({
    query: { ...route.query, tab: tab === 'chapters' ? 'chapters' : undefined },
  })
  if (tab === 'chapters' && chapters.value.length === 0) {
    fetchChapters()
  }
}

// Chapter Detail Modal Actions
async function openChapterDetailModal(chapterId: number) {
  isChapterDetailModalOpen.value = true
  isLoadingChapterDetail.value = true
  selectedChapter.value = null
  failedImageIds.value.clear()
  try {
    const res = await AdminService.getComicChapterDetail(currentComicId, chapterId)
    selectedChapter.value = res.data
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể tải chi tiết chương.'), 'error')
    isChapterDetailModalOpen.value = false
  } finally {
    isLoadingChapterDetail.value = false
  }
}

// Chapter Status Modal Actions
function openChapterStatusModal(chap: AdminComicChapterItem) {
  targetChapter.value = chap
  chapterFormStatus.value = chap.status
  chapterFormRejectionReason.value = chap.rejection_reason || ''
  isChapterStatusModalOpen.value = true
}

async function handleSaveChapterStatus() {
  if (!targetChapter.value) return
  isUpdatingChapterStatus.value = true
  try {
    const res = await AdminService.updateComicChapterStatus(currentComicId, targetChapter.value.id, {
      status: chapterFormStatus.value,
      rejection_reason: chapterFormStatus.value === 'rejected' ? chapterFormRejectionReason.value : undefined,
    })
    targetChapter.value.status = res.data.status
    targetChapter.value.rejection_reason = res.data.rejection_reason
    showToast(res.message || 'Cập nhật trạng thái chương thành công!')
    isChapterStatusModalOpen.value = false
    fetchChapters()
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể cập nhật trạng thái chương.'), 'error')
  } finally {
    isUpdatingChapterStatus.value = false
  }
}

// Comic VIP Modal Actions
function openVipModal() {
  if (!comic.value) return
  formIsVip.value = comic.value.is_vip
  formPrice.value = comic.value.price || 0
  isVipModalOpen.value = true
}

async function handleSaveComicVip() {
  if (!comic.value) return
  isUpdatingVip.value = true
  try {
    const res = await AdminService.updateComicVip(comic.value.id, {
      is_vip: formIsVip.value,
      price: formIsVip.value ? Number(formPrice.value) : 0,
    })
    comic.value.is_vip = res.data.is_vip
    comic.value.price = res.data.price
    showToast(res.message || 'Cập nhật thiết lập VIP thành công!')
    isVipModalOpen.value = false
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể cập nhật thiết lập VIP.'), 'error')
  } finally {
    isUpdatingVip.value = false
  }
}

// Comic Status Modal Actions
async function loadPendingChaptersForStatusModal() {
  if (!comic.value) return
  isLoadingPendingDetailChapters.value = true
  try {
    const res = await AdminService.getComicChapters(comic.value.id, {
      status: 'pending',
      per_page: 50,
    })
    pendingChaptersForDetailStatus.value = res.data || []
  } catch {
    pendingChaptersForDetailStatus.value = []
  } finally {
    isLoadingPendingDetailChapters.value = false
  }
}

function selectAllDetailStatusChapters() {
  selectedDetailStatusChapterIds.value = pendingChaptersForDetailStatus.value.map((c) => c.id)
}

function deselectAllDetailStatusChapters() {
  selectedDetailStatusChapterIds.value = []
}

function toggleDetailStatusChapter(id: number) {
  const idx = selectedDetailStatusChapterIds.value.indexOf(id)
  if (idx > -1) {
    selectedDetailStatusChapterIds.value.splice(idx, 1)
  } else {
    selectedDetailStatusChapterIds.value.push(id)
  }
}

function openComicStatusModal() {
  if (!comic.value) return
  formComicStatus.value = comic.value.status
  formApprovalStatus.value = comic.value.approval_status
  formIsFeatured.value = comic.value.is_featured
  formComicRejectionReason.value = comic.value.rejection_reason || ''
  selectedDetailStatusChapterIds.value = []
  pendingChaptersForDetailStatus.value = []
  isComicStatusModalOpen.value = true
  loadPendingChaptersForStatusModal()
}

async function handleSaveComicStatus() {
  if (!comic.value) return
  isUpdatingComicStatus.value = true
  try {
    await AdminService.updateComicStatus(comic.value.id, {
      status: formComicStatus.value,
      is_featured: formIsFeatured.value,
    })

    if (formApprovalStatus.value !== comic.value.approval_status) {
      const chapIds = formApprovalStatus.value === 'approved' && selectedDetailStatusChapterIds.value.length > 0
        ? selectedDetailStatusChapterIds.value
        : undefined

      await AdminService.updateComicApprovalStatus(comic.value.id, {
        approval_status: formApprovalStatus.value,
        rejection_reason: formComicRejectionReason.value || undefined,
        chapter_ids: chapIds,
      })
    }

    showToast('Cập nhật trạng thái truyện thành công!')
    isComicStatusModalOpen.value = false
    fetchComic()
    fetchChapters()
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể cập nhật trạng thái truyện.'), 'error')
  } finally {
    isUpdatingComicStatus.value = false
  }
}

onMounted(() => {
  fetchComic()
  fetchChapters()

  // If a chapterId was passed in route params, auto-open chapter detail modal
  if (props.chapterId || route.params.chapterId) {
    const chapId = Number(props.chapterId || route.params.chapterId)
    if (chapId) {
      activeTab.value = 'chapters'
      openChapterDetailModal(chapId)
    }
  }
})

watch(
  () => route.params.comicId,
  (newId) => {
    if (newId && Number(newId) !== currentComicId) {
      window.location.reload()
    }
  }
)
</script>

<template>
  <AdminLayout>
    <div class="admin-comic-detail-page">
      <!-- Toast Notification -->
      <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
        <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
        <span class="toast-text">{{ toastMessage.text }}</span>
      </div>

      <!-- Navigation & Breadcrumb -->
      <nav class="breadcrumb-bar" aria-label="Đường dẫn điều hướng">
        <RouterLink to="/admin/comics" class="breadcrumb-link">
          ← Quay lại Danh sách truyện
        </RouterLink>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">{{ comic?.title || `Truyện #${currentComicId}` }}</span>
      </nav>

      <!-- Loading Comic -->
      <div v-if="isLoadingComic" class="loading-container">
        <span class="spinner"></span>
        <p>Đang tải thông tin bộ truyện...</p>
      </div>

      <!-- Error Comic -->
      <div v-else-if="comicErrorMessage" class="alert-error" role="alert">
        <span>⚠️ {{ comicErrorMessage }}</span>
        <button type="button" class="btn btn-sm btn-outline" @click="fetchComic">Thử lại</button>
      </div>

      <!-- Main Comic Detail View -->
      <div v-else-if="comic" class="detail-container">
        <!-- Top Hero Card -->
        <header class="comic-hero-card">
          <div class="comic-cover-wrap">
            <img
              v-if="comic.cover_path"
              :src="comic.cover_path.startsWith('http') ? comic.cover_path : `/storage/${comic.cover_path}`"
              :alt="comic.title"
              class="comic-cover-img"
            />
            <div v-else class="comic-cover-placeholder">
              <span>📚</span>
            </div>
          </div>

          <div class="comic-hero-info">
            <div class="hero-top-row">
              <div class="badges-group">
                <span class="approval-badge" :class="comic.approval_status">
                  {{ comic.approval_status === 'approved' ? 'Đã duyệt' : comic.approval_status === 'pending' ? 'Chờ duyệt' : comic.approval_status === 'draft' ? 'Bản nháp' : 'Từ chối' }}
                </span>
                <span class="status-pill">{{ comic.status }}</span>
                <span v-if="comic.is_vip" class="vip-badge">💎 VIP ({{ comic.price }} Coin)</span>
                <span v-else class="free-badge">Miễn phí</span>
                <span v-if="comic.is_featured" class="badge-featured">⭐ Nổi bật</span>
              </div>

              <div class="hero-actions">
                <button type="button" class="btn btn-secondary btn-sm" @click="openVipModal">
                  💎 Cài đặt VIP
                </button>
                <button type="button" class="btn btn-secondary btn-sm" @click="openComicStatusModal">
                  ⚙️ Trạng thái & Duyệt
                </button>
                <a
                  :href="`/comics/${comic.slug}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="btn btn-primary btn-sm btn-preview"
                >
                  🔗 Xem trang đọc ↗
                </a>
              </div>
            </div>

            <h1 class="comic-title">{{ comic.title }}</h1>
            <p v-if="comic.alternative_titles" class="comic-alt-titles">
              Tên gọi khác: <em>{{ comic.alternative_titles }}</em>
            </p>

            <div class="hero-meta-row">
              <span><strong>Tác giả:</strong> {{ comic.author_name }}</span>
              <span class="meta-dot">•</span>
              <span><strong>Đăng bởi:</strong> {{ comic.user?.name || 'Ẩn danh' }} ({{ comic.user?.email || 'N/A' }})</span>
              <span class="meta-dot">•</span>
              <span><strong>Độ tuổi:</strong> {{ comic.age_rating }}</span>
            </div>

            <!-- Metric Stats Row -->
            <div class="stats-pills-row">
              <div class="stat-pill">
                <span class="stat-icon">📚</span>
                <span class="stat-value">{{ comic.chapters_count }}</span>
                <span class="stat-label">Chương</span>
              </div>
              <div class="stat-pill">
                <span class="stat-icon">👁️</span>
                <span class="stat-value">{{ comic.views_count.toLocaleString() }}</span>
                <span class="stat-label">Lượt xem</span>
              </div>
              <div class="stat-pill">
                <span class="stat-icon">❤️</span>
                <span class="stat-value">{{ comic.follows_count.toLocaleString() }}</span>
                <span class="stat-label">Theo dõi</span>
              </div>
              <div class="stat-pill">
                <span class="stat-icon">💎</span>
                <span class="stat-value">{{ comic.purchases_count || 0 }}</span>
                <span class="stat-label">Lượt mua VIP</span>
              </div>
            </div>
          </div>
        </header>

        <!-- Tabs Navigation -->
        <nav class="tabs-nav" role="tablist">
          <button
            type="button"
            class="tab-btn"
            :class="{ active: activeTab === 'overview' }"
            role="tab"
            :aria-selected="activeTab === 'overview'"
            @click="switchTab('overview')"
          >
            📖 Tổng quan truyện
          </button>
          <button
            type="button"
            class="tab-btn"
            :class="{ active: activeTab === 'chapters' }"
            role="tab"
            :aria-selected="activeTab === 'chapters'"
            @click="switchTab('chapters')"
          >
            📑 Quản lý chương ({{ totalChapters }})
          </button>
        </nav>

        <!-- TAB 1: OVERVIEW -->
        <section v-if="activeTab === 'overview'" class="tab-panel tab-overview" role="tabpanel">
          <div class="overview-grid">
            <!-- Left: Synopsis & Categories -->
            <div class="panel-card">
              <h3 class="card-title">Giới thiệu & Thể loại</h3>
              <p class="comic-desc">
                {{ comic.description || 'Chưa có mô tả cho bộ truyện này.' }}
              </p>

              <div class="tags-section">
                <span class="section-subtitle">Thể loại (Genres):</span>
                <div v-if="comic.genres && comic.genres.length > 0" class="chips-wrap">
                  <span v-for="g in comic.genres" :key="g.id" class="genre-chip">{{ g.name }}</span>
                </div>
                <span v-else class="text-muted text-sm">Chưa gắn thể loại</span>
              </div>

              <div class="tags-section" style="margin-top: 16px;">
                <span class="section-subtitle">Thẻ truyện (Tags):</span>
                <div v-if="comic.tags && comic.tags.length > 0" class="chips-wrap">
                  <span v-for="t in comic.tags" :key="t.id" class="tag-chip">#{{ t.name }}</span>
                </div>
                <span v-else class="text-muted text-sm">Chưa gắn thẻ truyện</span>
              </div>
            </div>

            <!-- Right: Creator & Admin Audit Info -->
            <div class="panel-card">
              <h3 class="card-title">Hồ sơ Tác giả / Người đăng</h3>
              <div class="creator-profile-box">
                <div class="creator-avatar-wrap">
                  <span>{{ (comic.user?.name || 'A')[0].toUpperCase() }}</span>
                </div>
                <div class="creator-details">
                  <h4 class="creator-name">{{ comic.user?.name || 'Ẩn danh' }}</h4>
                  <p class="creator-email">{{ comic.user?.email || 'N/A' }}</p>
                  <div class="creator-meta-tags">
                    <span class="badge-tag">Vai trò: {{ comic.user?.role || 'creator' }}</span>
                    <span class="badge-tag" :class="comic.user?.is_active ? 'active' : 'locked'">
                      {{ comic.user?.is_active ? 'Đang hoạt động' : 'Tài khoản khóa' }}
                    </span>
                    <span class="badge-tag">ID: #{{ comic.user?.id }}</span>
                  </div>
                </div>
              </div>

              <hr class="card-divider" />

              <h3 class="card-title" style="margin-top: 16px;">Thông tin Kiểm duyệt & Hệ thống</h3>
              <ul class="audit-list">
                <li>
                  <span class="audit-label">Trạng thái duyệt:</span>
                  <span class="approval-badge" :class="comic.approval_status">
                    {{ comic.approval_status }}
                  </span>
                </li>
                <li v-if="comic.approver">
                  <span class="audit-label">Người duyệt:</span>
                  <span>{{ comic.approver.name }} ({{ comic.approver.email }})</span>
                </li>
                <li v-if="comic.rejection_reason">
                  <span class="audit-label">Lý do từ chối:</span>
                  <span class="text-danger">{{ comic.rejection_reason }}</span>
                </li>
                <li>
                  <span class="audit-label">Ngày tạo:</span>
                  <span>{{ formatDate(comic.created_at) }}</span>
                </li>
                <li>
                  <span class="audit-label">Cập nhật lần cuối:</span>
                  <span>{{ formatDate(comic.updated_at) }}</span>
                </li>
              </ul>
            </div>
          </div>
        </section>

        <!-- TAB 2: CHAPTERS MANAGEMENT -->
        <section v-if="activeTab === 'chapters'" class="tab-panel tab-chapters" role="tabpanel">
          <!-- Chapters Toolbar -->
          <div class="chapters-toolbar">
            <div class="search-wrap">
              <input
                v-model="searchQuery"
                type="text"
                class="search-input"
                placeholder="Tìm theo tên chương, số chương..."
                @keyup.enter="handleChaptersSearch"
              />
              <button type="button" class="btn btn-secondary btn-sm" @click="handleChaptersSearch">
                🔍 Tìm
              </button>
            </div>

            <div class="filters-wrap">
              <div class="filter-item">
                <label class="filter-label">Trạng thái:</label>
                <select v-model="statusFilter" class="filter-select" @change="handleChaptersSearch">
                  <option value="all">Tất cả</option>
                  <option value="published">Đã phát hành (Published)</option>
                  <option value="pending">Chờ duyệt (Pending)</option>
                  <option value="draft">Bản nháp (Draft)</option>
                  <option value="rejected">Bị từ chối (Rejected)</option>
                </select>
              </div>

              <div class="filter-item">
                <label class="filter-label">Sắp xếp:</label>
                <select v-model="sortOption" class="filter-select" @change="handleChaptersSearch">
                  <option value="chapter_asc">Số chương: Tăng dần (1 → n)</option>
                  <option value="chapter_desc">Số chương: Giảm dần (n → 1)</option>
                  <option value="created_desc">Ngày tạo: Mới nhất</option>
                  <option value="created_asc">Ngày tạo: Cũ nhất</option>
                </select>
              </div>

              <div class="filter-item">
                <label class="filter-label">Hiển thị:</label>
                <select v-model.number="perPage" class="filter-select" @change="handleChaptersSearch">
                  <option :value="10">10 / trang</option>
                  <option :value="20">20 / trang</option>
                  <option :value="50">50 / trang</option>
                  <option :value="100">100 / trang</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Error Alert -->
          <div v-if="chaptersErrorMessage" class="alert-error" role="alert">
            <span>⚠️ {{ chaptersErrorMessage }}</span>
            <button type="button" class="btn btn-sm btn-outline" @click="fetchChapters">Thử lại</button>
          </div>

          <!-- Chapters Table Container -->
          <div class="table-container">
            <div v-if="isLoadingChapters" class="loading-state">
              <span class="spinner"></span>
              <p>Đang tải danh sách chương...</p>
            </div>

            <table v-else-if="chapters.length > 0" class="data-table">
              <thead>
                <tr>
                  <th style="width: 100px">Số chương</th>
                  <th>Tên chương</th>
                  <th>Số trang ảnh</th>
                  <th>Lượt xem</th>
                  <th>Trạng thái</th>
                  <th>Ngày cập nhật</th>
                  <th style="width: 220px; text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="chap in chapters" :key="chap.id">
                  <td>
                    <strong class="chapter-number-pill">#{{ chap.chapter_number }}</strong>
                  </td>
                  <td>
                    <div class="chapter-title-cell">
                      <span>{{ chap.title || `Chương ${chap.chapter_number}` }}</span>
                      <span class="chapter-slug-text">{{ chap.slug }}</span>
                    </div>
                  </td>
                  <td>
                    <span class="badge-count">{{ chap.images_count }} trang</span>
                  </td>
                  <td>{{ chap.views_count.toLocaleString() }}</td>
                  <td>
                    <span class="approval-badge" :class="chap.status">
                      {{ chap.status === 'published' ? 'Đã phát hành' : chap.status === 'pending' ? 'Chờ duyệt' : chap.status === 'draft' ? 'Bản nháp' : 'Bị từ chối' }}
                    </span>
                    <div v-if="chap.rejection_reason && chap.status === 'rejected'" class="rejection-hint" :title="chap.rejection_reason">
                      ⚠️ {{ chap.rejection_reason }}
                    </div>
                  </td>
                  <td>
                    <span class="text-xs text-muted">{{ formatDate(chap.created_at) }}</span>
                  </td>
                  <td style="text-align: right">
                    <div class="actions-group">
                      <button
                        type="button"
                        class="btn btn-sm btn-secondary"
                        title="Xem trang ảnh và chi tiết chương"
                        @click="openChapterDetailModal(chap.id)"
                      >
                        👁️ Chi tiết ảnh
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-secondary"
                        title="Cập nhật trạng thái chương"
                        @click="openChapterStatusModal(chap)"
                      >
                        ⚙️ Đổi trạng thái
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- Empty State -->
            <div v-else class="empty-state">
              <span class="empty-icon">📑</span>
              <h3>Chưa tìm thấy chương nào</h3>
              <p>Bộ truyện chưa có chương nào hoặc không có chương nào khớp với bộ lọc hiện tại.</p>
            </div>

            <!-- Chapters Pagination -->
            <div v-if="totalPages > 1" class="pagination-bar">
              <span>Trang {{ currentPage }} / {{ totalPages }} (Tổng {{ totalChapters }} chương)</span>
              <div class="pagination-buttons">
                <button
                  type="button"
                  class="btn btn-secondary btn-sm"
                  :disabled="currentPage <= 1 || isLoadingChapters"
                  @click="handlePageChange(currentPage - 1)"
                >
                  ← Trước
                </button>
                <button
                  type="button"
                  class="btn btn-secondary btn-sm"
                  :disabled="currentPage >= totalPages || isLoadingChapters"
                  @click="handlePageChange(currentPage + 1)"
                >
                  Sau →
                </button>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- MODAL 1: CHAPTER DETAIL (Full Webtoon Gallery Viewer) -->
      <div
        v-if="isChapterDetailModalOpen"
        class="modal-backdrop"
        @click.self="isChapterDetailModalOpen = false"
      >
        <div class="modal-box modal-viewer">
          <!-- Sticky Header -->
          <div class="modal-header">
            <div class="modal-header-titles">
              <div class="header-breadcrumbs">
                <span class="text-muted">Bộ truyện:</span>
                <span class="comic-name-badge">{{ comic?.title }}</span>
              </div>
              <h3 class="chapter-modal-title">
                Chương #{{ formatChapterNumber(selectedChapter?.chapter_number) }}<span v-if="selectedChapter?.title">: {{ selectedChapter.title }}</span>
              </h3>
            </div>

            <div class="header-controls">
              <span class="image-count-pill">
                📑 {{ selectedChapter?.images?.length || 0 }} trang ảnh
              </span>

              <!-- Width mode selector -->
              <div class="width-selector" role="group" aria-label="Độ rộng xem ảnh">
                <button
                  type="button"
                  class="btn-width-toggle"
                  :class="{ active: viewerWidthMode === 'standard' }"
                  title="Độ rộng chuẩn 650px"
                  @click="viewerWidthMode = 'standard'"
                >
                  Vừa
                </button>
                <button
                  type="button"
                  class="btn-width-toggle"
                  :class="{ active: viewerWidthMode === 'wide' }"
                  title="Độ rộng 820px"
                  @click="viewerWidthMode = 'wide'"
                >
                  Rộng
                </button>
                <button
                  type="button"
                  class="btn-width-toggle"
                  :class="{ active: viewerWidthMode === 'full' }"
                  title="Độ rộng toàn khung (100%)"
                  @click="viewerWidthMode = 'full'"
                >
                  100%
                </button>
              </div>

              <button
                type="button"
                class="btn-close"
                title="Đóng (ESC)"
                @click="isChapterDetailModalOpen = false"
              >
                ✕
              </button>
            </div>
          </div>

          <!-- Modal Body with Scrollable Viewer -->
          <div class="modal-body viewer-body">
            <div v-if="isLoadingChapterDetail" class="loading-state">
              <span class="spinner"></span>
              <p>Đang tải dữ liệu trang ảnh chương...</p>
            </div>

            <div v-else-if="selectedChapter" class="chapter-viewer-container">
              <!-- Meta summary row -->
              <div class="chapter-meta-summary">
                <div class="meta-item">
                  <span class="meta-label">Trạng thái:</span>
                  <span class="approval-badge" :class="selectedChapter.status">
                    {{ selectedChapter.status === 'published' ? 'Đã phát hành' : selectedChapter.status === 'pending' ? 'Chờ duyệt' : selectedChapter.status === 'draft' ? 'Bản nháp' : 'Từ chối' }}
                  </span>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Lượt xem:</span>
                  <strong>{{ selectedChapter.views_count.toLocaleString() }}</strong>
                </div>
                <div class="meta-item">
                  <span class="meta-label">Ngày tạo:</span>
                  <span>{{ formatDate(selectedChapter.created_at) }}</span>
                </div>
                <div v-if="selectedChapter.approver" class="meta-item">
                  <span class="meta-label">Duyệt bởi:</span>
                  <span>{{ selectedChapter.approver.name }}</span>
                </div>
                <div v-if="selectedChapter.rejection_reason && selectedChapter.status === 'rejected'" class="meta-item text-danger full-col">
                  <span class="meta-label">Lý do từ chối:</span>
                  <span>{{ selectedChapter.rejection_reason }}</span>
                </div>
              </div>

              <!-- Webtoon Scroll Viewer Strip -->
              <div class="webtoon-scroll-viewport webtoon-gallery">
                <div
                  v-if="selectedChapter.images && selectedChapter.images.length > 0"
                  class="webtoon-strip"
                  :style="{ maxWidth: viewerMaxWidth }"
                >
                  <div
                    v-for="img in selectedChapter.images"
                    :key="img.id"
                    class="webtoon-page-wrapper"
                  >
                    <!-- Floating Page Tag -->
                    <div class="page-indicator-pill">
                      Trang {{ img.page_number }} / {{ selectedChapter.images.length }}
                    </div>

                    <!-- Clean Unsquished Image -->
                    <img
                      v-if="!failedImageIds.has(img.id)"
                      :src="resolveStorageUrl(img.image_url || img.image_path)"
                      :alt="`Trang ${img.page_number}`"
                      loading="lazy"
                      class="webtoon-page-img"
                      @error="handleImageError(img.id)"
                    />

                    <!-- Error Fallback -->
                    <div v-else class="image-load-fallback" role="alert">
                      <span class="fallback-icon">⚠️</span>
                      <div class="fallback-text">
                        <strong>Trang {{ img.page_number }}: Không thể hiển thị ảnh</strong>
                        <span class="fallback-path">{{ img.image_path }}</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div v-else class="empty-state">
                  <span class="empty-icon">🖼️</span>
                  <p>Chương này chưa có hình ảnh nào được tải lên.</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Sticky Footer -->
          <div class="modal-footer viewer-footer">
            <span class="viewer-hint text-muted text-xs">
              💡 Cuộn dọc để duyệt toàn bộ trang ảnh chương truyện với độ phân giải gốc rõ nét.
            </span>
            <div class="footer-actions">
              <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="openChapterStatusFromDetail"
              >
                ⚙️ Đổi trạng thái chương
              </button>
              <button
                type="button"
                class="btn btn-primary btn-sm"
                @click="isChapterDetailModalOpen = false"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- MODAL 2: CHAPTER STATUS EDIT -->
      <div
        v-if="isChapterStatusModalOpen"
        class="modal-backdrop"
        @click.self="isChapterStatusModalOpen = false"
      >
        <div class="modal-box">
          <div class="modal-header">
            <h3>Cập nhật trạng thái Chương #{{ targetChapter?.chapter_number }}</h3>
            <button type="button" class="btn-close" @click="isChapterStatusModalOpen = false">✕</button>
          </div>
          <form @submit.prevent="handleSaveChapterStatus">
            <div class="modal-body">
              <div class="form-group">
                <label class="form-label">Trạng thái phát hành <span class="req">*</span></label>
                <select v-model="chapterFormStatus" class="form-select" required>
                  <option value="published">Đã phát hành (Published)</option>
                  <option value="pending">Chờ duyệt (Pending)</option>
                  <option value="draft">Bản nháp (Draft)</option>
                  <option value="rejected">Từ chối / Gỡ bỏ (Rejected)</option>
                </select>
              </div>

              <div v-if="chapterFormStatus === 'rejected'" class="form-group">
                <label class="form-label">Lý do từ chối / gỡ bỏ <span class="req">*</span></label>
                <textarea
                  v-model="chapterFormRejectionReason"
                  class="form-textarea"
                  rows="3"
                  placeholder="Nhập lý do cụ thể gửi tới Tác giả (ví dụ: vi phạm nội dung, lỗi ảnh)..."
                  required
                ></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" :disabled="isUpdatingChapterStatus" @click="isChapterStatusModalOpen = false">
                Hủy
              </button>
              <button type="submit" class="btn btn-primary" :disabled="isUpdatingChapterStatus">
                {{ isUpdatingChapterStatus ? 'Đang lưu...' : 'Lưu trạng thái chương' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- MODAL 3: COMIC VIP EDIT -->
      <div v-if="isVipModalOpen" class="modal-backdrop" @click.self="isVipModalOpen = false">
        <div class="modal-box">
          <div class="modal-header">
            <h3>Thiết lập VIP: {{ comic?.title }}</h3>
            <button type="button" class="btn-close" @click="isVipModalOpen = false">✕</button>
          </div>
          <form @submit.prevent="handleSaveComicVip">
            <div class="modal-body">
              <p class="text-muted" style="margin-bottom: 16px; font-size: 13px">
                Theo quy tắc <strong>COMIC_PURCHASE_ONLY</strong>, độc giả trả Coin 1 lần ở cấp truyện để đọc vĩnh viễn toàn bộ các chương hiện tại và tương lai.
              </p>

              <div class="form-group checkbox-group">
                <label class="checkbox-label">
                  <input v-model="formIsVip" type="checkbox" />
                  <span>Đặt bộ truyện này thành <strong>Truyện VIP (Thu phí Coin)</strong></span>
                </label>
              </div>

              <div v-if="formIsVip" class="form-group" style="margin-top: 14px;">
                <label class="form-label">Giá trọn bộ (Coin) <span class="req">*</span></label>
                <input
                  v-model.number="formPrice"
                  type="number"
                  min="1"
                  max="1000000"
                  class="form-input"
                  required
                />
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" :disabled="isUpdatingVip" @click="isVipModalOpen = false">
                Hủy
              </button>
              <button type="submit" class="btn btn-primary" :disabled="isUpdatingVip">
                {{ isUpdatingVip ? 'Đang lưu...' : 'Lưu thiết lập VIP' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- MODAL 4: COMIC STATUS & APPROVAL EDIT -->
      <div v-if="isComicStatusModalOpen" class="modal-backdrop" @click.self="isComicStatusModalOpen = false">
        <div class="modal-box">
          <div class="modal-header">
            <h3>Điều chỉnh Trạng thái & Duyệt: {{ comic?.title }}</h3>
            <button type="button" class="btn-close" @click="isComicStatusModalOpen = false">✕</button>
          </div>
          <form @submit.prevent="handleSaveComicStatus">
            <div class="modal-body">
              <div class="form-group">
                <label class="form-label">Tiến độ xuất bản</label>
                <select v-model="formComicStatus" class="form-select">
                  <option value="ongoing">Đang tiến hành (Ongoing)</option>
                  <option value="completed">Đã hoàn thành (Completed)</option>
                  <option value="hiatus">Tạm ngưng (Hiatus)</option>
                </select>
              </div>

              <div class="form-group" style="margin-top: 12px;">
                <label class="form-label">Trạng thái duyệt</label>
                <select v-model="formApprovalStatus" class="form-select">
                  <option value="approved">Đã duyệt (Approved)</option>
                  <option value="pending">Chờ duyệt (Pending)</option>
                  <option value="draft">Bản nháp (Draft)</option>
                  <option value="rejected">Bị từ chối (Rejected)</option>
                </select>
              </div>

              <!-- Chapter selection when approving comic (CAI-ADMIN-03) -->
              <div v-if="formApprovalStatus === 'approved'" class="detail-chapters-approval-box">
                <div class="box-title">Duyệt xuất bản chương cùng truyện (tùy chọn):</div>
                <div v-if="isLoadingPendingDetailChapters" class="loading-mini">⏳ Đang tải chương chờ duyệt...</div>
                <div v-else-if="pendingChaptersForDetailStatus.length === 0" class="empty-mini">
                  Không có chương nào đang ở trạng thái chờ duyệt.
                </div>
                <div v-else class="checklist-mini-wrap">
                  <div class="checklist-mini-toolbar">
                    <span>Đã chọn: <strong>{{ selectedDetailStatusChapterIds.length }}</strong> / {{ pendingChaptersForDetailStatus.length }} chương</span>
                    <div class="toolbar-btns">
                      <button type="button" class="btn-xs" @click="selectAllDetailStatusChapters">Chọn tất cả</button>
                      <button type="button" class="btn-xs" @click="deselectAllDetailStatusChapters">Bỏ chọn</button>
                    </div>
                  </div>
                  <div class="checklist-mini">
                    <label
                      v-for="chap in pendingChaptersForDetailStatus"
                      :key="chap.id"
                      class="check-row"
                      :class="{ active: selectedDetailStatusChapterIds.includes(chap.id) }"
                    >
                      <input
                        type="checkbox"
                        :checked="selectedDetailStatusChapterIds.includes(chap.id)"
                        @change="toggleDetailStatusChapter(chap.id)"
                      />
                      <span class="chap-badge">#{{ formatChapterNumber(chap.chapter_number) }}</span>
                      <span class="chap-name">{{ chap.title || 'Chương không tên' }}</span>
                    </label>
                  </div>
                </div>
              </div>

              <div v-if="formApprovalStatus === 'rejected'" class="form-group" style="margin-top: 12px;">
                <label class="form-label">Lý do từ chối</label>
                <textarea
                  v-model="formComicRejectionReason"
                  class="form-textarea"
                  rows="3"
                  placeholder="Ghi rõ lý do từ chối gửi tác giả..."
                ></textarea>
              </div>

              <div class="form-group checkbox-group" style="margin-top: 14px;">
                <label class="checkbox-label">
                  <input v-model="formIsFeatured" type="checkbox" />
                  <span>⭐ Đặt làm truyện <strong>Nổi bật (Featured)</strong> trên trang chủ</span>
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" :disabled="isUpdatingComicStatus" @click="isComicStatusModalOpen = false">
                Hủy
              </button>
              <button type="submit" class="btn btn-primary" :disabled="isUpdatingComicStatus">
                {{ isUpdatingComicStatus ? 'Đang lưu...' : 'Lưu thay đổi' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-comic-detail-page {
  padding: 24px;
  max-width: 1300px;
  margin: 0 auto;
}

/* Breadcrumb */
.breadcrumb-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
  font-size: 14px;
}

.breadcrumb-link {
  color: var(--color-primary, #38bdf8);
  text-decoration: none;
  font-weight: 500;
  transition: color 0.15s;
}

.breadcrumb-link:hover {
  text-decoration: underline;
}

.breadcrumb-separator {
  color: var(--color-text-muted, #64748b);
}

.breadcrumb-current {
  color: var(--color-text-secondary, #94a3b8);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 400px;
}

/* Hero Card */
.comic-hero-card {
  background: var(--color-bg-card, #151e32);
  border: 1px solid var(--color-border-subtle, #2d3b55);
  border-radius: var(--radius-lg, 12px);
  padding: 24px;
  display: flex;
  gap: 24px;
  margin-bottom: 24px;
}

.comic-cover-wrap {
  width: 140px;
  min-width: 140px;
  height: 196px;
  border-radius: var(--radius-md, 8px);
  overflow: hidden;
  background: #1e293b;
  border: 1px solid #334155;
  display: flex;
  align-items: center;
  justify-content: center;
}

.comic-cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.comic-cover-placeholder {
  font-size: 40px;
}

.comic-hero-info {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.hero-top-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
  gap: 12px;
  flex-wrap: wrap;
}

.badges-group {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.hero-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

.btn-preview {
  text-decoration: none;
}

.comic-title {
  font-size: 24px;
  font-weight: 700;
  color: #fff;
  margin: 0 0 4px 0;
}

.comic-alt-titles {
  font-size: 13px;
  color: var(--color-text-muted, #64748b);
  margin: 0 0 10px 0;
}

.hero-meta-row {
  font-size: 13px;
  color: var(--color-text-secondary, #94a3b8);
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.meta-dot {
  color: #475569;
}

/* Stats Row */
.stats-pills-row {
  display: flex;
  gap: 12px;
  margin-top: auto;
  flex-wrap: wrap;
}

.stat-pill {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 8px;
  padding: 8px 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.stat-icon {
  font-size: 16px;
}

.stat-value {
  font-weight: 700;
  color: #fff;
  font-size: 15px;
}

.stat-label {
  font-size: 12px;
  color: #94a3b8;
}

/* Tabs */
.tabs-nav {
  display: flex;
  border-bottom: 1px solid var(--color-border-subtle, #2d3b55);
  margin-bottom: 24px;
  gap: 8px;
}

.tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: 12px 20px;
  color: #94a3b8;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.tab-btn:hover {
  color: #fff;
}

.tab-btn.active {
  color: var(--color-primary, #38bdf8);
  border-bottom-color: var(--color-primary, #38bdf8);
}

/* Overview Panel */
.overview-grid {
  display: grid;
  grid-template-columns: 3fr 2fr;
  gap: 24px;
}

@media (max-width: 900px) {
  .overview-grid {
    grid-template-columns: 1fr;
  }
}

.panel-card {
  background: var(--color-bg-card, #151e32);
  border: 1px solid var(--color-border-subtle, #2d3b55);
  border-radius: 12px;
  padding: 20px;
}

.card-title {
  font-size: 16px;
  font-weight: 600;
  color: #fff;
  margin: 0 0 14px 0;
}

.comic-desc {
  font-size: 14px;
  line-height: 1.6;
  color: #cbd5e1;
  white-space: pre-line;
  margin-bottom: 16px;
}

.section-subtitle {
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
  display: block;
  margin-bottom: 8px;
}

.chips-wrap {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.genre-chip {
  background: rgba(56, 189, 248, 0.12);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.3);
  padding: 4px 10px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 500;
}

.tag-chip {
  background: #1e293b;
  color: #94a3b8;
  border: 1px solid #334155;
  padding: 4px 10px;
  border-radius: 9999px;
  font-size: 12px;
}

/* Creator profile */
.creator-profile-box {
  display: flex;
  gap: 14px;
  align-items: center;
}

.creator-avatar-wrap {
  width: 48px;
  height: 48px;
  background: var(--color-primary-active, #0284c7);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  color: #fff;
  font-size: 18px;
}

.creator-name {
  font-size: 15px;
  font-weight: 600;
  color: #fff;
  margin: 0;
}

.creator-email {
  font-size: 12px;
  color: #94a3b8;
  margin: 2px 0 6px 0;
}

.creator-meta-tags {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.badge-tag {
  background: #1e293b;
  color: #cbd5e1;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
}

.badge-tag.active {
  color: #10b981;
}

.badge-tag.locked {
  color: #ef4444;
}

.card-divider {
  border: none;
  border-top: 1px solid #334155;
  margin: 16px 0;
}

.audit-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 13px;
}

.audit-list li {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.audit-label {
  color: #94a3b8;
}

/* Chapters Toolbar */
.chapters-toolbar {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.search-wrap {
  display: flex;
  gap: 8px;
  flex: 1;
  max-width: 360px;
}

.search-input {
  flex: 1;
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 14px;
}

.filters-wrap {
  display: flex;
  gap: 14px;
  align-items: center;
  flex-wrap: wrap;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.filter-label {
  font-size: 13px;
  color: #94a3b8;
}

.filter-select {
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 7px 10px;
  border-radius: 8px;
  font-size: 13px;
}

/* Table */
.table-container {
  background: var(--color-bg-card, #151e32);
  border: 1px solid var(--color-border-subtle, #2d3b55);
  border-radius: 12px;
  overflow: hidden;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 14px;
}

.data-table th,
.data-table td {
  padding: 12px 16px;
  border-bottom: 1px solid #1e293b;
}

.data-table th {
  background: #111827;
  color: #94a3b8;
  font-weight: 600;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.data-table tbody tr:hover {
  background: #1c2842;
}

.chapter-number-pill {
  background: #1e293b;
  padding: 4px 8px;
  border-radius: 6px;
  font-family: var(--font-family-mono, monospace);
  font-size: 13px;
  color: #38bdf8;
}

.chapter-title-cell {
  display: flex;
  flex-direction: column;
}

.chapter-slug-text {
  font-size: 11px;
  color: #64748b;
  font-family: monospace;
}

.rejection-hint {
  font-size: 11px;
  color: #f87171;
  margin-top: 2px;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.actions-group {
  display: inline-flex;
  gap: 6px;
  align-items: center;
}

/* Pagination */
.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: #111827;
  color: #94a3b8;
  font-size: 13px;
  border-top: 1px solid #1e293b;
}

.pagination-buttons {
  display: flex;
  gap: 8px;
}

/* Badges */
.approval-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.approval-badge.approved,
.approval-badge.published {
  background: rgba(16, 185, 129, 0.15);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.3);
}

.approval-badge.pending {
  background: rgba(245, 158, 11, 0.15);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.approval-badge.draft {
  background: rgba(148, 163, 184, 0.15);
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.3);
}

.approval-badge.rejected {
  background: rgba(239, 68, 68, 0.15);
  color: #ef4444;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.status-pill {
  background: #1e293b;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  color: #cbd5e1;
}

.vip-badge {
  background: rgba(168, 85, 247, 0.15);
  color: #c084fc;
  border: 1px solid rgba(168, 85, 247, 0.3);
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
}

.free-badge {
  background: #1e293b;
  color: #94a3b8;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
}

.badge-featured {
  background: rgba(234, 179, 8, 0.15);
  color: #eab308;
  border: 1px solid rgba(234, 179, 8, 0.3);
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
}

.badge-count {
  background: #1e293b;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  color: #cbd5e1;
}

/* Modals */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
}

.modal-box {
  background: #151e32;
  border: 1px solid #334155;
  border-radius: 12px;
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.6);
}

.modal-box.modal-lg {
  max-width: 800px;
}

.modal-header {
  padding: 16px 20px;
  border-bottom: 1px solid #1e293b;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-header h3 {
  margin: 0;
  font-size: 17px;
  color: #fff;
}

.btn-close {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 18px;
  cursor: pointer;
}

.btn-close:hover {
  color: #fff;
}

.modal-body {
  padding: 20px;
  overflow-y: auto;
}

.modal-footer {
  padding: 16px 20px;
  border-top: 1px solid #1e293b;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* Gallery inside Modal */
.modal-box.modal-viewer {
  width: 95vw;
  max-width: 960px;
  height: 92vh;
  max-height: 94vh;
}

.modal-header-titles {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.header-breadcrumbs {
  font-size: 11px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.comic-name-badge {
  color: #38bdf8;
  font-weight: 500;
}

.chapter-modal-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #fff;
}

.header-controls {
  display: flex;
  align-items: center;
  gap: 10px;
}

.image-count-pill {
  background: #1e293b;
  border: 1px solid #334155;
  padding: 4px 10px;
  border-radius: 9999px;
  font-size: 12px;
  color: #94a3b8;
}

.width-selector {
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 6px;
  padding: 2px;
  display: flex;
  gap: 2px;
}

.btn-width-toggle {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 11px;
  padding: 3px 8px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-width-toggle:hover {
  color: #fff;
}

.btn-width-toggle.active {
  background: #38bdf8;
  color: #0b0f19;
  font-weight: 600;
}

.viewer-body {
  padding: 0 !important;
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: #060911;
}

.chapter-viewer-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.chapter-meta-summary {
  display: flex;
  align-items: center;
  gap: 16px;
  background: #111827;
  border-bottom: 1px solid #1e293b;
  padding: 10px 20px;
  margin-bottom: 0;
  font-size: 13px;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.meta-label {
  color: #94a3b8;
  font-size: 12px;
}

.meta-item.full-col {
  width: 100%;
}

.webtoon-scroll-viewport {
  flex: 1;
  overflow-y: auto;
  background-color: #060911;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 20px 10px;
  scroll-behavior: smooth;
}

.webtoon-strip {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  transition: max-width 0.2s ease;
}

.webtoon-page-wrapper {
  position: relative;
  width: 100%;
  margin-bottom: 16px;
  background-color: #0b0f19;
  border: 1px solid #1e293b;
  border-radius: 8px;
  overflow: hidden;
  display: flex;
  justify-content: center;
  flex-shrink: 0 !important; /* CRITICAL: Prevents flexbox vertical squishing */
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
}

.webtoon-page-img {
  width: 100%;
  height: auto !important; /* CRITICAL: Preserves true native aspect ratio */
  display: block;
  object-fit: contain !important; /* CRITICAL: Never crop or squeeze */
  image-rendering: auto;
}

.page-indicator-pill {
  position: absolute;
  top: 10px;
  left: 10px;
  background-color: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(4px);
  color: #fff;
  padding: 3px 10px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 600;
  pointer-events: none;
  z-index: 2;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.5);
}

.image-load-fallback {
  width: 100%;
  padding: 30px 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: rgba(239, 68, 68, 0.08);
  border: 1px dashed rgba(239, 68, 68, 0.3);
  color: #fca5a5;
}

.fallback-icon {
  font-size: 24px;
}

.fallback-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.fallback-path {
  font-size: 11px;
  color: #94a3b8;
  font-family: monospace;
}

.viewer-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  background: #111827;
  border-top: 1px solid #1e293b;
}

.viewer-hint {
  font-size: 12px;
  color: #64748b;
}

.footer-actions {
  display: flex;
  gap: 8px;
}

/* Forms */
.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: 13px;
  font-weight: 500;
  color: #cbd5e1;
}

.req {
  color: #ef4444;
}

.form-input,
.form-select,
.form-textarea {
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 14px;
  outline: none;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
  border-color: #38bdf8;
}

.checkbox-group {
  margin-top: 8px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  cursor: pointer;
  color: #cbd5e1;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 14px;
  font-weight: 500;
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  border: 1px solid transparent;
}

.btn-primary {
  background: #38bdf8;
  color: #0b0f19;
  font-weight: 600;
}

.btn-primary:hover {
  background: #7dd3fc;
}

.btn-secondary {
  background: #1e293b;
  color: #cbd5e1;
  border-color: #334155;
}

.btn-secondary:hover {
  background: #2d3b55;
  color: #fff;
}

.btn-outline {
  background: transparent;
  color: #38bdf8;
  border-color: #38bdf8;
}

.btn-sm {
  padding: 6px 12px;
  font-size: 13px;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* States */
.loading-container,
.loading-state,
.empty-state {
  padding: 40px 20px;
  text-align: center;
  color: #94a3b8;
}

.empty-icon {
  font-size: 36px;
  display: block;
  margin-bottom: 8px;
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #fca5a5;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* Toast */
.toast-wrapper {
  position: fixed;
  top: 24px;
  right: 24px;
  background: #0f172a;
  border: 1px solid #334155;
  padding: 12px 18px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  z-index: 1200;
}

.toast-wrapper.success {
  border-color: #10b981;
}

.toast-wrapper.error {
  border-color: #ef4444;
}

.spinner {
  display: inline-block;
  width: 24px;
  height: 24px;
  border: 2px solid #334155;
  border-top-color: #38bdf8;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 8px;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.text-xs {
  font-size: 12px;
}

.text-sm {
  font-size: 13px;
}

.text-muted {
  color: #64748b;
}

.text-danger {
  color: #ef4444;
}

/* Chapter Selection when Approving Comic (CAI-ADMIN-03) */
.detail-chapters-approval-box {
  margin-top: 14px;
  padding: 12px;
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
}

.detail-chapters-approval-box .box-title {
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
  margin-bottom: 8px;
}

.loading-mini,
.empty-mini {
  font-size: 12px;
  color: #64748b;
  padding: 6px 0;
}

.checklist-mini-wrap {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.checklist-mini-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  color: #cbd5e1;
}

.checklist-mini-toolbar strong {
  color: #38bdf8;
}

.toolbar-btns {
  display: flex;
  gap: 6px;
}

.btn-xs {
  background: #1e293b;
  border: 1px solid #334155;
  color: #94a3b8;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-xs:hover {
  color: #fff;
  border-color: #38bdf8;
}

.checklist-mini {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 180px;
  overflow-y: auto;
  padding-right: 4px;
}

.check-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 6px;
  cursor: pointer;
  font-size: 12px;
  transition: all 0.15s ease;
}

.check-row:hover {
  border-color: rgba(56, 189, 248, 0.4);
}

.check-row.active {
  background: rgba(56, 189, 248, 0.1);
  border-color: #38bdf8;
}

.check-row .chap-badge {
  font-weight: 700;
  color: #38bdf8;
}

.check-row .chap-name {
  color: #f8fafc;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
