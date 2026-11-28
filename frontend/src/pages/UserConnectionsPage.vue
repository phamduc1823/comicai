<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { CommunityService } from '@/services/communityService'
import { getErrorMessage } from '@/services/httpClient'
import type { FollowingUserItem } from '@/types/community'

const route = useRoute()
const router = useRouter()

// ---------------------------------------------------------------------------
// Tabs: 'followers' (Tab 1: Người theo dõi) | 'following' (Tab 2: Đang theo dõi)
// ---------------------------------------------------------------------------
const activeTab = ref<'followers' | 'following'>('followers')

// Sync tab with URL hash or query param
watch(
  () => [route.hash, route.query.tab],
  ([hash, tabQuery]) => {
    if (hash === '#following' || tabQuery === 'following') {
      activeTab.value = 'following'
    } else if (hash === '#followers' || tabQuery === 'followers') {
      activeTab.value = 'followers'
    }
  },
  { immediate: true }
)

function setTab(tab: 'followers' | 'following') {
  activeTab.value = tab
  const hash = tab === 'following' ? '#following' : '#followers'
  if (route.hash !== hash) {
    router.replace({ hash })
  }
}

// ---------------------------------------------------------------------------
// Tab 2: Following (Đang theo dõi) State & Handlers
// ---------------------------------------------------------------------------
const followingList = ref<FollowingUserItem[]>([])
const followingTotalCount = ref(0)
const followingSearchQuery = ref('')
const isLoadingFollowing = ref(false)
const followingFeedbackMessage = ref<string | null>(null)
const followingErrorMessage = ref<string | null>(null)
const isUnfollowingMap = ref<Record<number, boolean>>({})

async function loadFollowingList() {
  isLoadingFollowing.value = true
  followingErrorMessage.value = null
  try {
    const res = await CommunityService.getFollowingUsers(100)
    followingList.value = res.data || []
    followingTotalCount.value = res.meta?.total ?? followingList.value.length
  } catch (err) {
    followingErrorMessage.value = getErrorMessage(err, 'Không thể tải danh sách người đang theo dõi.')
  } finally {
    isLoadingFollowing.value = false
  }
}

watch(
  activeTab,
  (tab) => {
    if (tab === 'following' && followingList.value.length === 0 && !isLoadingFollowing.value) {
      loadFollowingList()
    }
  },
  { immediate: true }
)

const filteredFollowingList = computed(() => {
  const q = followingSearchQuery.value.trim().toLowerCase()
  if (!q) return followingList.value
  return followingList.value.filter((user) => user.name.toLowerCase().includes(q))
})

async function handleUnfollowUser(userId: number, userName: string) {
  if (isUnfollowingMap.value[userId]) return
  isUnfollowingMap.value[userId] = true
  followingFeedbackMessage.value = null
  followingErrorMessage.value = null

  try {
    await CommunityService.unfollowUser(userId)
    followingList.value = followingList.value.filter((u) => u.id !== userId)
    if (followingTotalCount.value > 0) {
      followingTotalCount.value--
    }
    followingFeedbackMessage.value = `Đã bỏ theo dõi ${userName}.`
    setTimeout(() => {
      if (followingFeedbackMessage.value?.includes(userName)) {
        followingFeedbackMessage.value = null
      }
    }, 4000)
  } catch (err) {
    followingErrorMessage.value = getErrorMessage(err, 'Không thể bỏ theo dõi người dùng này.')
  } finally {
    isUnfollowingMap.value[userId] = false
  }
}

// ---------------------------------------------------------------------------
// Tab 1: Followers (Người theo dõi) State & Handlers
// ---------------------------------------------------------------------------
const followersList = ref<FollowingUserItem[]>([])
const followersTotalCount = ref(0)
const followersSearchQuery = ref('')
const isLoadingFollowers = ref(false)
const followersErrorMessage = ref<string | null>(null)

async function loadFollowersList() {
  isLoadingFollowers.value = true
  followersErrorMessage.value = null
  try {
    const res = await CommunityService.getFollowerUsers(100)
    followersList.value = res.data || []
    followersTotalCount.value = res.meta?.total ?? followersList.value.length
  } catch (err) {
    followersErrorMessage.value = getErrorMessage(err, 'Không thể tải danh sách người theo dõi.')
  } finally {
    isLoadingFollowers.value = false
  }
}

watch(
  activeTab,
  (tab) => {
    if (tab === 'followers' && followersList.value.length === 0 && !isLoadingFollowers.value) {
      loadFollowersList()
    }
  },
  { immediate: true }
)

const filteredFollowersList = computed(() => {
  const q = followersSearchQuery.value.trim().toLowerCase()
  if (!q) return followersList.value
  return followersList.value.filter((user) => user.name.toLowerCase().includes(q))
})

onMounted(() => {
  if (activeTab.value === 'following') {
    loadFollowingList()
  } else {
    loadFollowersList()
  }
})
</script>

<template>
  <div class="user-connections-page">
    <div class="connections-container">
      <!-- Breadcrumb / Back Link -->
      <nav class="connections-breadcrumb" aria-label="Đường dẫn điều hướng">
        <RouterLink to="/profile" class="breadcrumb-link">
          ← Quay lại Hồ sơ cá nhân
        </RouterLink>
      </nav>

      <!-- Page Header -->
      <header class="connections-header">
        <div class="header-titles">
          <h1 class="page-title">Người dùng</h1>
          <p class="page-subtitle">
            Quản lý danh sách Người theo dõi và Đang theo dõi của tài khoản trên ComicAI.
          </p>
        </div>
      </header>

      <!-- Tabs Navigation -->
      <div class="connections-tabs" role="tablist" aria-label="Danh mục kết nối người dùng">
        <button
          type="button"
          class="tab-btn"
          :class="{ active: activeTab === 'followers' }"
          role="tab"
          :aria-selected="activeTab === 'followers'"
          aria-controls="tab-pane-followers"
          @click="setTab('followers')"
        >
          <span class="tab-icon" aria-hidden="true">👥</span>
          <span>Người theo dõi</span>
          <span v-if="followersTotalCount > 0" class="tab-badge">
            {{ followersTotalCount }}
          </span>
        </button>

        <button
          type="button"
          class="tab-btn"
          :class="{ active: activeTab === 'following' }"
          role="tab"
          :aria-selected="activeTab === 'following'"
          aria-controls="tab-pane-following"
          @click="setTab('following')"
        >
          <span class="tab-icon" aria-hidden="true">✨</span>
          <span>Đang theo dõi</span>
          <span v-if="followingTotalCount > 0" class="tab-badge">
            {{ followingTotalCount }}
          </span>
        </button>
      </div>

      <!-- Tab Content Area -->
      <main class="connections-content">
        <!-- TAB 1: Người theo dõi -->
        <section
          v-if="activeTab === 'followers'"
          id="tab-pane-followers"
          class="tab-panel"
          role="tabpanel"
          aria-labelledby="tab-followers"
        >
          <div class="panel-header-row">
            <div>
              <h2 class="section-title">Danh sách người theo dõi bạn</h2>
              <p class="section-desc">Những độc giả hoặc tác giả đang theo dõi các hoạt động và bài viết của bạn.</p>
            </div>
            <span v-if="followersTotalCount > 0" class="total-pill">
              {{ followersTotalCount }} người
            </span>
          </div>

          <!-- Search Bar in Followers List -->
          <div class="search-bar-wrap">
            <span class="search-icon" aria-hidden="true">🔍</span>
            <input
              v-model="followersSearchQuery"
              type="text"
              placeholder="Tìm kiếm theo tên trong danh sách người theo dõi..."
              class="search-input"
              aria-label="Tìm kiếm người theo dõi bạn"
            />
            <button
              v-if="followersSearchQuery"
              type="button"
              class="btn-clear"
              title="Xóa tìm kiếm"
              aria-label="Xóa tìm kiếm"
              @click="followersSearchQuery = ''"
            >
              ✕
            </button>
          </div>

          <!-- Loading State -->
          <div v-if="isLoadingFollowers" class="loading-box" aria-busy="true">
            <div class="spinner" aria-hidden="true"></div>
            <span>Đang tải danh sách người theo dõi...</span>
          </div>

          <!-- Empty State: 0 followers -->
          <div v-else-if="followersList.length === 0" class="empty-box">
            <div class="empty-icon" aria-hidden="true">👥</div>
            <h3 class="empty-title">Chưa có người theo dõi nào</h3>
            <p class="empty-desc">
              Khi có người dùng theo dõi tài khoản của bạn, danh sách tài khoản sẽ xuất hiện tại đây.
            </p>
            <RouterLink to="/community" class="btn-primary-action">
              Khám phá Cộng đồng
            </RouterLink>
          </div>

          <!-- Empty State: Search yields 0 results -->
          <div v-else-if="filteredFollowersList.length === 0" class="empty-box">
            <div class="empty-icon" aria-hidden="true">🔎</div>
            <h3 class="empty-title">Không tìm thấy người dùng phù hợp</h3>
            <p class="empty-desc">
              Không có người theo dõi nào khớp với từ khóa "<strong>{{ followersSearchQuery }}</strong>".
            </p>
            <button type="button" class="btn-primary-action secondary" @click="followersSearchQuery = ''">
              Xem tất cả người theo dõi
            </button>
          </div>

          <!-- Followers List Grid -->
          <div v-else class="users-grid" role="list">
            <div
              v-for="user in filteredFollowersList"
              :key="'follower-' + user.id"
              class="user-card"
              role="listitem"
            >
              <RouterLink :to="`/users/${user.id}`" class="user-card-link">
                <img
                  v-if="user.avatar_url || user.avatar_path"
                  :src="user.avatar_url || user.avatar_path || ''"
                  :alt="user.name"
                  class="user-avatar"
                />
                <div v-else class="user-avatar-fallback">
                  {{ user.name.charAt(0).toUpperCase() }}
                </div>
                <div class="user-card-meta">
                  <span class="user-name" :title="user.name">{{ user.name }}</span>
                  <span v-if="user.role === 'creator'" class="badge-creator">Tác giả</span>
                  <span v-else class="user-sub">{{ user.posts_count || 0 }} bài viết</span>
                </div>
              </RouterLink>
            </div>
          </div>
        </section>

        <!-- TAB 2: Đang theo dõi -->
        <section
          v-else-if="activeTab === 'following'"
          id="tab-pane-following"
          class="tab-panel"
          role="tabpanel"
          aria-labelledby="tab-following"
        >
          <div class="panel-header-row">
            <div>
              <h2 class="section-title">Danh sách người bạn đang theo dõi</h2>
              <p class="section-desc">Những tác giả và độc giả mà bạn đang theo dõi để cập nhật truyện và bài viết mới nhất.</p>
            </div>
            <span v-if="followingTotalCount > 0" class="total-pill">
              {{ followingTotalCount }} người
            </span>
          </div>

          <!-- Search Bar in Following List -->
          <div class="search-bar-wrap">
            <span class="search-icon" aria-hidden="true">🔍</span>
            <input
              v-model="followingSearchQuery"
              type="text"
              placeholder="Tìm kiếm theo tên trong danh sách theo dõi..."
              class="search-input"
              aria-label="Tìm kiếm người đang theo dõi"
            />
            <button
              v-if="followingSearchQuery"
              type="button"
              class="btn-clear"
              title="Xóa tìm kiếm"
              aria-label="Xóa tìm kiếm"
              @click="followingSearchQuery = ''"
            >
              ✕
            </button>
          </div>

          <!-- Alerts -->
          <div v-if="followingFeedbackMessage" class="feedback-banner success" role="alert">
            {{ followingFeedbackMessage }}
          </div>
          <div v-if="followingErrorMessage" class="feedback-banner error" role="alert">
            {{ followingErrorMessage }}
          </div>

          <!-- Loading State -->
          <div v-if="isLoadingFollowing" class="loading-box" aria-busy="true">
            <div class="spinner" aria-hidden="true"></div>
            <span>Đang tải danh sách người đang theo dõi...</span>
          </div>

          <!-- Empty: Not following anyone -->
          <div v-else-if="followingList.length === 0" class="empty-box">
            <div class="empty-icon" aria-hidden="true">✨</div>
            <h3 class="empty-title">Bạn chưa theo dõi người dùng nào</h3>
            <p class="empty-desc">
              Khám phá các tác giả nổi bật trong Cộng đồng để cập nhật bài viết và hoạt động mới nhất.
            </p>
            <RouterLink to="/community" class="btn-primary-action">
              Khám phá Cộng đồng
            </RouterLink>
          </div>

          <!-- Empty: Search yields 0 results -->
          <div v-else-if="filteredFollowingList.length === 0" class="empty-box">
            <div class="empty-icon" aria-hidden="true">🔎</div>
            <h3 class="empty-title">Không tìm thấy người dùng phù hợp</h3>
            <p class="empty-desc">
              Không có người dùng nào khớp với từ khóa "<strong>{{ followingSearchQuery }}</strong>".
            </p>
            <button type="button" class="btn-primary-action secondary" @click="followingSearchQuery = ''">
              Xem tất cả người đang theo dõi
            </button>
          </div>

          <!-- Following Users Grid -->
          <div v-else class="users-grid" role="list">
            <div
              v-for="user in filteredFollowingList"
              :key="'user-following-' + user.id"
              class="user-card"
              role="listitem"
            >
              <RouterLink :to="`/users/${user.id}`" class="user-card-link">
                <img
                  v-if="user.avatar_url || user.avatar_path"
                  :src="user.avatar_url || user.avatar_path || ''"
                  :alt="user.name"
                  class="user-avatar"
                />
                <div v-else class="user-avatar-fallback">
                  {{ user.name.charAt(0).toUpperCase() }}
                </div>
                <div class="user-card-meta">
                  <span class="user-name" :title="user.name">{{ user.name }}</span>
                  <span v-if="user.role === 'creator'" class="badge-creator">Tác giả</span>
                  <span v-else class="user-sub">{{ user.posts_count || 0 }} bài viết</span>
                </div>
              </RouterLink>

              <div class="user-card-actions">
                <button
                  type="button"
                  class="btn-following-toggle"
                  :disabled="isUnfollowingMap[user.id]"
                  :title="'Bấm để bỏ theo dõi ' + user.name"
                  @click="handleUnfollowUser(user.id, user.name)"
                >
                  <span v-if="isUnfollowingMap[user.id]">Đang hủy...</span>
                  <span v-else class="btn-label-state">Đang theo dõi</span>
                </button>
              </div>
            </div>
          </div>
        </section>
      </main>
    </div>
  </div>
</template>

<style scoped>
.user-connections-page {
  min-height: calc(100vh - 68px);
  padding: var(--spacing-xl) var(--spacing-md);
  background-color: var(--color-bg-base, #0d1117);
  color: var(--color-text-base, #e6edf3);
}

.connections-container {
  max-width: 1080px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

.connections-breadcrumb {
  margin-bottom: -4px;
}

.breadcrumb-link {
  color: var(--color-text-muted, #8b949e);
  font-size: var(--font-size-sm, 14px);
  text-decoration: none;
  transition: color var(--transition-fast, 0.15s ease);
}

.breadcrumb-link:hover {
  color: #38bdf8;
}

.connections-header {
  border-bottom: 1px solid var(--color-border, #30363d);
  padding-bottom: var(--spacing-md);
}

.page-title {
  font-size: 1.75rem;
  font-weight: 800;
  color: var(--color-text-base, #e6edf3);
  margin: 0 0 6px 0;
}

.page-subtitle {
  font-size: var(--font-size-sm, 14px);
  color: var(--color-text-muted, #8b949e);
  margin: 0;
}

.connections-tabs {
  display: flex;
  gap: 8px;
  border-bottom: 1px solid var(--color-border, #30363d);
  padding-bottom: 2px;
}

.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--color-text-muted, #8b949e);
  font-size: var(--font-size-sm, 14px);
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
}

.tab-btn:hover {
  color: var(--color-text-base, #e6edf3);
}

.tab-btn.active {
  color: #38bdf8;
  border-bottom-color: #38bdf8;
}

.tab-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  background: rgba(56, 189, 248, 0.15);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.3);
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 700;
}

.connections-content {
  background-color: var(--color-bg-surface, #161b22);
  border: 1px solid var(--color-border, #30363d);
  border-radius: var(--radius-xl, 12px);
  padding: var(--spacing-xl);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.tab-panel {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

.panel-header-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.section-title {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--color-text-base, #e6edf3);
  margin: 0 0 4px 0;
}

.section-desc {
  font-size: var(--font-size-sm, 14px);
  color: var(--color-text-muted, #8b949e);
  margin: 0;
}

.total-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  background: rgba(56, 189, 248, 0.1);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.25);
  border-radius: 9999px;
  font-size: var(--font-size-xs, 12px);
  font-weight: 600;
}

.search-bar-wrap {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}

.search-icon {
  position: absolute;
  left: 14px;
  color: var(--color-text-muted, #8b949e);
  font-size: 14px;
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 10px 38px 10px 38px;
  background-color: var(--color-bg-base, #0d1117);
  border: 1px solid var(--color-border, #30363d);
  border-radius: var(--radius-lg, 8px);
  color: var(--color-text-base, #e6edf3);
  font-size: var(--font-size-sm, 14px);
  transition: all var(--transition-fast, 0.15s ease);
}

.search-input:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
}

.btn-clear {
  position: absolute;
  right: 12px;
  background: transparent;
  border: none;
  color: var(--color-text-muted, #8b949e);
  cursor: pointer;
  padding: 4px;
  font-size: 13px;
}

.btn-clear:hover {
  color: var(--color-text-base, #e6edf3);
}

.feedback-banner {
  padding: 10px 14px;
  border-radius: var(--radius-md, 6px);
  font-size: var(--font-size-sm, 14px);
}

.feedback-banner.success {
  background-color: rgba(46, 160, 67, 0.15);
  color: #3fb950;
  border: 1px solid rgba(46, 160, 67, 0.3);
}

.feedback-banner.error {
  background-color: rgba(248, 81, 73, 0.15);
  color: #f85149;
  border: 1px solid rgba(248, 81, 73, 0.3);
}

.loading-box {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 48px 16px;
  color: var(--color-text-muted, #8b949e);
  font-size: var(--font-size-sm, 14px);
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(56, 189, 248, 0.3);
  border-top-color: #38bdf8;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.empty-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 48px 24px;
  background-color: rgba(255, 255, 255, 0.02);
  border: 1px dashed var(--color-border, #30363d);
  border-radius: var(--radius-xl, 12px);
  gap: 12px;
}

.empty-icon {
  font-size: 40px;
}

.empty-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--color-text-base, #e6edf3);
  margin: 0;
}

.empty-desc {
  font-size: var(--font-size-sm, 14px);
  color: var(--color-text-muted, #8b949e);
  max-width: 440px;
  margin: 0;
}

.btn-primary-action {
  margin-top: 8px;
  padding: 8px 20px;
  background-color: #0284c7;
  color: #fff;
  border: none;
  border-radius: var(--radius-md, 6px);
  font-size: var(--font-size-sm, 14px);
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: background-color var(--transition-fast, 0.15s ease);
}

.btn-primary-action:hover {
  background-color: #0369a1;
}

.btn-primary-action.secondary {
  background-color: rgba(255, 255, 255, 0.06);
  color: var(--color-text-base, #e6edf3);
  border: 1px solid var(--color-border, #30363d);
}

.btn-primary-action.secondary:hover {
  background-color: rgba(255, 255, 255, 0.1);
}

.users-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
  gap: 14px;
}

.user-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  background-color: var(--color-bg-base, #0d1117);
  border: 1px solid var(--color-border, #30363d);
  border-radius: var(--radius-lg, 8px);
  gap: 12px;
  transition: border-color var(--transition-fast, 0.15s ease), transform var(--transition-fast, 0.15s ease);
}

.user-card:hover {
  border-color: rgba(56, 189, 248, 0.3);
  transform: translateY(-1px);
}

.user-card-link {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  text-decoration: none;
  color: inherit;
  flex: 1;
}

.user-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid var(--color-border, #30363d);
}

.user-avatar-fallback {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0284c7, #38bdf8);
  color: #fff;
  font-weight: 700;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.user-card-meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 2px;
}

.user-name {
  font-size: var(--font-size-sm, 14px);
  font-weight: 600;
  color: var(--color-text-base, #e6edf3);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.badge-creator {
  display: inline-block;
  align-self: flex-start;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 4px;
  background: rgba(245, 158, 11, 0.15);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.user-sub {
  font-size: var(--font-size-xs, 12px);
  color: var(--color-text-muted, #8b949e);
}

.btn-following-toggle {
  padding: 6px 12px;
  border-radius: 9999px;
  font-size: var(--font-size-xs, 12px);
  font-weight: 600;
  border: 1px solid var(--color-border, #30363d);
  background-color: rgba(255, 255, 255, 0.05);
  color: var(--color-text-base, #e6edf3);
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-following-toggle:hover:not(:disabled) {
  background-color: rgba(239, 68, 68, 0.15);
  border-color: #ef4444;
  color: #ef4444;
}

.btn-following-toggle:hover:not(:disabled) .btn-label-state::after {
  content: ' (Hủy)';
}

@media (max-width: 640px) {
  .connections-content {
    padding: var(--spacing-md);
  }
  .users-grid {
    grid-template-columns: 1fr;
  }
}
</style>
