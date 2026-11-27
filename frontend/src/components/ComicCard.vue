<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { ComicListItem } from '@/types/catalog'

interface Props {
  comic: ComicListItem
  searchMode?: 'normal' | 'ai'
}

const props = withDefaults(defineProps<Props>(), {
  searchMode: 'normal',
})

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
  const count = props.comic.published_chapters_count ?? 0
  if (count > 0) {
    return `${count} chương`
  }
  return 'Chưa có chương'
})

const formattedViews = computed(() => {
  const views = props.comic.views_count
  if (views === null || views === undefined) return null
  const num = Number(views)
  if (isNaN(num) || num <= 0) return null

  // 1 tỉ (1 billion) -> 1b
  if (num >= 1_000_000_000) {
    const val = (num / 1_000_000_000).toFixed(1).replace(/\.0$/, '')
    return `${val}b`
  }
  // 1 triệu (1 million) -> 1m
  if (num >= 1_000_000) {
    const val = (num / 1_000_000).toFixed(1).replace(/\.0$/, '')
    return `${val}m`
  }
  // 1 nghìn (1 thousand) -> 1k
  if (num >= 1_000) {
    const val = (num / 1_000).toFixed(1).replace(/\.0$/, '')
    return `${val}k`
  }

  return num.toString()
})

/**
 * AI Relevance Indicators & Badges (Package 10.4D)
 */
const isAiMode = computed(() => props.searchMode === 'ai')

const hasValidScore = computed(() => {
  return (
    isAiMode.value &&
    props.comic.is_fallback !== true &&
    typeof props.comic.score === 'number' &&
    props.comic.score > 0
  )
})

const matchPercentage = computed(() => {
  if (typeof props.comic.score !== 'number') return null
  return Math.round(props.comic.score * 100)
})

const badgeConfig = computed(() => {
  if (!hasValidScore.value || matchPercentage.value === null) return null

  const type = props.comic.match_type

  if (type === 'clue') {
    return {
      className: 'badge-clue',
      icon: '🎯',
      label: `Điểm phù hợp ${matchPercentage.value}%`,
      title: `Điểm phù hợp từ khóa/manh mối: ${matchPercentage.value}%`,
    }
  }

  if (type === 'hybrid') {
    return {
      className: 'badge-hybrid',
      icon: '✨',
      label: `Điểm phù hợp ${matchPercentage.value}%`,
      title: `Điểm phù hợp xếp hạng AI kết hợp: ${matchPercentage.value}%`,
    }
  }

  if (type === 'semantic') {
    return {
      className: 'badge-semantic',
      icon: '✨',
      label: `Điểm phù hợp ${matchPercentage.value}%`,
      title: `Điểm phù hợp ngữ nghĩa AI: ${matchPercentage.value}%`,
    }
  }

  // Fallback when match_type is missing/null/undefined:
  // Neutral badge class and tooltip, not asserting semantic/clue/hybrid!
  return {
    className: 'badge-neutral',
    icon: '✨',
    label: `Điểm phù hợp ${matchPercentage.value}%`,
    title: `Điểm xếp hạng tìm kiếm AI: ${matchPercentage.value}%`,
  }
})

const coverSrc = computed(() => {
  if (props.comic.cover_url) return props.comic.cover_url
  if (props.comic.cover_path) {
    if (props.comic.cover_path.startsWith('http://') || props.comic.cover_path.startsWith('https://')) {
      return props.comic.cover_path
    }
    return `/storage/${props.comic.cover_path}`
  }
  return ''
})
</script>

<template>
  <!-- Destination route /comics/:slug dependency on CAI-019 -->
  <article class="comic-card" :class="{ 'card-ai-matched': Boolean(badgeConfig) }">
    <RouterLink
      :to="'/comics/' + comic.slug"
      class="comic-card-link"
      :aria-label="'Truyện ' + comic.title + ' - ' + statusInfo.label + ' - ' + chaptersLabel"
    >
      <!-- Cover Image with Fallback -->
      <div class="cover-wrapper">
        <img
          v-if="coverSrc && !imageError"
          :src="coverSrc"
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

        <!-- AI Relevance Score Badge -->
        <span
          v-if="badgeConfig"
          class="badge-ai-match"
          :class="badgeConfig.className"
          :title="badgeConfig.title"
        >
          <span class="badge-ai-sparkle" aria-hidden="true">{{ badgeConfig.icon }}</span>
          <span>{{ badgeConfig.label }}</span>
        </span>

        <!-- Published Chapters Count Badge -->
        <span class="badge-chapters">
          {{ chaptersLabel }}
        </span>

        <!-- Featured Badge -->
        <span
          v-if="comic.is_featured"
          class="badge-featured"
          :class="{ 'badge-featured-offset': Boolean(badgeConfig) }"
          title="Truyện nổi bật"
        >
          ★ Hot
        </span>
      </div>

      <!-- Comic Metadata -->
      <div class="comic-info">
        <h3 class="comic-title" :title="comic.title">
          {{ comic.title }}
        </h3>

        <!-- AI Search Match Reason Snippet -->
        <div
          v-if="isAiMode && comic.match_reason"
          class="comic-match-reason"
          :title="comic.match_reason"
        >
          <span class="reason-icon" aria-hidden="true">💡</span>
          <span class="reason-text">{{ comic.match_reason }}</span>
        </div>

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

        <!-- Footer Stats: Views (Always maintains uniform height even if 0 or null) -->
        <div class="comic-stats">
          <span
            v-if="formattedViews"
            class="stat-item"
            :title="(comic.views_count || 0) + ' lượt xem'"
          >
            <svg
              class="stat-icon-svg"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
              <circle cx="12" cy="12" r="3" />
            </svg>
            <span class="stat-count">{{ formattedViews }}</span>
          </span>
          <span v-else class="stat-item-empty" aria-hidden="true">&nbsp;</span>
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
  transition: top var(--transition-fast);
}

.badge-featured-offset {
  top: calc(var(--spacing-xs) + 24px);
}

/* AI Match Badges (Package 10.4D) */
.badge-ai-match {
  position: absolute;
  top: var(--spacing-xs);
  right: var(--spacing-xs);
  padding: 2px 7px;
  border-radius: var(--radius-xs);
  font-size: 10px;
  font-weight: var(--font-weight-bold);
  display: flex;
  align-items: center;
  gap: 3px;
  z-index: 2;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(6px);
  user-select: none;
}

.badge-semantic {
  background: linear-gradient(135deg, rgba(56, 189, 248, 0.95), rgba(14, 165, 233, 0.95));
  color: #0b0f19;
  border: 1px solid rgba(255, 255, 255, 0.35);
  box-shadow: 0 0 10px var(--color-primary-glow);
}

.badge-hybrid {
  background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(20, 184, 166, 0.95));
  color: #0b0f19;
  border: 1px solid rgba(255, 255, 255, 0.35);
  box-shadow: 0 0 10px rgba(14, 165, 233, 0.35);
}

.badge-clue {
  background: linear-gradient(135deg, rgba(16, 185, 129, 0.95), rgba(5, 150, 105, 0.95));
  color: #ffffff;
  border: 1px solid rgba(255, 255, 255, 0.25);
  box-shadow: 0 0 8px rgba(16, 185, 129, 0.35);
}

.badge-neutral {
  background: linear-gradient(135deg, rgba(51, 65, 85, 0.95), rgba(30, 41, 59, 0.95));
  color: #f1f5f9;
  border: 1px solid rgba(148, 163, 184, 0.35);
  box-shadow: 0 0 8px rgba(0, 0, 0, 0.35);
}

.badge-ai-sparkle {
  font-size: 10px;
}

.card-ai-matched {
  border-color: rgba(56, 189, 248, 0.35);
}

.card-ai-matched:hover {
  border-color: var(--color-primary);
  box-shadow: 0 0 20px var(--color-primary-glow);
}

.comic-match-reason {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 3px 6px;
  background-color: var(--color-primary-soft);
  border: 1px solid rgba(56, 189, 248, 0.25);
  border-radius: var(--radius-xs);
  font-size: 11px;
  color: var(--color-primary);
  margin-top: 2px;
  overflow: hidden;
}

.reason-icon {
  font-size: 10px;
  flex-shrink: 0;
}

.reason-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Info Section */
.comic-info {
  padding: var(--spacing-sm);
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
  min-height: 120px;
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
  height: 2.6em;
  min-height: 2.6em;
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
  height: 18px;
  min-height: 18px;
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
  height: 22px;
  min-height: 22px;
  overflow: hidden;
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
  justify-content: flex-start;
  font-size: 11px;
  color: var(--color-text-muted);
  height: 22px;
  min-height: 22px;
  box-sizing: border-box;
}

.stat-item {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--color-text-secondary, #cbd5e1);
  line-height: 1;
}

.stat-icon-svg {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  stroke: var(--color-primary, #38bdf8);
  display: block;
}

.stat-count {
  font-weight: var(--font-weight-medium, 500);
  color: var(--color-text-secondary, #94a3b8);
}

.stat-item-empty {
  display: inline-block;
  height: 14px;
  visibility: hidden;
}
</style>
