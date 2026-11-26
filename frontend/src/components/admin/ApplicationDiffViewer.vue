<script setup lang="ts">
import { ref, computed } from 'vue'
import type { CreatorApplication, CreatorApplicationHistory } from '@/types/creatorApplication'

interface Props {
  application: CreatorApplication
  histories?: CreatorApplicationHistory[]
}

const props = defineProps<Props>()

// Available versions sorted ascending (1, 2, 3...)
const availableHistories = computed(() => {
  const list = props.histories ? [...props.histories] : []
  return list.sort((a, b) => a.version - b.version)
})

// Extract distinct version numbers
const versionOptions = computed(() => {
  const versions = availableHistories.value.map((h) => h.version)
  const unique = Array.from(new Set(versions)).sort((a, b) => a - b)
  return unique
})

// Base version (old) and Target version (new)
const baseVersion = ref<number>(
  versionOptions.value.length > 1 ? versionOptions.value[versionOptions.value.length - 2] : 1
)
const targetVersion = ref<number>(
  versionOptions.value.length > 0 ? versionOptions.value[versionOptions.value.length - 1] : 1
)

// Helper to extract snapshot payload of a specific version
function getSnapshotForVersion(v: number): {
  pen_name: string
  bio: string
  reason: string
  sample_works: string[]
  status: string
  rejection_reason: string | null
  action_type?: string
  action_at?: string
  reviewer_name?: string
} {
  // Find the history entry matching this version
  const entry = availableHistories.value.find((h) => h.version === v)
  if (!entry) {
    return {
      pen_name: props.application.pen_name || '',
      bio: props.application.bio || '',
      reason: props.application.reason || '',
      sample_works: props.application.sample_works || [],
      status: props.application.status,
      rejection_reason: props.application.rejection_reason,
    }
  }

  const snap = (entry.snapshot_data as Record<string, unknown>) || {}

  return {
    pen_name: (snap.pen_name as string) || entry.pen_name || '',
    bio: (snap.bio as string) || entry.bio || '',
    reason: (snap.reason as string) || entry.reason || '',
    sample_works:
      (snap.sample_works as string[]) || (entry.sample_works as string[]) || [],
    status: (snap.status as string) || entry.status || '',
    rejection_reason:
      (snap.rejection_reason as string) || entry.rejection_reason || null,
    action_type: entry.action_type,
    action_at: entry.action_at,
  }
}

const baseSnapshot = computed(() => getSnapshotForVersion(baseVersion.value))
const targetSnapshot = computed(() => getSnapshotForVersion(targetVersion.value))

// Diff calculations
const isPenNameChanged = computed(() => {
  return baseSnapshot.value.pen_name.trim() !== targetSnapshot.value.pen_name.trim()
})

const isBioChanged = computed(() => {
  return baseSnapshot.value.bio.trim() !== targetSnapshot.value.bio.trim()
})

const isReasonChanged = computed(() => {
  return baseSnapshot.value.reason.trim() !== targetSnapshot.value.reason.trim()
})

const isStatusChanged = computed(() => {
  return baseSnapshot.value.status !== targetSnapshot.value.status
})

// Compare sample works arrays
const sampleWorksDiff = computed(() => {
  const baseLinks = baseSnapshot.value.sample_works || []
  const targetLinks = targetSnapshot.value.sample_works || []

  const added = targetLinks.filter((l) => !baseLinks.includes(l))
  const removed = baseLinks.filter((l) => !targetLinks.includes(l))
  const unchanged = targetLinks.filter((l) => baseLinks.includes(l))

  const isChanged = added.length > 0 || removed.length > 0

  return {
    added,
    removed,
    unchanged,
    isChanged,
  }
})

const totalChangesCount = computed(() => {
  let count = 0
  if (isPenNameChanged.value) count++
  if (isBioChanged.value) count++
  if (isReasonChanged.value) count++
  if (sampleWorksDiff.value.isChanged) count++
  if (isStatusChanged.value) count++
  return count
})

function formatDate(iso: string | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}
</script>

<template>
  <div class="diff-viewer">
    <div class="diff-header">
      <div class="diff-title-row">
        <h3 class="diff-title">So sánh các phiên bản hồ sơ (Diff Viewer)</h3>
        <span v-if="totalChangesCount > 0" class="changes-count-badge">
          {{ totalChangesCount }} trường thay đổi
        </span>
        <span v-else class="changes-count-badge no-changes">
          Không có thay đổi giữa 2 phiên bản
        </span>
      </div>

      <!-- Version Selectors -->
      <div class="version-selectors-row">
        <div class="selector-group">
          <label for="base-version-select" class="selector-label">Phiên bản gốc (Cũ):</label>
          <select id="base-version-select" v-model="baseVersion" class="version-select">
            <option v-for="v in versionOptions" :key="'base-' + v" :value="v">
              Phiên bản v{{ v }}
            </option>
          </select>
        </div>

        <span class="diff-arrow" aria-hidden="true">➔</span>

        <div class="selector-group">
          <label for="target-version-select" class="selector-label">Phiên bản so sánh (Mới):</label>
          <select id="target-version-select" v-model="targetVersion" class="version-select">
            <option v-for="v in versionOptions" :key="'target-' + v" :value="v">
              Phiên bản v{{ v }}
            </option>
          </select>
        </div>
      </div>
    </div>

    <!-- Notice if comparing same version -->
    <div v-if="baseVersion === targetVersion" class="diff-same-version-note" role="note">
      Đang chọn cùng phiên bản v{{ baseVersion }}. Vui lòng chọn 2 phiên bản khác nhau để thấy sự khác biệt.
    </div>

    <!-- Comparison Fields Grid -->
    <div class="diff-fields-container">
      <!-- 1. Pen Name -->
      <div class="diff-field-card" :class="{ 'is-modified': isPenNameChanged }">
        <div class="field-header">
          <span class="field-name">Bút danh tác giả</span>
          <span v-if="isPenNameChanged" class="delta-tag tag-changed">Đã sửa đổi</span>
          <span v-else class="delta-tag tag-same">Giống nhau</span>
        </div>
        <div class="field-diff-content">
          <div class="diff-col col-old">
            <span class="col-version-label">v{{ baseVersion }}</span>
            <div class="diff-box" :class="{ 'text-strikethrough': isPenNameChanged }">
              {{ baseSnapshot.pen_name || '—' }}
            </div>
          </div>
          <div class="diff-col col-new">
            <span class="col-version-label">v{{ targetVersion }}</span>
            <div class="diff-box" :class="{ 'text-highlight-green': isPenNameChanged }">
              {{ targetSnapshot.pen_name || '—' }}
            </div>
          </div>
        </div>
      </div>

      <!-- 2. Bio -->
      <div class="diff-field-card" :class="{ 'is-modified': isBioChanged }">
        <div class="field-header">
          <span class="field-name">Giới thiệu bản thân (Bio)</span>
          <span v-if="isBioChanged" class="delta-tag tag-changed">Đã sửa đổi</span>
          <span v-else class="delta-tag tag-same">Giống nhau</span>
        </div>
        <div class="field-diff-content">
          <div class="diff-col col-old">
            <span class="col-version-label">v{{ baseVersion }}</span>
            <div class="diff-box diff-box-multiline" :class="{ 'box-removed-tint': isBioChanged }">
              {{ baseSnapshot.bio || '—' }}
            </div>
          </div>
          <div class="diff-col col-new">
            <span class="col-version-label">v{{ targetVersion }}</span>
            <div class="diff-box diff-box-multiline" :class="{ 'box-added-tint': isBioChanged }">
              {{ targetSnapshot.bio || '—' }}
            </div>
          </div>
        </div>
      </div>

      <!-- 3. Reason -->
      <div class="diff-field-card" :class="{ 'is-modified': isReasonChanged }">
        <div class="field-header">
          <span class="field-name">Lý do đăng ký Creator</span>
          <span v-if="isReasonChanged" class="delta-tag tag-changed">Đã sửa đổi</span>
          <span v-else class="delta-tag tag-same">Giống nhau</span>
        </div>
        <div class="field-diff-content">
          <div class="diff-col col-old">
            <span class="col-version-label">v{{ baseVersion }}</span>
            <div class="diff-box diff-box-multiline" :class="{ 'box-removed-tint': isReasonChanged }">
              {{ baseSnapshot.reason || '—' }}
            </div>
          </div>
          <div class="diff-col col-new">
            <span class="col-version-label">v{{ targetVersion }}</span>
            <div class="diff-box diff-box-multiline" :class="{ 'box-added-tint': isReasonChanged }">
              {{ targetSnapshot.reason || '—' }}
            </div>
          </div>
        </div>
      </div>

      <!-- 4. Sample Works Links -->
      <div class="diff-field-card" :class="{ 'is-modified': sampleWorksDiff.isChanged }">
        <div class="field-header">
          <span class="field-name">Liên kết tác phẩm mẫu</span>
          <span v-if="sampleWorksDiff.isChanged" class="delta-tag tag-changed">Đã sửa đổi</span>
          <span v-else class="delta-tag tag-same">Giống nhau</span>
        </div>
        <div class="field-diff-content">
          <div class="diff-col col-old">
            <span class="col-version-label">v{{ baseVersion }} ({{ (baseSnapshot.sample_works || []).length }} links)</span>
            <div class="diff-box">
              <ul v-if="baseSnapshot.sample_works && baseSnapshot.sample_works.length > 0" class="sample-links">
                <li
                  v-for="(link, idx) in baseSnapshot.sample_works"
                  :key="idx"
                  :class="{ 'item-removed': sampleWorksDiff.removed.includes(link) }"
                >
                  <span v-if="sampleWorksDiff.removed.includes(link)" class="delta-indicator removed">-</span>
                  <a :href="link" target="_blank" rel="noopener noreferrer" class="link-text">{{ link }}</a>
                </li>
              </ul>
              <span v-else class="text-muted">Không có tác phẩm mẫu</span>
            </div>
          </div>
          <div class="diff-col col-new">
            <span class="col-version-label">v{{ targetVersion }} ({{ (targetSnapshot.sample_works || []).length }} links)</span>
            <div class="diff-box">
              <ul v-if="targetSnapshot.sample_works && targetSnapshot.sample_works.length > 0" class="sample-links">
                <li
                  v-for="(link, idx) in targetSnapshot.sample_works"
                  :key="idx"
                  :class="{ 'item-added': sampleWorksDiff.added.includes(link) }"
                >
                  <span v-if="sampleWorksDiff.added.includes(link)" class="delta-indicator added">+</span>
                  <a :href="link" target="_blank" rel="noopener noreferrer" class="link-text">{{ link }}</a>
                </li>
              </ul>
              <span v-else class="text-muted">Không có tác phẩm mẫu</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 5. Status & Rejection Reason (Progression) -->
      <div v-if="baseSnapshot.status || targetSnapshot.status" class="diff-field-card">
        <div class="field-header">
          <span class="field-name">Trạng thái hồ sơ tại thời điểm snapshot</span>
        </div>
        <div class="field-diff-content">
          <div class="diff-col col-old">
            <span class="col-version-label">v{{ baseVersion }}</span>
            <div class="diff-box">
              <p><strong>Trạng thái:</strong> {{ baseSnapshot.status }}</p>
              <p v-if="baseSnapshot.rejection_reason" class="text-danger">
                <strong>Lý do từ chối:</strong> {{ baseSnapshot.rejection_reason }}
              </p>
              <p v-if="baseSnapshot.action_at" class="text-muted text-xs">
                Thời điểm: {{ formatDate(baseSnapshot.action_at) }}
              </p>
            </div>
          </div>
          <div class="diff-col col-new">
            <span class="col-version-label">v{{ targetVersion }}</span>
            <div class="diff-box">
              <p><strong>Trạng thái:</strong> {{ targetSnapshot.status }}</p>
              <p v-if="targetSnapshot.rejection_reason" class="text-danger">
                <strong>Lý do từ chối:</strong> {{ targetSnapshot.rejection_reason }}
              </p>
              <p v-if="targetSnapshot.action_at" class="text-muted text-xs">
                Thời điểm: {{ formatDate(targetSnapshot.action_at) }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.diff-viewer {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 8px;
  padding: 20px;
  margin-top: 20px;
}

.diff-header {
  border-bottom: 1px solid #30363d;
  padding-bottom: 16px;
  margin-bottom: 20px;
}

.diff-title-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.diff-title {
  font-size: 16px;
  font-weight: 600;
  color: #f0f6fc;
  margin: 0;
}

.changes-count-badge {
  font-size: 12px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 12px;
  background-color: rgba(56, 139, 253, 0.15);
  color: #58a6ff;
  border: 1px solid rgba(56, 139, 253, 0.3);
}

.changes-count-badge.no-changes {
  background-color: rgba(139, 148, 158, 0.15);
  color: #8b949e;
  border-color: rgba(139, 148, 158, 0.3);
}

.version-selectors-row {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.selector-group {
  display: flex;
  align-items: center;
  gap: 8px;
}

.selector-label {
  font-size: 13px;
  color: #8b949e;
}

.version-select {
  padding: 6px 12px;
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  color: #e6edf3;
  font-size: 13px;
}

.diff-arrow {
  color: #58a6ff;
  font-size: 16px;
}

.diff-same-version-note {
  background-color: rgba(227, 179, 65, 0.1);
  border: 1px solid rgba(227, 179, 65, 0.3);
  color: #e3b341;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 16px;
}

.diff-fields-container {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.diff-field-card {
  background-color: #0d1117;
  border: 1px solid #30363d;
  border-radius: 6px;
  padding: 14px;
  transition: border-color 0.2s ease;
}

.diff-field-card.is-modified {
  border-color: rgba(56, 139, 253, 0.5);
  background-color: rgba(56, 139, 253, 0.02);
}

.field-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.field-name {
  font-size: 13px;
  font-weight: 600;
  color: #f0f6fc;
}

.delta-tag {
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
  font-weight: 600;
}

.tag-changed {
  background-color: rgba(227, 179, 65, 0.2);
  color: #e3b341;
}

.tag-same {
  background-color: rgba(139, 148, 158, 0.15);
  color: #8b949e;
}

.field-diff-content {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.diff-col {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.col-version-label {
  font-size: 11px;
  color: #8b949e;
  font-weight: 600;
  text-transform: uppercase;
}

.diff-box {
  background-color: #161b22;
  border: 1px solid #30363d;
  border-radius: 4px;
  padding: 8px 12px;
  font-size: 13px;
  color: #c9d1d9;
  min-height: 38px;
  box-sizing: border-box;
}

.diff-box-multiline {
  white-space: pre-wrap;
  line-height: 1.5;
}

.text-strikethrough {
  text-decoration: line-through;
  color: #f85149;
  background-color: rgba(248, 81, 73, 0.08);
}

.text-highlight-green {
  color: #3fb950;
  font-weight: 600;
  background-color: rgba(46, 160, 67, 0.08);
}

.box-removed-tint {
  background-color: rgba(248, 81, 73, 0.08);
  border-color: rgba(248, 81, 73, 0.3);
}

.box-added-tint {
  background-color: rgba(46, 160, 67, 0.08);
  border-color: rgba(46, 160, 67, 0.3);
}

.sample-links {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.sample-links li {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.link-text {
  color: #58a6ff;
  text-decoration: none;
  word-break: break-all;
}

.link-text:hover {
  text-decoration: underline;
}

.delta-indicator {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  font-weight: bold;
  font-size: 11px;
}

.delta-indicator.added {
  background-color: rgba(46, 160, 67, 0.2);
  color: #3fb950;
}

.delta-indicator.removed {
  background-color: rgba(248, 81, 73, 0.2);
  color: #f85149;
}

.item-added {
  background-color: rgba(46, 160, 67, 0.1);
  padding: 4px 6px;
  border-radius: 4px;
}

.item-removed {
  background-color: rgba(248, 81, 73, 0.1);
  padding: 4px 6px;
  border-radius: 4px;
}

.text-muted {
  color: #8b949e;
}

.text-danger {
  color: #f85149;
}

.text-xs {
  font-size: 11px;
}

@media (max-width: 768px) {
  .field-diff-content {
    grid-template-columns: 1fr;
  }
}
</style>
