<script setup lang="ts">
import { ref, computed, watch, onUnmounted, nextTick } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import type { Post } from '@/types/community'
import { useAuthStore } from '@/stores/auth'
import { useCommunityFeedStore } from '@/stores/communityFeed'
import { CommunityService } from '@/services/communityService'
import { getErrorMessage } from '@/services/httpClient'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import ReportModal from '@/components/common/ReportModal.vue'

const props = defineProps<{
  post: Post
  isDetailView?: boolean
}>()

const emit = defineEmits<{
  (e: 'like-toggle', post: Post): void
  (e: 'edit', post: Post): void
  (e: 'deleted', postId: number): void
  (e: 'share', post: Post): void
}>()

const router = useRouter()
const authStore = useAuthStore()
const communityFeedStore = useCommunityFeedStore()

function handleDetailNavigation() {
  communityFeedStore.setLastActivePostId(props.post.id)
}

function handleShareClick() {
  emit('share', props.post)
}

// State
const isLiking = ref(false)
const isConfirmDeleteOpen = ref(false)
const isDeleting = ref(false)
const deleteError = ref<string | null>(null)
const isImagePreviewOpen = ref(false)
const lightboxCloseBtnRef = ref<HTMLButtonElement | null>(null)
const isReportModalOpen = ref(false)

// Permissions
const isAuthor = computed(() => {
  return authStore.isAuthenticated && authStore.user?.id === props.post.user.id
})

const canDelete = computed(() => {
  return authStore.isAuthenticated && (isAuthor.value || authStore.isAdmin)
})

const userInitial = computed(() => {
  return props.post.user.name?.trim().charAt(0).toUpperCase() || 'U'
})

const formattedTime = computed(() => {
  if (!props.post.created_at) return ''
  const date = new Date(props.post.created_at)
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

async function handleLikeClick() {
  if (!authStore.isAuthenticated) {
    router.push({
      path: '/login',
      query: { redirect: router.currentRoute.value.fullPath },
    })
    return
  }

  if (isLiking.value) return
  isLiking.value = true

  try {
    const res = await CommunityService.togglePostLike(props.post.id)
    props.post.is_liked = res.data.liked
    props.post.likes_count = res.data.likes_count
    emit('like-toggle', props.post)
  } catch (err) {
    console.error('Lỗi khi thích bài đăng:', err)
  } finally {
    isLiking.value = false
  }
}

function handleEditClick() {
  emit('edit', props.post)
}

function openDeleteModal() {
  deleteError.value = null
  isConfirmDeleteOpen.value = true
}

async function confirmDelete() {
  isDeleting.value = true
  deleteError.value = null

  try {
    await CommunityService.deletePost(props.post.id)
    isConfirmDeleteOpen.value = false
    emit('deleted', props.post.id)
  } catch (err) {
    deleteError.value = getErrorMessage(err, 'Không thể xóa bài đăng. Vui lòng thử lại.')
  } finally {
    isDeleting.value = false
  }
}

// Lightbox Controls
function openLightbox() {
  if (!props.post.image_url) return
  isImagePreviewOpen.value = true
}

function closeLightbox() {
  isImagePreviewOpen.value = false
}

function handleLightboxKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && isImagePreviewOpen.value) {
    closeLightbox()
  }
}

watch(isImagePreviewOpen, (isOpen) => {
  if (isOpen) {
    window.addEventListener('keydown', handleLightboxKeyDown)
    document.body.style.overflow = 'hidden'
    nextTick(() => {
      lightboxCloseBtnRef.value?.focus()
    })
  } else {
    window.removeEventListener('keydown', handleLightboxKeyDown)
    document.body.style.overflow = ''
  }
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleLightboxKeyDown)
  if (isImagePreviewOpen.value) {
    document.body.style.overflow = ''
  }
})
</script>

<template>
  <article
    :id="'post-' + post.id"
    :data-post-id="post.id"
    class="post-card"
    :class="{ 'post-card--detail': isDetailView }"
  >
    <!-- Post Header -->
    <header class="post-header">
      <div class="author-info">
        <RouterLink
          :to="'/users/' + post.user.id"
          class="author-avatar-link"
          :aria-label="'Xem hồ sơ của ' + post.user.name"
        >
          <div class="author-avatar" aria-hidden="true">
            <img
              v-if="post.user.avatar_url"
              :src="post.user.avatar_url"
              :alt="post.user.name"
              class="avatar-img"
              loading="lazy"
            />
            <span v-else class="avatar-initial">{{ userInitial }}</span>
          </div>
        </RouterLink>
        <div class="author-meta">
          <div class="author-name-row">
            <RouterLink
              :to="'/users/' + post.user.id"
              class="author-name-link"
            >
              <span class="author-name">{{ post.user.name }}</span>
            </RouterLink>
            <span
              v-if="post.user.role === 'admin'"
              class="role-badge role-badge--admin"
              title="Quản trị viên"
            >
              Admin 🛡️
            </span>
            <span
              v-else-if="post.user.role === 'creator'"
              class="role-badge role-badge--creator"
              title="Tác giả"
            >
              Tác giả ✍️
            </span>
          </div>
          <time class="post-time" :datetime="post.created_at || undefined">
            {{ formattedTime }}
          </time>
        </div>
      </div>

      <!-- Action buttons for Author / Admin / Report -->
      <div class="post-actions-menu">
        <button
          v-if="isAuthor"
          type="button"
          class="btn-action btn-edit"
          title="Chỉnh sửa bài đăng"
          aria-label="Chỉnh sửa bài đăng"
          @click="handleEditClick"
        >
          ✏️ <span class="action-text">Sửa</span>
        </button>
        <button
          v-if="canDelete"
          type="button"
          class="btn-action btn-delete"
          title="Xóa bài đăng"
          aria-label="Xóa bài đăng"
          @click="openDeleteModal"
        >
          🗑️ <span class="action-text">Xóa</span>
        </button>
        <button
          v-if="!isAuthor"
          type="button"
          class="btn-action btn-report"
          title="Báo cáo bài đăng vi phạm"
          aria-label="Báo cáo bài đăng vi phạm"
          @click="isReportModalOpen = true"
        >
          🚩 <span class="action-text">Báo cáo</span>
        </button>
      </div>
    </header>

    <!-- Post Content -->
    <div class="post-body">
      <p class="post-text">{{ post.content }}</p>

      <!-- Attached Image -->
      <div v-if="post.image_url" class="post-image-container">
        <img
          :src="post.image_url"
          alt="Ảnh bài đăng"
          class="post-image"
          loading="lazy"
          tabindex="0"
          role="button"
          aria-label="Nhấn để xem ảnh phóng to"
          @click="openLightbox"
          @keydown.enter="openLightbox"
          @keydown.space.prevent="openLightbox"
        />
      </div>

      <!-- Attached Comic Tag -->
      <div v-if="post.comic" class="post-comic-tag">
        <RouterLink
          :to="`/comics/${post.comic.slug}`"
          class="comic-tag-link"
          :title="`Xem truyện ${post.comic.title}`"
        >
          <div class="comic-tag-cover" aria-hidden="true">
            <img
              v-if="post.comic.cover_url"
              :src="post.comic.cover_url"
              :alt="post.comic.title"
              class="cover-img"
              loading="lazy"
            />
            <span v-else class="cover-placeholder">📖</span>
          </div>
          <div class="comic-tag-info">
            <span class="comic-tag-badge">Truyện gắn kèm</span>
            <span class="comic-tag-title">{{ post.comic.title }}</span>
          </div>
          <span class="comic-tag-arrow" aria-hidden="true">›</span>
        </RouterLink>
      </div>
    </div>

    <!-- Post Footer: Like, Comment, Detail Link -->
    <footer class="post-footer">
      <div class="stats-group">
        <!-- Like Button -->
        <button
          type="button"
          class="btn-stat btn-like"
          :class="{ 'is-liked': post.is_liked }"
          :disabled="isLiking"
          :aria-label="post.is_liked ? 'Bỏ thích bài đăng' : 'Thích bài đăng'"
          @click="handleLikeClick"
        >
          <span class="stat-icon" aria-hidden="true">{{ post.is_liked ? '❤️' : '🤍' }}</span>
          <span class="stat-count">{{ post.likes_count }}</span>
          <span class="stat-label">Thích</span>
        </button>

        <!-- Comments Count / Link -->
        <RouterLink
          :to="`/posts/${post.id}`"
          class="btn-stat btn-comment"
          title="Xem bình luận bài viết"
          @click="handleDetailNavigation"
        >
          <span class="stat-icon" aria-hidden="true">💬</span>
          <span class="stat-count">{{ post.comments_count }}</span>
          <span class="stat-label">Bình luận</span>
        </RouterLink>

        <!-- Share Button -->
        <button
          type="button"
          class="btn-stat btn-share"
          title="Chia sẻ"
          aria-label="Chia sẻ"
          @click.stop.prevent="handleShareClick"
        >
          <span class="stat-icon" aria-hidden="true">
            <svg
              class="share-icon-svg"
              viewBox="0 0 24 24"
              width="20"
              height="20"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M13 4v4c-6.575 1.028-9.02 6.788-10 12-.037.206 5.384-5.962 10-6v4l8-7-8-7z" />
            </svg>
          </span>
          <span class="stat-label">Chia sẻ</span>
        </button>
      </div>

      <!-- Detail link if not already on detail page -->
      <RouterLink
        v-if="!isDetailView"
        :to="`/posts/${post.id}`"
        class="link-detail"
        @click="handleDetailNavigation"
      >
        <span>Xem chi tiết</span>
        <span aria-hidden="true">→</span>
      </RouterLink>
    </footer>

    <!-- Delete Confirmation Modal -->
    <ConfirmModal
      :is-open="isConfirmDeleteOpen"
      title="Xác nhận xóa bài đăng"
      message="Bạn có chắc chắn muốn xóa bài đăng này? Thao tác này không thể hoàn tác và toàn bộ bình luận liên quan cũng sẽ bị xóa."
      confirm-text="Xóa bài đăng"
      cancel-text="Hủy"
      type="danger"
      :is-loading="isDeleting"
      @confirm="confirmDelete"
      @close="isConfirmDeleteOpen = false"
    >
      <div v-if="deleteError" class="modal-error-message" role="alert">
        {{ deleteError }}
      </div>
    </ConfirmModal>

    <!-- Report Modal -->
    <ReportModal
      :is-open="isReportModalOpen"
      reportable-type="post"
      :reportable-id="post.id"
      :target-title="post.user ? `Bài viết của ${post.user.name}` : `Bài viết #${post.id}`"
      @close="isReportModalOpen = false"
    />

    <!-- Image Lightbox Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="isImagePreviewOpen && post.image_url"
          class="lightbox-backdrop"
          role="dialog"
          aria-modal="true"
          aria-label="Xem ảnh kích thước đầy đủ"
          @click="closeLightbox"
        >
          <div class="lightbox-content" @click.stop>
            <button
              ref="lightboxCloseBtnRef"
              type="button"
              class="lightbox-close"
              aria-label="Đóng xem ảnh (Phím Escape)"
              @click="closeLightbox"
            >
              ✕
            </button>
            <img :src="post.image_url" alt="Ảnh phóng to" class="lightbox-img" />
          </div>
        </div>
      </Transition>
    </Teleport>
  </article>
</template>

<style scoped>
.post-card {
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--spacing-lg);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.post-card:not(.post-card--detail):hover {
  border-color: rgba(56, 189, 248, 0.3);
  box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.4);
}

.post-card--detail {
  padding: var(--spacing-xl);
}

/* Header */
.post-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--spacing-md);
}

.author-info {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.author-avatar-link {
  text-decoration: none;
  display: block;
  border-radius: 50%;
}

.author-name-link {
  text-decoration: none;
  color: inherit;
  display: inline-flex;
  align-items: center;
}

.author-name-link:hover .author-name {
  color: var(--color-primary);
}

.author-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background-color: var(--color-primary-soft);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid rgba(56, 189, 248, 0.2);
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-initial {
  color: var(--color-primary);
  font-weight: var(--font-weight-bold);
  font-size: var(--font-size-base);
}

.author-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.author-name-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-wrap: wrap;
}

.author-name {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.role-badge {
  font-size: 10px;
  font-weight: var(--font-weight-medium);
  padding: 1px 6px;
  border-radius: var(--radius-full);
}

.role-badge--admin {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.role-badge--creator {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.post-time {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* Actions Menu */
.post-actions-menu {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.btn-action {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-secondary);
  font-size: var(--font-size-xs);
  padding: 4px 8px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all var(--transition-fast);
}

.btn-edit:hover {
  color: var(--color-primary);
  border-color: var(--color-primary);
  background-color: var(--color-primary-soft);
}

.btn-delete:hover {
  color: var(--color-danger);
  border-color: var(--color-danger);
  background-color: var(--color-danger-soft);
}

.btn-report:hover {
  color: var(--color-danger);
  border-color: var(--color-danger);
  background-color: var(--color-danger-soft);
}

/* Body */
.post-body {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.post-text {
  font-size: var(--font-size-base);
  line-height: var(--line-height-relaxed);
  color: var(--color-text-primary);
  white-space: pre-wrap;
  word-break: break-word;
  margin: 0;
}

.post-image-container {
  max-height: 480px;
  border-radius: var(--radius-md);
  overflow: hidden;
  background-color: rgba(0, 0, 0, 0.2);
  border: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.post-image {
  max-width: 100%;
  max-height: 480px;
  object-fit: contain;
  transition: transform var(--transition-fast);
}

.post-image:hover {
  transform: scale(1.01);
}

/* Attached Comic Tag */
.post-comic-tag {
  display: inline-flex;
}

.comic-tag-link {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: all var(--transition-fast);
  max-width: 100%;
}

.comic-tag-link:hover {
  border-color: var(--color-primary);
  background-color: var(--color-primary-soft);
}

.comic-tag-cover {
  width: 32px;
  height: 44px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  background-color: var(--color-bg-surface);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-placeholder {
  font-size: 14px;
}

.comic-tag-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow: hidden;
}

.comic-tag-badge {
  font-size: 10px;
  color: var(--color-primary);
  font-weight: var(--font-weight-medium);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.comic-tag-title {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.comic-tag-arrow {
  font-size: var(--font-size-lg);
  color: var(--color-text-muted);
  margin-left: auto;
}

/* Footer */
.post-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid var(--color-border);
  padding-top: var(--spacing-md);
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.stats-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
}

.btn-stat {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: none;
  color: var(--color-text-secondary);
  font-size: var(--font-size-sm);
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  text-decoration: none;
  transition: all var(--transition-fast);
}

.btn-stat:hover {
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
}

.btn-like.is-liked {
  color: var(--color-danger);
}

.btn-share .share-icon-svg {
  width: 20px;
  height: 20px;
  display: block;
  color: inherit;
  transition: transform var(--transition-fast, 0.15s ease), color var(--transition-fast, 0.15s ease);
}

.btn-share:hover .share-icon-svg {
  transform: scale(1.12);
  color: var(--color-primary, #38bdf8);
}

.btn-like.is-liked .stat-icon {
  transform: scale(1.1);
}

.link-detail {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  color: var(--color-primary);
  text-decoration: none;
  transition: color var(--transition-fast);
}

.link-detail:hover {
  color: var(--color-primary-hover);
  text-decoration: underline;
}

.modal-error-message {
  margin-top: var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-sm);
  color: var(--color-danger);
  font-size: var(--font-size-xs);
}

/* Lightbox */
.lightbox-backdrop {
  position: fixed;
  inset: 0;
  z-index: 2000;
  background-color: rgba(0, 0, 0, 0.88);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
}

.lightbox-content {
  position: relative;
  max-width: 90vw;
  max-height: 90vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

.lightbox-img {
  max-width: 100%;
  max-height: 90vh;
  object-fit: contain;
  border-radius: var(--radius-md);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
}

.lightbox-close {
  position: absolute;
  top: -40px;
  right: 0;
  background: transparent;
  border: none;
  color: #fff;
  font-size: 24px;
  cursor: pointer;
  padding: 4px 8px;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 640px) {
  .post-card {
    padding: var(--spacing-md);
  }

  .action-text {
    display: none;
  }

  .stat-label {
    display: none;
  }
}
</style>
