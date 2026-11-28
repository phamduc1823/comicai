<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import type { CommentableType, CommentItemData, PaginationMeta } from '@/types/community'
import { CommunityService } from '@/services/communityService'
import { useAuthStore } from '@/stores/auth'
import {
  getErrorMessage,
  isNotFoundError,
  isRateLimitError,
  getRetryAfterSeconds,
} from '@/services/httpClient'
import CommentForm from './CommentForm.vue'
import CommentItem from './CommentItem.vue'

export interface CommentSectionProps {
  targetType: CommentableType
  targetId: number
  comicAuthorId?: number | null
}

interface ActiveReplyTarget {
  rootCommentId: number
  authorName: string
  initialContent: string
}

const props = defineProps<CommentSectionProps>()

const emit = defineEmits<{
  (e: 'comment-count-change', count: number): void
}>()

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

// Reference to create CommentForm
const createFormRef = ref<InstanceType<typeof CommentForm> | null>(null)

// Data states
const comments = ref<CommentItemData[]>([])
const pagination = ref<PaginationMeta | null>(null)
const isLoading = ref(true)
const isLoadingMore = ref(false)
const errorMessage = ref<string | null>(null)
const isNotFound = ref(false)

// Action & Synchronous submission guards
const isCreating = ref(false)
const isReplying = ref(false)
const createError = ref<string | null>(null)
const replyError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const activeCooldownSeconds = ref(0)
const activeReplyTarget = ref<ActiveReplyTarget | null>(null)

// Per-comment loading trackers
const likingCommentIds = ref<Set<number>>(new Set())
const deletingCommentIds = ref<Set<number>>(new Set())

// Concurrency & Lifecycle tracking
let activeFetchId = 0
let isComponentMounted = true

// Computed values
const currentUser = computed(() => {
  return authStore.user
    ? {
        id: authStore.user.id,
        name: authStore.user.name,
        avatar_path: authStore.user.avatar_path,
        role: authStore.user.role,
      }
    : null
})

const totalCountDisplay = computed(() => {
  if (pagination.value) {
    return pagination.value.total
  }
  return comments.value.length
})

const hasMorePages = computed(() => {
  if (!pagination.value) return false
  return pagination.value.current_page < pagination.value.last_page
})

// Fetch comments with race-condition protection
async function fetchComments(page = 1) {
  const fetchId = ++activeFetchId

  if (page === 1) {
    isLoading.value = true
    errorMessage.value = null
    isNotFound.value = false
  } else {
    isLoadingMore.value = true
  }

  try {
    const res = await CommunityService.getComments({
      commentable_type: props.targetType,
      commentable_id: props.targetId,
      page,
      per_page: 15,
    })

    // Drop stale response if another fetch was triggered or component unmounted
    if (!isComponentMounted || fetchId !== activeFetchId) {
      return
    }

    if (page === 1) {
      comments.value = res.data
    } else {
      // Append new comments, deduplicating IDs
      const existingIds = new Set(comments.value.map((c) => c.id))
      const uniqueNewItems = res.data.filter((c) => !existingIds.has(c.id))
      comments.value = [...comments.value, ...uniqueNewItems]
    }

    pagination.value = res.meta
    emit('comment-count-change', res.meta.total)
  } catch (err: unknown) {
    if (!isComponentMounted || fetchId !== activeFetchId) {
      return
    }

    if (isNotFoundError(err)) {
      isNotFound.value = true
    } else {
      errorMessage.value = getErrorMessage(
        err,
        'Không thể tải danh sách bình luận. Vui lòng thử lại sau.'
      )
    }
  } finally {
    if (isComponentMounted && fetchId === activeFetchId) {
      isLoading.value = false
      isLoadingMore.value = false
    }
  }
}

// Load more handler
function handleLoadMore() {
  if (isLoadingMore.value || !hasMorePages.value) return
  const nextPage = (pagination.value?.current_page || 1) + 1
  fetchComments(nextPage)
}

// Create new root comment
async function handleCreateComment(content: string) {
  if (isCreating.value || activeCooldownSeconds.value > 0) return
  isCreating.value = true
  createError.value = null

  try {
    const res = await CommunityService.createComment({
      commentable_type: props.targetType,
      commentable_id: props.targetId,
      content,
    })

    const newComment = res.data
    // Prepend to comment list if not already present
    if (!comments.value.some((c) => c.id === newComment.id)) {
      comments.value.unshift(newComment)
    }

    // Reset create form state
    createFormRef.value?.resetForm()

    // Update pagination count
    if (pagination.value) {
      pagination.value.total += 1
      emit('comment-count-change', pagination.value.total)
    }
  } catch (err: unknown) {
    if (isRateLimitError(err)) {
      const retryAfter = getRetryAfterSeconds(err)
      activeCooldownSeconds.value = retryAfter && retryAfter > 0 ? retryAfter : 30
    } else {
      createError.value = getErrorMessage(
        err,
        'Không thể gửi bình luận. Vui lòng kiểm tra lại nội dung.'
      )
    }
  } finally {
    isCreating.value = false
  }
}

// Open reply form pointing to target root comment
function handleOpenReply(rootCommentId: number, authorName: string) {
  activeReplyTarget.value = {
    rootCommentId,
    authorName,
    initialContent: `@${authorName} `,
  }
  replyError.value = null
}

// Submit reply
async function handleReplySubmit(content: string) {
  if (!activeReplyTarget.value || isReplying.value || activeCooldownSeconds.value > 0) {
    return
  }

  isReplying.value = true
  replyError.value = null
  const targetRootId = activeReplyTarget.value.rootCommentId

  try {
    const res = await CommunityService.replyComment(targetRootId, { content })
    const newReply = res.data

    // Locate root comment in state
    const rootComment = comments.value.find((c) => c.id === targetRootId)
    if (rootComment) {
      if (!rootComment.replies) {
        rootComment.replies = []
      }
      if (!rootComment.replies.some((r) => r.id === newReply.id)) {
        rootComment.replies.push(newReply)
      }
      rootComment.replies_count = Math.max(
        rootComment.replies_count + 1,
        rootComment.replies.length
      )
    } else {
      // If root comment is missing in local page state, reload
      await fetchComments(pagination.value?.current_page || 1)
    }

    // Close reply form on success
    activeReplyTarget.value = null
  } catch (err: unknown) {
    if (isRateLimitError(err)) {
      const retryAfter = getRetryAfterSeconds(err)
      activeCooldownSeconds.value = retryAfter && retryAfter > 0 ? retryAfter : 30
    } else {
      replyError.value = getErrorMessage(
        err,
        'Không thể gửi phản hồi. Vui lòng kiểm tra lại nội dung.'
      )
    }
  } finally {
    isReplying.value = false
  }
}

// Like / Unlike comment with optimistic update & rollback
async function handleLikeComment(commentId: number) {
  if (!authStore.isAuthenticated) {
    handleLoginRedirect()
    return
  }

  if (likingCommentIds.value.has(commentId)) return
  likingCommentIds.value.add(commentId)

  // Find target comment in root or replies
  let targetComment: CommentItemData | null = null
  for (const c of comments.value) {
    if (c.id === commentId) {
      targetComment = c
      break
    }
    if (c.replies) {
      const found = c.replies.find((r) => r.id === commentId)
      if (found) {
        targetComment = found
        break
      }
    }
  }

  if (!targetComment) {
    likingCommentIds.value.delete(commentId)
    return
  }

  const prevLiked = targetComment.is_liked
  const prevCount = targetComment.likes_count

  // Optimistic UI update
  targetComment.is_liked = !prevLiked
  targetComment.likes_count = Math.max(0, prevCount + (prevLiked ? -1 : 1))

  try {
    const res = await CommunityService.toggleCommentLike(commentId)
    targetComment.is_liked = res.data.liked
    targetComment.likes_count = Math.max(0, res.data.likes_count)
  } catch (err: unknown) {
    // Rollback on failure
    targetComment.is_liked = prevLiked
    targetComment.likes_count = prevCount
    console.error('Lỗi khi thích bình luận:', err)
  } finally {
    likingCommentIds.value.delete(commentId)
  }
}

// Delete comment
async function handleDeleteComment(commentId: number) {
  if (deletingCommentIds.value.has(commentId)) return
  deletingCommentIds.value.add(commentId)
  actionError.value = null

  try {
    await CommunityService.deleteComment(commentId)

    // Check if deleting a root comment
    const rootIndex = comments.value.findIndex((c) => c.id === commentId)
    if (rootIndex !== -1) {
      comments.value.splice(rootIndex, 1)
      if (pagination.value) {
        pagination.value.total = Math.max(0, pagination.value.total - 1)
        emit('comment-count-change', pagination.value.total)
      }
    } else {
      // Check if deleting a level-2 reply
      for (const root of comments.value) {
        if (root.replies) {
          const replyIndex = root.replies.findIndex((r) => r.id === commentId)
          if (replyIndex !== -1) {
            root.replies.splice(replyIndex, 1)
            root.replies_count = Math.max(0, root.replies_count - 1)
            break
          }
        }
      }
    }
  } catch (err: unknown) {
    actionError.value = getErrorMessage(
      err,
      'Không thể xóa bình luận. Vui lòng thử lại sau.'
    )
  } finally {
    deletingCommentIds.value.delete(commentId)
  }
}

// Redirect guest to login with current route redirect query
function handleLoginRedirect() {
  if (router) {
    router.push({
      path: '/login',
      query: { redirect: route?.fullPath || '/community' },
    })
  } else if (typeof window !== 'undefined') {
    window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname)
  }
}

// Watch target changes with full state reset to prevent race condition
watch(
  () => [props.targetType, props.targetId],
  ([newType, newId], [oldType, oldId]) => {
    if (newType !== oldType || newId !== oldId) {
      // Synchronous immediate reset
      comments.value = []
      pagination.value = null
      activeReplyTarget.value = null
      createError.value = null
      replyError.value = null
      actionError.value = null
      errorMessage.value = null
      isNotFound.value = false
      isLoading.value = true
      isLoadingMore.value = false
      activeCooldownSeconds.value = 0
      likingCommentIds.value.clear()
      deletingCommentIds.value.clear()

      // Fetch fresh comments for new target
      fetchComments(1)
    }
  }
)

onMounted(() => {
  isComponentMounted = true
  fetchComments(1)
})

onUnmounted(() => {
  isComponentMounted = false
})
</script>

<template>
  <section class="comment-section-container" aria-label="Khu vực thảo luận và bình luận">
    <!-- Section Header -->
    <header class="section-header">
      <div class="header-title-wrapper">
        <span class="header-icon" aria-hidden="true">💬</span>
        <h3 class="header-title">
          Bình luận
          <span class="header-counter">({{ totalCountDisplay }})</span>
        </h3>
      </div>
    </header>

    <!-- Global Action Error Banner -->
    <div v-if="actionError" class="global-error-banner" role="alert">
      <span class="error-icon" aria-hidden="true">⚠️</span>
      <span class="error-text">{{ actionError }}</span>
      <button
        type="button"
        class="btn-close-error"
        aria-label="Đóng thông báo"
        @click="actionError = null"
      >
        ✕
      </button>
    </div>

    <!-- 404 Private / Unpublished Target State -->
    <div v-if="isNotFound" class="state-card state-card--not-found">
      <div class="state-icon" aria-hidden="true">🔒</div>
      <h4 class="state-title">Khu vực bình luận chưa khả dụng</h4>
      <p class="state-desc">
        Nội dung này chưa được phát hành công khai hoặc không tồn tại trong hệ thống.
      </p>
    </div>

    <!-- General Fetch Error State -->
    <div v-else-if="errorMessage" class="state-card state-card--error" role="alert">
      <div class="state-icon" aria-hidden="true">⚠️</div>
      <h4 class="state-title">Không thể tải danh sách bình luận</h4>
      <p class="state-desc">{{ errorMessage }}</p>
      <button type="button" class="btn-retry" @click="() => fetchComments(1)">
        Thử lại
      </button>
    </div>

    <!-- Normal Content Flow -->
    <div v-else class="comment-content-flow">
      <!-- Create Root Comment Form -->
      <div class="create-form-wrapper">
        <CommentForm
          ref="createFormRef"
          mode="create"
          :is-submitting="isCreating"
          :is-authenticated="authStore.isAuthenticated"
          :current-user="currentUser"
          :retry-after-seconds="activeCooldownSeconds"
          placeholder="Viết bình luận của bạn... (Tối đa 2.000 ký tự)"
          @submit="handleCreateComment"
          @login="handleLoginRedirect"
        />
        <div v-if="createError" class="form-error-banner" role="alert">
          <span class="error-icon" aria-hidden="true">⚠️</span>
          <span>{{ createError }}</span>
        </div>
      </div>

      <!-- Initial Loading Skeleton -->
      <div v-if="isLoading" class="loading-skeletons" aria-busy="true" aria-label="Đang tải bình luận...">
        <div v-for="i in 3" :key="i" class="comment-skeleton-item">
          <div class="skeleton-avatar"></div>
          <div class="skeleton-lines">
            <div class="skeleton-line skeleton-line--short"></div>
            <div class="skeleton-line skeleton-line--long"></div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div
        v-else-if="comments.length === 0"
        class="state-card state-card--empty"
      >
        <div class="state-icon" aria-hidden="true">💭</div>
        <h4 class="state-title">Chưa có bình luận nào</h4>
        <p class="state-desc">
          Hãy là người đầu tiên chia sẻ suy nghĩ và mở đầu cuộc thảo luận nhé!
        </p>
      </div>

      <!-- Comments List -->
      <div v-else class="comments-list">
        <div
          v-for="comment in comments"
          :key="comment.id"
          class="comment-thread-block"
        >
          <!-- Root Comment Item (Level 1) -->
          <CommentItem
            :comment="comment"
            :current-user="currentUser"
            :is-authenticated="authStore.isAuthenticated"
            :is-admin="authStore.isAdmin"
            :is-liking="likingCommentIds.has(comment.id)"
            :is-deleting="deletingCommentIds.has(comment.id)"
            :comic-author-id="comicAuthorId"
            @like="handleLikeComment"
            @reply="handleOpenReply"
            @delete="handleDeleteComment"
            @login="handleLoginRedirect"
          />

          <!-- Inline Reply Form for this Root Comment -->
          <div
            v-if="activeReplyTarget && activeReplyTarget.rootCommentId === comment.id"
            class="inline-reply-wrapper"
          >
            <CommentForm
              mode="reply"
              :is-submitting="isReplying"
              :is-authenticated="authStore.isAuthenticated"
              :current-user="currentUser"
              :retry-after-seconds="activeCooldownSeconds"
              :initial-content="activeReplyTarget.initialContent"
              :placeholder="`Trả lời @${activeReplyTarget.authorName}...`"
              @submit="handleReplySubmit"
              @cancel="activeReplyTarget = null"
              @login="handleLoginRedirect"
            />
            <div v-if="replyError" class="form-error-banner" role="alert">
              <span class="error-icon" aria-hidden="true">⚠️</span>
              <span>{{ replyError }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Pagination / Load More Controls -->
      <div v-if="hasMorePages" class="pagination-wrapper">
        <button
          type="button"
          class="btn-load-more"
          :disabled="isLoadingMore"
          @click="handleLoadMore"
        >
          <span v-if="isLoadingMore" class="spinner-mini" aria-hidden="true"></span>
          <span>
            {{ isLoadingMore ? 'Đang tải thêm bình luận...' : 'Xem thêm bình luận cũ hơn' }}
          </span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.comment-section-container {
  width: 100%;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--spacing-lg);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
  box-sizing: border-box;
}

/* Header */
.section-header {
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--spacing-sm);
}

.header-title-wrapper {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.header-icon {
  font-size: 1.35rem;
}

.header-title {
  margin: 0;
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  display: flex;
  align-items: center;
  gap: 6px;
}

.header-counter {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-primary);
  font-variant-numeric: tabular-nums;
}

/* State Cards (Empty, Error, Not Found) */
.state-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: var(--spacing-2xl) var(--spacing-md);
  background-color: var(--color-bg-card);
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-lg);
  gap: var(--spacing-xs);
}

.state-icon {
  font-size: 2.25rem;
  margin-bottom: 4px;
}

.state-title {
  margin: 0;
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.state-desc {
  margin: 0;
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 440px;
  line-height: var(--line-height-normal);
}

.btn-retry {
  margin-top: var(--spacing-sm);
  padding: 6px 16px;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-retry:hover {
  background-color: var(--color-primary-hover);
}

/* Error Banners */
.global-error-banner,
.form-error-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-md);
  color: var(--color-danger);
  font-size: var(--font-size-sm);
}

.form-error-banner {
  margin-top: var(--spacing-xs);
  font-size: var(--font-size-xs);
}

.btn-close-error {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--color-danger);
  cursor: pointer;
  padding: 2px 6px;
  font-size: var(--font-size-sm);
}

/* Flow */
.comment-content-flow {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}

/* Create Form */
.create-form-wrapper {
  width: 100%;
}

/* Loading Skeletons */
.loading-skeletons {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.comment-skeleton-item {
  display: flex;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.skeleton-avatar {
  width: 38px;
  height: 38px;
  border-radius: var(--radius-full);
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: skeleton-pulse 1.4s ease infinite;
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
  border-radius: var(--radius-xs);
  background: linear-gradient(90deg, var(--color-bg-card) 25%, var(--color-bg-elevated) 50%, var(--color-bg-card) 75%);
  background-size: 200% 100%;
  animation: skeleton-pulse 1.4s ease infinite;
}

.skeleton-line--short {
  width: 30%;
}

.skeleton-line--long {
  width: 75%;
}

@keyframes skeleton-pulse {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* Comments List */
.comments-list {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.comment-thread-block {
  display: flex;
  flex-direction: column;
}

/* Inline Reply Form */
.inline-reply-wrapper {
  margin-left: 20px;
  padding-left: 14px;
  margin-top: var(--spacing-xs);
  margin-bottom: var(--spacing-sm);
  border-left: 2px solid var(--color-primary);
}

/* Pagination */
.pagination-wrapper {
  display: flex;
  justify-content: center;
  padding-top: var(--spacing-xs);
}

.btn-load-more {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 24px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-primary);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
}

.btn-load-more:hover:not(:disabled) {
  background-color: var(--color-primary-soft);
  border-color: var(--color-primary);
}

.btn-load-more:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.spinner-mini {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(56, 189, 248, 0.2);
  border-top-color: var(--color-primary);
  border-radius: var(--radius-full);
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Responsive */
@media (max-width: 640px) {
  .comment-section-container {
    padding: var(--spacing-md);
    border-radius: var(--radius-lg);
  }

  .inline-reply-wrapper {
    margin-left: 10px;
    padding-left: 8px;
  }

  .btn-load-more {
    width: 100%;
    justify-content: center;
  }
}
</style>
