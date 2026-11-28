<script setup lang="ts">
import { ref, watch, nextTick, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { Conversation, Message } from '@/types/chat'

const props = defineProps<{
  conversation: Conversation | null
  messages: Message[]
  currentUserId?: number
  isLoading: boolean
  hasMore: boolean
}>()

const emit = defineEmits<{
  (e: 'loadOlder'): void
  (e: 'back'): void
}>()

const messagesContainer = ref<HTMLElement | null>(null)
const isLoadingOlder = ref(false)

function scrollToBottom(smooth = false) {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTo({
        top: messagesContainer.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
      })
    }
  })
}

// Watch messages length to scroll down when new message is added at bottom
watch(
  () => props.messages.length,
  (newLen, oldLen) => {
    if (newLen > oldLen && !isLoadingOlder.value) {
      scrollToBottom(true)
    }
  }
)

// Watch conversation change to reset scroll position to bottom
watch(
  () => props.conversation?.id,
  () => {
    scrollToBottom(false)
  }
)

onMounted(() => {
  scrollToBottom(false)
})

async function handleLoadOlder() {
  if (isLoadingOlder.value || !props.hasMore) return
  isLoadingOlder.value = true

  const container = messagesContainer.value
  const prevScrollHeight = container?.scrollHeight || 0

  emit('loadOlder')

  // Maintain scroll position after prepending older messages
  await nextTick()
  if (container) {
    const newScrollHeight = container.scrollHeight
    container.scrollTop = newScrollHeight - prevScrollHeight
  }
  isLoadingOlder.value = false
}

function formatMessageTime(isoString: string | null): string {
  if (!isoString) return ''
  try {
    const date = new Date(isoString)
    return date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
  } catch {
    return ''
  }
}

function getRecipientInitials(): string {
  const name = props.conversation?.recipient?.name?.trim()
  return name ? name.charAt(0).toUpperCase() : 'U'
}

const otherParticipantLastReadId = computed(() => {
  if (!props.conversation || !props.currentUserId || !props.conversation.participants) return 0
  const other = props.conversation.participants.find(
    (p) => Number(p.user_id) !== Number(props.currentUserId)
  )
  return other?.last_read_message_id ? Number(other.last_read_message_id) : 0
})

const lightboxImageUrl = ref<string | null>(null)

function openImageLightbox(url: string) {
  lightboxImageUrl.value = url
}

function closeImageLightbox() {
  lightboxImageUrl.value = null
}
</script>

<template>
  <div class="message-pane">
    <!-- Active Conversation Header -->
    <header v-if="conversation" class="conversation-header">
      <button
        type="button"
        class="btn-back-mobile"
        aria-label="Quay lại danh sách hội thoại"
        @click="emit('back')"
      >
        ←
      </button>

      <div class="header-avatar">
        <img
          v-if="conversation.recipient?.avatar_path"
          :src="conversation.recipient.avatar_path"
          :alt="conversation.recipient.name"
          class="header-avatar-img"
        />
        <div v-else class="header-avatar-initial">
          {{ getRecipientInitials() }}
        </div>
      </div>

      <div class="header-user-info">
        <div class="header-name-row">
          <span class="header-name">{{ conversation.recipient?.name || 'Người dùng' }}</span>
          <span
            v-if="conversation.recipient?.role"
            class="header-role-badge"
            :class="'role-' + conversation.recipient.role"
          >
            {{
              conversation.recipient.role === 'admin'
                ? 'Admin'
                : conversation.recipient.role === 'creator'
                ? 'Tác giả'
                : 'Độc giả'
            }}
          </span>
        </div>
        <span class="header-status-hint">Trực tuyến</span>
      </div>
    </header>

    <!-- Empty State (No Conversation Selected) -->
    <div v-if="!conversation" class="no-conversation-selected">
      <div class="empty-graphic" aria-hidden="true">💬</div>
      <h3 class="empty-title">Chọn một cuộc trò chuyện</h3>
      <p class="empty-desc">
        Chọn người dùng từ danh sách bên trái để bắt đầu trò chuyện trực tiếp.
      </p>
    </div>

    <!-- Messages Container -->
    <div v-else ref="messagesContainer" class="messages-container">
      <!-- Load More / Older Messages Button -->
      <div v-if="hasMore" class="load-more-container">
        <button
          type="button"
          class="btn-load-older"
          :disabled="isLoadingOlder"
          @click="handleLoadOlder"
        >
          {{ isLoadingOlder ? 'Đang tải tin nhắn cũ...' : '↑ Tải tin nhắn cũ hơn' }}
        </button>
      </div>

      <!-- Loading Spinner for Messages -->
      <div v-if="isLoading && messages.length === 0" class="messages-loading" aria-busy="true">
        <div class="loading-spinner"></div>
        <span>Đang tải tin nhắn...</span>
      </div>

      <!-- Empty Messages State -->
      <div v-else-if="messages.length === 0" class="messages-empty">
        <div class="empty-bubble-icon" aria-hidden="true">👋</div>
        <p class="empty-bubble-text">Chưa có tin nhắn nào. Hãy gửi lời chào đầu tiên!</p>
      </div>

      <!-- Message Bubbles -->
      <div
        v-for="msg in messages"
        :key="msg.id"
        class="message-row"
        :class="{ 'is-me': Number(msg.sender_id) === Number(currentUserId) }"
      >
        <div
          class="message-bubble"
          :class="{
            'is-me': Number(msg.sender_id) === Number(currentUserId),
            'is-sending': msg.status === 'sending',
            'is-failed': msg.status === 'failed',
          }"
        >
          <!-- Attached Images Grid -->
          <div
            v-if="msg.attachments?.images && msg.attachments.images.length > 0"
            class="msg-attachments-images"
            :class="'count-' + Math.min(msg.attachments.images.length, 5)"
          >
            <div
              v-for="(img, idx) in msg.attachments.images"
              :key="idx"
              class="attachment-img-item"
              @click="openImageLightbox(img.url)"
            >
              <img :src="img.url" :alt="img.name || 'Ảnh đính kèm'" loading="lazy" />
            </div>
          </div>

          <!-- Attached Comic Card -->
          <div v-if="msg.attachments?.comic" class="msg-attached-comic-wrapper">
            <div v-if="msg.attachments.comic.is_unavailable" class="msg-attached-comic unavailable">
              <span class="comic-icon" aria-hidden="true">📖</span>
              <div class="comic-info">
                <span class="comic-title">Truyện không khả dụng hoặc đã bị gỡ</span>
              </div>
            </div>
            <RouterLink
              v-else
              :to="'/comics/' + msg.attachments.comic.slug"
              class="msg-attached-comic"
              :title="'Xem truyện ' + msg.attachments.comic.title"
            >
              <div class="comic-thumb">
                <img
                  v-if="msg.attachments.comic.cover_url"
                  :src="msg.attachments.comic.cover_url"
                  :alt="msg.attachments.comic.title"
                />
                <span v-else>📖</span>
              </div>
              <div class="comic-info">
                <span class="comic-badge">Truyện đính kèm</span>
                <h4 class="comic-title">{{ msg.attachments.comic.title }}</h4>
                <span class="comic-author">{{ msg.attachments.comic.author_name || 'Tác giả' }}</span>
              </div>
              <span class="comic-arrow" aria-hidden="true">›</span>
            </RouterLink>
          </div>

          <!-- Attached Post Card -->
          <div v-if="msg.attachments?.post" class="msg-attached-post-wrapper">
            <div v-if="msg.attachments.post.is_unavailable" class="msg-attached-post unavailable">
              <span class="post-icon" aria-hidden="true">💬</span>
              <div class="post-info">
                <span class="post-title">Bài viết không khả dụng hoặc đã bị gỡ</span>
              </div>
            </div>
            <RouterLink
              v-else
              :to="'/posts/' + msg.attachments.post.id"
              class="msg-attached-post"
              title="Xem bài viết cộng đồng"
            >
              <div class="post-badge-row">
                <span class="post-badge">Bài viết cộng đồng</span>
              </div>
              <div class="post-author-row">
                <strong class="post-author-name">{{ msg.attachments.post.user?.name || 'Thành viên' }}</strong>
              </div>
              <p class="post-content-snippet">{{ msg.attachments.post.content }}</p>
            </RouterLink>
          </div>

          <!-- Text Body -->
          <div v-if="msg.body" class="message-body">{{ msg.body }}</div>
          <div class="message-meta">
            <span v-if="msg.status === 'sending'" class="message-status-sending" title="Đang gửi...">
              ⏱ Đang gửi
            </span>
            <span v-else-if="msg.status === 'failed'" class="message-status-failed" title="Gửi thất bại">
              ⚠️ Lỗi gửi
            </span>
            <span v-else class="message-time">{{ formatMessageTime(msg.created_at) }}</span>
            <span
              v-if="(!msg.status || msg.status === 'sent') && Number(msg.sender_id) === Number(currentUserId) && Number(otherParticipantLastReadId) >= Number(msg.id)"
              class="message-read-receipt"
              title="Đã xem"
            >
              ✓✓
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Image Lightbox Modal -->
    <div
      v-if="lightboxImageUrl"
      class="lightbox-backdrop"
      @click="closeImageLightbox"
    >
      <div class="lightbox-dialog" @click.stop>
        <button
          type="button"
          class="lightbox-close"
          aria-label="Đóng ảnh"
          @click="closeImageLightbox"
        >
          ✕
        </button>
        <img :src="lightboxImageUrl" alt="Ảnh phóng to" class="lightbox-full-img" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.message-pane {
  display: flex;
  flex-direction: column;
  flex: 1;
  height: 100%;
  background-color: var(--color-bg);
  overflow: hidden;
  position: relative;
}

.conversation-header {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) var(--spacing-md);
  background-color: var(--color-bg-surface);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
  height: 60px;
}

.btn-back-mobile {
  display: none;
  background: transparent;
  border: none;
  color: var(--color-text-primary);
  font-size: 20px;
  cursor: pointer;
  padding: 4px;
}

.header-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.header-avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.header-avatar-initial {
  color: var(--color-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
}

.header-user-info {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.header-name-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.header-name {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
}

.header-role-badge {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: var(--radius-sm);
  font-weight: var(--font-weight-medium);
}

.header-role-badge.role-admin {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
}

.header-role-badge.role-creator {
  background-color: var(--color-info-soft);
  color: var(--color-info);
}

.header-role-badge.role-reader {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
}

.header-status-hint {
  font-size: 10px;
  color: var(--color-success);
}

.no-conversation-selected {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-xl);
  color: var(--color-text-muted);
  text-align: center;
}

.empty-graphic {
  font-size: 48px;
  margin-bottom: var(--spacing-sm);
  opacity: 0.8;
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin-bottom: var(--spacing-xs);
}

.empty-desc {
  font-size: var(--font-size-sm);
  max-width: 300px;
}

.messages-container {
  flex: 1;
  overflow-y: auto;
  padding: var(--spacing-md);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.load-more-container {
  display: flex;
  justify-content: center;
  margin-bottom: var(--spacing-xs);
}

.btn-load-older {
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-primary);
  padding: 4px 12px;
  border-radius: var(--radius-full);
  font-size: 11px;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-load-older:hover:not(:disabled) {
  background-color: var(--color-primary-soft);
}

.messages-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-xl);
  color: var(--color-text-muted);
  font-size: var(--font-size-xs);
}

.loading-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.messages-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-xl);
  color: var(--color-text-muted);
  text-align: center;
}

.empty-bubble-icon {
  font-size: 32px;
  margin-bottom: var(--spacing-xs);
}

.empty-bubble-text {
  font-size: var(--font-size-xs);
  margin: 0;
}

.message-row {
  display: flex;
  width: 100%;
}

.message-row.is-me {
  justify-content: flex-end;
}

.message-bubble {
  max-width: 70%;
  padding: var(--spacing-xs) var(--spacing-sm);
  border-radius: var(--radius-md);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
  display: flex;
  flex-direction: column;
  gap: 2px;
  box-shadow: var(--shadow-sm);
  word-break: break-word;
}

.message-bubble.is-me {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border-bottom-right-radius: 2px;
}

.message-bubble.is-sending {
  opacity: 0.75;
}

.message-bubble.is-failed {
  border: 1px solid var(--color-danger);
  background-color: var(--color-danger-soft);
  color: var(--color-danger);
}

.message-bubble:not(.is-me) {
  border-bottom-left-radius: 2px;
}

.message-body {
  font-size: var(--font-size-sm);
  line-height: var(--line-height-normal);
  white-space: pre-wrap;
}

.message-meta {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
}

.message-time {
  font-size: 9px;
  opacity: 0.75;
}

.message-status-sending {
  font-size: 9px;
  opacity: 0.8;
  letter-spacing: 0.2px;
}

.message-status-failed {
  font-size: 9px;
  color: var(--color-danger);
  font-weight: var(--font-weight-bold);
}

.message-read-receipt {
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  color: var(--color-text-inverse);
  opacity: 0.9;
}

@media (max-width: 768px) {
  .btn-back-mobile {
    display: inline-flex;
  }
}

/* Attachments in Message Bubble */
.msg-attachments-images {
  display: grid;
  gap: 4px;
  margin-bottom: 6px;
  border-radius: 8px;
  overflow: hidden;
  max-width: 300px;
}

.msg-attachments-images.count-1 {
  grid-template-columns: 1fr;
}

.msg-attachments-images.count-2 {
  grid-template-columns: 1fr 1fr;
}

.msg-attachments-images.count-3 {
  grid-template-columns: 1fr 1fr 1fr;
}

.msg-attachments-images.count-4 {
  grid-template-columns: 1fr 1fr;
}

.msg-attachments-images.count-5 {
  grid-template-columns: 1fr 1fr 1fr;
}

.attachment-img-item {
  position: relative;
  aspect-ratio: 1;
  overflow: hidden;
  cursor: pointer;
  background-color: rgba(0, 0, 0, 0.2);
  border-radius: 4px;
}

.attachment-img-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.15s ease;
}

.attachment-img-item:hover img {
  transform: scale(1.05);
}

.msg-attached-comic-wrapper,
.msg-attached-post-wrapper {
  margin-bottom: 6px;
}

.msg-attached-comic {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background-color: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  text-decoration: none;
  color: inherit;
  transition: all 0.15s ease;
  max-width: 280px;
}

.msg-attached-comic:hover {
  background-color: rgba(0, 0, 0, 0.35);
  border-color: var(--color-primary);
}

.msg-attached-comic.unavailable {
  opacity: 0.7;
}

.comic-thumb {
  width: 32px;
  height: 44px;
  border-radius: 4px;
  overflow: hidden;
  background-color: #0b0f19;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.comic-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.comic-info {
  flex: 1;
  min-width: 0;
}

.comic-badge {
  display: inline-block;
  font-size: 9px;
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.2;
}

.comic-title {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comic-author {
  font-size: 10px;
  opacity: 0.75;
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comic-arrow {
  font-size: 16px;
  color: var(--color-primary);
  flex-shrink: 0;
}

.msg-attached-post {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 6px 10px;
  background-color: rgba(0, 0, 0, 0.25);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  text-decoration: none;
  color: inherit;
  transition: all 0.15s ease;
  max-width: 280px;
}

.msg-attached-post:hover {
  background-color: rgba(0, 0, 0, 0.35);
  border-color: var(--color-primary);
}

.post-badge {
  font-size: 9px;
  font-weight: 700;
  color: var(--color-primary);
}

.post-author-name {
  font-size: 11px;
}

.post-content-snippet {
  margin: 0;
  font-size: 11px;
  opacity: 0.85;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Lightbox Modal */
.lightbox-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 99999;
  padding: var(--spacing-md);
  backdrop-filter: blur(4px);
}

.lightbox-dialog {
  position: relative;
  max-width: 90vw;
  max-height: 90vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.lightbox-full-img {
  max-width: 100%;
  max-height: 90vh;
  object-fit: contain;
  border-radius: 8px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
}

.lightbox-close {
  position: absolute;
  top: -36px;
  right: 0;
  background: rgba(0, 0, 0, 0.6);
  color: #ffffff;
  border: none;
  border-radius: 50%;
  width: 30px;
  height: 30px;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
