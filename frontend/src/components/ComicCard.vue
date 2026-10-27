<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { ComicListItem } from '@/types/catalog'

interface Props {
  comic: ComicListItem
}

const props = defineProps<Props>()
const imageError = ref(false)

const statusInfo = computed(() => {
  switch (props.comic.status) {
    case 'completed':
      return { label: 'Hoàn thành', className: 'status-completed' }
    case 'hiatus':
      return { label: 'Tạm ngưng', className: 'status-hiatus' }
    case 'ongoing':
    default:
      return { label: 'Đang tiến hành', className: 'status-ongoing' }
  }
})

const chaptersLabel = computed(() => {
  const count = props.comic.published_chapters_count
  if (count > 0) {
    return `${count} chương`
  }
  return 'Chưa có chương'
})

const formattedViews = computed(() => {
  const views = props.comic.views_count
  if (!views) return null
  if (views >= 1000000) return (views / 1000000).toFixed(1) + 'M'
  if (views >= 1000) return (views / 1000).toFixed(1) + 'k'
  return views.toString()
})
</script>

<template>
  <!-- Destination route /comics/:slug dependency on CAI-019 -->
  <article class="comic-card">
    <RouterLink
      :to="'/comics/' + comic.slug"
      class="comic-card-link"
      :aria-label="'Truyện ' + comic.title + ' - ' + statusInfo.label + ' - ' + chaptersLabel"
    >
      <!-- Cover Image with Fallback -->
      <div class="cover-wrapper">
        <img
          v-if="comic.cover_url && !imageError"
          :src="comic.cover_url"
          :alt="'Bìa truyện ' + comic.title"
          class="comic-cover"
          loading="lazy"
          @error="imageError = true"
        />
        <div v-else class="comic-cover-fallback" aria-hidden="true">
          <div class="fallback-glow"></div>
          <span class="fallback-icon">📖</span>
          <span class="fallback-title">{{ comic.title }}</span>
        </div>

        <!-- Status Badge -->
        <span class="badge-status" :class="statusInfo.className">
          {{ statusInfo.label }}
        </span>

        <!-- Published Chapters Count Badge -->
        <span class="badge-chapters">
          {{ chaptersLabel }}
        </span>

        <!-- Featured Badge -->
        <span v-if="comic.is_featured" class="badge-featured" title="Truyện nổi bật">
          ★ Hot
        </span>
      </div>

      <!-- Comic Metadata -->
      <div class="comic-info">
        <h3 class="comic-title" :title="comic.title">
          {{ comic.title }}
        </h3>

        <p v-if="comic.author_name" class="comic-author">
          <span class="author-icon" aria-hidden="true">✍</span>
          <span class="author-name">{{ comic.author_name }}</span>
        </p>

        <!-- Genres Tags (first 2) -->
        <div v-if="comic.genres && comic.genres.length > 0" class="comic-genres">
          <span
            v-for="genre in comic.genres.slice(0, 2)"
            :key="genre.id"
            class="genre-chip"
          >
            {{ genre.name }}
          </span>
        </div>

        <!-- Footer Stats: Views -->
        <div v-if="formattedViews" class="comic-stats">
          <span class="stat-item" :title="comic.views_count + ' lượt xem'">
            <span class="stat-icon" aria-hidden="true">👁</span>
            <span>{{ formattedViews }}</span>
          </span>
        </div>
      </div>
    </RouterLink>
  </article>
</template>

<style scoped>
.comic-card {
  position: relative;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  transition: all var(--transition-normal);
  display: flex;
  flex-direction: column;
}

.comic-card:hover {
  background-color: var(--color-bg-card-hover);
  border-color: var(--color-border-accent);
  box-shadow: var(--shadow-glow);
  transform: translateY(-4px);
}

.comic-card-link {
  display: flex;
  flex-direction: column;
  height: 100%;
  color: inherit;
  text-decoration: none;
  outline: none;
}

.comic-card-link:focus-visible {
  box-shadow: inset 0 0 0 2px var(--color-primary);
  border-radius: var(--radius-lg);
}

/* Cover Wrapper (Aspect Ratio ~ 3:4) */
.cover-wrapper {
  position: relative;
  width: 100%;
  aspect-ratio: 3 / 4;
  background-color: var(--color-bg-elevated);
  overflow: hidden;
}

.comic-cover {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform var(--transition-slow);
}

.comic-card:hover .comic-cover {
  transform: scale(1.05);
}

/* Fallback Cover */
.comic-cover-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-sm);
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  text-align: center;
  position: relative;
  overflow: hidden;
}

.fallback-glow {
  position: absolute;
  width: 120px;
  height: 120px;
  background: var(--color-primary-glow);
  border-radius: 50%;
  filter: blur(28px);
}

.fallback-icon {
  font-size: var(--font-size-3xl);
  margin-bottom: var(--spacing-2xs);
  z-index: 1;
}

.fallback-title {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-secondary);
  z-index: 1;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Badges */
.badge-status {
  position: absolute;
  top: var(--spacing-xs);
  left: var(--spacing-xs);
  padding: 2px 8px;
  border-radius: var(--radius-xs);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  backdrop-filter: blur(8px);
  z-index: 2;
  box-shadow: var(--shadow-sm);
}

.status-ongoing {
  background-color: rgba(56, 189, 248, 0.85);
  color: #0b0f19;
}

.status-completed {
  background-color: rgba(16, 185, 129, 0.85);
  color: #ffffff;
}

.status-hiatus {
  background-color: rgba(245, 158, 11, 0.85);
  color: #0b0f19;
}

.badge-chapters {
  position: absolute;
  bottom: var(--spacing-xs);
  right: var(--spacing-xs);
  padding: 2px 8px;
  border-radius: var(--radius-xs);
  font-size: 11px;
  font-weight: var(--font-weight-medium);
  background-color: rgba(11, 15, 25, 0.85);
  color: var(--color-text-primary);
  border: 1px solid var(--color-border);
  backdrop-filter: blur(6px);
  z-index: 2;
}

.badge-featured {
  position: absolute;
  top: var(--spacing-xs);
  right: var(--spacing-xs);
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  background-color: rgba(239, 68, 68, 0.9);
  color: #ffffff;
  z-index: 2;
  box-shadow: 0 0 8px rgba(239, 68, 68, 0.5);
}

/* Info Section */
.comic-info {
  padding: var(--spacing-sm);
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
}

.comic-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  line-height: var(--line-height-tight);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.5em;
  transition: color var(--transition-fast);
}

.comic-card:hover .comic-title {
  color: var(--color-primary);
}

.comic-author {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  display: flex;
  align-items: center;
  gap: 4px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.author-icon {
  font-size: 10px;
  opacity: 0.8;
}

.author-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comic-genres {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 2px;
}

.genre-chip {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: var(--radius-xs);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

.comic-stats {
  margin-top: auto;
  padding-top: 4px;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  font-size: 11px;
  color: var(--color-text-muted);
}

.stat-item {
  display: flex;
  align-items: center;
  gap: 4px;
}

.stat-icon {
  font-size: 10px;
}
</style>
