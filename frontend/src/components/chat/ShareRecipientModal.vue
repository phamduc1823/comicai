<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import type { ShareRecipient } from '@/types/chat'
import { ChatService } from '@/services/chatService'
import { getErrorMessage } from '@/services/httpClient'

const props = defineProps<{
  isOpen: boolean
  comic?: {
    id: number
    title: string
    slug: string
    cover_url?: string | null
    author?: string
  } | null
  post?: {
    id: number
    content: string
    user?: {
      id: number
      name: string
      avatar_url?: string | null
    } | null
  } | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'shared', count: number): void
}>()

const recipients = ref<ShareRecipient[]>([])
const selectedRecipientIds = ref<number[]>([])
const searchQuery = ref('')
const messageBody = ref('')
const isLoadingRecipients = ref(false)
const isSharing = ref(false)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

async function loadRecipients() {
  isLoadingRecipients.value = true
  errorMessage.value = null
  try {
    const list = await ChatService.getShareRecipients()
    recipients.value = list
  } catch (err) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách người nhận.')
  } finally {
    isLoadingRecipients.value = false
  }
}

function initModal() {
  selectedRecipientIds.value = []
  searchQuery.value = ''
  messageBody.value = ''
  errorMessage.value = null
  successMessage.value = null
  loadRecipients()
}

onMounted(() => {
  if (props.isOpen && recipients.value.length === 0 && !isLoadingRecipients.value) {
    initModal()
  }
})

watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      initModal()
    }
  },
  { immediate: true }
)

const filteredRecipients = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  if (!query) return recipients.value
  return recipients.value.filter((r) => r.name.toLowerCase().includes(query))
})

const selectedRecipients = computed(() => {
  return recipients.value.filter((r) => selectedRecipientIds.value.includes(r.id))
})

function toggleRecipient(id: number) {
  const idx = selectedRecipientIds.value.indexOf(id)
  if (idx > -1) {
    selectedRecipientIds.value.splice(idx, 1)
  } else {
    if (selectedRecipientIds.value.length >= 20) {
      errorMessage.value = 'Chỉ có thể chọn tối đa 20 người nhận cùng lúc.'
      return
    }
    selectedRecipientIds.value.push(id)
  }
}

function removeRecipient(id: number) {
  const idx = selectedRecipientIds.value.indexOf(id)
  if (idx > -1) {
    selectedRecipientIds.value.splice(idx, 1)
  }
}

async function handleShare() {
  if (selectedRecipientIds.value.length === 0) {
    errorMessage.value = 'Vui lòng chọn ít nhất một người nhận.'
    return
  }

  isSharing.value = true
  errorMessage.value = null

  try {
    const res = await ChatService.shareToRecipients({
      recipient_ids: [...selectedRecipientIds.value],
      comic_id: props.comic?.id,
      post_id: props.post?.id,
      body: messageBody.value.trim() || undefined,
    })

    successMessage.value = `Đã chia sẻ thành công đến ${res.sent_count} người nhận!`
    setTimeout(() => {
      emit('shared', res.sent_count)
      emit('close')
    }, 900)
  } catch (err) {
    errorMessage.value = getErrorMessage(err, 'Không thể chia sẻ nội dung. Vui lòng thử lại.')
  } finally {
    isSharing.value = false
  }
}
</script>

<template>
  <div v-if="isOpen" class="share-modal-backdrop" @click.self="emit('close')">
    <div class="share-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="share-modal-title">
      <header class="share-modal-header">
        <h3 id="share-modal-title" class="share-title">
          <span>📤 Chia sẻ qua tin nhắn</span>
        </h3>
        <button type="button" class="btn-close" aria-label="Đóng" @click="emit('close')">✕</button>
      </header>

      <!-- Preview Item Card -->
      <div class="preview-item-section">
        <div v-if="comic" class="preview-card preview-comic">
          <div class="preview-thumb">
            <img v-if="comic.cover_url" :src="comic.cover_url" :alt="comic.title" class="preview-img" />
            <span v-else class="preview-icon">📖</span>
          </div>
          <div class="preview-info">
            <span class="preview-badge">Truyện tranh</span>
            <h4 class="preview-title">{{ comic.title }}</h4>
            <span class="preview-author">{{ comic.author || 'Tác giả' }}</span>
          </div>
        </div>

        <div v-else-if="post" class="preview-card preview-post">
          <span class="preview-badge">Bài viết cộng đồng</span>
          <div class="preview-post-author">
            <strong>{{ post.user?.name || 'Thành viên' }}</strong>:
          </div>
          <p class="preview-post-text">{{ post.content }}</p>
        </div>
      </div>

      <!-- Recipient Search & Selected Chips -->
      <div class="recipient-select-section">
        <div v-if="selectedRecipients.length > 0" class="selected-chips-bar">
          <span
            v-for="user in selectedRecipients"
            :key="user.id"
            class="recipient-chip"
          >
            <span>{{ user.name }}</span>
            <button
              type="button"
              class="chip-remove"
              :aria-label="'Bỏ chọn ' + user.name"
              @click.stop="removeRecipient(user.id)"
            >
              ✕
            </button>
          </span>
        </div>

        <div class="search-input-wrapper">
          <span class="search-icon" aria-hidden="true">🔍</span>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Tìm người nhận trong danh bạ tin nhắn..."
            class="search-input"
          />
        </div>
      </div>

      <!-- Recipients List with Checkboxes -->
      <div class="recipients-list" aria-live="polite">
        <div v-if="isLoadingRecipients" class="state-message">
          <div class="spinner"></div>
          <span>Đang tải danh bạ liên hệ...</span>
        </div>

        <div v-else-if="recipients.length === 0" class="state-message">
          <p class="empty-hint">Chưa có người dùng nào trong danh bạ tin nhắn trực tiếp.</p>
          <small class="empty-sub">Bạn chỉ có thể chia sẻ cho người đã có cuộc trò chuyện được chấp nhận.</small>
        </div>

        <div v-else-if="filteredRecipients.length === 0" class="state-message">
          <p class="empty-hint">Không tìm thấy người nhận nào phù hợp.</p>
        </div>

        <label
          v-for="r in filteredRecipients"
          v-else
          :key="r.id"
          class="recipient-item"
          :class="{ selected: selectedRecipientIds.includes(r.id) }"
        >
          <input
            type="checkbox"
            :checked="selectedRecipientIds.includes(r.id)"
            class="recipient-checkbox"
            @change="toggleRecipient(r.id)"
          />
          <div class="recipient-avatar">
            <img v-if="r.avatar_url" :src="r.avatar_url" :alt="r.name" class="avatar-img" />
            <span v-else class="avatar-initial">{{ r.name.charAt(0).toUpperCase() }}</span>
          </div>
          <div class="recipient-name-col">
            <span class="recipient-name">{{ r.name }}</span>
            <span class="recipient-role-label">{{ r.role === 'creator' ? 'Tác giả' : r.role === 'admin' ? 'Admin' : 'Độc giả' }}</span>
          </div>
        </label>
      </div>

      <!-- Optional message textarea -->
      <div class="optional-message-section">
        <textarea
          v-model="messageBody"
          rows="2"
          placeholder="Viết thêm lời nhắn kèm theo (tùy chọn)..."
          class="message-textarea"
          maxlength="1000"
        ></textarea>
      </div>

      <!-- Status alerts -->
      <div v-if="errorMessage" class="share-error-alert" role="alert">
        {{ errorMessage }}
      </div>
      <div v-if="successMessage" class="share-success-alert" role="status">
        {{ successMessage }}
      </div>

      <!-- Modal Footer -->
      <footer class="share-modal-footer">
        <button
          type="button"
          class="btn-cancel"
          :disabled="isSharing"
          @click="emit('close')"
        >
          Hủy
        </button>
        <button
          type="button"
          class="btn-confirm-share"
          :disabled="selectedRecipientIds.length === 0 || isSharing"
          @click="handleShare"
        >
          <span v-if="isSharing">Đang gửi...</span>
          <span v-else>Gửi chia sẻ ({{ selectedRecipientIds.length }})</span>
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.share-modal-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: var(--spacing-md);
  backdrop-filter: blur(4px);
}

.share-modal-dialog {
  background-color: var(--color-bg-card, #151e32);
  border: 1px solid var(--color-border, #1e293b);
  border-radius: var(--radius-lg, 12px);
  width: 100%;
  max-width: 480px;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
}

.share-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-sm) var(--spacing-md);
  border-bottom: 1px solid var(--color-border, #1e293b);
}

.share-title {
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
}

.preview-item-section {
  padding: var(--spacing-xs) var(--spacing-md);
  background-color: var(--color-bg-surface, #111827);
  border-bottom: 1px solid var(--color-border, #1e293b);
}

.preview-card {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: 6px;
  background-color: var(--color-bg-elevated, #1f2937);
  border-radius: var(--radius-md, 8px);
}

.preview-post {
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}

.preview-thumb {
  width: 34px;
  height: 48px;
  border-radius: 4px;
  overflow: hidden;
  background-color: #0b0f19;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.preview-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.preview-info {
  flex: 1;
  min-width: 0;
}

.preview-badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  color: var(--color-primary, #38bdf8);
  background: var(--color-primary-soft, rgba(56, 189, 248, 0.15));
  padding: 1px 6px;
  border-radius: 4px;
  margin-bottom: 2px;
}

.preview-title {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-primary, #f8fafc);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.preview-author {
  font-size: 11px;
  color: var(--color-text-muted, #64748b);
}

.preview-post-text {
  margin: 0;
  font-size: 12px;
  color: var(--color-text-secondary, #94a3b8);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.recipient-select-section {
  padding: var(--spacing-xs) var(--spacing-md);
  border-bottom: 1px solid var(--color-border, #1e293b);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.selected-chips-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  max-height: 60px;
  overflow-y: auto;
}

.recipient-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: var(--color-primary-soft, rgba(56, 189, 248, 0.2));
  color: var(--color-primary, #38bdf8);
  border: 1px solid var(--color-primary, #38bdf8);
  padding: 2px 6px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 600;
}

.chip-remove {
  background: transparent;
  border: none;
  color: inherit;
  font-size: 10px;
  cursor: pointer;
  padding: 0;
}

.search-input-wrapper {
  position: relative;
}

.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 12px;
  color: var(--color-text-muted, #64748b);
}

.search-input {
  width: 100%;
  padding: 6px 10px 6px 28px;
  background: var(--color-bg-surface, #111827);
  border: 1px solid var(--color-border, #1e293b);
  border-radius: 6px;
  color: var(--color-text-primary, #f8fafc);
  font-size: 12px;
}

.search-input:focus {
  outline: none;
  border-color: var(--color-primary, #38bdf8);
}

.recipients-list {
  flex: 1;
  overflow-y: auto;
  padding: var(--spacing-xs) var(--spacing-md);
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 200px;
}

.state-message {
  padding: var(--spacing-md);
  text-align: center;
  color: var(--color-text-muted, #64748b);
  font-size: 12px;
}

.recipient-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border-radius: 6px;
  background-color: var(--color-bg-surface, #111827);
  cursor: pointer;
  transition: all 0.15s ease;
}

.recipient-item:hover {
  background-color: var(--color-bg-elevated, #1f2937);
}

.recipient-item.selected {
  background-color: rgba(56, 189, 248, 0.12);
  border: 1px solid rgba(56, 189, 248, 0.3);
}

.recipient-checkbox {
  cursor: pointer;
}

.recipient-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  overflow: hidden;
  background: var(--color-primary-soft, rgba(56, 189, 248, 0.2));
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.avatar-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar-initial {
  color: var(--color-primary, #38bdf8);
  font-size: 12px;
  font-weight: 700;
}

.recipient-name-col {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 6px;
}

.recipient-name {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-primary, #f8fafc);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.recipient-role-label {
  font-size: 10px;
  color: var(--color-text-muted, #64748b);
}

.optional-message-section {
  padding: var(--spacing-xs) var(--spacing-md);
  border-top: 1px solid var(--color-border, #1e293b);
}

.message-textarea {
  width: 100%;
  background: var(--color-bg-surface, #111827);
  border: 1px solid var(--color-border, #1e293b);
  border-radius: 6px;
  color: var(--color-text-primary, #f8fafc);
  padding: 6px 10px;
  font-size: 12px;
  resize: none;
}

.message-textarea:focus {
  outline: none;
  border-color: var(--color-primary, #38bdf8);
}

.share-error-alert {
  padding: 6px var(--spacing-md);
  background-color: var(--color-danger-soft, rgba(239, 68, 68, 0.15));
  color: var(--color-danger, #ef4444);
  font-size: 11px;
}

.share-success-alert {
  padding: 6px var(--spacing-md);
  background-color: var(--color-success-soft, rgba(16, 185, 129, 0.15));
  color: var(--color-success, #10b981);
  font-size: 11px;
}

.share-modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding: var(--spacing-sm) var(--spacing-md);
  border-top: 1px solid var(--color-border, #1e293b);
  background-color: var(--color-bg-surface, #111827);
}

.btn-cancel {
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid var(--color-border, #1e293b);
  background: transparent;
  color: var(--color-text-secondary, #94a3b8);
  font-size: 12px;
  cursor: pointer;
}

.btn-confirm-share {
  padding: 6px 14px;
  border-radius: 6px;
  border: none;
  background: var(--color-primary, #38bdf8);
  color: #0b0f19;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.btn-confirm-share:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
