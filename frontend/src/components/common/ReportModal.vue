<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ReportService } from '@/services/reportService'
import { getErrorMessage } from '@/services/httpClient'
import type { ReportableType, StoreReportPayload, Report } from '@/types/report'

interface Props {
  isOpen: boolean
  reportableType: ReportableType
  reportableId: number
  targetTitle?: string
}

const props = withDefaults(defineProps<Props>(), {
  targetTitle: '',
})

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'submitted', report: Report): void
}>()

const router = useRouter()
const authStore = useAuthStore()

// Preset reasons matching Vietnamese moderation guidelines
const PRESET_REASONS = [
  'Spam / Quảng cáo trái phép',
  'Nội dung phản cảm / Đồi trụy',
  'Vi phạm bản quyền',
  'Quấy rối / Đả kích cá nhân',
  'Ngôn từ thù địch / Xúc phạm',
  'Thông tin sai lệch / Giả mạo',
  'Khác',
]

// State
const selectedReason = ref(PRESET_REASONS[0])
const customReason = ref('')
const description = ref('')
const isSubmitting = ref(false)
const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

// Computed final reason
const finalReason = computed(() => {
  if (selectedReason.value === 'Khác') {
    return customReason.value.trim()
  }
  return selectedReason.value
})

const typeName = computed(() => {
  switch (props.reportableType) {
    case 'post':
      return 'bài viết'
    case 'comment':
      return 'bình luận'
    case 'comic':
      return 'bộ truyện'
    case 'chapter':
      return 'chương truyện'
    case 'user':
      return 'người dùng'
    default:
      return 'nội dung'
  }
})

// Reset on open
watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      selectedReason.value = PRESET_REASONS[0]
      customReason.value = ''
      description.value = ''
      errorMessage.value = null
      successMessage.value = null
      isSubmitting.value = false
    }
  }
)

function handleClose() {
  if (isSubmitting.value) return
  emit('close')
}

function handleLoginRedirect() {
  emit('close')
  router.push({
    path: '/login',
    query: { redirect: router.currentRoute.value.fullPath },
  })
}

async function handleSubmit() {
  if (!authStore.isAuthenticated) {
    handleLoginRedirect()
    return
  }

  const reason = finalReason.value
  if (!reason) {
    errorMessage.value = 'Vui lòng chọn hoặc nhập lý do báo cáo vi phạm.'
    return
  }

  if (reason.length > 50) {
    errorMessage.value = 'Lý do báo cáo không được vượt quá 50 ký tự.'
    return
  }

  if (description.value.length > 1000) {
    errorMessage.value = 'Mô tả chi tiết không được vượt quá 1000 ký tự.'
    return
  }

  isSubmitting.value = true
  errorMessage.value = null
  successMessage.value = null

  try {
    const payload: StoreReportPayload = {
      reportable_type: props.reportableType,
      reportable_id: props.reportableId,
      reason,
      description: description.value.trim() || null,
    }

    const res = await ReportService.submitReport(payload)
    successMessage.value = res.message || 'Gửi báo cáo vi phạm thành công. BQT sẽ sớm xem xét xử lý.'

    setTimeout(() => {
      emit('submitted', res.data)
      emit('close')
    }, 1200)
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể gửi báo cáo vi phạm. Vui lòng thử lại.')
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <Transition name="fade">
    <div v-if="isOpen" class="report-modal-backdrop" @click="handleClose">
      <div class="report-modal-card" @click.stop>
        <!-- Modal Header -->
        <div class="report-modal-header">
          <div class="header-title-row">
            <span class="report-icon" aria-hidden="true">🚩</span>
            <h2 class="report-title">Báo cáo vi phạm {{ typeName }}</h2>
          </div>
          <button
            type="button"
            class="report-close-btn"
            aria-label="Đóng"
            :disabled="isSubmitting"
            @click="handleClose"
          >
            ✕
          </button>
        </div>

        <!-- Target Info -->
        <div v-if="targetTitle" class="target-context-box">
          <span class="context-label">Nội dung báo cáo:</span>
          <span class="context-title font-medium">{{ targetTitle }}</span>
        </div>

        <!-- Non-authenticated Gate -->
        <div v-if="!authStore.isAuthenticated" class="auth-gate-box">
          <span class="auth-icon">🔒</span>
          <p class="auth-text">
            Bạn cần đăng nhập vào tài khoản BlueToon để gửi báo cáo vi phạm.
          </p>
          <button
            type="button"
            class="btn-login-prompt"
            @click="handleLoginRedirect"
          >
            Đăng nhập ngay
          </button>
        </div>

        <!-- Form Body -->
        <form v-else @submit.prevent="handleSubmit" class="report-form">
          <div class="report-form-body">
            <!-- Alert Messages -->
            <div v-if="successMessage" class="alert-success">
              <span>✅ {{ successMessage }}</span>
            </div>
            <div v-if="errorMessage" class="alert-error">
              <span>⚠️ {{ errorMessage }}</span>
            </div>

            <!-- Reason Selection -->
            <div class="form-group">
              <label class="group-label required">Lý do báo cáo:</label>
              <div class="reasons-list">
                <label
                  v-for="r in PRESET_REASONS"
                  :key="r"
                  class="reason-option"
                  :class="{ selected: selectedReason === r }"
                >
                  <input
                    v-model="selectedReason"
                    type="radio"
                    :value="r"
                    name="report-reason"
                    class="sr-only"
                    :disabled="isSubmitting || !!successMessage"
                  />
                  <span class="radio-indicator"></span>
                  <span class="reason-text">{{ r }}</span>
                </label>
              </div>

              <!-- Custom reason text when "Khác" is selected -->
              <div v-if="selectedReason === 'Khác'" class="custom-reason-input-wrap">
                <input
                  v-model="customReason"
                  type="text"
                  maxlength="50"
                  placeholder="Nhập lý do ngắn gọn (tối đa 50 ký tự)..."
                  class="form-input"
                  :disabled="isSubmitting || !!successMessage"
                  required
                />
                <span class="char-count">{{ customReason.length }}/50</span>
              </div>
            </div>

            <!-- Description Textarea -->
            <div class="form-group">
              <div class="label-with-counter">
                <label for="report-description" class="group-label">
                  Mô tả chi tiết (không bắt buộc):
                </label>
                <span
                  class="char-count"
                  :class="{ 'count-warning': description.length > 900 }"
                >
                  {{ description.length }}/1000
                </span>
              </div>
              <textarea
                id="report-description"
                v-model="description"
                rows="3"
                maxlength="1000"
                class="form-textarea"
                placeholder="Vui lòng cung cấp thêm thông tin (link ảnh, ngữ cảnh, từ ngữ vi phạm...) để giúp BQT xử lý nhanh hơn..."
                :disabled="isSubmitting || !!successMessage"
              ></textarea>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="report-modal-footer">
            <button
              type="button"
              class="btn-cancel"
              :disabled="isSubmitting"
              @click="handleClose"
            >
              Hủy
            </button>
            <button
              type="submit"
              class="btn-submit"
              :disabled="isSubmitting || !!successMessage"
            >
              <span v-if="isSubmitting" class="spinner-sm"></span>
              <span>{{ isSubmitting ? 'Đang gửi...' : 'Gửi báo cáo' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.report-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1050;
  padding: 1rem;
}

.report-modal-card {
  background: #1f2937;
  border: 1px solid #374151;
  border-radius: 0.75rem;
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
  overflow: hidden;
}

.report-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #374151;
}

.header-title-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.report-icon {
  font-size: 1.25rem;
}

.report-title {
  font-size: 1.125rem;
  font-weight: 700;
  color: #f3f4f6;
  margin: 0;
}

.report-close-btn {
  background: transparent;
  border: none;
  color: #9ca3af;
  font-size: 1.25rem;
  cursor: pointer;
  padding: 0.25rem;
  line-height: 1;
}

.report-close-btn:hover {
  color: #f3f4f6;
}

.target-context-box {
  background: #111827;
  padding: 0.6rem 1.25rem;
  border-bottom: 1px solid #2d3748;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.8125rem;
}

.context-label {
  color: #9ca3af;
  flex-shrink: 0;
}

.context-title {
  color: #60a5fa;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.auth-gate-box {
  padding: 2.5rem 1.5rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
}

.auth-icon {
  font-size: 2.5rem;
}

.auth-text {
  color: #d1d5db;
  font-size: 0.875rem;
  margin: 0;
}

.btn-login-prompt {
  background: #3b82f6;
  color: #ffffff;
  border: none;
  padding: 0.5rem 1.25rem;
  border-radius: 0.375rem;
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
  margin-top: 0.5rem;
}

.btn-login-prompt:hover {
  background: #2563eb;
}

.report-form {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.report-form-body {
  padding: 1.25rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.alert-success {
  background: rgba(16, 185, 129, 0.15);
  border: 1px solid rgba(16, 185, 129, 0.3);
  color: #34d399;
  padding: 0.6rem 0.75rem;
  border-radius: 0.375rem;
  font-size: 0.8125rem;
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #f87171;
  padding: 0.6rem 0.75rem;
  border-radius: 0.375rem;
  font-size: 0.8125rem;
}

.group-label {
  display: block;
  font-size: 0.875rem;
  font-weight: 600;
  color: #e5e7eb;
  margin-bottom: 0.5rem;
}

.required::after {
  content: ' *';
  color: #ef4444;
}

.reasons-list {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.reason-option {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.75rem;
  border-radius: 0.375rem;
  border: 1px solid #374151;
  background: #111827;
  cursor: pointer;
  transition: all 0.15s ease;
}

.reason-option:hover {
  background: #252e3e;
}

.reason-option.selected {
  border-color: #3b82f6;
  background: rgba(59, 130, 246, 0.08);
}

.radio-indicator {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid #6b7280;
  display: inline-block;
  position: relative;
  flex-shrink: 0;
}

.reason-option.selected .radio-indicator {
  border-color: #3b82f6;
}

.reason-option.selected .radio-indicator::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #3b82f6;
}

.reason-text {
  font-size: 0.875rem;
  color: #f3f4f6;
}

.custom-reason-input-wrap {
  position: relative;
  margin-top: 0.5rem;
}

.form-input {
  width: 100%;
  background: #111827;
  border: 1px solid #374151;
  border-radius: 0.375rem;
  color: #f3f4f6;
  font-size: 0.875rem;
  padding: 0.5rem 0.75rem;
  padding-right: 3rem;
  outline: none;
}

.form-input:focus {
  border-color: #3b82f6;
}

.label-with-counter {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.35rem;
}

.char-count {
  font-size: 0.75rem;
  color: #9ca3af;
}

.count-warning {
  color: #f87171;
}

.form-textarea {
  width: 100%;
  background: #111827;
  border: 1px solid #374151;
  border-radius: 0.375rem;
  color: #f3f4f6;
  font-size: 0.875rem;
  padding: 0.6rem 0.75rem;
  outline: none;
  resize: vertical;
}

.form-textarea:focus {
  border-color: #3b82f6;
}

.report-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-top: 1px solid #374151;
  background: #111827;
}

.btn-cancel {
  background: #374151;
  color: #d1d5db;
  border: none;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-cancel:hover:not(:disabled) {
  background: #4b5563;
}

.btn-submit {
  background: #ef4444;
  color: #ffffff;
  border: none;
  padding: 0.5rem 1.25rem;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.btn-submit:hover:not(:disabled) {
  background: #dc2626;
}

.btn-submit:disabled,
.btn-cancel:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.spinner-sm {
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

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}
</style>
