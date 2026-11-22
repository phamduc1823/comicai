<script setup lang="ts">
import { ref, computed } from 'vue'
import type { CommentItemData } from '@/types/community'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import CommentItem from './CommentItem.vue'

export interface CommentItemProps {
  comment: CommentItemData
  currentUser?: {
    id: number
    name: string
    avatar_url?: string | null
    avatar_path?: string | null
    role?: string
  } | null
  isAuthenticated?: boolean
  isAdmin?: boolean
  isLiking?: boolean
  isDeleting?: boolean
  isReply?: boolean
  parentCommentId?: number | null
}

const props = withDefaults(defineProps<CommentItemProps>(), {
  currentUser: null,
  isAuthenticated: false,
  isAdmin: false,
  isLiking: false,
  isDeleting: false,
  isReply: false,
  parentCommentId: null,
})

const emit = defineEmits<{
  (e: 'like', commentId: number): void
  (e: 'reply', rootCommentId: number, authorName: string): void
  (e: 'delete', commentId: number): void
  (e: 'login'): void
}>()

const DEFAULT_VISIBLE_REPLIES = 3

// Internal UI states
const isConfirmDeleteOpen = ref(false)
const isExpanded = ref(false)
const avatarLoadError = ref(false)

// Author initials for fallback
const userInitial = computed(() => {
  return props.comment.user?.name?.trim().charAt(0).toUpperCase() || '?'
})

// Permissions check
const isAuthor = computed(() => {
  return !!(props.currentUser && props.currentUser.id === props.comment.user.id)
})

const canDelete = computed(() => {
  if (!props.isAuthenticated) return false
  if (props.isAdmin || props.currentUser?.role === 'admin') return true
  return isAuthor.value
})

// Relative time formatting
const formattedTime = computed(() => {
  if (!props.comment.created_at) return ''
  const date = new Date(props.comment.created_at)
  if (isNaN(date.getTime())) return ''
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return 'Vừa xong'
  const diffInMinutes = Math.floor(diffInSeconds / 60)
  if (diffInMinutes < 60) return `${diffInMinutes} phút trước`
  const diffInHours = Math.floor(diffInMinutes / 60)
  if (diffInHours < 24) return `${diffInHours} giờ trước`
  const diffInDays = Math.floor(diffInHours / 24)
  if (diffInDays < 30) return `${diffInDays} ngày trước`

  return date.toLocaleDateString('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
})

// Replies handling
const hasReplies = computed(() => {
  return Array.isArray(props.comment.replies) && props.comment.replies.length > 0
})

const repliesCount = computed(() => {
  return props.comment.replies?.length || 0
})

const visibleReplies = computed(() => {
  if (!props.comment.replies || !Array.isArray(props.comment.replies)) {
    return []
  }
  if (isExpanded.value || props.comment.replies.length <= DEFAULT_VISIBLE_REPLIES) {
    return props.comment.replies
  }
  return props.comment.replies.slice(0, DEFAULT_VISIBLE_REPLIES)
})

const hasMoreReplies = computed(() => {
  return repliesCount.value > DEFAULT_VISIBLE_REPLIES
})

const remainingRepliesCount = computed(() => {
  return Math.max(0, repliesCount.value - DEFAULT_VISIBLE_REPLIES)
})

// Action handlers
function handleLike() {
  if (!props.isAuthenticated) {
    emit('login')
    return
  }
  if (props.isLiking) return
  emit('like', props.comment.id)
}

function handleReply() {
  if (!props.isAuthenticated) {
    emit('login')
    return
  }
  // If this is a level-2 reply, target the root comment ID to strictly maintain max 2 levels
  const targetRootId = props.isReply
    ? (props.parentCommentId || props.comment.parent_id || props.comment.id)
    : props.comment.id

  emit('reply', targetRootId, props.comment.user.name)
}

function handleConfirmDelete() {
  emit('delete', props.comment.id)
  isConfirmDeleteOpen.value = false
}
</script>

<template>
  <div
    class="comment-item-card"
    :class="{
      'comment-item--reply': isReply,
      'comment-item--root': !isReply,
    }"
  >
    <!-- Main Comment Bubble -->
    <div class="comment-main-row">
      <!-- Author Avatar Column -->
      <div class="comment-avatar-col">
        <div class="comment-avatar" :title="comment.user.name" aria-hidden="true">
          <img
            v-if="comment.user.avatar_url && !avatarLoadError"
            :src="comment.user.avatar_url"
            :alt="comment.user.name"
            class="avatar-img"
            loading="lazy"
            @error="avatarLoadError = true"
          />
          <span v-else class="avatar-initial">{{ userInitial }}</span>
        </div>
      </div>

      <!-- Comment Content & Meta Column -->
      <div class="comment-body-col">
        <div class="comment-bubble">
          <!-- Author Info Row -->
          <div class="author-info-row">
            <span class="author-name">{{ comment.user.name }}</span>

            <!-- Role Badges -->
            <span
              v-if="comment.user.role === 'admin'"
              class="role-badge role-badge--admin"
              title="Quản trị viên"
            >
              Admin 🛡️
            </span>
            <span
              v-else-if="comment.user.role === 'creator'"
              class="role-badge role-badge--creator"
              title="Tác giả sáng tác"
            >
              Tác giả ✍️
            </span>

            <!-- Relative Time -->
            <time
              v-if="formattedTime"
              class="comment-time"
              :datetime="comment.created_at || undefined"
            >
              {{ formattedTime }}
            </time>
          </div>

          <!-- Comment Content Body -->
          <div class="comment-text-box">
            <p class="comment-content">{{ comment.content }}</p>
          </div>
        </div>

        <!-- Comment Interactive Action Toolbar -->
        <div class="comment-actions-toolbar">
          <!-- Like Button -->
          <button
            type="button"
            class="btn-action btn-like"
            :class="{ 'is-liked': comment.is_liked }"
            :disabled="isLiking"
            :aria-label="comment.is_liked ? 'Bỏ thích bình luận' : 'Thích bình luận'"
            @click="handleLike"
          >
            <span class="action-icon" aria-hidden="true">{{ comment.is_liked ? '❤️' : '🤍' }}</span>
            <span v-if="comment.likes_count > 0" class="action-count">{{ comment.likes_count }}</span>
            <span class="action-label">Thích</span>
          </button>

          <span class="action-divider" aria-hidden="true">•</span>

          <!-- Reply Button -->
          <button
            type="button"
            class="btn-action btn-reply"
            :aria-label="`Trả lời bình luận của ${comment.user.name}`"
            @click="handleReply"
          >
            <span class="action-icon" aria-hidden="true">💬</span>
            <span class="action-label">Trả lời</span>
          </button>

          <!-- Delete Button (Only Author or Admin) -->
          <template v-if="canDelete">
            <span class="action-divider" aria-hidden="true">•</span>
            <button
              type="button"
              class="btn-action btn-delete"
              :disabled="isDeleting"
              :title="isReply ? 'Xóa phản hồi' : 'Xóa bình luận'"
              :aria-label="isReply ? 'Xóa phản hồi này' : 'Xóa bình luận này'"
              @click="isConfirmDeleteOpen = true"
            >
              <span class="action-icon" aria-hidden="true">🗑️</span>
              <span class="action-label">Xóa</span>
            </button>
          </template>
        </div>
      </div>
    </div>

    <!-- Nested Level-2 Replies Section (Strictly for Root Comments) -->
    <div
      v-if="!isReply && (hasReplies || comment.replies_count > 0)"
      class="comment-replies-container"
    >
      <!-- List of Nested Replies -->
      <div v-if="hasReplies" class="nested-replies-list">
        <CommentItem
          v-for="rep in visibleReplies"
          :key="rep.id"
          :comment="rep"
          :is-reply="true"
          :parent-comment-id="comment.id"
          :current-user="currentUser"
          :is-authenticated="isAuthenticated"
          :is-admin="isAdmin"
          @like="(id) => emit('like', id)"
          @reply="(rootId, author) => emit('reply', rootId, author)"
          @delete="(id) => emit('delete', id)"
          @login="() => emit('login')"
        />
      </div>

      <!-- Toggle Expand / Collapse Button -->
      <div v-if="hasMoreReplies" class="replies-expand-toolbar">
        <button
          type="button"
          class="btn-toggle-replies"
          :aria-expanded="isExpanded"
          @click="isExpanded = !isExpanded"
        >
          <span class="toggle-icon" aria-hidden="true">{{ isExpanded ? '▲' : '▼' }}</span>
          <span>
            {{ isExpanded ? 'Thu gọn phản hồi' : `Xem thêm ${remainingRepliesCount} phản hồi` }}
          </span>
        </button>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <ConfirmModal
      :is-open="isConfirmDeleteOpen"
      :title="isReply ? 'Xác nhận xóa phản hồi' : 'Xác nhận xóa bình luận'"
      :message="
        isReply
          ? 'Bạn có chắc chắn muốn xóa phản hồi này? Thao tác này không thể hoàn tác.'
          : 'Bạn có chắc chắn muốn xóa bình luận này? Thao tác này không thể hoàn tác và toàn bộ phản hồi liên quan cũng sẽ bị xóa.'
      "
      confirm-text="Xóa vĩnh viễn"
      cancel-text="Hủy"
      type="danger"
      :is-loading="isDeleting"
      @confirm="handleConfirmDelete"
      @close="isConfirmDeleteOpen = false"
    />
  </div>
</template>

<style scoped>
.comment-item-card {
  width: 100%;
  display: flex;
  flex-direction: column;
  transition: background-color var(--transition-fast, 0.15s ease);
}

.comment-item--root {
  margin-bottom: var(--spacing-md);
}

.comment-item--reply {
  margin-top: var(--spacing-xs);
  margin-bottom: var(--spacing-xs);
}

/* Main Comment Layout Row */
.comment-main-row {
  display: flex;
  gap: var(--spacing-sm);
  align-items: flex-start;
}

/* Avatar Column */
.comment-avatar-col {
  flex-shrink: 0;
  padding-top: 2px;
}

.comment-avatar {
  width: 38px;
  height: 38px;
  border-radius: var(--radius-full);
  overflow: hidden;
  background-color: var(--color-primary-soft);
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-border-subtle);
}

.comment-item--reply .comment-avatar {
  width: 30px;
  height: 30px;
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-initial {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
}

.comment-item--reply .avatar-initial {
  font-size: var(--font-size-xs);
}

/* Body Column */
.comment-body-col {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

/* Comment Bubble Box */
.comment-bubble {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: 10px 14px;
  transition: border-color var(--transition-fast, 0.15s ease);
}

.comment-item--reply .comment-bubble {
  background-color: var(--color-bg-surface);
  border-color: var(--color-border-subtle);
  padding: 8px 12px;
}

.comment-bubble:hover {
  border-color: var(--color-border-subtle);
}

/* Author Info Row */
.author-info-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}

.author-name {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.comment-item--reply .author-name {
  font-size: var(--font-size-xs);
}

/* Role Badges */
.role-badge {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  font-size: 0.7rem;
  font-weight: var(--font-weight-medium);
  border-radius: var(--radius-xs);
  line-height: var(--line-height-tight);
}

.role-badge--admin {
  background-color: var(--color-danger-soft);
  color: var(--color-danger);
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.role-badge--creator {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

/* Timestamp */
.comment-time {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-left: auto;
}

/* Comment Content Text */
.comment-text-box {
  word-break: break-word;
  overflow-wrap: anywhere;
}

.comment-content {
  margin: 0;
  font-size: var(--font-size-sm);
  line-height: var(--line-height-normal);
  color: var(--color-text-primary);
  white-space: pre-wrap;
  word-break: break-word;
  overflow-wrap: anywhere;
}

.comment-item--reply .comment-content {
  font-size: var(--font-size-xs);
  line-height: var(--line-height-relaxed);
}

/* Action Toolbar */
.comment-actions-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 6px 2px 6px;
  font-size: var(--font-size-xs);
}

.action-divider {
  color: var(--color-border);
  font-size: 0.75rem;
}

.btn-action {
  background: none;
  border: none;
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 4px;
  border-radius: var(--radius-xs);
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-action:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.btn-action:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-like.is-liked {
  color: var(--color-danger);
  font-weight: var(--font-weight-semibold);
}

.btn-like.is-liked:hover:not(:disabled) {
  background-color: var(--color-danger-soft);
}

.btn-delete:hover:not(:disabled) {
  color: var(--color-danger);
  background-color: var(--color-danger-soft);
}

.action-icon {
  font-size: 0.9em;
  display: inline-flex;
  align-items: center;
}

.action-count {
  font-weight: var(--font-weight-semibold);
  font-variant-numeric: tabular-nums;
}

/* Nested Replies Container */
.comment-replies-container {
  margin-left: 20px;
  padding-left: 14px;
  margin-top: var(--spacing-xs);
  border-left: 2px solid var(--color-border);
  display: flex;
  flex-direction: column;
}

.nested-replies-list {
  display: flex;
  flex-direction: column;
}

/* Toggle Expand / Collapse Replies Button */
.replies-expand-toolbar {
  padding-top: var(--spacing-2xs);
}

.btn-toggle-replies {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: none;
  color: var(--color-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  padding: 4px 8px;
  border-radius: var(--radius-sm);
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-toggle-replies:hover {
  background-color: var(--color-primary-soft);
  color: var(--color-primary-hover);
}

.toggle-icon {
  font-size: 0.7em;
  transition: transform var(--transition-fast, 0.15s ease);
}

/* Responsive adjustments */
@media (max-width: 640px) {
  .comment-replies-container {
    margin-left: 10px;
    padding-left: 8px;
  }

  .comment-avatar {
    width: 32px;
    height: 32px;
  }

  .comment-item--reply .comment-avatar {
    width: 26px;
    height: 26px;
  }

  .comment-bubble {
    padding: 8px 10px;
  }

  .comment-actions-toolbar {
    gap: 6px;
  }
}
</style>
