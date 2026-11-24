<script setup lang="ts">
import { ref, computed } from 'vue'
import type { Conversation } from '@/types/chat'
import { echoConnectionStatus } from '@/services/echoService'

const props = defineProps<{
  conversations: Conversation[]
  activeConversationId: number | null
  isLoading: boolean
  error: string | null
}>()

const emit = defineEmits<{
  (e: 'select', conversationId: number): void
  (e: 'retry'): void
}>()

const searchQuery = ref('')

const filteredConversations = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  const validList = props.conversations.filter(
    (conv) => conv.id !== 0 && (conv.last_message !== null || conv.last_message_at !== null)
  )
  if (!query) {
    return validList
  }
  return validList.filter((conv) => {
    const recipientName = conv.recipient?.name?.toLowerCase() || ''
    const lastMsgBody = conv.last_message?.body?.toLowerCase() || ''
    return recipientName.includes(query) || lastMsgBody.includes(query)
  })
})

function formatTime(isoString: string | null): string {
  if (!isoString) return ''
  try {
    const date = new Date(isoString)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffMins = Math.floor(diffMs / (1000 * 60))
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60))
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

    if (diffMins < 1) return 'Vừa xong'
    if (diffMins < 60) return `${diffMins}p trước`
    if (diffHours < 24) {
      return date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
    }
    if (diffDays === 1) return 'Hôm qua'
    if (diffDays < 7) return `${diffDays} ngày trước`

    return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' })
  } catch {
    return ''
  }
}

function getRecipientInitials(conv: Conversation): string {
  const name = conv.recipient?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'U'
}

const connectionStatus = computed(() => echoConnectionStatus.value)
</script>

<template>
  <aside class="conversation-sidebar" aria-label="Danh sách cuộc trò chuyện">
    <!-- Header with Search & Realtime Status -->
    <div class="sidebar-header">
      <div class="sidebar-title-row">
        <h2 class="sidebar-title">Tin nhắn</h2>
        <span
          class="connection-pill"
          :class="'status-' + connectionStatus"
          :title="'Trạng thái realtime: ' + connectionStatus"
        >
          <span class="status-dot"></span>
          <span class="status-text">{{ connectionStatus === 'connected' ? 'Trực tiếp' : 'Đang kết nối' }}</span>
        </span>
      </div>

      <div class="search-box">
        <span class="search-icon" aria-hidden="true">🔍</span>
        <input
          v-model="searchQuery"
          type="search"
          placeholder="Tìm kiếm người dùng hoặc tin nhắn..."
          class="search-input"
          aria-label="Tìm kiếm cuộc trò chuyện"
        />
        <button
          v-if="searchQuery"
          type="button"
          class="search-clear"
          aria-label="Xóa tìm kiếm"
          @click="searchQuery = ''"
        >
          ✕
        </button>
      </div>
    </div>

    <!-- Error State -->
    <div v-if="error" class="sidebar-error" role="alert">
      <span class="error-text">{{ error }}</span>
      <button type="button" class="btn-retry" @click="emit('retry')">
        Thử lại
      </button>
    </div>

    <!-- Loading Skeleton -->
    <div v-if="isLoading && conversations.length === 0" class="sidebar-skeleton-list" aria-busy="true">
      <div v-for="i in 5" :key="i" class="skeleton-item">
        <div class="skeleton-avatar"></div>
        <div class="skeleton-lines">
          <div class="skeleton-line title"></div>
          <div class="skeleton-line snippet"></div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredConversations.length === 0" class="sidebar-empty">
      <div class="empty-icon" aria-hidden="true">💬</div>
      <p class="empty-text">
        {{ searchQuery ? 'Không tìm thấy cuộc trò chuyện phù hợp.' : 'Chưa có cuộc trò chuyện nào.' }}
      </p>
    </div>

    <!-- Conversations List -->
    <ul v-else class="conversations-list" role="list">
      <li
        v-for="conv in filteredConversations"
        :key="conv.id"
        class="conversation-item"
        :class="{ active: conv.id === activeConversationId }"
        role="button"
        tabindex="0"
        :aria-selected="conv.id === activeConversationId"
        @click="emit('select', conv.id)"
        @keydown.enter="emit('select', conv.id)"
        @keydown.space.prevent="emit('select', conv.id)"
      >
        <div class="avatar-wrapper">
          <img
            v-if="conv.recipient?.avatar_path"
            :src="conv.recipient.avatar_path"
            :alt="conv.recipient.name"
            class="recipient-avatar-img"
          />
          <div v-else class="recipient-avatar-initial">
            {{ getRecipientInitials(conv) }}
          </div>
        </div>

        <div class="conversation-info">
          <div class="conversation-row-top">
            <span class="recipient-name" :title="conv.recipient?.name || 'Người dùng'">
              {{ conv.recipient?.name || 'Người dùng' }}
            </span>
            <span class="timestamp">{{ formatTime(conv.last_message_at) }}</span>
          </div>

          <div class="conversation-row-bottom">
            <span class="last-message-snippet">
              {{ conv.last_message ? conv.last_message.body : 'Bắt đầu cuộc trò chuyện...' }}
            </span>
            <span
              v-if="conv.unread_count > 0"
              class="unread-badge"
              :aria-label="conv.unread_count + ' tin nhắn chưa đọc'"
            >
              {{ conv.unread_count > 99 ? '99+' : conv.unread_count }}
            </span>
          </div>
        </div>
      </li>
    </ul>
  </aside>
</template>

<style scoped>
.conversation-sidebar {
  display: flex;
  flex-direction: column;
  width: 320px;
  height: 100%;
  background-color: var(--color-bg-surface);
  border-right: 1px solid var(--color-border);
  flex-shrink: 0;
  overflow: hidden;
}

.sidebar-header {
  padding: var(--spacing-sm) var(--spacing-md);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
  background-color: var(--color-bg-surface);
}

.sidebar-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.sidebar-title {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
}

.connection-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  padding: 2px 7px;
  border-radius: var(--radius-full);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-muted);
}

.status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background-color: var(--color-text-muted);
}

.connection-pill.status-connected {
  color: var(--color-success);
  background-color: var(--color-success-soft);
}

.connection-pill.status-connected .status-dot {
  background-color: var(--color-success);
}

.connection-pill.status-connecting,
.connection-pill.status-reconnecting {
  color: var(--color-warning);
  background-color: var(--color-warning-soft);
}

.connection-pill.status-connecting .status-dot,
.connection-pill.status-reconnecting .status-dot {
  background-color: var(--color-warning);
  animation: pulse 1.2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(0.85); }
}

.search-box {
  position: relative;
  display: flex;
  align-items: center;
}

.search-icon {
  position: absolute;
  left: 9px;
  font-size: 13px;
  color: var(--color-text-muted);
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 6px 28px 6px 30px;
  font-size: var(--font-size-xs);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  outline: none;
  transition: border-color var(--transition-fast);
}

.search-input:focus {
  border-color: var(--color-primary);
}

.search-clear {
  position: absolute;
  right: 7px;
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 12px;
  cursor: pointer;
  padding: 2px;
}

.sidebar-error {
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: var(--font-size-xs);
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-danger);
}

.btn-retry {
  background: var(--color-danger);
  color: var(--color-text-inverse);
  border: none;
  border-radius: var(--radius-sm);
  padding: 2px 8px;
  font-size: 11px;
  cursor: pointer;
}

.sidebar-skeleton-list {
  padding: var(--spacing-xs);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.skeleton-item {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm);
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
  opacity: 0.5;
}

.skeleton-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background-color: var(--color-border);
  flex-shrink: 0;
}

.skeleton-lines {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  height: 10px;
  background-color: var(--color-border);
  border-radius: var(--radius-sm);
}

.skeleton-line.title {
  width: 60%;
}

.skeleton-line.snippet {
  width: 85%;
}

.sidebar-empty {
  padding: var(--spacing-xl) var(--spacing-md);
  text-align: center;
  color: var(--color-text-muted);
}

.empty-icon {
  font-size: 32px;
  margin-bottom: var(--spacing-xs);
}

.empty-text {
  font-size: var(--font-size-xs);
  margin: 0;
}

.conversations-list {
  list-style: none;
  margin: 0;
  padding: 4px;
  overflow-y: auto;
  flex: 1;
}

.conversation-item {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast);
  border-bottom: 1px solid rgba(255, 255, 255, 0.03);
}

.conversation-item:hover {
  background-color: var(--color-bg-elevated);
}

.conversation-item.active {
  background-color: var(--color-primary-soft);
  border-color: rgba(56, 189, 248, 0.3);
}

.avatar-wrapper {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.recipient-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.recipient-avatar-initial {
  color: var(--color-primary);
  font-weight: var(--font-weight-bold);
  font-size: var(--font-size-sm);
}

.conversation-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.conversation-row-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.recipient-name {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.timestamp {
  font-size: 10px;
  color: var(--color-text-muted);
  flex-shrink: 0;
}

.conversation-row-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-xs);
}

.last-message-snippet {
  font-size: 11px;
  color: var(--color-text-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
}

.conversation-item.active .last-message-snippet {
  color: var(--color-text-primary);
}

.unread-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: var(--radius-full);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  flex-shrink: 0;
}
</style>
