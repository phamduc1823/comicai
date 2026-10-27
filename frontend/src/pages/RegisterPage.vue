<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getSafeGuestRedirect } from '@/router'
import {
  isValidationError,
  isRateLimitError,
  getValidationErrors,
  getRetryAfterSeconds,
  getErrorMessage,
} from '@/services/httpClient'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

// Form state
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

// UI & Error state
const isSubmitting = ref(false)
const generalError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

// Computed login link preserving safe redirect query
const loginLink = computed(() => {
  const safe = getSafeGuestRedirect(route.query.redirect, route.path, route.fullPath)
  return safe ? `/login?redirect=${encodeURIComponent(safe)}` : '/login'
})

// Clear error messages when user interacts with form
function clearErrors() {
  if (generalError.value) {
    generalError.value = null
  }
  if (Object.keys(fieldErrors.value).length > 0) {
    fieldErrors.value = {}
  }
  authStore.clearError()
}

onMounted(() => {
  clearErrors()
})

async function handleRegister() {
  if (isSubmitting.value) return

  clearErrors()

  // Client-side confirmation check
  if (password.value !== passwordConfirmation.value) {
    fieldErrors.value = {
      password_confirmation: ['Mật khẩu xác nhận không khớp với mật khẩu đã nhập.'],
    }
    return
  }

  isSubmitting.value = true

  try {
    // Only send fields supported by backend RegisterRequest
    await authStore.register({
      name: name.value.trim(),
      email: email.value.trim(),
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    // On success, backend automatically authenticates the user
    // Navigate to verified safe internal destination or fallback to '/'
    const safeTarget = getSafeGuestRedirect(route.query.redirect, route.path, route.fullPath)
    await router.push(safeTarget || '/')
  } catch (err: unknown) {
    if (isValidationError(err)) {
      fieldErrors.value = getValidationErrors(err)
      if (!fieldErrors.value.name && !fieldErrors.value.email && !fieldErrors.value.password) {
        generalError.value = getErrorMessage(err, 'Dữ liệu đăng ký không hợp lệ. Vui lòng kiểm tra lại.')
      }
    } else if (isRateLimitError(err)) {
      const retrySeconds = getRetryAfterSeconds(err)
      generalError.value = retrySeconds
        ? `Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau ${retrySeconds} giây.`
        : 'Bạn đã thử đăng ký quá nhiều lần. Vui lòng đợi trong giây lát rồi thử lại.'
    } else {
      generalError.value = getErrorMessage(err, 'Không thể kết nối đến máy chủ. Vui lòng kiểm tra đường truyền và thử lại.')
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-header">
        <div class="auth-brand-badge">
          <span class="auth-logo">📖</span>
          <span>BlueToon</span>
        </div>
        <h1 class="auth-title">Đăng ký tài khoản</h1>
        <p class="auth-subtitle">Tạo tài khoản để theo dõi truyện và lưu lịch sử đọc cá nhân</p>
      </div>

      <!-- General alert banner -->
      <div v-if="generalError" class="auth-alert" role="alert">
        <span class="alert-icon" aria-hidden="true">⚠️</span>
        <div class="alert-content">
          <p class="alert-message">{{ generalError }}</p>
        </div>
        <button
          type="button"
          class="alert-close"
          aria-label="Đóng thông báo"
          @click="generalError = null"
        >
          ✕
        </button>
      </div>

      <form class="auth-form" novalidate @submit.prevent="handleRegister">
        <!-- Name field -->
        <div class="form-group" :class="{ 'has-error': fieldErrors.name?.length }">
          <label for="register-name" class="form-label">
            Họ và tên <span class="required" aria-hidden="true">*</span>
          </label>
          <div class="input-wrapper">
            <input
              id="register-name"
              v-model="name"
              type="text"
              class="form-input"
              autocomplete="name"
              placeholder="Nguyễn Văn A"
              required
              minlength="2"
              maxlength="255"
              :disabled="isSubmitting"
              :aria-invalid="!!fieldErrors.name?.length"
              :aria-describedby="fieldErrors.name?.length ? 'register-name-error' : undefined"
              @input="clearErrors"
            />
          </div>
          <p
            v-if="fieldErrors.name?.length"
            id="register-name-error"
            class="field-error"
            role="alert"
          >
            {{ fieldErrors.name[0] }}
          </p>
        </div>

        <!-- Email field -->
        <div class="form-group" :class="{ 'has-error': fieldErrors.email?.length }">
          <label for="register-email" class="form-label">
            Địa chỉ Email <span class="required" aria-hidden="true">*</span>
          </label>
          <div class="input-wrapper">
            <input
              id="register-email"
              v-model="email"
              type="email"
              class="form-input"
              autocomplete="email"
              placeholder="name@example.com"
              required
              maxlength="255"
              :disabled="isSubmitting"
              :aria-invalid="!!fieldErrors.email?.length"
              :aria-describedby="fieldErrors.email?.length ? 'register-email-error' : undefined"
              @input="clearErrors"
            />
          </div>
          <p
            v-if="fieldErrors.email?.length"
            id="register-email-error"
            class="field-error"
            role="alert"
          >
            {{ fieldErrors.email[0] }}
          </p>
        </div>

        <!-- Password field -->
        <div class="form-group" :class="{ 'has-error': fieldErrors.password?.length }">
          <label for="register-password" class="form-label">
            Mật khẩu <span class="required" aria-hidden="true">*</span>
          </label>
          <div class="input-wrapper">
            <input
              id="register-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="form-input has-action"
              autocomplete="new-password"
              placeholder="Tối thiểu 8 ký tự"
              required
              minlength="8"
              :disabled="isSubmitting"
              :aria-invalid="!!fieldErrors.password?.length"
              :aria-describedby="fieldErrors.password?.length ? 'register-password-error' : undefined"
              @input="clearErrors"
            />
            <button
              type="button"
              class="password-toggle-btn"
              :aria-label="showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
              :disabled="isSubmitting"
              @click="showPassword = !showPassword"
            >
              <span aria-hidden="true">{{ showPassword ? '🙈' : '👁️' }}</span>
            </button>
          </div>
          <p
            v-if="fieldErrors.password?.length"
            id="register-password-error"
            class="field-error"
            role="alert"
          >
            {{ fieldErrors.password[0] }}
          </p>
        </div>

        <!-- Password Confirmation field -->
        <div
          class="form-group"
          :class="{ 'has-error': fieldErrors.password_confirmation?.length }"
        >
          <label for="register-password-confirmation" class="form-label">
            Xác nhận mật khẩu <span class="required" aria-hidden="true">*</span>
          </label>
          <div class="input-wrapper">
            <input
              id="register-password-confirmation"
              v-model="passwordConfirmation"
              :type="showPasswordConfirmation ? 'text' : 'password'"
              class="form-input has-action"
              autocomplete="new-password"
              placeholder="Nhập lại mật khẩu"
              required
              minlength="8"
              :disabled="isSubmitting"
              :aria-invalid="!!fieldErrors.password_confirmation?.length"
              :aria-describedby="fieldErrors.password_confirmation?.length ? 'register-confirm-error' : undefined"
              @input="clearErrors"
            />
            <button
              type="button"
              class="password-toggle-btn"
              :aria-label="showPasswordConfirmation ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'"
              :disabled="isSubmitting"
              @click="showPasswordConfirmation = !showPasswordConfirmation"
            >
              <span aria-hidden="true">{{ showPasswordConfirmation ? '🙈' : '👁️' }}</span>
            </button>
          </div>
          <p
            v-if="fieldErrors.password_confirmation?.length"
            id="register-confirm-error"
            class="field-error"
            role="alert"
          >
            {{ fieldErrors.password_confirmation[0] }}
          </p>
        </div>

        <!-- Submit button -->
        <button
          type="submit"
          class="submit-btn"
          :disabled="
            isSubmitting ||
            !name.trim() ||
            !email.trim() ||
            !password ||
            !passwordConfirmation
          "
        >
          <span v-if="isSubmitting" class="spinner" aria-hidden="true"></span>
          <span>{{ isSubmitting ? 'Đang tạo tài khoản...' : 'Đăng ký tài khoản' }}</span>
        </button>
      </form>

      <!-- Footer switch link -->
      <div class="auth-footer">
        <p class="auth-switch-text">
          Đã có tài khoản?
          <RouterLink :to="loginLink" class="auth-switch-link">
            Đăng nhập ngay
          </RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.auth-page {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-xl) var(--spacing-md);
  background: radial-gradient(circle at top, var(--color-bg-surface) 0%, var(--color-bg) 70%);
}

.auth-card {
  width: 100%;
  max-width: 460px;
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--spacing-xl);
  box-shadow: var(--shadow-lg);
  backdrop-filter: blur(16px);
}

.auth-header {
  text-align: center;
  margin-bottom: var(--spacing-lg);
}

.auth-brand-badge {
  display: inline-flex;
  align-items: center;
  gap: var(--spacing-xs);
  padding: 4px 12px;
  background-color: var(--color-primary-soft);
  border: 1px solid var(--color-border-subtle);
  border-radius: var(--radius-full);
  color: var(--color-primary);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  margin-bottom: var(--spacing-sm);
}

.auth-logo {
  font-size: var(--font-size-base);
}

.auth-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text-primary);
  letter-spacing: -0.02em;
  margin-bottom: var(--spacing-xs);
}

.auth-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  line-height: var(--line-height-normal);
}

/* Alert Box */
.auth-alert {
  display: flex;
  align-items: flex-start;
  gap: var(--spacing-xs);
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-md);
  padding: var(--spacing-sm) var(--spacing-md);
  margin-bottom: var(--spacing-md);
  color: var(--color-danger);
  font-size: var(--font-size-sm);
  line-height: var(--line-height-normal);
}

.alert-icon {
  flex-shrink: 0;
  font-size: var(--font-size-base);
}

.alert-content {
  flex: 1;
}

.alert-message {
  margin: 0;
  word-break: break-word;
}

.alert-close {
  flex-shrink: 0;
  color: var(--color-danger);
  opacity: 0.7;
  padding: 0 4px;
  font-size: var(--font-size-sm);
}

.alert-close:hover {
  opacity: 1;
}

/* Form */
.auth-form {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-2xs);
}

.form-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
}

.required {
  color: var(--color-danger);
}

.input-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}

.form-input {
  width: 100%;
  height: 44px;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 0 var(--spacing-md);
  color: var(--color-text-primary);
  font-family: inherit;
  font-size: var(--font-size-sm);
  outline: none;
  transition: all var(--transition-fast);
}

.form-input.has-action {
  padding-right: 44px;
}

.form-input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px var(--color-primary-soft);
}

.form-input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.form-group.has-error .form-input {
  border-color: var(--color-danger);
}

.form-group.has-error .form-input:focus {
  box-shadow: 0 0 0 3px var(--color-danger-soft);
}

.password-toggle-btn {
  position: absolute;
  right: 8px;
  height: 32px;
  width: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  color: var(--color-text-secondary);
  background: transparent;
  transition: all var(--transition-fast);
}

.password-toggle-btn:hover:not(:disabled) {
  color: var(--color-text-primary);
  background-color: var(--color-bg-elevated);
}

.field-error {
  font-size: var(--font-size-xs);
  color: var(--color-danger);
  margin-top: 2px;
}

/* Submit Button */
.submit-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--spacing-xs);
  height: 46px;
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  border-radius: var(--radius-md);
  margin-top: var(--spacing-xs);
  box-shadow: var(--shadow-md);
  transition: all var(--transition-fast);
}

.submit-btn:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
  box-shadow: var(--shadow-glow);
  transform: translateY(-1px);
}

.submit-btn:active:not(:disabled) {
  background-color: var(--color-primary-active);
  transform: translateY(0);
}

.submit-btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}

.spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(11, 15, 25, 0.25);
  border-top-color: var(--color-text-inverse);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Footer */
.auth-footer {
  margin-top: var(--spacing-lg);
  padding-top: var(--spacing-md);
  border-top: 1px solid var(--color-border);
  text-align: center;
}

.auth-switch-text {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
}

.auth-switch-link {
  color: var(--color-primary);
  font-weight: var(--font-weight-medium);
  margin-left: 4px;
}

.auth-switch-link:hover {
  text-decoration: underline;
}
</style>
