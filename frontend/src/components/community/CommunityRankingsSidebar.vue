<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import type { RankingPostItem } from '@/types/community'
import { CommunityService } from '@/services/communityService'

const router = useRouter()

const topLikedPosts = ref<RankingPostItem[]>([])
const topCommentedPosts = ref<RankingPostItem[]>([])
const isLoadingLiked = ref(true)
const isLoadingCommented = ref(true)
const errorLiked = ref<string | null>(null)
const errorCommented = ref<string | null>(null)

async function fetchTopLiked() {
  isLoadingLiked.value = true
  errorLiked.value = null
  try {
    const res = await CommunityService.getTopLikedPosts()
    topLikedPosts.value = res.data || []
  } catch {
    errorLiked.value = 'Không thể tải bảng xếp hạng yêu thích.'
  } finally {
    isLoadingLiked.value = false
  }
}

async function fetchTopCommented() {
  isLoadingCommented.value = true
  errorCommented.value = null
  try {
    const res = await CommunityService.getTopCommentedPosts()
    topCommentedPosts.value = res.data || []
  } catch {
    errorCommented.value = 'Không thể tải bảng xếp hạng thảo luận.'
  } finally {
    isLoadingCommented.value = false
  }
}

function navigateToPost(postId: number) {
  router.push(`/posts/${postId}`)
}

function formatRelativeTime(isoString: string | null): string {
  if (!isoString) return ''
  try {
    const date = new Date(isoString)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffMins = Math.floor(diffMs / (1000 * 60))
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60))
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

    if (diffMins < 1) return 'Vừa xong'
    if (diffMins < 60) return `${diffMins} phút trước`
    if (diffHours < 24) return `${diffHours} giờ trước`
    if (diffDays === 1) return 'Hôm qua'
    if (diffDays < 7) return `${diffDays} ngày trước`

    return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' })
  } catch {
    return ''
  }
}

onMounted(() => {
  fetchTopLiked()
  fetchTopCommented()
})
</script>

<template>
  <div class="community-rankings-sidebar" aria-label="Bảng xếp hạng bài viết cộng đồng">
    <!-- Section A: Top 5 Liked Posts in Last 7 Days -->
    <section class="ranking-card" aria-labelledby="top-liked-heading">
      <div class="ranking-header">
        <div class="ranking-title-group">
          <span class="ranking-icon" aria-hidden="true">🔥</span>
          <h2 id="top-liked-heading" class="ranking-title">Top Yêu Thích Tuần</h2>
        </div>
        <span class="ranking-badge-sub">7 ngày qua</span>
      </div>

      <!-- Loading State -->
      <div v-if="isLoadingLiked" class="ranking-skeleton-list" aria-busy="true">
        <div v-for="i in 3" :key="i" class="ranking-skeleton-item">
          <div class="skeleton-rank"></div>
          <div class="skeleton-content">
            <div class="skeleton-line title"></div>
            <div class="skeleton-line sub"></div>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="errorLiked" class="ranking-error">
        <span>{{ errorLiked }}</span>
        <button type="button" class="btn-retry" @click="fetchTopLiked">Thử lại</button>
      </div>

      <!-- Empty State -->
      <div v-else-if="topLikedPosts.length === 0" class="ranking-empty">
        <p class="empty-text">Chưa có bài viết nào được yêu thích trong tuần qua.</p>
      </div>

      <!-- List -->
      <ol v-else class="ranking-list" role="list">
        <li
          v-for="post in topLikedPosts"
          :key="'liked-' + post.id"
          class="ranking-item"
          role="button"
          tabindex="0"
          @click="navigateToPost(post.id)"
          @keydown.enter="navigateToPost(post.id)"
          @keydown.space.prevent="navigateToPost(post.id)"
        >
          <div class="rank-number-box" :class="'rank-' + post.rank">
            {{ post.rank }}
          </div>

          <div class="rank-item-body">
            <div class="author-row">
              <img
                v-if="post.user?.avatar_url || post.user?.avatar_path"
                :src="post.user.avatar_url || post.user.avatar_path || ''"
                :alt="post.user.name"
                class="author-avatar"
              />
              <div v-else class="author-avatar-fallback">
                {{ post.user?.name?.charAt(0)?.toUpperCase() || 'U' }}
              </div>
              <span class="author-name" :title="post.user?.name">{{ post.user?.name }}</span>
              <span class="post-time">{{ formatRelativeTime(post.created_at) }}</span>
            </div>

            <p class="post-snippet">{{ post.snippet || post.content }}</p>

            <div class="interaction-meta">
              <span class="interaction-pill liked">
                <span class="pill-icon" aria-hidden="true">❤️</span>
                <span class="pill-count">{{ post.weekly_count }} lượt thích tuần này</span>
              </span>
            </div>
          </div>
        </li>
      </ol>
    </section>

    <!-- Section B: Top 5 Most Commented Posts in Last 7 Days -->
    <section class="ranking-card" aria-labelledby="top-commented-heading">
      <div class="ranking-header">
        <div class="ranking-title-group">
          <span class="ranking-icon" aria-hidden="true">💬</span>
          <h2 id="top-commented-heading" class="ranking-title">Top Bình Luận Tuần</h2>
        </div>
        <span class="ranking-badge-sub">7 ngày qua</span>
      </div>

      <!-- Loading State -->
      <div v-if="isLoadingCommented" class="ranking-skeleton-list" aria-busy="true">
        <div v-for="i in 3" :key="i" class="ranking-skeleton-item">
          <div class="skeleton-rank"></div>
          <div class="skeleton-content">
            <div class="skeleton-line title"></div>
            <div class="skeleton-line sub"></div>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="errorCommented" class="ranking-error">
        <span>{{ errorCommented }}</span>
        <button type="button" class="btn-retry" @click="fetchTopCommented">Thử lại</button>
      </div>

      <!-- Empty State -->
      <div v-else-if="topCommentedPosts.length === 0" class="ranking-empty">
        <p class="empty-text">Chưa có bài viết nào có bình luận trong tuần qua.</p>
      </div>

      <!-- List -->
      <ol v-else class="ranking-list" role="list">
        <li
          v-for="post in topCommentedPosts"
          :key="'commented-' + post.id"
          class="ranking-item"
          role="button"
          tabindex="0"
          @click="navigateToPost(post.id)"
          @keydown.enter="navigateToPost(post.id)"
          @keydown.space.prevent="navigateToPost(post.id)"
        >
          <div class="rank-number-box" :class="'rank-' + post.rank">
            {{ post.rank }}
          </div>

          <div class="rank-item-body">
            <div class="author-row">
              <img
                v-if="post.user?.avatar_url || post.user?.avatar_path"
                :src="post.user.avatar_url || post.user.avatar_path || ''"
                :alt="post.user.name"
                class="author-avatar"
              />
              <div v-else class="author-avatar-fallback">
                {{ post.user?.name?.charAt(0)?.toUpperCase() || 'U' }}
              </div>
              <span class="author-name" :title="post.user?.name">{{ post.user?.name }}</span>
              <span class="post-time">{{ formatRelativeTime(post.created_at) }}</span>
            </div>

            <p class="post-snippet">{{ post.snippet || post.content }}</p>

            <div class="interaction-meta">
              <span class="interaction-pill commented">
                <span class="pill-icon" aria-hidden="true">💬</span>
                <span class="pill-count">{{ post.weekly_count }} bình luận tuần này</span>
              </span>
            </div>
          </div>
        </li>
      </ol>
    </section>
  </div>
</template>

<style scoped>
.community-rankings-sidebar {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg, 24px);
  width: 100%;
}

.ranking-card {
  background: var(--color-bg-surface, #ffffff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--radius-lg, 12px);
  padding: var(--spacing-md, 16px);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ranking-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--spacing-md, 16px);
  padding-bottom: var(--spacing-xs, 8px);
  border-bottom: 1px solid var(--color-border-subtle, #f1f5f9);
}

.ranking-title-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs, 8px);
}

.ranking-icon {
  font-size: 1.25rem;
}

.ranking-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--color-text-primary, #0f172a);
  margin: 0;
}

.ranking-badge-sub {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted, #64748b);
  background: var(--color-bg-secondary, #f8fafc);
  padding: 2px 8px;
  border-radius: 9999px;
  border: 1px solid var(--color-border-subtle, #e2e8f0);
}

.ranking-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm, 12px);
}

.ranking-item {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-sm, 12px);
  padding: var(--spacing-xs, 8px);
  border-radius: var(--radius-md, 8px);
  cursor: pointer;
  transition: background-color 0.15s ease, transform 0.1s ease;
}

.ranking-item:hover {
  background: var(--color-bg-hover, #f8fafc);
  transform: translateY(-1px);
}

.rank-number-box {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
  font-weight: 700;
  flex-shrink: 0;
  background: var(--color-bg-secondary, #e2e8f0);
  color: var(--color-text-secondary, #475569);
}

.rank-number-box.rank-1 {
  background: linear-gradient(135deg, #fbbf24, #d97706);
  color: #ffffff;
}

.rank-number-box.rank-2 {
  background: linear-gradient(135deg, #94a3b8, #64748b);
  color: #ffffff;
}

.rank-number-box.rank-3 {
  background: linear-gradient(135deg, #d97706, #b45309);
  color: #ffffff;
}

.rank-item-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
  min-width: 0;
}

.author-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.author-avatar {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  object-fit: cover;
}

.author-avatar-fallback {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--color-primary-light, #e0e7ff);
  color: var(--color-primary, #4338ca);
  font-size: 0.65rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.author-name {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-primary, #1e293b);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 110px;
}

.post-time {
  font-size: 0.72rem;
  color: var(--color-text-muted, #94a3b8);
  margin-left: auto;
  white-space: nowrap;
}

.post-snippet {
  font-size: 0.83rem;
  color: var(--color-text-secondary, #334155);
  line-height: 1.35;
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}

.interaction-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}

.interaction-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 4px;
}

.interaction-pill.liked {
  color: #e11d48;
  background: #ffe4e6;
}

.interaction-pill.commented {
  color: #0284c7;
  background: #e0f2fe;
}

.pill-icon {
  font-size: 0.75rem;
}

.ranking-empty {
  padding: var(--spacing-sm, 12px) 0;
  text-align: center;
  color: var(--color-text-muted, #64748b);
  font-size: 0.85rem;
}

.ranking-error {
  padding: var(--spacing-sm, 12px) 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: var(--color-danger, #ef4444);
  font-size: 0.85rem;
}

.btn-retry {
  padding: 4px 12px;
  font-size: 0.8rem;
  background: transparent;
  border: 1px solid var(--color-border, #cbd5e1);
  border-radius: 4px;
  cursor: pointer;
}

.btn-retry:hover {
  background: var(--color-bg-secondary, #f1f5f9);
}

.ranking-skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ranking-skeleton-item {
  display: flex;
  gap: 12px;
  align-items: center;
}

.skeleton-rank {
  width: 24px;
  height: 24px;
  border-radius: 6px;
  background: var(--color-bg-secondary, #e2e8f0);
}

.skeleton-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  background: var(--color-bg-secondary, #e2e8f0);
  border-radius: 4px;
}

.skeleton-line.title {
  width: 70%;
  height: 12px;
}

.skeleton-line.sub {
  width: 45%;
  height: 10px;
}
</style>
