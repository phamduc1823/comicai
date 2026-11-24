<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notification'
import type { NotificationItem } from '@/types/notification'

const router = useRouter()
const notificationStore = useNotificationStore()

const isOpen = ref(false)
const containerRef = ref<HTMLElement | null>(null)
const brokenAvatars = ref<Record<string, boolean>>({})

function togglePanel() {
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    notificationStore.fetchNotifications(1)
  }
}

function closePanel() {
  isOpen.value = false
}

function handleDocumentClick(event: MouseEvent) {
  if (!isOpen.value || !containerRef.value) return
  if (!containerRef.value.contains(event.target as Node)) {
    closePanel()
  }
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && isOpen.value) {
    closePanel()
  }
}

onMounted(() => {
  document.addEventListener('click', handleDocumentClick)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('click', handleDocumentClick)
  document.removeEventListener('keydown', handleKeydown)
})

function getActorAvatar(item: NotificationItem): string | null {
  if (brokenAvatars.value[item.id]) return null
  return item.actor?.avatar_url || item.actor?.avatar_path || null
}

function getActorInitial(item: NotificationItem): string {
  const name = item.actor?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'U'
}

function handleAvatarError(itemId: string) {
  brokenAvatars.value[itemId] = true
}

function formatRelativeTime(isoString: string | null): string {
  if (!isoString) return ''
  try {
    const date = new Date(isoString)
    const diffMs = Date.now() - date.getTime()
    if (isNaN(diffMs)) return ''

    const diffMins = Math.floor(diffMs / 60_000)
    const diffHours = Math.floor(diffMs / 3_600_000)
    const diffDays = Math.floor(diffMs / 86_400_000)

    if (diffMins < 1) return 'Vừa xong'
    if (diffMins < 60) return `${diffMins} phút trước`
    if (diffHours < 24) return `${diffHours} giờ trước`
    if (diffDays === 1) return 'Hôm qua'
    if (diffDays < 7) return `${diffDays} ngày trước`

    return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
  } catch {
    return ''
  }
}

function resolveNotificationRoute(item: NotificationItem): string | { path: string; query?: Record<string, string> } | null {
  if (item.target?.type === 'conversation' && item.target.id) {
    // Strictly navigate using conversation_id so lazy conversation creation (CAI-MSG-01) is preserved
    return {
      path: '/chat',
      query: { conversation_id: String(item.target.id) },
    }
  }

  if (item.target?.type === 'post' && item.target.id) {
    return `/posts/${item.target.id}`
  }

  if (item.target?.type === 'comic' && item.target.id) {
    return `/comics/${item.target.id}`
  }

  if (item.target?.type === 'chapter') {
    return item.target.url || `/comics/${item.target.id}`
  }

  const rawUrl = item.target?.url
  if (typeof rawUrl === 'string' && rawUrl.startsWith('/') && !rawUrl.startsWith('//')) {
    return rawUrl
  }

  return null
}

async function handleNotificationClick(item: NotificationItem) {
  if (!item.is_read) {
    // Trigger mark-as-read with optimistic UI update
    notificationStore.markAsRead(item.id)
  }

  const destination = resolveNotificationRoute(item)
  closePanel()

  if (destination) {
    await router.push(destination)
  }
}

async function handleMarkAllRead() {
  await notificationStore.markAllAsRead()
}

function handleRetry() {
  notificationStore.fetchNotifications(1)
}

function handleLoadMore() {
  notificationStore.loadMore()
}
</script>

<template>
  <div ref="containerRef" class="notification-bell-wrapper">
    <button
      type="button"
      class="btn-notification-bell"
      :class="{ 'is-open': isOpen, 'has-unread': notificationStore.unreadCount > 0 }"
      :aria-expanded="isOpen"
      aria-haspopup="true"
      :aria-label="
        notificationStore.unreadCount > 0
          ? `Thông báo (${notificationStore.unreadCount} chưa đọc)`
          : 'Thông báo'
      "
      title="Thông báo"
      @click.stop="togglePanel"
    >
      <svg
        class="bell-icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
      </svg>

      <span
        v-if="notificationStore.unreadCount > 0"
        class="notification-badge"
        :aria-label="`${notificationStore.unreadCount} thông báo chưa đọc`"
      >
        {{ notificationStore.badgeLabel }}
      </span>
    </button>

    <!-- Notification Dropdown Panel -->
    <section
      v-if="isOpen"
      class="notification-panel"
      role="dialog"
      aria-label="Danh sách thông báo"
    >
      <header class="panel-header">
        <div class="panel-title-group">
          <h3 class="panel-title">Thông báo</h3>
          <span v-if="notificationStore.unreadCount > 0" class="panel-unread-pill">
            {{ notificationStore.badgeLabel }} mới
          </span>
        </div>

        <button
          v-if="notificationStore.unreadCount > 0"
          type="button"
          class="btn-mark-all"
          :disabled="notificationStore.isMarkingAll"
          @click="handleMarkAllRead"
        >
          {{ notificationStore.isMarkingAll ? 'Đang xử lý...' : 'Đánh dấu tất cả đã đọc' }}
        </button>
      </header>

      <!-- Inline error banner when list already exists but an action failed -->
      <div
        v-if="notificationStore.error && notificationStore.items.length > 0"
        class="panel-inline-error"
        role="alert"
      >
        <span>{{ notificationStore.error }}</span>
      </div>

      <div class="panel-body">
        <!-- Loading Skeleton State -->
        <div
          v-if="notificationStore.isLoading && notificationStore.items.length === 0"
          class="panel-loading"
          aria-busy="true"
        >
          <div v-for="i in 3" :key="i" class="skeleton-row">
            <div class="skeleton-avatar"></div>
            <div class="skeleton-lines">
              <div class="skeleton-line skeleton-line-title"></div>
              <div class="skeleton-line skeleton-line-body"></div>
            </div>
          </div>
        </div>

        <!-- Error State (no items loaded) -->
        <div
          v-else-if="notificationStore.error && notificationStore.items.length === 0"
          class="panel-state panel-error"
          role="alert"
        >
          <div class="state-icon" aria-hidden="true">⚠️</div>
          <p class="state-text">{{ notificationStore.error }}</p>
          <button type="button" class="btn-retry" @click="handleRetry">
            Thử lại
          </button>
        </div>

        <!-- Empty State -->
        <div
          v-else-if="notificationStore.items.length === 0"
          class="panel-state panel-empty"
        >
          <div class="state-icon" aria-hidden="true">🔔</div>
          <p class="state-title">Chưa có thông báo nào</p>
          <p class="state-subtitle">
            Khi có tin nhắn mới hoặc người khác thích bài viết của bạn, thông báo sẽ xuất hiện tại đây.
          </p>
        </div>

        <!-- Notification Items List -->
        <ul v-else class="notification-list" role="list">
          <li
            v-for="item in notificationStore.items"
            :key="item.id"
            class="notification-item"
            :class="{ 'is-unread': !item.is_read }"
          >
            <button
              type="button"
              class="notification-item-btn"
              @click="handleNotificationClick(item)"
            >
              <div class="item-avatar-wrap">
                <img
                  v-if="getActorAvatar(item)"
                  :src="getActorAvatar(item)!"
                  :alt="item.actor?.name || 'Người dùng'"
                  class="item-avatar-img"
                  @error="handleAvatarError(item.id)"
                />
                <span v-else class="item-avatar-initial">{{ getActorInitial(item) }}</span>
                <span
                  class="item-type-icon"
                  :class="item.type === 'post_liked' ? 'type-like' : 'type-message'"
                  aria-hidden="true"
                >
                  {{ item.type === 'post_liked' ? '❤️' : '💬' }}
                </span>
              </div>

              <div class="item-content">
                <p class="item-title">{{ item.title }}</p>
                <p v-if="item.body" class="item-body">{{ item.body }}</p>
                <span class="item-time">{{ formatRelativeTime(item.created_at) }}</span>
              </div>

              <span
                v-if="!item.is_read"
                class="unread-dot"
                title="Chưa đọc"
                aria-label="Chưa đọc"
              ></span>
            </button>
          </li>
        </ul>

        <!-- Load More Pagination -->
        <div v-if="notificationStore.hasMore" class="panel-footer">
          <button
            type="button"
            class="btn-load-more"
            :disabled="notificationStore.isLoadingMore"
            @click="handleLoadMore"
          >
            {{ notificationStore.isLoadingMore ? 'Đang tải thêm...' : 'Xem thêm thông báo' }}
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.notification-bell-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.btn-notification-bell {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-full);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-notification-bell:hover,
.btn-notification-bell.is-open {
  color: var(--color-primary);
  border-color: var(--color-primary);
  background-color: var(--color-primary-soft);
}

.bell-icon {
  width: 18px;
  height: 18px;
}

.notification-badge {
  position: absolute;
  top: -5px;
  right: -5px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: var(--radius-full);
  background-color: var(--color-danger, #ef4444);
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
  border: 2px solid var(--color-bg-surface);
  pointer-events: none;
}

.notification-panel {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  width: 380px;
  max-width: calc(100vw - 24px);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg, 12px);
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
  z-index: 100;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
}

.panel-title-group {
  display: flex;
  align-items: center;
  gap: 8px;
}

.panel-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: var(--color-text-primary);
}

.panel-unread-pill {
  padding: 2px 8px;
  border-radius: var(--radius-full);
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  font-size: 11px;
  font-weight: 600;
}

.btn-mark-all {
  background: transparent;
  border: none;
  color: var(--color-primary);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: var(--radius-sm);
  transition: background-color var(--transition-fast);
  white-space: nowrap;
}

.btn-mark-all:hover:not(:disabled) {
  background-color: var(--color-primary-soft);
}

.btn-mark-all:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.panel-inline-error {
  padding: 8px 14px;
  background-color: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: 12px;
  border-bottom: 1px solid var(--color-border);
}

.panel-body {
  max-height: 440px;
  overflow-y: auto;
}

.panel-loading {
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.skeleton-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.skeleton-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background-color: var(--color-bg-elevated);
  flex-shrink: 0;
}

.skeleton-lines {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 4px;
}

.skeleton-line {
  height: 12px;
  border-radius: 6px;
  background-color: var(--color-bg-elevated);
}

.skeleton-line-title {
  width: 75%;
}

.skeleton-line-body {
  width: 55%;
}

.panel-state {
  padding: 36px 20px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.state-icon {
  font-size: 32px;
  line-height: 1;
}

.state-title {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
}

.state-subtitle,
.state-text {
  margin: 0;
  font-size: 12px;
  color: var(--color-text-secondary);
  line-height: 1.5;
  max-width: 280px;
}

.btn-retry {
  margin-top: 6px;
  padding: 6px 14px;
  border-radius: var(--radius-md);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: 12px;
  font-weight: 600;
  border: none;
  cursor: pointer;
}

.notification-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.notification-item {
  border-bottom: 1px solid var(--color-border);
}

.notification-item:last-child {
  border-bottom: none;
}

.notification-item.is-unread {
  background-color: rgba(56, 189, 248, 0.07);
}

.notification-item-btn {
  width: 100%;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 16px;
  background: transparent;
  border: none;
  text-align: left;
  cursor: pointer;
  transition: background-color var(--transition-fast);
}

.notification-item-btn:hover {
  background-color: var(--color-bg-elevated);
}

.item-avatar-wrap {
  position: relative;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.item-avatar-img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.item-avatar-initial {
  color: var(--color-primary);
  font-size: 15px;
  font-weight: 700;
}

.item-type-icon {
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  line-height: 1;
}

.item-content {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.item-title {
  margin: 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-primary);
  line-height: 1.35;
}

.notification-item.is-unread .item-title {
  font-weight: 700;
}

.item-body {
  margin: 0;
  font-size: 12px;
  color: var(--color-text-secondary);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}

.item-time {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

.unread-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: var(--color-primary);
  flex-shrink: 0;
  margin-top: 6px;
}

.panel-footer {
  padding: 10px 16px;
  border-top: 1px solid var(--color-border);
  text-align: center;
}

.btn-load-more {
  width: 100%;
  padding: 8px 12px;
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-primary);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-load-more:hover:not(:disabled) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.btn-load-more:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 640px) {
  .notification-panel {
    position: fixed;
    top: 64px;
    left: 12px;
    right: 12px;
    width: auto;
    max-width: none;
  }

  .panel-body {
    max-height: 65vh;
  }
}
</style>
