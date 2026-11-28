<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import { CommunityService } from '@/services/communityService'
import type { SuggestedUserItem, FollowingUserItem } from '@/types/community'

const router = useRouter()
const authStore = useAuthStore()
const chatStore = useChatStore()

const isAuthenticated = computed(() => authStore.isAuthenticated)

// State
const suggestedUsers = ref<SuggestedUserItem[]>([])
const followingUsers = ref<FollowingUserItem[]>([])
const isLoadingSuggested = ref(false)
const isLoadingFollowing = ref(false)
const followActionLoadingMap = ref<Record<number, boolean>>({})

// User Search in Sidebar (CAI-USER-02)
const userSearchQuery = ref('')
const userSearchResults = ref<SuggestedUserItem[]>([])
const isSearchingUsers = ref(false)
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

function handleUserSearchInput() {
  const q = userSearchQuery.value.trim()
  if (!q) {
    userSearchResults.value = []
    isSearchingUsers.value = false
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    return
  }

  if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
  searchDebounceTimer = setTimeout(async () => {
    isSearchingUsers.value = true
    try {
      const res = await CommunityService.searchUsers(q)
      userSearchResults.value = res.data || []
    } catch {
      userSearchResults.value = []
    } finally {
      isSearchingUsers.value = false
    }
  }, 250)
}

function clearUserSearch() {
  userSearchQuery.value = ''
  userSearchResults.value = []
  isSearchingUsers.value = false
  if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
}

async function handleToggleFollowSearchResult(user: SuggestedUserItem) {
  if (followActionLoadingMap.value[user.id]) return
  if (user.is_following) {
    await handleUnfollow(user.id)
    user.is_following = false
  } else {
    await handleFollow(user)
    user.is_following = true
  }
}

async function loadSuggestedUsers() {
  if (!isAuthenticated.value) return
  isLoadingSuggested.value = true
  try {
    const res = await CommunityService.getSuggestedUsers()
    suggestedUsers.value = res.data || []
  } catch {
    suggestedUsers.value = []
  } finally {
    isLoadingSuggested.value = false
  }
}

async function loadFollowingUsers() {
  if (!isAuthenticated.value) return
  isLoadingFollowing.value = true
  try {
    const res = await CommunityService.getFollowingUsers(15)
    followingUsers.value = res.data || []
  } catch {
    followingUsers.value = []
  } finally {
    isLoadingFollowing.value = false
  }
}

async function handleFollow(user: SuggestedUserItem) {
  if (followActionLoadingMap.value[user.id]) return
  followActionLoadingMap.value[user.id] = true
  try {
    await CommunityService.followUser(user.id)
    user.is_following = true
    // Refresh following list
    await loadFollowingUsers()
  } catch (err) {
    console.error('Không thể theo dõi người dùng:', err)
  } finally {
    followActionLoadingMap.value[user.id] = false
  }
}

async function handleUnfollow(userId: number) {
  if (followActionLoadingMap.value[userId]) return
  followActionLoadingMap.value[userId] = true
  try {
    await CommunityService.unfollowUser(userId)
    followingUsers.value = followingUsers.value.filter((u) => u.id !== userId)
    // If was in suggested, update state
    const suggested = suggestedUsers.value.find((u) => u.id === userId)
    if (suggested) {
      suggested.is_following = false
    }
  } catch (err) {
    console.error('Không thể bỏ theo dõi người dùng:', err)
  } finally {
    followActionLoadingMap.value[userId] = false
  }
}

function navigateToUserProfile(userId: number) {
  router.push(`/users/${userId}`)
}

function navigateToChatRequests() {
  router.push({ path: '/chat', query: { tab: 'requests' } })
}

onMounted(() => {
  if (isAuthenticated.value) {
    loadSuggestedUsers()
    loadFollowingUsers()
    chatStore.fetchMessageRequestsCount()
  }
})
</script>

<template>
  <div class="community-connections-sidebar" aria-label="Kết nối cộng đồng">
    <!-- Case 2: Guest / Unauthenticated State -->
    <section v-if="!isAuthenticated" class="connection-card guest-cta-card" aria-label="Đăng nhập để kết nối">
      <div class="guest-cta-icon" aria-hidden="true">🤝</div>
      <h2 class="guest-cta-title">Kết nối cùng độc giả</h2>
      <p class="guest-cta-desc">
        Đăng nhập vào ComicAI để theo dõi tác giả, kết nối với cộng đồng và gửi nhận tin nhắn trực tiếp.
      </p>
      <router-link to="/login?redirect=/community" class="btn-login-cta">
        Đăng nhập ngay
      </router-link>
    </section>

    <!-- Case 1: Authenticated User State -->
    <template v-else>
      <!-- Section C: Message Requests Shortcut -->
      <section class="connection-card message-requests-shortcut-card" aria-label="Lối tắt tin nhắn chờ">
        <div
          class="requests-shortcut-row"
          role="button"
          tabindex="0"
          @click="navigateToChatRequests"
          @keydown.enter="navigateToChatRequests"
          @keydown.space.prevent="navigateToChatRequests"
        >
          <div class="shortcut-icon-wrapper">
            <span class="shortcut-icon" aria-hidden="true">📬</span>
            <span
              v-if="chatStore.messageRequestsCount > 0"
              class="unread-requests-badge"
              :aria-label="chatStore.messageRequestsCount + ' tin nhắn chờ chưa đọc'"
            >
              {{ chatStore.messageRequestsCount > 99 ? '99+' : chatStore.messageRequestsCount }}
            </span>
          </div>

          <div class="shortcut-text-group">
            <span class="shortcut-title">Tin nhắn chờ</span>
            <span class="shortcut-subtitle">
              {{
                chatStore.messageRequestsCount > 0
                  ? `Có ${chatStore.messageRequestsCount} tin nhắn chờ mới`
                  : 'Xem tin nhắn từ người gửi mới'
              }}
            </span>
          </div>

          <span class="shortcut-arrow" aria-hidden="true">➔</span>
        </div>
      </section>

      <!-- Section A: Suggested Users to Follow -->
      <section class="connection-card" aria-labelledby="suggested-users-heading">
        <div class="card-header">
          <div class="card-title-group">
            <span class="card-icon" aria-hidden="true">✨</span>
            <h2 id="suggested-users-heading" class="card-title">Gợi ý theo dõi</h2>
          </div>
        </div>

        <!-- Search User Box (CAI-USER-02) -->
        <div class="sidebar-search-box">
          <span class="search-box-icon" aria-hidden="true">🔍</span>
          <input
            v-model="userSearchQuery"
            type="text"
            placeholder="Tìm người dùng theo tên..."
            class="sidebar-search-input"
            aria-label="Tìm kiếm người dùng trong cộng đồng"
            @input="handleUserSearchInput"
          />
          <button
            v-if="userSearchQuery"
            type="button"
            class="btn-clear-search-sidebar"
            title="Xóa tìm kiếm"
            aria-label="Xóa tìm kiếm"
            @click="clearUserSearch"
          >
            ✕
          </button>
        </div>

        <!-- SEARCH MODE ACTIVE -->
        <template v-if="userSearchQuery.trim()">
          <!-- Searching Skeleton -->
          <div v-if="isSearchingUsers" class="connection-skeleton-list" aria-busy="true">
            <div v-for="i in 2" :key="'search-skel-' + i" class="connection-skeleton-item">
              <div class="skeleton-avatar"></div>
              <div class="skeleton-text">
                <div class="skeleton-line name"></div>
                <div class="skeleton-line role"></div>
              </div>
            </div>
          </div>

          <!-- Search Empty -->
          <div v-else-if="userSearchResults.length === 0" class="connection-empty">
            <p class="empty-text">Không tìm thấy người dùng nào phù hợp với "{{ userSearchQuery }}".</p>
          </div>

          <!-- Search Results List -->
          <ul v-else class="user-connection-list" role="list">
            <li
              v-for="user in userSearchResults"
              :key="'search-res-' + user.id"
              class="user-connection-item"
            >
              <div class="user-info-area" @click="navigateToUserProfile(user.id)">
                <img
                  v-if="user.avatar_url || user.avatar_path"
                  :src="user.avatar_url || user.avatar_path || ''"
                  :alt="user.name"
                  class="user-avatar"
                />
                <div v-else class="user-avatar-fallback">
                  {{ user.name.charAt(0).toUpperCase() }}
                </div>

                <div class="user-meta">
                  <span class="user-name" :title="user.name">{{ user.name }}</span>
                  <span v-if="user.role === 'creator'" class="badge-role creator">Tác giả</span>
                  <span v-else class="user-subtext">{{ user.posts_count }} bài viết</span>
                </div>
              </div>

              <button
                type="button"
                class="btn-follow"
                :class="{ 'is-following': user.is_following }"
                :disabled="followActionLoadingMap[user.id]"
                @click.stop="handleToggleFollowSearchResult(user)"
              >
                <span v-if="followActionLoadingMap[user.id]">...</span>
                <span v-else-if="user.is_following">Đang theo dõi</span>
                <span v-else>Theo dõi</span>
              </button>
            </li>
          </ul>
        </template>

        <!-- DEFAULT SUGGESTIONS MODE -->
        <template v-else>
          <!-- Loading -->
          <div v-if="isLoadingSuggested" class="connection-skeleton-list" aria-busy="true">
            <div v-for="i in 3" :key="i" class="connection-skeleton-item">
              <div class="skeleton-avatar"></div>
              <div class="skeleton-text">
                <div class="skeleton-line name"></div>
                <div class="skeleton-line role"></div>
              </div>
            </div>
          </div>

          <!-- Empty -->
          <div v-else-if="suggestedUsers.length === 0" class="connection-empty">
            <p class="empty-text">Hiện chưa có gợi ý mới.</p>
          </div>

          <!-- List -->
          <ul v-else class="user-connection-list" role="list">
            <li
              v-for="user in suggestedUsers"
              :key="'suggested-' + user.id"
              class="user-connection-item"
            >
              <div class="user-info-area" @click="navigateToUserProfile(user.id)">
                <img
                  v-if="user.avatar_url || user.avatar_path"
                  :src="user.avatar_url || user.avatar_path || ''"
                  :alt="user.name"
                  class="user-avatar"
                />
                <div v-else class="user-avatar-fallback">
                  {{ user.name.charAt(0).toUpperCase() }}
                </div>

                <div class="user-meta">
                  <span class="user-name" :title="user.name">{{ user.name }}</span>
                  <span v-if="user.role === 'creator'" class="badge-role creator">Tác giả</span>
                  <span v-else class="user-subtext">{{ user.posts_count }} bài viết</span>
                </div>
              </div>

              <button
                type="button"
                class="btn-follow"
                :class="{ 'is-following': user.is_following }"
                :disabled="user.is_following || followActionLoadingMap[user.id]"
                @click.stop="handleFollow(user)"
              >
                <span v-if="followActionLoadingMap[user.id]">...</span>
                <span v-else-if="user.is_following">Đã theo dõi</span>
                <span v-else>Theo dõi</span>
              </button>
            </li>
          </ul>
        </template>
      </section>

      <!-- Section B: Following Users List -->
      <section class="connection-card" aria-labelledby="following-users-heading">
        <div class="card-header">
          <div class="card-title-group">
            <span class="card-icon" aria-hidden="true">👥</span>
            <h2 id="following-users-heading" class="card-title">Đang theo dõi</h2>
          </div>
          <span v-if="followingUsers.length > 0" class="following-count-badge">
            {{ followingUsers.length }}
          </span>
        </div>

        <!-- Loading -->
        <div v-if="isLoadingFollowing" class="connection-skeleton-list" aria-busy="true">
          <div v-for="i in 3" :key="i" class="connection-skeleton-item">
            <div class="skeleton-avatar"></div>
            <div class="skeleton-text">
              <div class="skeleton-line name"></div>
            </div>
          </div>
        </div>

        <!-- Empty -->
        <div v-else-if="followingUsers.length === 0" class="connection-empty">
          <p class="empty-text">Bạn chưa theo dõi người dùng nào.</p>
        </div>

        <!-- List -->
        <ul v-else class="user-connection-list" role="list">
          <li
            v-for="user in followingUsers"
            :key="'following-' + user.id"
            class="user-connection-item"
          >
            <div class="user-info-area" @click="navigateToUserProfile(user.id)">
              <img
                v-if="user.avatar_url || user.avatar_path"
                :src="user.avatar_url || user.avatar_path || ''"
                :alt="user.name"
                class="user-avatar"
              />
              <div v-else class="user-avatar-fallback">
                {{ user.name.charAt(0).toUpperCase() }}
              </div>

              <div class="user-meta">
                <span class="user-name" :title="user.name">{{ user.name }}</span>
                <span v-if="user.role === 'creator'" class="badge-role creator">Tác giả</span>
              </div>
            </div>

            <button
              type="button"
              class="btn-unfollow"
              :disabled="followActionLoadingMap[user.id]"
              title="Bỏ theo dõi"
              aria-label="Bỏ theo dõi người dùng"
              @click.stop="handleUnfollow(user.id)"
            >
              <span v-if="followActionLoadingMap[user.id]">...</span>
              <span v-else>Bỏ theo dõi</span>
            </button>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<style scoped>
.community-connections-sidebar {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg, 24px);
  width: 100%;
}

.connection-card {
  background: var(--color-bg-surface, #ffffff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--radius-lg, 12px);
  padding: var(--spacing-md, 16px);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--spacing-md, 16px);
  padding-bottom: var(--spacing-xs, 8px);
  border-bottom: 1px solid var(--color-border-subtle, #f1f5f9);
}

.sidebar-search-box {
  position: relative;
  display: flex;
  align-items: center;
  margin-bottom: var(--spacing-sm, 12px);
}

.search-box-icon {
  position: absolute;
  left: 10px;
  font-size: 13px;
  color: var(--color-text-muted, #94a3b8);
  pointer-events: none;
}

.sidebar-search-input {
  width: 100%;
  padding: 7px 30px 7px 32px;
  background: var(--color-bg-base, #f8fafc);
  border: 1px solid var(--color-border, #cbd5e1);
  border-radius: var(--radius-md, 8px);
  color: var(--color-text-primary, #0f172a);
  font-size: 0.85rem;
  transition: all var(--transition-fast, 0.15s ease);
}

.sidebar-search-input:focus {
  outline: none;
  border-color: #38bdf8;
  background: var(--color-bg-surface, #ffffff);
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}

.btn-clear-search-sidebar {
  position: absolute;
  right: 8px;
  background: transparent;
  border: none;
  color: var(--color-text-muted, #94a3b8);
  cursor: pointer;
  padding: 4px;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.btn-clear-search-sidebar:hover {
  color: var(--color-text-primary, #0f172a);
}

.card-title-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs, 8px);
}

.card-icon {
  font-size: 1.15rem;
}

.card-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--color-text-primary, #0f172a);
  margin: 0;
}

.following-count-badge {
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--color-primary, #3b82f6);
  background: var(--color-primary-light, #eff6ff);
  padding: 2px 8px;
  border-radius: 9999px;
}

/* Guest CTA */
.guest-cta-card {
  text-align: center;
  padding: var(--spacing-lg, 24px) var(--spacing-md, 16px);
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.guest-cta-icon {
  font-size: 2.5rem;
  margin-bottom: var(--spacing-xs, 8px);
}

.guest-cta-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--color-text-primary, #0f172a);
  margin: 0 0 8px 0;
}

.guest-cta-desc {
  font-size: 0.85rem;
  color: var(--color-text-muted, #64748b);
  line-height: 1.5;
  margin: 0 0 var(--spacing-md, 16px) 0;
}

.btn-login-cta {
  display: inline-block;
  padding: 8px 20px;
  background: var(--color-primary, #3b82f6);
  color: #ffffff;
  font-size: 0.9rem;
  font-weight: 600;
  border-radius: var(--radius-md, 8px);
  text-decoration: none;
  transition: background-color 0.15s ease;
}

.btn-login-cta:hover {
  background: var(--color-primary-dark, #2563eb);
}

/* Message Requests Shortcut */
.message-requests-shortcut-card {
  background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%);
  border-color: #bae6fd;
  padding: 12px 14px;
  cursor: pointer;
  transition: transform 0.12s ease, box-shadow 0.12s ease;
}

.message-requests-shortcut-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
}

.requests-shortcut-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.shortcut-icon-wrapper {
  position: relative;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
  flex-shrink: 0;
}

.unread-requests-badge {
  position: absolute;
  top: -4px;
  right: -6px;
  background: #ef4444;
  color: #ffffff;
  font-size: 0.65rem;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 9999px;
  border: 2px solid #ffffff;
}

.shortcut-text-group {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.shortcut-title {
  font-size: 0.92rem;
  font-weight: 700;
  color: var(--color-text-primary, #0f172a);
}

.shortcut-subtitle {
  font-size: 0.78rem;
  color: var(--color-text-secondary, #475569);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.shortcut-arrow {
  color: var(--color-primary, #0284c7);
  font-size: 0.95rem;
  font-weight: 700;
}

/* User connection lists */
.user-connection-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.user-connection-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 4px;
  border-radius: var(--radius-md, 8px);
}

.user-info-area {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  flex: 1;
  min-width: 0;
}

.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}

.user-avatar-fallback {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-primary-light, #e0e7ff);
  color: var(--color-primary, #4338ca);
  font-size: 0.95rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.user-meta {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 2px;
}

.user-name {
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--color-text-primary, #0f172a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.badge-role.creator {
  font-size: 0.68rem;
  font-weight: 700;
  color: #b45309;
  background: #fef3c7;
  padding: 1px 6px;
  border-radius: 4px;
  align-self: flex-start;
}

.user-subtext {
  font-size: 0.75rem;
  color: var(--color-text-muted, #64748b);
}

.btn-follow {
  padding: 5px 12px;
  font-size: 0.78rem;
  font-weight: 600;
  border-radius: 6px;
  border: 1px solid var(--color-primary, #3b82f6);
  background: var(--color-primary, #3b82f6);
  color: #ffffff;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.15s ease;
  flex-shrink: 0;
}

.btn-follow:hover:not(:disabled) {
  background: var(--color-primary-dark, #2563eb);
}

.btn-follow.is-following {
  background: var(--color-bg-secondary, #f1f5f9);
  border-color: var(--color-border, #cbd5e1);
  color: var(--color-text-secondary, #475569);
  cursor: default;
}

.btn-unfollow {
  padding: 4px 10px;
  font-size: 0.75rem;
  font-weight: 600;
  border-radius: 6px;
  border: 1px solid var(--color-border, #e2e8f0);
  background: #ffffff;
  color: var(--color-danger, #ef4444);
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.15s ease;
  flex-shrink: 0;
}

.btn-unfollow:hover:not(:disabled) {
  background: #fef2f2;
  border-color: #fecaca;
}

.connection-empty {
  padding: var(--spacing-sm, 12px) 0;
  text-align: center;
  color: var(--color-text-muted, #64748b);
  font-size: 0.85rem;
}

.connection-skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.connection-skeleton-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.skeleton-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--color-bg-secondary, #e2e8f0);
}

.skeleton-text {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  background: var(--color-bg-secondary, #e2e8f0);
  border-radius: 4px;
}

.skeleton-line.name {
  width: 60%;
  height: 12px;
}

.skeleton-line.role {
  width: 35%;
  height: 10px;
}
</style>
