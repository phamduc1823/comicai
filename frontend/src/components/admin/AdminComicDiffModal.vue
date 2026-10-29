<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import type { AdminModerationComic } from '@/types/admin'
import type { Genre, Tag } from '@/types/catalog'

const props = withDefaults(
  defineProps<{
    isOpen: boolean
    comic: AdminModerationComic | null
    genresList?: Genre[]
    tagsList?: Tag[]
    isLoading?: boolean
    actionError?: string | null
  }>(),
  {
    genresList: () => [],
    tagsList: () => [],
    isLoading: false,
    actionError: null,
  }
)

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'approveChanges', comic: AdminModerationComic): void
  (e: 'rejectChanges', comic: AdminModerationComic): void
}>()

const oldCoverError = ref(false)
const newCoverError = ref(false)

function handleClose() {
  if (props.isLoading) return
  emit('close')
}

function handleApprove() {
  if (props.comic && !props.isLoading) {
    emit('approveChanges', props.comic)
  }
}

function handleReject() {
  if (props.comic && !props.isLoading) {
    emit('rejectChanges', props.comic)
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.isOpen && !props.isLoading) {
    handleClose()
  }
}

watch(
  () => [props.isOpen, props.comic?.id],
  ([open]) => {
    if (open) {
      oldCoverError.value = false
      newCoverError.value = false
      window.addEventListener('keydown', handleKeyDown)
      document.body.style.overflow = 'hidden'
    } else {
      window.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = ''
    }
  }
)

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
  document.body.style.overflow = ''
})

const pendingChanges = computed(() => {
  return props.comic?.pending_changes || null
})

// Taxonomy ID normalization helpers
function normalizeId(id: unknown): number | null {
  if (id === null || id === undefined) return null
  const num = typeof id === 'number' ? id : parseInt(String(id).trim(), 10)
  return !isNaN(num) && num > 0 ? num : null
}

function extractNumericIds(list: unknown[] | null | undefined): number[] {
  if (!Array.isArray(list)) return []
  const result: number[] = []
  for (const item of list) {
    let rawId: unknown = item
    if (item !== null && typeof item === 'object' && 'id' in item) {
      rawId = (item as { id: unknown }).id
    }
    const num = normalizeId(rawId)
    if (num !== null && !result.includes(num)) {
      result.push(num)
    }
  }
  return result
}

// Genre maps
const genreMap = computed(() => {
  const map = new Map<number, string>()
  props.genresList.forEach((g) => {
    const id = normalizeId(g?.id)
    if (id !== null && g?.name) map.set(id, g.name)
  })
  props.comic?.genres?.forEach((g) => {
    const id = normalizeId(g?.id)
    if (id !== null && g?.name) map.set(id, g.name)
  })
  return map
})

// Tag maps
const tagMap = computed(() => {
  const map = new Map<number, string>()
  props.tagsList.forEach((t) => {
    const id = normalizeId(t?.id)
    if (id !== null && t?.name) map.set(id, t.name)
  })
  props.comic?.tags?.forEach((t) => {
    const id = normalizeId(t?.id)
    if (id !== null && t?.name) map.set(id, t.name)
  })
  return map
})

// Diff computations
const titleDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const current = props.comic.title
  const proposed = pendingChanges.value.title
  const isChanged = proposed !== undefined && proposed.trim() !== current
  return { current, proposed: proposed ?? current, isChanged }
})

const slugDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const current = props.comic.slug
  const proposed = pendingChanges.value.slug
  const isChanged = proposed !== undefined && proposed.trim() !== current
  return { current, proposed: proposed ?? current, isChanged }
})

const authorDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const current = props.comic.author_name
  const proposed = pendingChanges.value.author_name
  const isChanged = proposed !== undefined && proposed.trim() !== current
  return { current, proposed: proposed ?? current, isChanged }
})

const ageRatingDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const current = props.comic.age_rating
  const proposed = pendingChanges.value.age_rating
  const isChanged = proposed !== undefined && proposed !== current
  return { current, proposed: proposed ?? current, isChanged }
})

const statusDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const current = props.comic.status
  const proposed = pendingChanges.value.status
  const isChanged = proposed !== undefined && proposed !== current
  return { current, proposed: proposed ?? current, isChanged }
})

const coverDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const currentPath = props.comic.cover_path
  const currentUrl = props.comic.cover_url || props.comic.cover_path
  const proposedPath = pendingChanges.value.cover_path
  const proposedUrl = pendingChanges.value.cover_url || pendingChanges.value.cover_path
  const isChanged = Boolean(proposedPath && proposedPath !== currentPath)
  return {
    currentUrl,
    proposedUrl: proposedUrl || currentUrl,
    isChanged,
  }
})

const descDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null

  const currentRaw = props.comic.description
  const current = typeof currentRaw === 'string' ? currentRaw.trim() : ''

  const hasProposed = 'description' in pendingChanges.value && pendingChanges.value.description !== undefined
  if (!hasProposed) {
    return {
      current,
      proposed: current,
      isChanged: false,
    }
  }

  const proposedRaw = pendingChanges.value.description
  const proposed = typeof proposedRaw === 'string' ? proposedRaw.trim() : ''
  const isChanged = proposed !== current

  return {
    current,
    proposed,
    isChanged,
  }
})

const genresDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const currentIds = extractNumericIds(props.comic.genres)
  const proposedRaw = pendingChanges.value.genres

  if (proposedRaw === undefined) {
    const names = currentIds.map((id) => genreMap.value.get(id) || `ID:${id}`)
    return {
      currentNames: names,
      added: [],
      removed: [],
      unchanged: names,
      isChanged: false,
    }
  }

  const proposedIds = extractNumericIds(proposedRaw)
  const currentSet = new Set(currentIds)
  const proposedSet = new Set(proposedIds)

  const added = proposedIds.filter((id) => !currentSet.has(id)).map((id) => genreMap.value.get(id) || `ID:${id}`)
  const removed = currentIds.filter((id) => !proposedSet.has(id)).map((id) => genreMap.value.get(id) || `ID:${id}`)
  const unchanged = proposedIds.filter((id) => currentSet.has(id)).map((id) => genreMap.value.get(id) || `ID:${id}`)

  return {
    currentNames: currentIds.map((id) => genreMap.value.get(id) || `ID:${id}`),
    added,
    removed,
    unchanged,
    isChanged: added.length > 0 || removed.length > 0,
  }
})

const tagsDiff = computed(() => {
  if (!props.comic || !pendingChanges.value) return null
  const currentIds = extractNumericIds(props.comic.tags)
  const proposedRaw = pendingChanges.value.tags

  if (proposedRaw === undefined) {
    const names = currentIds.map((id) => tagMap.value.get(id) || `ID:${id}`)
    return {
      currentNames: names,
      added: [],
      removed: [],
      unchanged: names,
      isChanged: false,
    }
  }

  const proposedIds = extractNumericIds(proposedRaw)
  const currentSet = new Set(currentIds)
  const proposedSet = new Set(proposedIds)

  const added = proposedIds.filter((id) => !currentSet.has(id)).map((id) => tagMap.value.get(id) || `ID:${id}`)
  const removed = currentIds.filter((id) => !proposedSet.has(id)).map((id) => tagMap.value.get(id) || `ID:${id}`)
  const unchanged = proposedIds.filter((id) => currentSet.has(id)).map((id) => tagMap.value.get(id) || `ID:${id}`)

  return {
    currentNames: currentIds.map((id) => tagMap.value.get(id) || `ID:${id}`),
    added,
    removed,
    unchanged,
    isChanged: added.length > 0 || removed.length > 0,
  }
})

const totalChangesCount = computed(() => {
  let count = 0
  if (coverDiff.value?.isChanged) count++
  if (titleDiff.value?.isChanged) count++
  if (slugDiff.value?.isChanged) count++
  if (authorDiff.value?.isChanged) count++
  if (ageRatingDiff.value?.isChanged) count++
  if (statusDiff.value?.isChanged) count++
  if (genresDiff.value?.isChanged) count++
  if (tagsDiff.value?.isChanged) count++
  if (descDiff.value?.isChanged) count++
  return count
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="isOpen && comic && pendingChanges"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="diff-modal-title"
        @click.self="handleClose"
      >
        <div class="modal-card">
          <!-- Header -->
          <div class="modal-header">
            <div class="header-main">
              <div class="header-badge-row">
                <span class="diff-badge">
                  🔍 So sánh bản chỉnh sửa metadata
                </span>
                <span
                  class="diff-summary-pill"
                  :class="totalChangesCount > 0 ? 'pill-warning' : 'pill-muted'"
                >
                  {{ totalChangesCount > 0 ? `${totalChangesCount} trường có thay đổi` : 'Không có thay đổi nào' }}
                </span>
              </div>
              <h2 id="diff-modal-title" class="modal-title">
                {{ comic.title }}
              </h2>
              <p class="comic-subtitle">
                Tác giả: {{ comic.author_name }} · ID: #{{ comic.id }}
              </p>
            </div>

            <button
              type="button"
              class="modal-close-btn"
              aria-label="Đóng cửa sổ so sánh"
              :disabled="isLoading"
              @click="handleClose"
            >
              ✕
            </button>
          </div>

          <!-- Alert for action error if any -->
          <div v-if="actionError" class="modal-error-bar" role="alert">
            <span aria-hidden="true">⚠️</span>
            <span>{{ actionError }}</span>
          </div>

          <!-- Body -->
          <div class="modal-body">
            <p class="diff-guide">
              Đối chiếu thông tin hiện đang công khai với bản chỉnh sửa do tác giả đề xuất.
              Các trường có thay đổi được đánh dấu nổi bật màu vàng.
            </p>

            <div class="diff-table-container">
              <!-- Cover Image Diff -->
              <div
                class="diff-row"
                :class="{ 'diff-row-changed': coverDiff?.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Ảnh bìa</span>
                  <span
                    class="status-indicator"
                    :class="coverDiff?.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ coverDiff?.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>

                <div class="diff-comparison-grid">
                  <!-- Current Cover -->
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <div class="cover-thumb-wrap">
                      <img
                        v-if="coverDiff?.currentUrl && !oldCoverError"
                        :src="coverDiff.currentUrl"
                        alt="Ảnh bìa hiện tại"
                        class="diff-cover-img"
                        @error="oldCoverError = true"
                      />
                      <div v-else class="thumb-placeholder">
                        <span>📖 Không có ảnh</span>
                      </div>
                    </div>
                  </div>

                  <!-- Proposed Cover -->
                  <div class="diff-col-value" :class="{ 'highlight-proposed': coverDiff?.isChanged }">
                    <span class="value-label">Đề xuất mới:</span>
                    <div class="cover-thumb-wrap">
                      <img
                        v-if="coverDiff?.proposedUrl && !newCoverError"
                        :src="coverDiff.proposedUrl"
                        alt="Ảnh bìa đề xuất"
                        class="diff-cover-img"
                        @error="newCoverError = true"
                      />
                      <div v-else class="thumb-placeholder">
                        <span>📖 Không có ảnh</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Title Diff -->
              <div
                v-if="titleDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': titleDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Tiêu đề truyện</span>
                  <span
                    class="status-indicator"
                    :class="titleDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ titleDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <span class="text-content">{{ titleDiff.current }}</span>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': titleDiff.isChanged }">
                    <span class="value-label">Đề xuất:</span>
                    <span class="text-content font-semibold">{{ titleDiff.proposed }}</span>
                  </div>
                </div>
              </div>

              <!-- Slug Diff -->
              <div
                v-if="slugDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': slugDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Slug định danh</span>
                  <span
                    class="status-indicator"
                    :class="slugDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ slugDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <span class="text-content font-mono">{{ slugDiff.current }}</span>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': slugDiff.isChanged }">
                    <span class="value-label">Đề xuất:</span>
                    <span class="text-content font-mono font-semibold">{{ slugDiff.proposed }}</span>
                  </div>
                </div>
              </div>

              <!-- Author Diff -->
              <div
                v-if="authorDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': authorDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Tác giả gốc</span>
                  <span
                    class="status-indicator"
                    :class="authorDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ authorDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <span class="text-content">{{ authorDiff.current }}</span>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': authorDiff.isChanged }">
                    <span class="value-label">Đề xuất:</span>
                    <span class="text-content font-semibold">{{ authorDiff.proposed }}</span>
                  </div>
                </div>
              </div>

              <!-- Age Rating Diff -->
              <div
                v-if="ageRatingDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': ageRatingDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Độ tuổi phù hợp</span>
                  <span
                    class="status-indicator"
                    :class="ageRatingDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ ageRatingDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <span class="badge-pill">{{ ageRatingDiff.current }}</span>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': ageRatingDiff.isChanged }">
                    <span class="value-label">Đề xuất:</span>
                    <span class="badge-pill font-semibold">{{ ageRatingDiff.proposed }}</span>
                  </div>
                </div>
              </div>

              <!-- Status Diff -->
              <div
                v-if="statusDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': statusDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Tình trạng phát hành</span>
                  <span
                    class="status-indicator"
                    :class="statusDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ statusDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <span class="badge-pill">{{ statusDiff.current }}</span>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': statusDiff.isChanged }">
                    <span class="value-label">Đề xuất:</span>
                    <span class="badge-pill font-semibold">{{ statusDiff.proposed }}</span>
                  </div>
                </div>
              </div>

              <!-- Genres Diff -->
              <div
                v-if="genresDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': genresDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Thể loại</span>
                  <span
                    class="status-indicator"
                    :class="genresDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ genresDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <div class="chips-wrap">
                      <span
                        v-for="g in genresDiff.currentNames"
                        :key="g"
                        class="chip chip-muted"
                      >
                        {{ g }}
                      </span>
                      <span v-if="genresDiff.currentNames.length === 0" class="text-muted">
                        Chưa có thể loại
                      </span>
                    </div>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': genresDiff.isChanged }">
                    <span class="value-label">Đề xuất mới:</span>
                    <div class="chips-wrap">
                      <!-- Added -->
                      <span
                        v-for="g in genresDiff.added"
                        :key="'add-' + g"
                        class="chip chip-added"
                        title="Được thêm mới"
                      >
                        + {{ g }}
                      </span>
                      <!-- Removed -->
                      <span
                        v-for="g in genresDiff.removed"
                        :key="'rem-' + g"
                        class="chip chip-removed"
                        title="Bị gỡ bỏ"
                      >
                        - {{ g }}
                      </span>
                      <!-- Unchanged -->
                      <span
                        v-for="g in genresDiff.unchanged"
                        :key="'unc-' + g"
                        class="chip chip-muted"
                      >
                        {{ g }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Tags Diff -->
              <div
                v-if="tagsDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': tagsDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Tags</span>
                  <span
                    class="status-indicator"
                    :class="tagsDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ tagsDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <div class="chips-wrap">
                      <span
                        v-for="t in tagsDiff.currentNames"
                        :key="t"
                        class="chip chip-muted"
                      >
                        #{{ t }}
                      </span>
                      <span v-if="tagsDiff.currentNames.length === 0" class="text-muted">
                        Chưa có tag
                      </span>
                    </div>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': tagsDiff.isChanged }">
                    <span class="value-label">Đề xuất mới:</span>
                    <div class="chips-wrap">
                      <!-- Added -->
                      <span
                        v-for="t in tagsDiff.added"
                        :key="'add-t-' + t"
                        class="chip chip-added"
                        title="Được thêm mới"
                      >
                        + #{{ t }}
                      </span>
                      <!-- Removed -->
                      <span
                        v-for="t in tagsDiff.removed"
                        :key="'rem-t-' + t"
                        class="chip chip-removed"
                        title="Bị gỡ bỏ"
                      >
                        - #{{ t }}
                      </span>
                      <!-- Unchanged -->
                      <span
                        v-for="t in tagsDiff.unchanged"
                        :key="'unc-t-' + t"
                        class="chip chip-muted"
                      >
                        #{{ t }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Description Diff -->
              <div
                v-if="descDiff"
                class="diff-row"
                :class="{ 'diff-row-changed': descDiff.isChanged }"
              >
                <div class="diff-col-field">
                  <span class="field-title">Mô tả tóm tắt</span>
                  <span
                    class="status-indicator"
                    :class="descDiff.isChanged ? 'text-warning' : 'text-muted'"
                  >
                    {{ descDiff.isChanged ? '● Thay đổi' : '○ Không đổi' }}
                  </span>
                </div>
                <div class="diff-comparison-grid">
                  <div class="diff-col-value">
                    <span class="value-label">Hiện tại:</span>
                    <p class="text-desc">{{ descDiff.current || '(Trống)' }}</p>
                  </div>
                  <div class="diff-col-value" :class="{ 'highlight-proposed': descDiff.isChanged }">
                    <span class="value-label">Đề xuất:</span>
                    <p class="text-desc font-semibold">{{ descDiff.proposed || '(Trống)' }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer Actions -->
          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-outline"
              :disabled="isLoading"
              @click="handleClose"
            >
              Đóng
            </button>

            <div class="action-buttons-group">
              <button
                type="button"
                class="btn btn-danger"
                :disabled="isLoading"
                @click="handleReject"
              >
                <span>✕ Từ chối bản chỉnh sửa</span>
              </button>
              <button
                type="button"
                class="btn btn-success"
                :disabled="isLoading"
                @click="handleApprove"
              >
                <span v-if="isLoading" class="btn-spinner" aria-hidden="true"></span>
                <span>{{ isLoading ? 'Đang duyệt...' : '✓ Duyệt áp dụng bản chỉnh sửa' }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1050;
  background-color: rgba(5, 9, 18, 0.85);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
  animation: fadeIn 0.2s ease;
}

.modal-card {
  width: 100%;
  max-width: 960px;
  max-height: 90vh;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.7);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-md);
  padding: var(--spacing-lg) var(--spacing-xl);
  border-bottom: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
}

.header-main {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
  flex: 1;
}

.header-badge-row {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  flex-wrap: wrap;
}

.diff-badge {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-warning);
}

.diff-summary-pill {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  font-weight: var(--font-weight-medium);
}

.pill-warning {
  background-color: var(--color-warning-soft);
  color: var(--color-warning);
  border: 1px solid rgba(245, 158, 11, 0.3);
}

.pill-muted {
  background-color: var(--color-bg-base);
  color: var(--color-text-muted);
  border: 1px solid var(--color-border);
}

.modal-title {
  margin: 0;
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
}

.comic-subtitle {
  margin: 0;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.modal-close-btn {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: var(--font-size-lg);
  cursor: pointer;
  padding: 4px 8px;
  border-radius: var(--radius-md);
  transition: all var(--transition-fast);
}

.modal-close-btn:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: rgba(255, 255, 255, 0.1);
}

.modal-error-bar {
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: var(--spacing-sm) var(--spacing-xl);
  background-color: rgba(239, 68, 68, 0.15);
  border-bottom: 1px solid rgba(239, 68, 68, 0.3);
  color: var(--color-danger);
  font-size: var(--font-size-xs);
}

.modal-body {
  padding: var(--spacing-xl);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.diff-guide {
  margin: 0;
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.diff-table-container {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.diff-row {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: var(--spacing-md);
  padding: var(--spacing-md);
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  transition: all var(--transition-fast);
}

.diff-row-changed {
  background-color: rgba(245, 158, 11, 0.05);
  border-color: rgba(245, 158, 11, 0.35);
}

@media (max-width: 700px) {
  .diff-row {
    grid-template-columns: 1fr;
  }
}

.diff-col-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.status-indicator {
  font-size: 11px;
}

.text-warning {
  color: var(--color-warning);
}

.text-muted {
  color: var(--color-text-muted);
}

.diff-comparison-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--spacing-md);
}

@media (max-width: 500px) {
  .diff-comparison-grid {
    grid-template-columns: 1fr;
  }
}

.diff-col-value {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: var(--spacing-xs) var(--spacing-sm);
  background-color: var(--color-bg-elevated);
  border-radius: var(--radius-sm);
  border: 1px solid transparent;
}

.diff-col-value.highlight-proposed {
  background-color: rgba(245, 158, 11, 0.1);
  border-color: rgba(245, 158, 11, 0.3);
}

.value-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
}

.text-content {
  font-size: var(--font-size-sm);
  color: var(--color-text-primary);
  word-break: break-word;
}

.font-semibold {
  font-weight: var(--font-weight-semibold);
}

.font-mono {
  font-family: monospace;
}

.badge-pill {
  display: inline-block;
  padding: 2px 8px;
  background-color: var(--color-bg-base);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
}

.cover-thumb-wrap {
  width: 90px;
  aspect-ratio: 3 / 4;
  border-radius: var(--radius-sm);
  overflow: hidden;
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-base);
}

.diff-cover-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: var(--color-text-muted);
  text-align: center;
  padding: 4px;
}

.chips-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  font-size: 11px;
}

.chip-muted {
  background-color: var(--color-bg-base);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

.chip-added {
  background-color: rgba(16, 185, 129, 0.2);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.4);
  font-weight: var(--font-weight-medium);
}

.chip-removed {
  background-color: rgba(239, 68, 68, 0.15);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.3);
  text-decoration: line-through;
}

.text-desc {
  margin: 0;
  font-size: var(--font-size-xs);
  line-height: var(--line-height-normal);
  color: var(--color-text-primary);
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 140px;
  overflow-y: auto;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md) var(--spacing-xl);
  border-top: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  gap: var(--spacing-md);
}

.action-buttons-group {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: var(--spacing-xs) var(--spacing-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  border-radius: var(--radius-md);
  border: 1px solid transparent;
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-outline {
  background-color: transparent;
  border-color: var(--color-border);
  color: var(--color-text-secondary);
}

.btn-outline:hover:not(:disabled) {
  background-color: var(--color-bg-base);
  color: var(--color-text-primary);
}

.btn-danger {
  background-color: var(--color-danger);
  color: #ffffff;
}

.btn-danger:hover:not(:disabled) {
  background-color: #dc2626;
}

.btn-success {
  background-color: #10b981;
  color: #ffffff;
}

.btn-success:hover:not(:disabled) {
  background-color: #059669;
}

.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #ffffff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Modal Animations */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

@keyframes slideUp {
  from {
    transform: translateY(20px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
</style>
