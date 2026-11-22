<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import type { Post } from '@/types/community'
import { CommunityService } from '@/services/communityService'
import { getErrorMessage, isNotFoundError } from '@/services/httpClient'
import PostCard from '@/components/community/PostCard.vue'
import PostFormModal from '@/components/community/PostFormModal.vue'
import CommentSection from '@/components/community/CommentSection.vue'

const route = useRoute()
const router = useRouter()

// Request synchronization & lifecycle guard
let activeFetchId = 0
let isComponentMounted = true

// State
const post = ref<Post | null>(null)
const isLoading = ref(true)
const isNotFound = ref(false)
const errorMessage = ref<string | null>(null)

// Modal State
const isEditModalOpen = ref(false)

async function fetchPostDetail(targetParam?: string | string[]) {
  const fetchId = ++activeFetchId
  const rawId = targetParam !== undefined ? targetParam : route.params.id
  const stringId = Array.isArray(rawId) ? rawId[0] : String(rawId || '').trim()
  const numericId = parseInt(stringId, 10)

  // Reset state immediately to prevent displaying stale post data
  post.value = null
  errorMessage.value = null
  isNotFound.value = false

  // Validate ID format before calling backend
  if (!stringId || isNaN(numericId) || numericId <= 0) {
    isNotFound.value = true
    isLoading.value = false
    return
  }

  isLoading.value = true

  try {
    const res = await CommunityService.getPost(numericId)

    // Discard response if superseded or component unmounted
    if (!isComponentMounted || fetchId !== activeFetchId) {
      return
    }

    post.value = res.data
  } catch (err: unknown) {
    // Discard error if superseded or component unmounted
    if (!isComponentMounted || fetchId !== activeFetchId) {
      return
    }

    if (isNotFoundError(err)) {
      isNotFound.value = true
    } else {
      errorMessage.value = getErrorMessage(err, 'Không thể tải chi tiết bài viết. Vui lòng thử lại.')
    }
  } finally {
    if (isComponentMounted && fetchId === activeFetchId) {
      isLoading.value = false
    }
  }
}

function handleEditPost() {
  isEditModalOpen.value = true
}

function handlePostSaved(updatedPost: Post) {
  post.value = updatedPost
}

function handlePostDeleted() {
  // Navigate back to community board after successful deletion
  router.push('/community')
}

function handleCommentCountChange(count: number) {
  if (post.value) {
    post.value.comments_count = count
  }
}

// Watch for route param ID changes when navigating between posts
watch(
  () => route.params.id,
  (newId) => {
    if (newId) {
      fetchPostDetail(newId)
    }
  }
)

onMounted(() => {
  fetchPostDetail()
})

onUnmounted(() => {
  isComponentMounted = false
})
</script>

<template>
  <div class="post-detail-page">
    <div class="container post-detail-container">
      <!-- Back navigation button -->
      <nav class="back-nav" aria-label="Điều hướng quay lại">
        <RouterLink to="/community" class="btn-back">
          <span aria-hidden="true">←</span>
          <span>Quay lại Bảng tin Cộng đồng</span>
        </RouterLink>
      </nav>

      <!-- Loading State -->
      <section v-if="isLoading" class="loading-state" aria-busy="true">
        <div class="spinner-large" aria-hidden="true"></div>
        <p class="loading-text">Đang tải chi tiết bài viết...</p>
      </section>

      <!-- 404 Not Found State -->
      <section v-else-if="isNotFound" class="empty-state">
        <div class="empty-icon" aria-hidden="true">🔍</div>
        <h1 class="empty-title">Bài đăng không tồn tại hoặc đã bị xóa</h1>
        <p class="empty-desc">
          Bài viết bạn đang tìm kiếm không tồn tại, đã bị tác giả gỡ bỏ hoặc bị kiểm duyệt bởi ban quản trị.
        </p>
        <RouterLink to="/community" class="btn-action-primary">
          Về Bảng tin Cộng đồng
        </RouterLink>
      </section>

      <!-- Error State -->
      <section v-else-if="errorMessage" class="error-state" role="alert">
        <div class="error-icon" aria-hidden="true">⚠️</div>
        <h2 class="error-title">Không thể tải dữ liệu</h2>
        <p class="error-message">{{ errorMessage }}</p>
        <button
          type="button"
          class="btn-retry"
          @click="() => fetchPostDetail()"
        >
          Thử lại
        </button>
      </section>

      <!-- Post Detail Content -->
      <section v-else-if="post" class="post-detail-content">
        <PostCard
          :post="post"
          :is-detail-view="true"
          @edit="handleEditPost"
          @deleted="handlePostDeleted"
        />

        <!-- Comments Section (Package 9.4B) -->
        <CommentSection
          target-type="post"
          :target-id="post.id"
          @comment-count-change="handleCommentCountChange"
        />
      </section>
    </div>

    <!-- Edit Post Modal -->
    <PostFormModal
      v-if="post"
      :is-open="isEditModalOpen"
      :post="post"
      @close="isEditModalOpen = false"
      @saved="handlePostSaved"
    />
  </div>
</template>

<style scoped>
.post-detail-page {
  padding: var(--spacing-xl) 0;
  min-height: calc(100vh - 64px - 100px);
}

.post-detail-container {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
  max-width: 800px;
}

.back-nav {
  display: flex;
  align-items: center;
}

.btn-back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-primary);
  text-decoration: none;
  padding: 6px 12px;
  border-radius: var(--radius-md);
  background-color: var(--color-primary-soft);
  border: 1px solid rgba(56, 189, 248, 0.25);
  transition: all var(--transition-fast);
}

.btn-back:hover {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  box-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
}

/* States */
.loading-state,
.error-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-3xl) var(--spacing-lg);
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  text-align: center;
  gap: var(--spacing-md);
}

.spinner-large {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(56, 189, 248, 0.2);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.loading-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

.error-icon,
.empty-icon {
  font-size: 44px;
}

.error-title,
.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  margin: 0;
}

.error-message,
.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 440px;
  margin: 0;
}

.btn-retry,
.btn-action-primary {
  padding: 8px 20px;
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  border: none;
  text-decoration: none;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-retry:hover,
.btn-action-primary:hover {
  background-color: var(--color-primary-hover);
}

.post-detail-content {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
}


</style>
