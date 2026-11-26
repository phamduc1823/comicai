<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { WalletService } from '@/services/walletService'
import { getErrorMessage } from '@/services/httpClient'
import type { PurchaseComicResult } from '@/types/wallet'

interface Props {
  isOpen: boolean
  comicId: number | string
  comicTitle: string
  price: number
  userBalance: number
}

const props = withDefaults(defineProps<Props>(), {
  comicTitle: '',
})

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'purchased', result: PurchaseComicResult): void
}>()

const isPurchasing = ref(false)
const purchaseError = ref<string | null>(null)

const isSufficient = computed(() => props.userBalance >= props.price)
const balanceAfter = computed(() => Math.max(0, props.userBalance - props.price))
const missingCoins = computed(() => Math.max(0, props.price - props.userBalance))

function generateIdempotencyKey(): string {
  try {
    return crypto.randomUUID()
  } catch {
    return 'buy-comic-' + props.comicId + '-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
  }
}

async function handleConfirmPurchase() {
  if (isPurchasing.value || !isSufficient.value) return
  isPurchasing.value = true
  purchaseError.value = null

  const key = generateIdempotencyKey()

  try {
    const res = await WalletService.purchaseComic(props.comicId, key)
    emit('purchased', res.data)
    emit('close')
  } catch (err: unknown) {
    purchaseError.value = getErrorMessage(
      err,
      'Không thể mua truyện VIP. Vui lòng kiểm tra lại số dư Coin hoặc thử lại sau.'
    )
  } finally {
    isPurchasing.value = false
  }
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click="emit('close')">
    <div
      class="modal-dialog"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'modal-title-' + comicId"
      @click.stop
    >
      <!-- Header -->
      <header class="modal-header">
        <div class="header-icon-wrap" aria-hidden="true">
          <span class="header-icon">👑</span>
        </div>
        <div class="header-titles">
          <h2 :id="'modal-title-' + comicId" class="modal-title">
            Mua Truyện VIP Trọn Bộ
          </h2>
          <p class="modal-subtitle">
            Sở hữu vĩnh viễn &bull; Đọc toàn bộ chương hiện tại và chương mới
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
            <span class="pricing-label">Giá mua trọn bộ:</span>
            <span class="pricing-val price-highlight">{{ price }} Coin</span>
          </div>
          <div class="pricing-row">
            <span class="pricing-label">Số dư hiện tại của bạn:</span>
            <span class="pricing-val">{{ userBalance }} Coin</span>
          </div>
          <div class="pricing-divider"></div>
          <div class="pricing-row row-after">
            <span class="pricing-label">Số dư còn lại sau khi mua:</span>
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
              Bạn cần thêm <strong>{{ missingCoins }} Coin</strong> nữa để mua truyện này. Bạn có thể nhận 100 Coin miễn phí từ Faucet mỗi ngày!
            </p>
            <RouterLink to="/wallet" class="btn-goto-faucet" @click="emit('close')">
              🎁 Đến trang Ví để nhận Coin miễn phí →
            </RouterLink>
          </div>
        </div>

        <!-- Benefits Notice -->
        <div v-else class="unlock-benefit-notice">
          <p class="benefit-item">
            <span class="benefit-check" aria-hidden="true">✓</span>
            <span>Chỉ trả Coin 1 lần duy nhất để sở hữu truyện vĩnh viễn.</span>
          </p>
          <p class="benefit-item">
            <span class="benefit-check" aria-hidden="true">✓</span>
            <span>Mở khóa toàn bộ các chương hiện có mà không bị trừ thêm phí.</span>
          </p>
          <p class="benefit-item">
            <span class="benefit-check" aria-hidden="true">✓</span>
            <span>Tự động được đọc mọi chương mới tác giả đăng tải sau này!</span>
          </p>
        </div>

        <!-- Error Message -->
        <div v-if="purchaseError" class="modal-error-alert" role="alert">
          {{ purchaseError }}
        </div>
      </div>

      <!-- Footer -->
      <footer class="modal-footer">
        <button
          type="button"
          class="btn-cancel"
          :disabled="isPurchasing"
          @click="emit('close')"
        >
          Hủy bỏ
        </button>
        <button
          type="button"
          class="btn-confirm-purchase"
          :disabled="isPurchasing || !isSufficient"
          @click="handleConfirmPurchase"
        >
          <span v-if="isPurchasing" class="btn-spinner" aria-hidden="true"></span>
          <span>{{ isPurchasing ? 'Đang xử lý...' : `Xác nhận mua (${price} Coin)` }}</span>
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background-color: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
  animation: fadeIn 0.2s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-dialog {
  background: var(--color-bg-surface, #161b22);
  border: 1px solid var(--color-border, #30363d);
  border-radius: 16px;
  max-width: 460px;
  width: 100%;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
  overflow: hidden;
  animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes slideUp {
  from {
    transform: translateY(20px) scale(0.96);
    opacity: 0;
  }
  to {
    transform: translateY(0) scale(1);
    opacity: 1;
  }
}

.modal-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 20px 24px 16px;
  border-bottom: 1px solid var(--color-border, #30363d);
  position: relative;
}

.header-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: linear-gradient(135deg, rgba(234, 179, 8, 0.2), rgba(202, 138, 4, 0.1));
  border: 1px solid rgba(234, 179, 8, 0.3);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}

.header-titles {
  flex: 1;
}

.modal-title {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--color-text-main, #f0f6fc);
  margin: 0 0 4px;
}

.modal-subtitle {
  font-size: 0.85rem;
  color: var(--color-text-muted, #8b949e);
  margin: 0;
}

.btn-close {
  background: transparent;
  border: none;
  color: var(--color-text-muted, #8b949e);
  font-size: 18px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;
  line-height: 1;
  transition: all 0.15s ease;
}

.btn-close:hover {
  background-color: rgba(255, 255, 255, 0.1);
  color: #fff;
}

.modal-body {
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.comic-name-note {
  font-size: 0.9rem;
  color: var(--color-text-main, #f0f6fc);
  margin: 0;
  line-height: 1.4;
}

.pricing-box {
  background-color: var(--color-bg-surface-raised, #0d1117);
  border: 1px solid var(--color-border, #30363d);
  border-radius: 12px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pricing-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 0.9rem;
}

.pricing-label {
  color: var(--color-text-muted, #8b949e);
}

.pricing-val {
  font-weight: 600;
  color: var(--color-text-main, #f0f6fc);
}

.price-highlight {
  color: #eab308;
  font-size: 1.05rem;
}

.pricing-divider {
  height: 1px;
  background-color: var(--color-border, #30363d);
  margin: 2px 0;
}

.row-after {
  font-size: 0.95rem;
}

.balance-ok {
  color: #3fb950;
}

.balance-insufficient {
  color: #f85149;
}

.insufficient-alert {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 14px;
  background-color: rgba(248, 81, 73, 0.1);
  border: 1px solid rgba(248, 81, 73, 0.3);
  border-radius: 10px;
}

.alert-icon {
  font-size: 20px;
  flex-shrink: 0;
}

.alert-title {
  font-size: 0.9rem;
  font-weight: 600;
  color: #f85149;
  margin: 0 0 4px;
}

.alert-desc {
  font-size: 0.82rem;
  color: var(--color-text-muted, #8b949e);
  margin: 0 0 10px;
  line-height: 1.4;
}

.btn-goto-faucet {
  display: inline-flex;
  align-items: center;
  font-size: 0.85rem;
  font-weight: 600;
  color: #58a6ff;
  text-decoration: none;
  transition: color 0.15s ease;
}

.btn-goto-faucet:hover {
  color: #79c0ff;
  text-decoration: underline;
}

.unlock-benefit-notice {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 14px;
  background-color: rgba(56, 139, 253, 0.08);
  border: 1px solid rgba(56, 139, 253, 0.2);
  border-radius: 10px;
}

.benefit-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 0.83rem;
  color: var(--color-text-main, #f0f6fc);
  margin: 0;
  line-height: 1.4;
}

.benefit-check {
  color: #3fb950;
  font-weight: 700;
}

.modal-error-alert {
  padding: 10px 14px;
  background-color: rgba(248, 81, 73, 0.15);
  border: 1px solid #f85149;
  border-radius: 8px;
  color: #f85149;
  font-size: 0.85rem;
  line-height: 1.4;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid var(--color-border, #30363d);
  background-color: rgba(22, 27, 34, 0.5);
}

.btn-cancel {
  padding: 8px 16px;
  background: transparent;
  border: 1px solid var(--color-border, #30363d);
  border-radius: 8px;
  color: var(--color-text-muted, #8b949e);
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-cancel:hover:not(:disabled) {
  background-color: rgba(255, 255, 255, 0.05);
  color: var(--color-text-main, #f0f6fc);
}

.btn-confirm-purchase {
  padding: 8px 18px;
  background: linear-gradient(135deg, #eab308, #ca8a04);
  border: 1px solid rgba(234, 179, 8, 0.5);
  border-radius: 8px;
  color: #000;
  font-size: 0.9rem;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.15s ease;
  box-shadow: 0 2px 8px rgba(234, 179, 8, 0.3);
}

.btn-confirm-purchase:hover:not(:disabled) {
  background: linear-gradient(135deg, #facc15, #eab308);
  transform: translateY(-1px);
}

.btn-confirm-purchase:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(0, 0, 0, 0.3);
  border-top-color: #000;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
