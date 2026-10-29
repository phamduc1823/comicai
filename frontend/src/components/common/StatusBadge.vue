<script setup lang="ts">
import { computed } from 'vue'
import type { ApprovalStatus, ComicProgressStatus, ChapterStatus } from '@/types/creator'

const props = defineProps<{
  status?: ApprovalStatus | ChapterStatus
  progressStatus?: ComicProgressStatus
  hasPendingChanges?: boolean
}>()

const statusConfig = computed(() => {
  switch (props.status) {
    case 'draft':
      return {
        label: 'Bản nháp',
        icon: '📝',
        className: 'badge-draft',
      }
    case 'pending':
      return {
        label: 'Chờ duyệt',
        icon: '⏳',
        className: 'badge-pending',
      }
    case 'published':
      return {
        label: 'Đã xuất bản',
        icon: '✓',
        className: 'badge-approved',
      }
    case 'approved':
      return {
        label: 'Đã duyệt',
        icon: '✓',
        className: 'badge-approved',
      }
    case 'rejected':
      return {
        label: 'Bị từ chối',
        icon: '✕',
        className: 'badge-rejected',
      }
    default:
      return null
  }
})

const progressConfig = computed(() => {
  switch (props.progressStatus) {
    case 'completed':
      return {
        label: 'Trọn bộ',
        className: 'badge-completed',
      }
    case 'ongoing':
      return {
        label: 'Đang tiến hành',
        className: 'badge-ongoing',
      }
    default:
      return null
  }
})
</script>

<template>
  <div class="badge-group">
    <!-- Approval Status Badge -->
    <span
      v-if="statusConfig"
      class="status-badge"
      :class="statusConfig.className"
      :title="`Trạng thái kiểm duyệt: ${statusConfig.label}`"
    >
      <span class="badge-icon" aria-hidden="true">{{ statusConfig.icon }}</span>
      <span class="badge-text">{{ statusConfig.label }}</span>
    </span>

    <!-- Staged Changes Chip -->
    <span
      v-if="hasPendingChanges"
      class="status-badge badge-changes"
      title="Bộ truyện đang có bản chỉnh sửa chờ Quản trị viên duyệt lại"
    >
      <span class="badge-icon" aria-hidden="true">🔄</span>
      <span class="badge-text">Có bản sửa đổi</span>
    </span>

    <!-- Progress Status Badge -->
    <span
      v-if="progressConfig"
      class="status-badge"
      :class="progressConfig.className"
      :title="`Tiến độ: ${progressConfig.label}`"
    >
      <span class="badge-text">{{ progressConfig.label }}</span>
    </span>
  </div>
</template>

<style scoped>
.badge-group {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--spacing-2xs);
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  line-height: 1.2;
  border-radius: var(--radius-sm);
  white-space: nowrap;
  letter-spacing: 0.02em;
}

.badge-icon {
  font-size: 11px;
}

/* Draft - Neutral Gray */
.badge-draft {
  background-color: rgba(148, 163, 184, 0.14);
  color: #cbd5e1;
  border: 1px solid rgba(148, 163, 184, 0.28);
}

/* Pending - Warning Amber */
.badge-pending {
  background-color: var(--color-warning-soft);
  color: #fbbf24;
  border: 1px solid rgba(245, 158, 11, 0.35);
}

/* Approved / Published - Emerald Success */
.badge-approved {
  background-color: var(--color-success-soft);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.35);
}

/* Rejected - Danger Rose */
.badge-rejected {
  background-color: var(--color-danger-soft);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.35);
}

/* Staged pending changes - Pastel Sky Blue Accent */
.badge-changes {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border: 1px solid rgba(56, 189, 248, 0.4);
}

/* Progress ongoing */
.badge-ongoing {
  background-color: rgba(30, 41, 59, 0.8);
  color: var(--color-text-secondary);
  border: 1px solid var(--color-border);
}

/* Progress completed */
.badge-completed {
  background-color: rgba(14, 165, 233, 0.12);
  color: #38bdf8;
  border: 1px solid rgba(56, 189, 248, 0.25);
}
</style>
