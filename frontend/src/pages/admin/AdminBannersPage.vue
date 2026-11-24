<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import { AdminService } from '@/services/adminService'
import { CatalogService } from '@/services/catalogService'
import { getErrorMessage } from '@/services/httpClient'
import type { AdminBanner } from '@/types/admin'
import type { ComicListItem } from '@/types/catalog'

// State
const banners = ref<AdminBanner[]>([])
const approvedComics = ref<ComicListItem[]>([])
const isLoading = ref(true)
const isComicsLoading = ref(false)
const comicsLoadError = ref<string | null>(null)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Modal form states
const isFormModalOpen = ref(false)
const isEditing = ref(false)
const editingBannerId = ref<number | null>(null)
const isSubmitting = ref(false)
const formErrors = ref<Record<string, string>>({})

// Form fields
const formComicId = ref<number | null>(null)
const formTitleOverride = ref('')
const formSortOrder = ref<number>(0)
const formIsActive = ref(true)

// File upload & previews
const desktopFile = ref<File | null>(null)
const desktopPreview = ref<string | null>(null)
const mobileFile = ref<File | null>(null)
const mobilePreview = ref<string | null>(null)
const existingDesktopUrl = ref<string | null>(null)
const existingMobileUrl = ref<string | null>(null)

// Delete confirm state
const isDeleteModalOpen = ref(false)
const bannerToDelete = ref<AdminBanner | null>(null)
const isDeleting = ref(false)

// Toggle status loading state map
const togglingBannerId = ref<number | null>(null)

// Toast timeout
let toastTimer: ReturnType<typeof setTimeout> | null = null

function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

function dismissToast() {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = null
}

// Stats
const totalBannersCount = computed(() => banners.value.length)
const activeBannersCount = computed(() => banners.value.filter((b) => b.is_active).length)
const inactiveBannersCount = computed(() => banners.value.filter((b) => !b.is_active).length)

async function fetchBanners() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getBanners()
    banners.value = res.data || []
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách banner quản trị.')
  } finally {
    isLoading.value = false
  }
}

async function fetchApprovedComics() {
  isComicsLoading.value = true
  comicsLoadError.value = null
  try {
    const res = await CatalogService.getComics({ per_page: 50, sort: 'latest' })
    approvedComics.value = res.data || []
  } catch (err: unknown) {
    approvedComics.value = []
    comicsLoadError.value = getErrorMessage(err, 'Không thể nạp danh sách truyện đã duyệt. Vui lòng thử lại.')
    console.error('Không thể nạp danh sách truyện approved:', err)
  } finally {
    isComicsLoading.value = false
  }
}

function openCreateModal() {
  isEditing.value = false
  editingBannerId.value = null
  formComicId.value = approvedComics.value.length > 0 ? approvedComics.value[0].id : null
  formTitleOverride.value = ''
  formSortOrder.value = banners.value.length
  formIsActive.value = true
  desktopFile.value = null
  desktopPreview.value = null
  mobileFile.value = null
  mobilePreview.value = null
  existingDesktopUrl.value = null
  existingMobileUrl.value = null
  formErrors.value = {}
  isFormModalOpen.value = true
}

function openEditModal(banner: AdminBanner) {
  isEditing.value = true
  editingBannerId.value = banner.id
  formComicId.value = banner.comic_id
  formTitleOverride.value = banner.title_override || ''
  formSortOrder.value = banner.sort_order
  formIsActive.value = banner.is_active
  desktopFile.value = null
  desktopPreview.value = null
  mobileFile.value = null
  mobilePreview.value = null
  existingDesktopUrl.value = banner.desktop_banner_url
  existingMobileUrl.value = banner.mobile_banner_url
  formErrors.value = {}
  isFormModalOpen.value = true
}

function closeFormModal() {
  if (isSubmitting.value) return
  isFormModalOpen.value = false
  // Revoke object URLs to prevent memory leaks
  if (desktopPreview.value) URL.revokeObjectURL(desktopPreview.value)
  if (mobilePreview.value) URL.revokeObjectURL(mobilePreview.value)
}

function handleDesktopFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files && target.files[0]) {
    const file = target.files[0]
    desktopFile.value = file
    if (desktopPreview.value) URL.revokeObjectURL(desktopPreview.value)
    desktopPreview.value = URL.createObjectURL(file)
    delete formErrors.value.desktop_banner
  }
}

function handleMobileFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files && target.files[0]) {
    const file = target.files[0]
    mobileFile.value = file
    if (mobilePreview.value) URL.revokeObjectURL(mobilePreview.value)
    mobilePreview.value = URL.createObjectURL(file)
    delete formErrors.value.mobile_banner
  }
}

async function handleFormSubmit() {
  formErrors.value = {}

  // Basic client validation
  if (!formComicId.value) {
    formErrors.value.comic_id = 'Vui lòng chọn bộ truyện tranh liên kết.'
  }
  if (!isEditing.value && !desktopFile.value) {
    formErrors.value.desktop_banner = 'Vui lòng tải lên ảnh banner desktop.'
  }

  if (Object.keys(formErrors.value).length > 0) return

  isSubmitting.value = true

  const formData = new FormData()
  formData.append('comic_id', String(formComicId.value))
  formData.append('title_override', formTitleOverride.value.trim())
  formData.append('sort_order', String(formSortOrder.value))
  formData.append('is_active', formIsActive.value ? '1' : '0')

  if (desktopFile.value) {
    formData.append('desktop_banner', desktopFile.value)
  }
  if (mobileFile.value) {
    formData.append('mobile_banner', mobileFile.value)
  }

  try {
    if (isEditing.value && editingBannerId.value) {
      formData.append('_method', 'PUT')
      await AdminService.updateBanner(editingBannerId.value, formData)
      showToast('Cập nhật banner thành công.')
    } else {
      await AdminService.createBanner(formData)
      showToast('Thêm banner mới thành công.')
    }
    isFormModalOpen.value = false
    await fetchBanners()
  } catch (err: any) {
    if (err?.response?.data?.errors) {
      const backendErrors: Record<string, string> = {}
      for (const [key, msgs] of Object.entries(err.response.data.errors)) {
        if (Array.isArray(msgs) && msgs.length > 0) {
          backendErrors[key] = msgs[0] as string
        }
      }
      formErrors.value = backendErrors
    } else {
      showToast(getErrorMessage(err, 'Đã xảy ra lỗi khi lưu banner.'), 'error')
    }
  } finally {
    isSubmitting.value = false
  }
}

async function handleToggleStatus(banner: AdminBanner) {
  togglingBannerId.value = banner.id
  try {
    const res = await AdminService.toggleBannerStatus(banner.id, !banner.is_active)
    banner.is_active = res.data.is_active
    showToast(`Đã ${banner.is_active ? 'bật' : 'tắt'} hiển thị banner thành công.`)
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể thay đổi trạng thái banner.'), 'error')
  } finally {
    togglingBannerId.value = null
  }
}

function openDeleteModal(banner: AdminBanner) {
  bannerToDelete.value = banner
  isDeleteModalOpen.value = true
}

async function confirmDeleteBanner() {
  if (!bannerToDelete.value) return
  isDeleting.value = true
  try {
    await AdminService.deleteBanner(bannerToDelete.value.id)
    showToast('Xóa banner thành công.')
    isDeleteModalOpen.value = false
    bannerToDelete.value = null
    await fetchBanners()
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể xóa banner.'), 'error')
  } finally {
    isDeleting.value = false
  }
}

onMounted(() => {
  fetchBanners()
  fetchApprovedComics()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-banners-page">
      <!-- Toast Alert Notification -->
      <Transition name="fade">
        <div
          v-if="toastMessage"
          class="toast-alert"
          :class="'toast-' + toastMessage.type"
          role="status"
          aria-live="polite"
        >
          <span class="toast-icon" aria-hidden="true">
            {{ toastMessage.type === 'success' ? '✓' : '⚠️' }}
          </span>
          <span class="toast-text">{{ toastMessage.text }}</span>
          <button
            type="button"
            class="toast-close"
            aria-label="Đóng thông báo"
            @click="dismissToast"
          >
            ✕
          </button>
        </div>
      </Transition>

      <!-- Page Header -->
      <div class="page-header">
        <div class="header-left">
          <h1 class="page-title">Quản lý Banner Slider Trang Chủ</h1>
          <p class="page-subtitle">
            Cấu hình các bộ truyện nổi bật xuất hiện trên slider hero của trang chủ BlueToon.
          </p>
        </div>

        <div class="header-right">
          <button
            type="button"
            class="btn btn-outline"
            :disabled="isLoading"
            aria-label="Tải lại danh sách banner"
            @click="fetchBanners"
          >
            <span class="refresh-icon" :class="{ spinning: isLoading }" aria-hidden="true">🔄</span>
            <span>Làm mới</span>
          </button>

          <button
            type="button"
            class="btn btn-primary btn-add"
            aria-label="Thêm banner mới"
            @click="openCreateModal"
          >
            <span>➕ Thêm Banner Mới</span>
          </button>
        </div>
      </div>

      <!-- Stats Summary Cards -->
      <div class="stats-row">
        <div class="stat-card">
          <span class="stat-icon">🖼️</span>
          <div class="stat-info">
            <span class="stat-value">{{ totalBannersCount }}</span>
            <span class="stat-label">Tổng số banner</span>
          </div>
        </div>

        <div class="stat-card">
          <span class="stat-icon">🟢</span>
          <div class="stat-info">
            <span class="stat-value text-success">{{ activeBannersCount }}</span>
            <span class="stat-label">Đang hiển thị public</span>
          </div>
        </div>

        <div class="stat-card">
          <span class="stat-icon">⚪</span>
          <div class="stat-info">
            <span class="stat-value text-muted">{{ inactiveBannersCount }}</span>
            <span class="stat-label">Tạm ẩn</span>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-if="errorMessage" class="error-banner" role="alert">
        <span class="error-icon" aria-hidden="true">⚠️</span>
        <div class="error-text">
          <p class="error-title">Không thể tải dữ liệu banner</p>
          <p class="error-detail">{{ errorMessage }}</p>
        </div>
        <button type="button" class="btn btn-sm btn-outline" @click="fetchBanners">
          Thử lại
        </button>
      </div>

      <!-- Table Section -->
      <div class="table-container">
        <!-- Loading State -->
        <div v-if="isLoading" class="loading-state" aria-busy="true" aria-label="Đang tải danh sách banner">
          <span class="spinner" aria-hidden="true"></span>
          <p>Đang tải dữ liệu banner...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="banners.length === 0" class="empty-state">
          <span class="empty-icon" aria-hidden="true">🖼️</span>
          <h2 class="empty-title">Chưa có banner nào</h2>
          <p class="empty-desc">
            Hiện tại trang chủ chưa có banner nào được tạo. Nhấn "Thêm Banner Mới" để bắt đầu cấu hình hero slider.
          </p>
          <button type="button" class="btn btn-primary" @click="openCreateModal">
            ➕ Thêm Banner Đầu Tiên
          </button>
        </div>

        <!-- Table View -->
        <div v-else class="table-responsive">
          <table class="banners-table">
            <thead>
              <tr>
                <th scope="col" class="th-sort">Thứ tự</th>
                <th scope="col" class="th-preview">Ảnh Desktop</th>
                <th scope="col" class="th-preview">Ảnh Mobile</th>
                <th scope="col" class="th-comic">Truyện liên kết & Tiêu đề</th>
                <th scope="col" class="th-status">Trạng thái</th>
                <th scope="col" class="th-actions text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="banner in banners" :key="banner.id" class="table-row">
                <!-- Sort Order -->
                <td class="td-sort">
                  <span class="badge-sort">#{{ banner.sort_order }}</span>
                </td>

                <!-- Desktop Thumbnail -->
                <td class="td-preview">
                  <div class="thumb-wrap desktop-thumb">
                    <img
                      v-if="banner.desktop_banner_url"
                      :src="banner.desktop_banner_url"
                      :alt="banner.title_override || banner.comic?.title || 'Desktop Banner'"
                      class="thumb-img"
                    />
                    <span v-else class="thumb-fallback">Chưa có</span>
                  </div>
                </td>

                <!-- Mobile Thumbnail -->
                <td class="td-preview">
                  <div class="thumb-wrap mobile-thumb">
                    <img
                      v-if="banner.mobile_banner_url"
                      :src="banner.mobile_banner_url"
                      :alt="banner.title_override || banner.comic?.title || 'Mobile Banner'"
                      class="thumb-img"
                    />
                    <span v-else class="thumb-fallback text-muted">Mặc định</span>
                  </div>
                </td>

                <!-- Comic & Title Override -->
                <td class="td-comic">
                  <div class="comic-cell">
                    <div class="comic-header-line">
                      <strong class="comic-name">{{ banner.comic?.title || `Truyện #${banner.comic_id}` }}</strong>
                      <span class="status-approved-badge">Approved</span>
                    </div>
                    <div v-if="banner.title_override" class="title-override-line">
                      <span class="label-override">Tiêu đề slider:</span>
                      <span class="value-override">"{{ banner.title_override }}"</span>
                    </div>
                  </div>
                </td>

                <!-- Active Status -->
                <td class="td-status">
                  <button
                    type="button"
                    class="btn-status-toggle"
                    :class="{ active: banner.is_active, loading: togglingBannerId === banner.id }"
                    :disabled="togglingBannerId === banner.id"
                    :title="banner.is_active ? 'Nhấn để tạm ẩn banner' : 'Nhấn để kích hoạt banner'"
                    @click="handleToggleStatus(banner)"
                  >
                    <span class="status-dot"></span>
                    <span>{{ banner.is_active ? 'Hoạt động' : 'Tạm ẩn' }}</span>
                  </button>
                </td>

                <!-- Actions -->
                <td class="td-actions text-right">
                  <div class="action-buttons">
                    <button
                      type="button"
                      class="btn-action btn-edit"
                      title="Chỉnh sửa banner"
                      @click="openEditModal(banner)"
                    >
                      ✏️ Sửa
                    </button>
                    <button
                      type="button"
                      class="btn-action btn-delete"
                      title="Xóa banner"
                      @click="openDeleteModal(banner)"
                    >
                      🗑️ Xóa
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Create / Edit Modal -->
      <Transition name="fade">
        <div v-if="isFormModalOpen" class="modal-backdrop" @click="closeFormModal">
          <div class="modal-card" @click.stop>
            <div class="modal-header">
              <h2 class="modal-title">
                {{ isEditing ? `Chỉnh sửa Banner #${editingBannerId}` : 'Thêm Banner Mới' }}
              </h2>
              <button
                type="button"
                class="modal-close"
                aria-label="Đóng cửa sổ"
                :disabled="isSubmitting"
                @click="closeFormModal"
              >
                ✕
              </button>
            </div>

            <form @submit.prevent="handleFormSubmit" class="modal-form">
              <!-- Comic Select -->
              <div class="form-group">
                <div class="form-label-row">
                  <label for="form-comic-id" class="form-label">
                    Bộ truyện tranh liên kết <span class="required">*</span>
                  </label>
                  <button
                    v-if="comicsLoadError"
                    type="button"
                    class="btn-retry-comics"
                    :disabled="isComicsLoading"
                    @click="fetchApprovedComics"
                  >
                    🔄 Thử lại
                  </button>
                </div>

                <select
                  id="form-comic-id"
                  v-model.number="formComicId"
                  class="form-select"
                  :class="{ 'is-invalid': formErrors.comic_id || comicsLoadError }"
                  :disabled="isSubmitting || isComicsLoading"
                  required
                >
                  <option v-if="isComicsLoading" :value="null" disabled>
                    ⏳ Đang tải danh sách truyện đã duyệt...
                  </option>
                  <option v-else-if="comicsLoadError" :value="null" disabled>
                    ⚠️ Lỗi tải danh sách: {{ comicsLoadError }}
                  </option>
                  <option v-else-if="approvedComics.length === 0" :value="null" disabled>
                    -- Hiện không có bộ truyện nào ở trạng thái đã duyệt (approved) --
                  </option>
                  <option v-else :value="null" disabled>
                    -- Chọn bộ truyện đã duyệt (approved) --
                  </option>

                  <option
                    v-for="comic in approvedComics"
                    :key="comic.id"
                    :value="comic.id"
                  >
                    {{ comic.title }} (ID: {{ comic.id }})
                  </option>
                </select>

                <p v-if="formErrors.comic_id" class="error-feedback">{{ formErrors.comic_id }}</p>
                <div v-else-if="comicsLoadError" class="comics-error-row">
                  <p class="error-feedback">{{ comicsLoadError }}</p>
                </div>
                <p v-else-if="!isComicsLoading && approvedComics.length === 0" class="empty-comics-warning">
                  ⚠️ Chưa có bộ truyện nào được duyệt trong hệ thống để liên kết với banner.
                </p>
                <p v-else class="help-text">Chỉ các bộ truyện đã được duyệt (approved) mới có thể tạo banner trang chủ.</p>
              </div>

              <!-- Title Override -->
              <div class="form-group">
                <label for="form-title-override" class="form-label">
                  Tiêu đề hiển thị trên slider (tùy chọn)
                </label>
                <input
                  id="form-title-override"
                  v-model="formTitleOverride"
                  type="text"
                  maxlength="200"
                  class="form-input"
                  placeholder="Để trống sẽ mặc định dùng tên gốc của bộ truyện"
                  :disabled="isSubmitting"
                />
                <p v-if="formErrors.title_override" class="error-feedback">{{ formErrors.title_override }}</p>
              </div>

              <!-- Desktop Banner Upload -->
              <div class="form-group">
                <label for="desktop-banner-input" class="form-label">
                  Ảnh Banner Desktop (Tỷ lệ ~16:5 hoặc 1920x600)
                  <span v-if="!isEditing" class="required">*</span>
                </label>
                <div class="file-upload-box">
                  <input
                    id="desktop-banner-input"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/jpg"
                    class="file-input"
                    :disabled="isSubmitting"
                    @change="handleDesktopFileChange"
                  />
                  <div v-if="desktopPreview || existingDesktopUrl" class="upload-preview-wrap">
                    <img
                      :src="desktopPreview || existingDesktopUrl || ''"
                      alt="Desktop Banner Preview"
                      class="preview-img"
                    />
                    <span class="preview-tag">
                      {{ desktopPreview ? 'Ảnh mới chọn' : 'Ảnh hiện tại' }}
                    </span>
                  </div>
                  <div v-else class="upload-placeholder">
                    <span>📁 Nhấn để chọn file ảnh Desktop (tối đa 5MB)</span>
                  </div>
                </div>
                <p v-if="formErrors.desktop_banner" class="error-feedback">{{ formErrors.desktop_banner }}</p>
              </div>

              <!-- Mobile Banner Upload -->
              <div class="form-group">
                <label for="mobile-banner-input" class="form-label">
                  Ảnh Banner Mobile (Tùy chọn, tối ưu cho màn hình nhỏ)
                </label>
                <div class="file-upload-box">
                  <input
                    id="mobile-banner-input"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/jpg"
                    class="file-input"
                    :disabled="isSubmitting"
                    @change="handleMobileFileChange"
                  />
                  <div v-if="mobilePreview || existingMobileUrl" class="upload-preview-wrap mobile-preview-wrap">
                    <img
                      :src="mobilePreview || existingMobileUrl || ''"
                      alt="Mobile Banner Preview"
                      class="preview-img"
                    />
                    <span class="preview-tag">
                      {{ mobilePreview ? 'Ảnh mới chọn' : 'Ảnh hiện tại' }}
                    </span>
                  </div>
                  <div v-else class="upload-placeholder">
                    <span>📱 Nhấn để chọn file ảnh Mobile (nếu không chọn sẽ dùng ảnh Desktop)</span>
                  </div>
                </div>
                <p v-if="formErrors.mobile_banner" class="error-feedback">{{ formErrors.mobile_banner }}</p>
              </div>

              <!-- Row: Sort Order & Is Active -->
              <div class="form-row">
                <div class="form-group flex-1">
                  <label for="form-sort-order" class="form-label">Thứ tự hiển thị (0 - 255)</label>
                  <input
                    id="form-sort-order"
                    v-model.number="formSortOrder"
                    type="number"
                    min="0"
                    max="255"
                    class="form-input"
                    :disabled="isSubmitting"
                  />
                  <p class="help-text">Số nhỏ hơn sẽ hiển thị trước trên slider.</p>
                </div>

                <div class="form-group flex-1">
                  <label class="form-label">Trạng thái công khai</label>
                  <label class="checkbox-label">
                    <input
                      v-model="formIsActive"
                      type="checkbox"
                      class="form-checkbox"
                      :disabled="isSubmitting"
                    />
                    <span>Kích hoạt hiển thị công khai ngay</span>
                  </label>
                </div>
              </div>

              <!-- Modal Actions -->
              <div class="modal-footer">
                <button
                  type="button"
                  class="btn btn-outline"
                  :disabled="isSubmitting"
                  @click="closeFormModal"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  class="btn btn-primary"
                  :disabled="isSubmitting"
                >
                  <span v-if="isSubmitting" class="spinner-sm" aria-hidden="true"></span>
                  <span>{{ isSubmitting ? 'Đang lưu...' : (isEditing ? 'Lưu Thay Đổi' : 'Tạo Banner') }}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>

      <!-- Delete Confirmation Modal -->
      <ConfirmModal
        :is-open="isDeleteModalOpen"
        title="Xác nhận xóa Banner"
        :message="`Bạn có chắc chắn muốn xóa banner của bộ truyện '${bannerToDelete?.comic?.title || 'này'}'? Thao tác này sẽ gỡ banner khỏi slider trang chủ và xóa file ảnh liên quan.`"
        confirm-text="Xóa vĩnh viễn"
        cancel-text="Hủy bỏ"
        type="danger"
        :is-loading="isDeleting"
        @confirm="confirmDeleteBanner"
        @close="isDeleteModalOpen = false"
      />
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-banners-page {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-lg);
  color: var(--color-text-primary);
}

/* Toast Alert */
.toast-alert {
  position: fixed;
  top: 80px;
  right: 24px;
  z-index: 100;
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  padding: var(--spacing-sm) var(--spacing-md);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  box-shadow: var(--shadow-lg);
}

.toast-success {
  background-color: rgba(16, 185, 129, 0.95);
  color: #ffffff;
  border: 1px solid #10b981;
}

.toast-error {
  background-color: rgba(239, 68, 68, 0.95);
  color: #ffffff;
  border: 1px solid #ef4444;
}

.toast-close {
  background: transparent;
  border: none;
  color: currentColor;
  cursor: pointer;
  padding: 0 4px;
}

/* Page Header */
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.page-title {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  margin: 0 0 4px;
}

.page-subtitle {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  margin: 0;
}

.header-right {
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
}

/* Stats Row */
.stats-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--spacing-md);
}

.stat-card {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-md) var(--spacing-lg);
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.stat-icon {
  font-size: 28px;
}

.stat-info {
  display: flex;
  flex-direction: column;
}

.stat-value {
  font-size: var(--font-size-2xl);
  font-weight: var(--font-weight-bold);
  line-height: 1.2;
}

.stat-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.text-success {
  color: var(--color-success);
}

.text-muted {
  color: var(--color-text-muted);
}

/* Error Banner */
.error-banner {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  padding: var(--spacing-md);
  background-color: var(--color-danger-soft);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-md);
}

.error-title {
  font-weight: var(--font-weight-semibold);
  margin: 0;
}

.error-detail {
  font-size: var(--font-size-xs);
  margin: 2px 0 0;
}

/* Table Container */
.table-container {
  background-color: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.table-responsive {
  overflow-x: auto;
}

.banners-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: var(--font-size-sm);
}

.banners-table th {
  padding: 12px 16px;
  background-color: rgba(15, 23, 42, 0.6);
  border-bottom: 1px solid var(--color-border);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-secondary);
}

.banners-table td {
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-border-subtle);
  vertical-align: middle;
}

.table-row:hover {
  background-color: var(--color-bg-card-hover);
}

.badge-sort {
  display: inline-block;
  padding: 2px 8px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-family: var(--font-family-mono);
  font-weight: var(--font-weight-bold);
  color: var(--color-primary);
}

/* Thumbnail */
.thumb-wrap {
  width: 140px;
  height: 52px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: center;
}

.mobile-thumb {
  width: 60px;
  height: 52px;
}

.thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb-fallback {
  font-size: 11px;
  color: var(--color-text-muted);
}

/* Comic Cell */
.comic-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.comic-header-line {
  display: flex;
  align-items: center;
  gap: 8px;
}

.comic-name {
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.status-approved-badge {
  font-size: 10px;
  padding: 1px 6px;
  background-color: var(--color-success-soft);
  color: var(--color-success);
  border-radius: var(--radius-xs);
  font-weight: var(--font-weight-semibold);
}

.title-override-line {
  font-size: var(--font-size-xs);
  display: flex;
  gap: 6px;
}

.label-override {
  color: var(--color-text-muted);
}

.value-override {
  color: var(--color-primary);
  font-style: italic;
}

/* Status Toggle Button */
.btn-status-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: var(--radius-full);
  font-size: 12px;
  font-weight: var(--font-weight-medium);
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-status-toggle.active {
  background-color: var(--color-success-soft);
  border-color: rgba(16, 185, 129, 0.4);
  color: var(--color-success);
}

.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background-color: currentColor;
}

/* Action Buttons */
.action-buttons {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.btn-action {
  padding: 5px 10px;
  font-size: var(--font-size-xs);
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
  background-color: var(--color-bg-elevated);
  color: var(--color-text-primary);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.btn-edit:hover {
  background-color: var(--color-primary-soft);
  color: var(--color-primary);
  border-color: var(--color-primary);
}

.btn-delete:hover {
  background-color: var(--color-danger-soft);
  color: var(--color-danger);
  border-color: var(--color-danger);
}

/* Generic Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 38px;
  padding: 0 var(--spacing-md);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: all var(--transition-fast);
  border: 1px solid transparent;
}

.btn-primary {
  background-color: var(--color-primary);
  color: var(--color-text-inverse);
  box-shadow: var(--shadow-sm);
}

.btn-primary:hover:not(:disabled) {
  background-color: var(--color-primary-hover);
  box-shadow: var(--shadow-glow);
}

.btn-outline {
  background-color: transparent;
  border-color: var(--color-border);
  color: var(--color-text-primary);
}

.btn-outline:hover:not(:disabled) {
  background-color: var(--color-bg-elevated);
  border-color: var(--color-border-subtle);
}

.btn-sm {
  height: 30px;
  padding: 0 var(--spacing-sm);
  font-size: var(--font-size-xs);
}

.spinning {
  display: inline-block;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Loading & Empty States */
.loading-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px var(--spacing-md);
  text-align: center;
  gap: var(--spacing-sm);
}

.empty-icon {
  font-size: 48px;
}

.empty-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  margin: 0;
}

.empty-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-secondary);
  max-width: 440px;
  margin: 0 0 var(--spacing-md);
}

/* Modal Styling */
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background-color: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--spacing-md);
}

.modal-card {
  width: 100%;
  max-width: 620px;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--spacing-md) var(--spacing-lg);
  border-bottom: 1px solid var(--color-border);
}

.modal-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  margin: 0;
}

.modal-close {
  background: transparent;
  border: none;
  font-size: 18px;
  color: var(--color-text-secondary);
  cursor: pointer;
}

.modal-form {
  padding: var(--spacing-lg);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-md);
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-row {
  display: flex;
  gap: var(--spacing-md);
  flex-wrap: wrap;
}

.flex-1 {
  flex: 1;
  min-width: 200px;
}

.form-label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.btn-retry-comics {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-primary);
  border-radius: var(--radius-sm);
  padding: 2px 8px;
  font-size: var(--font-size-xs);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all var(--transition-fast);
}

.btn-retry-comics:hover:not(:disabled) {
  background-color: var(--color-primary-bg, rgba(59, 130, 246, 0.1));
  border-color: var(--color-primary);
}

.btn-retry-comics:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.comics-error-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.empty-comics-warning {
  font-size: var(--font-size-xs);
  color: var(--color-warning, #f59e0b);
  margin: 0;
}

.form-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.required {
  color: var(--color-danger);
}

.form-input,
.form-select {
  height: 40px;
  padding: 0 12px;
  background-color: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--font-size-sm);
  outline: none;
  transition: border-color var(--transition-fast);
}

.form-input:focus,
.form-select:focus {
  border-color: var(--color-primary);
}

.form-input.is-invalid,
.form-select.is-invalid {
  border-color: var(--color-danger);
}

.file-upload-box {
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--spacing-md);
  background-color: var(--color-bg-elevated);
  display: flex;
  flex-direction: column;
  gap: var(--spacing-sm);
}

.file-input {
  font-size: var(--font-size-xs);
  color: var(--color-text-secondary);
}

.upload-preview-wrap {
  width: 100%;
  max-height: 120px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  position: relative;
  background: #000;
}

.mobile-preview-wrap {
  max-width: 140px;
  max-height: 100px;
}

.preview-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.preview-tag {
  position: absolute;
  bottom: 4px;
  right: 4px;
  background-color: rgba(0, 0, 0, 0.7);
  font-size: 10px;
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  color: #fff;
}

.upload-placeholder {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: var(--font-size-sm);
  cursor: pointer;
  margin-top: 8px;
}

.form-checkbox {
  width: 18px;
  height: 18px;
  cursor: pointer;
}

.help-text {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin: 0;
}

.error-feedback {
  font-size: var(--font-size-xs);
  color: var(--color-danger);
  margin: 0;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--spacing-sm);
  padding-top: var(--spacing-sm);
  border-top: 1px solid var(--color-border);
}

.spinner-sm {
  width: 14px;
  height: 14px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 768px) {
  .banners-table th:nth-child(3),
  .banners-table td:nth-child(3) {
    display: none; /* Hide mobile thumb column on tablet/mobile to save space */
  }
}
</style>
