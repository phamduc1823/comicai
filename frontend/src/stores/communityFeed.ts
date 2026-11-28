import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Post, PostSortOption, PaginationMeta } from '@/types/community'

export const useCommunityFeedStore = defineStore('communityFeed', () => {
  const posts = ref<Post[]>([])
  const paginationMeta = ref<PaginationMeta | null>(null)
  const currentSort = ref<PostSortOption>('latest')
  const selectedComicId = ref<number | null>(null)
  const currentPage = ref(1)
  const savedScrollY = ref(0)
  const lastActivePostId = ref<number | null>(null)
  const hasSavedState = ref(false)

  function saveFeedState(state: {
    posts: Post[]
    paginationMeta: PaginationMeta | null
    currentSort: PostSortOption
    selectedComicId: number | null
    currentPage: number
    scrollY: number
    lastActivePostId?: number | null
  }) {
    posts.value = [...state.posts]
    paginationMeta.value = state.paginationMeta ? { ...state.paginationMeta } : null
    currentSort.value = state.currentSort
    selectedComicId.value = state.selectedComicId
    currentPage.value = state.currentPage
    savedScrollY.value = state.scrollY
    if (state.lastActivePostId !== undefined) {
      lastActivePostId.value = state.lastActivePostId
    }
    hasSavedState.value = true
  }

  function setLastActivePostId(postId: number | null) {
    lastActivePostId.value = postId
  }

  function clearFeedState() {
    posts.value = []
    paginationMeta.value = null
    currentSort.value = 'latest'
    selectedComicId.value = null
    currentPage.value = 1
    savedScrollY.value = 0
    lastActivePostId.value = null
    hasSavedState.value = false
  }

  return {
    posts,
    paginationMeta,
    currentSort,
    selectedComicId,
    currentPage,
    savedScrollY,
    lastActivePostId,
    hasSavedState,
    saveFeedState,
    setLastActivePostId,
    clearFeedState,
  }
})
