<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { WalletService } from '@/services/walletService'
import { getErrorMessage } from '@/services/httpClient'
import type { UnlockChapterResult } from '@/types/wallet'
import { formatChapterNumber } from '@/utils/chapter'

interface Props {
  isOpen: boolean
  chapterId: number | string
  chapterNumber: string | number
  chapterTitle?: string | null
  comicTitle?: string
  price: number
  userBalance: number
}

const props = withDefaults(defineProps<Props>(), {
  chapterTitle: null,
  comicTitle: '',
})

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'unlocked', result: UnlockChapterResult): void
}>()

const isUnlocking = ref(false)
const unlockError = ref<string | null>(null)

const isSufficient = computed(() => props.userBalance >= props.price)
const balanceAfter = computed(() => Math.max(0, props.userBalance - props.price))
const missingCoins = computed(() => Math.max(0, props.price - props.userBalance))

function generateIdempotencyKey(): string {
  try {
    return crypto.randomUUID()
  } catch {
    return 'unlock-' + props.chapterId + '-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
  }
}

async function handleConfirmUnlock() {
  if (isUnlocking.value || !isSufficient.value) return
  isUnlocking.value = true
  unlockError.value = null

  const key = generateIdempotencyKey()

  try {
    const res = await WalletService.unlockChapter(props.chapterId, key)
    emit('unlocked', res.data)
    emit('close')
  } catch (err: unknown) {
    unlockError.value = getErrorMessage(
      err,
      'Không thể mở khóa chương truyện. Vui lòng kiểm tra lại số dư Coin hoặc thử lại sau.'
    )
  } finally {
    isUnlocking.value = false
  }
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click="emit('close')">
    <div
      class="modal-dialog"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'modal-title-' + chapterId"
      @click.stop
    >
      <!-- Header -->
      <header class="modal-header">
        <div class="header-icon-wrap" aria-hidden="true">
          <span class="header-icon">👑</span>
        </div>
        <div class="header-titles">
          <h2 :id="'modal-title-' + chapterId" class="modal-title">
            Mở khóa Chương VIP
          </h2>
          <p class="modal-subtitle">
            Chương {{ formatChapterNumber(chapterNumber) }}{{ chapterTitle ? ': ' + chapterTitle : '' }}
          </p>
        </div>
        <button
          type="button"
          class="btn-close"
          aria-label="Đóng hộp thoại"
          @click="emit('close')"
        >
          ✕
        </button>
      </header>

      <!-- Body -->
      <div class="modal-body">
        <p v-if="comicTitle" class="comic-name-note">
          Truyện: <strong>{{ comicTitle }}</strong>
        </p>

        <!-- Pricing Summary Box -->
        <div class="pricing-box">
          <div class="pricing-row">
            <span class="pricing-label">Giá mở khóa:</span>
            <span class="pricing-val price-highlight">{{ price }} Coin</span>
          </div>
          <div class="pricing-row">
            <span class="pricing-label">Số dư hiện tại của bạn:</span>
            <span class="pricing-val">{{ userBalance }} Coin</span>
          </div>
          <div class="pricing-divider"></div>
          <div class="pricing-row row-after">
            <span class="pricing-label">Số dư còn lại sau khi mở:</span>
            <span
              class="pricing-val"
              :class="isSufficient ? 'balance-ok' : 'balance-insufficient'"
            >
              {{ isSufficient ? balanceAfter + ' Coin' : 'Không đủ Coin' }}
            </span>
          </div>
        </div>

        <!-- Insufficient Coins Warning -->
        <div v-if="!isSufficient" class="insufficient-alert" role="alert">
          <span class="alert-icon" aria-hidden="true">⚠️</span>
          <div class="alert-body">
            <p class="alert-title">Số dư không đủ</p>
            <p class="alert-desc">
              Bạn cần thêm <strong>{{ missingCoins }} Coin</strong> nữa để mở khóa chương này. Bạn có thể nhận 100 Coin miễn phí từ Faucet mỗi ngày!
            </p>
            <RouterLink to="/wallet" class="btn-goto-faucet" @click="emit('close')">
              🎁 Đến trang Ví để nhận Coin miễn phí →
            </RouterLink>
          </div>
        </div>

        <!-- Success/Information Notice if Sufficient -->
        <p v-else class="confirm-notice">
          ℹ️ Coin sẽ được trừ trực tiếp từ ví của bạn. Sau khi mở khóa thành công, bạn có thể đọc chương này vĩnh viễn.
        </p>

        <!-- API Error Feedback -->
        <div v-if="unlockError" class="unlock-error-alert" role="alert">
          <span class="alert-icon" aria-hidden="true">✕</span>
          <span class="alert-text">{{ unlockError }}</span>
        </div>
      </div>

      <!-- Footer Actions -->
      <footer class="modal-footer">
        <button
          type="button"
          class="btn-cancel"
          :disabled="isUnlocking"
          @click="emit('close')"
        >
          Hủy bỏ
        </button>

        <button
          type="button"
          class="btn-confirm-unlock"
          :disabled="!isSufficient || isUnlocking"
          @click="handleConfirmUnlock"
        >
          <span v-if="isUnlocking" class="btn-spinner" aria-hidden="true"></span>
          <span>
            {{ isUnlocking ? 'Đang mở khóa...' : `Mở khóa (${price} Coin)` }}
          </span>
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: var(--spacing-md);
  animation: fadeIn 0.2s ease-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

.modal-dialog {
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  max-width: 480px;
  width: 100%;
  box-shadow: var(--shadow-xl);
  overflow: hidden;
  animation: slideUp 0.2s ease-out;
}

@keyframes slideUp {
  from {
    transform: translateY(12px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.modal-header {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-lg);
  border-bottom: 1px solid var(--color-border);
  position: relative;
}

.header-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-md);
  background: rgba(234, 179, 8, 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.header-icon {
  font-size: 22px;
}

.header-titles {
  flex: 1;
  min-width: 0;
}

.modal-title {
  font-size: var(--font-size-lg);
  font-weight: 700;
  color: var(--color-text);
  margin: 0 0 2px 0;
}

.modal-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.btn-close {
  background: none;
  border: none;
  font-size: 18px;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: 4px 8px;
  border-radius: var(--radius-sm);
  transition: all 0.15s ease;
}

.btn-close:hover {
  background: rgba(255, 255, 255, 0.08);
  color: var(--color-text);
}

.modal-body {
  padding: var(--spacing-lg);
}

.comic-name-note {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin: 0 0 var(--spacing-md) 0;
}

.pricing-box {
  background: var(--color-bg-subtle, rgba(255, 255, 255, 0.04));
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--spacing-md);
  margin-bottom: var(--spacing-md);
}

.pricing-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: var(--font-size-sm);
  margin-bottom: var(--spacing-xs);
}

.pricing-label {
  color: var(--color-text-muted);
}

.pricing-val {
  font-weight: 600;
  color: var(--color-text);
}

.price-highlight {
  font-size: var(--font-size-base);
  font-weight: 700;
  color: #eab308;
}

.pricing-divider {
  height: 1px;
  background: var(--color-border);
  margin: var(--spacing-sm) 0;
}

.row-after {
  margin-bottom: 0;
}

.balance-ok {
  color: #16a34a;
}

.balance-insufficient {
  color: #dc2626;
}

.insufficient-alert {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: var(--radius-md);
  padding: var(--spacing-md);
  display: flex;
  gap: var(--spacing-sm);
  margin-bottom: var(--spacing-md);
}

.alert-icon {
  font-size: 20px;
  flex-shrink: 0;
}

.alert-body {
  flex: 1;
}

.alert-title {
  font-weight: 600;
  color: #b91c1c;
  font-size: var(--font-size-sm);
  margin: 0 0 2px 0;
}

.alert-desc {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0 0 var(--spacing-xs) 0;
  line-height: 1.4;
}

.btn-goto-faucet {
  display: inline-block;
  font-size: var(--font-size-xs);
  font-weight: 600;
  color: var(--color-primary);
  text-decoration: none;
}

.btn-goto-faucet:hover {
  text-decoration: underline;
}

.confirm-notice {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0 0 var(--spacing-md) 0;
  line-height: 1.4;
}

.unlock-error-alert {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.4);
  border-radius: var(--radius-md);
  padding: var(--spacing-sm) var(--spacing-md);
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
  color: #dc2626;
  font-size: var(--font-size-sm);
  margin-top: var(--spacing-sm);
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--spacing-sm);
  padding: var(--spacing-md) var(--spacing-lg);
  border-top: 1px solid var(--color-border);
  background: var(--color-bg-subtle, rgba(255, 255, 255, 0.02));
}

.btn-cancel {
  padding: 8px 16px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
  font-weight: 500;
  cursor: pointer;
}

.btn-cancel:hover:not(:disabled) {
  color: var(--color-text);
  border-color: var(--color-text-muted);
}

.btn-confirm-unlock {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: 8px 18px;
  border-radius: var(--radius-md);
  border: none;
  background: linear-gradient(135deg, #eab308, #ca8a04);
  color: #000000;
  font-size: var(--font-size-sm);
  font-weight: 700;
  cursor: pointer;
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.btn-confirm-unlock:hover:not(:disabled) {
  opacity: 0.95;
  transform: translateY(-1px);
}

.btn-confirm-unlock:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  transform: none;
}

.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
