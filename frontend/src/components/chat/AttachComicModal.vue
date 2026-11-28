<script setup lang="ts">
import { ref, watch } from 'vue'
import type { ComicListItem } from '@/types/catalog'
import { CatalogService } from '@/services/catalogService'

const props = defineProps<{
  isOpen: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'select', comic: ComicListItem): void
}>()

const searchQuery = ref('')
const comics = ref<ComicListItem[]>([])
const isLoading = ref(false)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

async function searchComics(keyword: string) {
  isLoading.value = true
  try {
    const res = await CatalogService.getComics({
      q: keyword.trim() || undefined,
      per_page: 15,
    })
    comics.value = res.data || []
  } catch (err) {
    console.error('Lỗi tìm kiếm truyện đính kèm:', err)
    comics.value = []
  } finally {
    isLoading.value = false
  }
}

watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      searchQuery.value = ''
      searchComics('')
    }
  }
)

function handleInput() {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
  debounceTimer = setTimeout(() => {
    searchComics(searchQuery.value)
  }, 300)
}

function handleSelect(comic: ComicListItem) {
  emit('select', comic)
  emit('close')
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="emit('close')">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="attach-comic-title">
      <header class="modal-header">
        <h3 id="attach-comic-title" class="modal-title">📖 Đính kèm truyện tranh</h3>
        <button type="button" class="btn-close" aria-label="Đóng" @click="emit('close')">✕</button>
      </header>

      <div class="modal-search">
        <span class="search-icon" aria-hidden="true">🔍</span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Tìm tên truyện muốn chia sẻ..."
          class="search-input"
          @input="handleInput"
        />
      </div>

      <div class="comics-list" aria-live="polite">
        <div v-if="isLoading" class="loading-state">
          <div class="spinner"></div>
          <span>Đang tìm kiếm truyện...</span>
        </div>

        <div v-else-if="comics.length === 0" class="empty-state">
          <span>Không tìm thấy bộ truyện nào phù hợp.</span>
        </div>

        <div
          v-for="comic in comics"
          v-else
          :key="comic.id"
          class="comic-item"
          role="button"
          tabindex="0"
          @click="handleSelect(comic)"
          @keydown.enter="handleSelect(comic)"
        >
          <div class="comic-thumb">
            <img v-if="comic.cover_url" :src="comic.cover_url" :alt="comic.title" class="cover-img" />
            <span v-else class="placeholder">📖</span>
          </div>
          <div class="comic-meta">
            <h4 class="comic-title">{{ comic.title }}</h4>
            <span class="comic-author">{{ comic.author_name || 'Đang cập nhật' }}</span>
          </div>
          <button type="button" class="btn-select">Chọn</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: var(--spacing-md);
  backdrop-filter: blur(4px);
}

.modal-dialog {
  background-color: var(--color-bg-card, #151e32);
  border: 1px solid var(--color-border, #1e293b);
  border-radius: var(--radius-lg, 12px);
  width: 100%;
  max-width: 480px;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-sm) var(--spacing-md);
  border-bottom: 1px solid var(--color-border, #1e293b);
}

.modal-title {
  margin: 0;
  font-size: var(--font-size-base, 1rem);
  font-weight: 700;
  color: var(--color-text-primary, #f8fafc);
}

.btn-close {
  background: transparent;
  border: none;
  color: var(--color-text-muted, #64748b);
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
}

.modal-search {
  position: relative;
  padding: var(--spacing-sm) var(--spacing-md);
  border-bottom: 1px solid var(--color-border, #1e293b);
}

.search-icon {
  position: absolute;
  left: 24px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 13px;
  color: var(--color-text-muted, #64748b);
}

.search-input {
  width: 100%;
  padding: 8px 12px 8px 32px;
  background: var(--color-bg-surface, #111827);
  border: 1px solid var(--color-border, #1e293b);
  border-radius: var(--radius-md, 8px);
  color: var(--color-text-primary, #f8fafc);
  font-size: var(--font-size-xs, 0.75rem);
}

.search-input:focus {
  outline: none;
  border-color: var(--color-primary, #38bdf8);
}

.comics-list {
  flex: 1;
  overflow-y: auto;
  padding: var(--spacing-sm);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.loading-state,
.empty-state {
  padding: var(--spacing-xl);
  text-align: center;
  color: var(--color-text-muted, #64748b);
  font-size: var(--font-size-xs, 0.75rem);
}

.comic-item {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-sm);
  border-radius: var(--radius-md, 8px);
  background-color: var(--color-bg-surface, #111827);
  cursor: pointer;
  transition: all 0.15s ease;
}

.comic-item:hover {
  background-color: var(--color-bg-elevated, #1f2937);
  border-color: var(--color-primary, #38bdf8);
}

.comic-thumb {
  width: 34px;
  height: 48px;
  border-radius: var(--radius-sm, 4px);
  overflow: hidden;
  background-color: #0b0f19;
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

.comic-meta {
  flex: 1;
  min-width: 0;
}

.comic-title {
  margin: 0;
  font-size: var(--font-size-xs, 0.75rem);
  font-weight: 600;
  color: var(--color-text-primary, #f8fafc);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comic-author {
  font-size: 11px;
  color: var(--color-text-muted, #64748b);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
}

.btn-select {
  padding: 4px 10px;
  border-radius: var(--radius-sm, 6px);
  border: none;
  background: var(--color-primary, #38bdf8);
  color: #0b0f19;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}
</style>
