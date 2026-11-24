<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { UserProfileService } from '@/services/userProfileService'
import { getErrorMessage, isNotFoundError } from '@/services/httpClient'
import type { UserProfile } from '@/types/userProfile'
import type { ComicListItem, PaginationMeta } from '@/types/catalog'
import type { Post } from '@/types/community'
import ComicCard from '@/components/ComicCard.vue'
import PostCard from '@/components/community/PostCard.vue'
import PostFormModal from '@/components/community/PostFormModal.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// State
const profile = ref<UserProfile | null>(null)
const isLoadingProfile = ref(true)
const profileError = ref<string | null>(null)
const isNotFound = ref(false)
const avatarLoadError = ref(false)

// Active Tab ('comics' or 'posts')
const activeTab = ref<'comics' | 'posts'>('comics')

// Comics state
const comics = ref<ComicListItem[]>([])
const isLoadingComics = ref(false)
const comicsPagination = ref<PaginationMeta | null>(null)
const currentComicsPage = ref(1)

// Posts state
const posts = ref<Post[]>([])
const isLoadingPosts = ref(false)
const postsPagination = ref<PaginationMeta | null>(null)
const currentPostsPage = ref(1)

// Post edit modal state (for self profile)
const isEditModalOpen = ref(false)
const editingPost = ref<Post | null>(null)

// Concurrency guard
let activeRequestId = 0
let isMounted = true

const isOwnProfile = computed(() => {
  return authStore.isAuthenticated && authStore.user?.id === profile.value?.id
})

const isCreator = computed(() => {
  return profile.value?.role === 'creator' || profile.value?.role === 'admin'
})

const userInitial = computed(() => {
  return profile.value?.name?.trim().charAt(0).toUpperCase() || 'U'
})

const formattedJoinDate = computed(() => {
  if (!profile.value?.created_at) return ''
  const date = new Date(profile.value.created_at)
  if (isNaN(date.getTime())) return ''
  return date.toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
})

async function loadProfileData() {
  const requestId = ++activeRequestId
  const rawId = route.params.id
  const userId = parseInt(String(rawId), 10)

  if (isNaN(userId) || userId <= 0) {
    isNotFound.value = true
    isLoadingProfile.value = false
    return
  }

  isLoadingProfile.value = true
  profileError.value = null
  isNotFound.value = false
  avatarLoadError.value = false

  try {
    const res = await UserProfileService.getUserProfile(userId)
    if (!isMounted || requestId !== activeRequestId) return

    profile.value = res.data
    document.title = `${profile.value.name} — BlueToon`

    // Determine default tab based on role
    if (profile.value.role === 'reader') {
      activeTab.value = 'posts'
      await loadUserPosts(1)
    } else {
      activeTab.value = 'comics'
      await Promise.all([
        loadUserComics(1),
        loadUserPosts(1),
      ])
    }
  } catch (err: unknown) {
    if (!isMounted || requestId !== activeRequestId) return
    if (isNotFoundError(err)) {
      isNotFound.value = true
    } else {
      profileError.value = getErrorMessage(err, 'Không thể tải thông tin hồ sơ người dùng.')
    }
  } finally {
    if (isMounted && requestId === activeRequestId) {
      isLoadingProfile.value = false
    }
  }
}

async function loadUserComics(page = 1) {
  if (!profile.value) return
  isLoadingComics.value = true
  currentComicsPage.value = page

  try {
    const res = await UserProfileService.getUserComics(profile.value.id, page, 12)
    comics.value = res.data || []
    comicsPagination.value = res.meta || null
  } catch (err) {
    console.error('Lỗi khi tải truyện của tác giả:', err)
  } finally {
    isLoadingComics.value = false
  }
}

async function loadUserPosts(page = 1) {
  if (!profile.value) return
  isLoadingPosts.value = true
  currentPostsPage.value = page

  try {
    const res = await UserProfileService.getUserPosts(profile.value.id, page, 10)
    posts.value = res.data || []
    postsPagination.value = res.meta || null
  } catch (err) {
    console.error('Lỗi khi tải bài viết của người dùng:', err)
  } finally {
    isLoadingPosts.value = false
  }
}

function handleTabChange(tab: 'comics' | 'posts') {
  activeTab.value = tab
  if (tab === 'comics' && comics.value.length === 0 && !isLoadingComics.value) {
    loadUserComics(1)
  } else if (tab === 'posts' && posts.value.length === 0 && !isLoadingPosts.value) {
    loadUserPosts(1)
  }
}

function handleStartMessage() {
  if (!profile.value) return

  // Unauthenticated guests redirected to login
  if (!authStore.isAuthenticated) {
    router.push({
      path: '/login',
      query: { redirect: route.fullPath },
    })
    return
  }

  // Navigate to ChatPage with user_id query parameter
  router.push({
    path: '/chat',
    query: { user_id: profile.value.id },
  })
}

// Post events handlers
function handlePostLikeToggle(updatedPost: Post) {
  const idx = posts.value.findIndex((p) => p.id === updatedPost.id)
  if (idx !== -1) {
    posts.value[idx].is_liked = updatedPost.is_liked
    posts.value[idx].likes_count = updatedPost.likes_count
  }
}

function handlePostEdit(postToEdit: Post) {
  editingPost.value = postToEdit
  isEditModalOpen.value = true
}

function handlePostDeleted(deletedPostId: number) {
  posts.value = posts.value.filter((p) => p.id !== deletedPostId)
  if (profile.value) {
    profile.value.stats.posts_count = Math.max(0, profile.value.stats.posts_count - 1)
  }
}

function handlePostFormSaved(savedPost: Post) {
  const idx = posts.value.findIndex((p) => p.id === savedPost.id)
  if (idx !== -1) {
    posts.value[idx] = savedPost
  }
  isEditModalOpen.value = false
  editingPost.value = null
}

watch(
  () => route.params.id,
  (newId) => {
    if (newId) {
      loadProfileData()
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  }
)

onMounted(() => {
  loadProfileData()
})

onUnmounted(() => {
  isMounted = false
  activeRequestId++
})
</script>

<template>
  <div class="user-profile-page">
    <div class="container">
      <!-- Back Navigation Link -->
      <nav class="back-nav" aria-label="Điều hướng quay lại">
        <button type="button" class="btn-back" @click="router.back()">
          ← Quay lại
        </button>
      </nav>

      <!-- 404 Not Found State -->
      <div v-if="isNotFound" class="state-container not-found-state" role="alert">
        <span class="state-icon" aria-hidden="true">👤</span>
        <h1 class="state-title">Không tìm thấy người dùng</h1>
        <p class="state-desc">
          Người dùng bạn đang tìm kiếm không tồn tại hoặc tài khoản đã bị vô hiệu hóa.
        </p>
        <div class="state-actions">
          <RouterLink to="/community" class="btn-action-primary">
            Quay lại Cộng đồng
          </RouterLink>
          <RouterLink to="/" class="btn-action-secondary">
            Về Trang chủ
          </RouterLink>
        </div>
      </div>

      <!-- General Error State -->
      <div v-else-if="profileError" class="state-container error-state" role="alert">
        <span class="state-icon" aria-hidden="true">⚠️</span>
        <h1 class="state-title">Đã xảy ra lỗi khi tải hồ sơ</h1>
        <p class="state-desc">{{ profileError }}</p>
        <button type="button" class="btn-action-primary" @click="loadProfileData">
          Thử lại
        </button>
      </div>

      <!-- Loading Skeleton -->
      <div v-else-if="isLoadingProfile" class="profile-skeleton-card" aria-busy="true">
        <div class="skeleton-header">
          <div class="skeleton-avatar"></div>
          <div class="skeleton-info">
            <div class="skeleton-line title"></div>
            <div class="skeleton-line role"></div>
            <div class="skeleton-line meta"></div>
          </div>
        </div>
        <div class="skeleton-tabs">
          <div class="skeleton-tab"></div>
          <div class="skeleton-tab"></div>
        </div>
        <div class="skeleton-grid">
          <div v-for="n in 6" :key="'skel-' + n" class="skeleton-card"></div>
        </div>
      </div>

      <!-- Profile Content -->
      <div v-else-if="profile" class="profile-content">
        <!-- Profile Header Card -->
        <header class="profile-header-card">
          <div class="profile-avatar-wrapper">
            <img
              v-if="profile.avatar_url && !avatarLoadError"
              :src="profile.avatar_url"
              :alt="profile.name"
              class="profile-avatar-img"
              @error="avatarLoadError = true"
            />
            <span v-else class="profile-avatar-initial">{{ userInitial }}</span>
          </div>

          <div class="profile-info-block">
            <div class="profile-name-row">
              <h1 class="profile-name">{{ profile.name }}</h1>

              <!-- Role Badges -->
              <span
                v-if="profile.role === 'admin'"
                class="role-badge role-badge--admin"
                title="Quản trị viên hệ thống"
              >
                Admin 🛡️
              </span>
              <span
                v-else-if="profile.role === 'creator'"
                class="role-badge role-badge--creator"
                title="Tác giả sáng tạo"
              >
                Tác giả ✍️
              </span>
              <span
                v-else
                class="role-badge role-badge--reader"
                title="Độc giả BlueToon"
              >
                Độc giả 📖
              </span>
            </div>

            <!-- Metadata info -->
            <div class="profile-meta-row">
              <span v-if="formattedJoinDate" class="meta-item">
                <span class="meta-icon" aria-hidden="true">📅</span>
                <span>Tham gia ngày {{ formattedJoinDate }}</span>
              </span>
            </div>

            <!-- Profile Stats Row -->
            <div class="profile-stats-row">
              <div v-if="isCreator" class="stat-box">
                <strong class="stat-number">{{ profile.stats.comics_count }}</strong>
                <span class="stat-label">Bộ truyện</span>
              </div>
              <div class="stat-box">
                <strong class="stat-number">{{ profile.stats.posts_count }}</strong>
                <span class="stat-label">Bài viết</span>
              </div>
            </div>
          </div>

          <!-- Action Button: Nhắn tin (chỉ hiển thị trên profile người khác) -->
          <div v-if="!isOwnProfile" class="profile-action-block">
            <button
              type="button"
              class="btn-message"
              aria-label="Nhắn tin với người dùng này"
              @click="handleStartMessage"
            >
              <span class="btn-icon" aria-hidden="true">💬</span>
              <span>Nhắn tin</span>
            </button>
          </div>
        </header>

        <!-- Tabs Navigation -->
        <nav class="profile-tabs-nav" aria-label="Các mục hồ sơ">
          <button
            v-if="isCreator"
            type="button"
            class="tab-btn"
            :class="{ 'tab-btn--active': activeTab === 'comics' }"
            :aria-selected="activeTab === 'comics'"
            @click="handleTabChange('comics')"
          >
            <span class="tab-icon" aria-hidden="true">📚</span>
            <span>Truyện đã đăng</span>
            <span class="tab-count">({{ profile.stats.comics_count }})</span>
          </button>

          <button
            type="button"
            class="tab-btn"
            :class="{ 'tab-btn--active': activeTab === 'posts' }"
            :aria-selected="activeTab === 'posts'"
            @click="handleTabChange('posts')"
          >
            <span class="tab-icon" aria-hidden="true">📝</span>
            <span>Bài viết cộng đồng</span>
            <span class="tab-count">({{ profile.stats.posts_count }})</span>
          </button>
        </nav>

        <!-- Tab Body: Comics -->
        <section
          v-if="activeTab === 'comics' && isCreator"
          class="tab-panel comics-panel"
          aria-label="Danh sách truyện đã đăng"
        >
          <!-- Loading skeleton for comics -->
          <div v-if="isLoadingComics" class="comics-grid" aria-busy="true">
            <div v-for="n in 6" :key="'comic-skel-' + n" class="card-skeleton">
              <div class="skeleton-cover"></div>
              <div class="skeleton-line title"></div>
            </div>
          </div>

          <!-- Empty Comics State -->
          <div v-else-if="comics.length === 0" class="panel-empty-state">
            <span class="empty-icon" aria-hidden="true">📚</span>
            <h3 class="empty-title">Chưa có bộ truyện nào</h3>
            <p class="empty-desc">
              Tác giả chưa có bộ truyện nào được xuất bản công khai.
            </p>
          </div>

          <!-- Comics Grid -->
          <div v-else class="comics-grid">
            <ComicCard
              v-for="comic in comics"
              :key="comic.id"
              :comic="comic"
            />
          </div>

          <!-- Pagination for Comics -->
          <nav
            v-if="comicsPagination && comicsPagination.last_page > 1"
            class="pagination-nav"
            aria-label="Điều hướng phân trang truyện"
          >
            <button
              type="button"
              class="btn-page"
              :disabled="currentComicsPage <= 1 || isLoadingComics"
              @click="loadUserComics(currentComicsPage - 1)"
            >
              ‹ Trang trước
            </button>
            <span class="page-indicator">
              Trang <strong>{{ currentComicsPage }}</strong> / {{ comicsPagination.last_page }}
            </span>
            <button
              type="button"
              class="btn-page"
              :disabled="currentComicsPage >= comicsPagination.last_page || isLoadingComics"
              @click="loadUserComics(currentComicsPage + 1)"
            >
              Trang sau ›
            </button>
          </nav>
        </section>

        <!-- Tab Body: Posts -->
        <section
          v-if="activeTab === 'posts'"
          class="tab-panel posts-panel"
          aria-label="Danh sách bài viết cộng đồng"
        >
          <!-- Loading skeleton for posts -->
          <div v-if="isLoadingPosts" class="posts-skeleton-list" aria-busy="true">
            <div v-for="n in 3" :key="'post-skel-' + n" class="post-skeleton-item">
              <div class="post-skel-header"></div>
              <div class="post-skel-body"></div>
            </div>
          </div>

          <!-- Empty Posts State -->
          <div v-else-if="posts.length === 0" class="panel-empty-state">
            <span class="empty-icon" aria-hidden="true">💬</span>
            <h3 class="empty-title">Chưa có bài viết nào</h3>
            <p class="empty-desc">
              Người dùng chưa có bài viết nào trong cộng đồng.
            </p>
          </div>

          <!-- Posts List -->
          <div v-else class="posts-list">
            <PostCard
              v-for="postItem in posts"
              :key="postItem.id"
              :post="postItem"
              @like-toggle="handlePostLikeToggle"
              @edit="handlePostEdit"
              @deleted="handlePostDeleted"
            />
          </div>

          <!-- Pagination for Posts -->
          <nav
            v-if="postsPagination && postsPagination.last_page > 1"
            class="pagination-nav"
            aria-label="Điều hướng phân trang bài viết"
          >
            <button
              type="button"
              class="btn-page"
              :disabled="currentPostsPage <= 1 || isLoadingPosts"
              @click="loadUserPosts(currentPostsPage - 1)"
            >
              ‹ Trang trước
            </button>
            <span class="page-indicator">
              Trang <strong>{{ currentPostsPage }}</strong> / {{ postsPagination.last_page }}
            </span>
            <button
              type="button"
              class="btn-page"
              :disabled="currentPostsPage >= postsPagination.last_page || isLoadingPosts"
              @click="loadUserPosts(currentPostsPage + 1)"
            >
              Trang sau ›
            </button>
          </nav>
        </section>
      </div>

      <!-- Post Form Modal (for author editing own post on profile) -->
      <PostFormModal
        v-if="isEditModalOpen"
        :is-open="isEditModalOpen"
        :post="editingPost"
        @saved="handlePostFormSaved"
        @close="isEditModalOpen = false; editingPost = null"
      />
    </div>
  </div>
</template>

<style scoped>
.user-profile-page {
  padding: var(--spacing-xl) 0 var(--spacing-3xl);
  min-height: calc(100vh - 180px);
}

/* Back Navigation */
.back-nav {
  margin-bottom: var(--spacing-lg);
}

.btn-back {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  padding: var(--spacing-xs) var(--spacing-md);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-back:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

/* State Containers */
.state-container {
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-lg);
  background-color: var(--color-bg-surface);
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  max-width: 600px;
  margin: var(--spacing-2xl) auto;
}

.state-icon {
  font-size: 3rem;
  display: block;
  margin-bottom: var(--spacing-md);
}

.state-title {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  margin-bottom: var(--spacing-sm);
  color: var(--color-text);
}

.state-desc {
  font-size: var(--font-size-base);
  color: var(--color-text-secondary);
  margin-bottom: var(--spacing-xl);
  line-height: 1.6;
}

.state-actions {
  display: flex;
  gap: var(--spacing-md);
  justify-content: center;
  flex-wrap: wrap;
}

.btn-action-primary {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-sm) var(--spacing-lg);
  background-color: var(--color-primary);
  color: #fff;
  border: none;
  border-radius: var(--radius-md);
  font-size: var(--font-size-base);
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: background-color var(--transition-fast);
}

.btn-action-primary:hover {
  background-color: var(--color-primary-hover);
}

.btn-action-secondary {
  display: inline-flex;
  align-items: center;
  padding: var(--spacing-sm) var(--spacing-lg);
  background-color: transparent;
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-base);
  font-weight: 600;
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-action-secondary:hover {
  border-color: var(--color-border-hover);
  background-color: var(--color-bg-hover);
}

/* Profile Header Card */
.profile-header-card {
  display: flex;
  align-items: center;
  gap: var(--spacing-xl);
  padding: var(--spacing-xl);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  margin-bottom: var(--spacing-xl);
  box-shadow: var(--shadow-sm);
}

.profile-avatar-wrapper {
  flex-shrink: 0;
  width: 96px;
  height: 96px;
  border-radius: 50%;
  overflow: hidden;
  background: linear-gradient(135deg, var(--color-primary), #3b82f6);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.profile-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.profile-avatar-initial {
  font-size: 2.25rem;
  font-weight: 700;
  color: #fff;
}

.profile-info-block {
  flex: 1;
  min-width: 0;
}

.profile-name-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
  margin-bottom: var(--spacing-xs);
}

.profile-name {
  font-size: var(--font-size-2xl);
  font-weight: 700;
  color: var(--color-text);
  margin: 0;
  line-height: 1.2;
}

.role-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: var(--font-size-xs);
  font-weight: 600;
  line-height: 1.4;
}

.role-badge--admin {
  background-color: rgba(239, 68, 68, 0.15);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.role-badge--creator {
  background-color: rgba(56, 189, 248, 0.15);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.role-badge--reader {
  background-color: rgba(168, 85, 247, 0.15);
  color: #c084fc;
  border: 1px solid rgba(168, 85, 247, 0.3);
}

.profile-meta-row {
  display: flex;
  gap: var(--spacing-md);
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
  margin-bottom: var(--spacing-md);
}

.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.profile-stats-row {
  display: flex;
  gap: var(--spacing-xl);
}

.stat-box {
  display: flex;
  flex-direction: column;
}

.stat-number {
  font-size: var(--font-size-xl);
  font-weight: 700;
  color: var(--color-text);
}

.stat-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
}

/* Action block: Nhắn tin button */
.profile-action-block {
  flex-shrink: 0;
}

.btn-message {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-sm) var(--spacing-lg);
  background-color: var(--color-primary);
  color: #fff;
  border: none;
  border-radius: var(--radius-lg);
  font-size: var(--font-size-sm);
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition-fast);
  box-shadow: 0 2px 8px rgba(14, 165, 233, 0.3);
}

.btn-message:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-1px);
}

/* Tabs Navigation */
.profile-tabs-nav {
  display: flex;
  gap: var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
  margin-bottom: var(--spacing-xl);
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-md) var(--spacing-lg);
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--color-text-secondary);
  font-size: var(--font-size-base);
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.tab-btn:hover {
  color: var(--color-text);
}

.tab-btn--active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
}

.tab-count {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* Panels */
.tab-panel {
  min-height: 300px;
}

.comics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--spacing-lg);
  margin-bottom: var(--spacing-2xl);
}

.posts-list {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
  max-width: 800px;
  margin: 0 auto var(--spacing-2xl);
}

/* Empty State inside tabs */
.panel-empty-state {
  text-align: center;
  padding: var(--spacing-3xl) var(--spacing-lg);
  background-color: var(--color-bg-surface);
  border-radius: var(--radius-lg);
  border: 1px dashed var(--color-border);
  max-width: 500px;
  margin: var(--spacing-xl) auto;
}

.empty-icon {
  font-size: 2.5rem;
  display: block;
  margin-bottom: var(--spacing-sm);
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: 700;
  color: var(--color-text);
  margin-bottom: var(--spacing-xs);
}

.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

/* Pagination */
.pagination-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-md);
  margin-top: var(--spacing-xl);
}

.btn-page {
  padding: var(--spacing-xs) var(--spacing-md);
  background-color: var(--color-bg-surface);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-page:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.btn-page:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.page-indicator {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

/* Skeleton Loaders */
.profile-skeleton-card {
  background-color: var(--color-bg-surface);
  border-radius: var(--radius-xl);
  padding: var(--spacing-xl);
  border: 1px solid var(--color-border);
}

.skeleton-header {
  display: flex;
  gap: var(--spacing-xl);
  align-items: center;
  margin-bottom: var(--spacing-xl);
}

.skeleton-avatar {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background-color: rgba(255, 255, 255, 0.08);
  animation: pulse 1.5s infinite;
}

.skeleton-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.skeleton-line {
  background-color: rgba(255, 255, 255, 0.08);
  border-radius: var(--radius-sm);
  animation: pulse 1.5s infinite;
}

.skeleton-line.title {
  width: 200px;
  height: 28px;
}

.skeleton-line.role {
  width: 100px;
  height: 18px;
}

.skeleton-line.meta {
  width: 150px;
  height: 16px;
}

.skeleton-tabs {
  display: flex;
  gap: var(--spacing-md);
  margin-bottom: var(--spacing-xl);
}

.skeleton-tab {
  width: 140px;
  height: 38px;
  background-color: rgba(255, 255, 255, 0.06);
  border-radius: var(--radius-md);
  animation: pulse 1.5s infinite;
}

.skeleton-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--spacing-lg);
}

.skeleton-card {
  height: 240px;
  background-color: rgba(255, 255, 255, 0.06);
  border-radius: var(--radius-md);
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

/* Responsive */
@media (max-width: 640px) {
  .profile-header-card {
    flex-direction: column;
    text-align: center;
    gap: var(--spacing-lg);
  }

  .profile-name-row {
    justify-content: center;
  }

  .profile-meta-row {
    justify-content: center;
  }

  .profile-stats-row {
    justify-content: center;
  }

  .profile-action-block {
    width: 100%;
  }

  .btn-message {
    width: 100%;
    justify-content: center;
  }

  .comics-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: var(--spacing-md);
  }
}
</style>
