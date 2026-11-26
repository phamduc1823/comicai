<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminGenre, AdminTag } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

// Active Tab
const activeTab = ref<'genres' | 'tags'>('genres')

// State
const genres = ref<AdminGenre[]>([])
const tags = ref<AdminTag[]>([])
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)
const toastMessage = ref<{ text: string; type: 'success' | 'error' } | null>(null)

// Search & Filter
const searchQuery = ref('')
const genreStatusFilter = ref<string>('all')

// Modal State
const isModalOpen = ref(false)
const modalMode = ref<'create' | 'edit'>('create')
const modalType = ref<'genre' | 'tag'>('genre')
const isSaving = ref(false)
const formErrors = ref<Record<string, string>>({})

const formId = ref<number | null>(null)
const formName = ref('')
const formSlug = ref('')
const formDescription = ref('')
const formIsActive = ref(true)

// Delete Dialog
const isDeleteModalOpen = ref(false)
const deleteTarget = ref<{ type: 'genre' | 'tag'; id: number; name: string; comicsCount: number } | null>(null)
const isDeleting = ref(false)
const deleteError = ref<string | null>(null)

let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(text: string, type: 'success' | 'error' = 'success') {
  if (toastTimer) clearTimeout(toastTimer)
  toastMessage.value = { text, type }
  toastTimer = setTimeout(() => {
    toastMessage.value = null
  }, 4000)
}

// Fetch Genres
async function fetchGenres() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getGenres({
      q: searchQuery.value || undefined,
      is_active: genreStatusFilter.value !== 'all' ? genreStatusFilter.value : undefined,
      per_page: 100,
    })
    genres.value = res.data || []
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách thể loại.')
  } finally {
    isLoading.value = false
  }
}

// Fetch Tags
async function fetchTags() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getTags({
      q: searchQuery.value || undefined,
      per_page: 100,
    })
    tags.value = res.data || []
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải danh sách thẻ.')
  } finally {
    isLoading.value = false
  }
}

function handleTabChange(tab: 'genres' | 'tags') {
  activeTab.value = tab
  searchQuery.value = ''
  if (tab === 'genres') {
    fetchGenres()
  } else {
    fetchTags()
  }
}

function handleSearch() {
  if (activeTab.value === 'genres') {
    fetchGenres()
  } else {
    fetchTags()
  }
}

// Open Create Modal
function openCreateModal() {
  modalMode.value = 'create'
  modalType.value = activeTab.value === 'genres' ? 'genre' : 'tag'
  formId.value = null
  formName.value = ''
  formSlug.value = ''
  formDescription.value = ''
  formIsActive.value = true
  formErrors.value = {}
  isModalOpen.value = true
}

// Open Edit Modal
function openEditGenreModal(genre: AdminGenre) {
  modalMode.value = 'edit'
  modalType.value = 'genre'
  formId.value = genre.id
  formName.value = genre.name
  formSlug.value = genre.slug
  formDescription.value = genre.description || ''
  formIsActive.value = genre.is_active
  formErrors.value = {}
  isModalOpen.value = true
}

function openEditTagModal(tag: AdminTag) {
  modalMode.value = 'edit'
  modalType.value = 'tag'
  formId.value = tag.id
  formName.value = tag.name
  formSlug.value = tag.slug
  formDescription.value = tag.description || ''
  formIsActive.value = true
  formErrors.value = {}
  isModalOpen.value = true
}

// Save Modal
async function handleSave() {
  formErrors.value = {}
  if (!formName.value.trim()) {
    formErrors.value.name = 'Vui lòng nhập tên.'
    return
  }

  isSaving.value = true
  try {
    if (modalType.value === 'genre') {
      if (modalMode.value === 'create') {
        await AdminService.createGenre({
          name: formName.value.trim(),
          slug: formSlug.value.trim() || undefined,
          description: formDescription.value.trim() || undefined,
          is_active: formIsActive.value,
        })
        showToast('Tạo thể loại mới thành công!')
      } else if (formId.value) {
        await AdminService.updateGenre(formId.value, {
          name: formName.value.trim(),
          slug: formSlug.value.trim() || undefined,
          description: formDescription.value.trim() || undefined,
          is_active: formIsActive.value,
        })
        showToast('Cập nhật thể loại thành công!')
      }
      fetchGenres()
    } else {
      if (modalMode.value === 'create') {
        await AdminService.createTag({
          name: formName.value.trim(),
          slug: formSlug.value.trim() || undefined,
          description: formDescription.value.trim() || undefined,
        })
        showToast('Tạo thẻ truyện mới thành công!')
      } else if (formId.value) {
        await AdminService.updateTag(formId.value, {
          name: formName.value.trim(),
          slug: formSlug.value.trim() || undefined,
          description: formDescription.value.trim() || undefined,
        })
        showToast('Cập nhật thẻ truyện thành công!')
      }
      fetchTags()
    }
    isModalOpen.value = false
  } catch (err: unknown) {
    const msg = getErrorMessage(err, 'Lưu dữ liệu không thành công.')
    formErrors.value.general = msg
  } finally {
    isSaving.value = false
  }
}

// Toggle Genre Status
async function handleToggleGenreStatus(genre: AdminGenre) {
  try {
    await AdminService.toggleGenreStatus(genre.id)
    genre.is_active = !genre.is_active
    showToast(`Đã ${genre.is_active ? 'kích hoạt' : 'tạm ngưng'} thể loại "${genre.name}".`)
  } catch (err: unknown) {
    showToast(getErrorMessage(err, 'Không thể thay đổi trạng thái.'), 'error')
  }
}

// Open Delete Confirm
function confirmDelete(type: 'genre' | 'tag', item: AdminGenre | AdminTag) {
  deleteTarget.value = {
    type,
    id: item.id,
    name: item.name,
    comicsCount: item.comics_count ?? 0,
  }
  deleteError.value = null
  isDeleteModalOpen.value = true
}

async function handleDelete() {
  if (!deleteTarget.value) return
  isDeleting.value = true
  deleteError.value = null
  try {
    if (deleteTarget.value.type === 'genre') {
      await AdminService.deleteGenre(deleteTarget.value.id)
      showToast(`Đã xóa thể loại "${deleteTarget.value.name}".`)
      fetchGenres()
    } else {
      await AdminService.deleteTag(deleteTarget.value.id)
      showToast(`Đã xóa thẻ "${deleteTarget.value.name}".`)
      fetchTags()
    }
    isDeleteModalOpen.value = false
  } catch (err: unknown) {
    deleteError.value = getErrorMessage(err, 'Không thể xóa mục này.')
  } finally {
    isDeleting.value = false
  }
}

onMounted(() => {
  fetchGenres()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-genres-tags-page">
    <!-- Toast Notification -->
    <div v-if="toastMessage" class="toast-wrapper" :class="toastMessage.type" role="status">
      <span class="toast-icon">{{ toastMessage.type === 'success' ? '✅' : '⚠️' }}</span>
      <span class="toast-text">{{ toastMessage.text }}</span>
    </div>

    <!-- Page Header -->
    <header class="page-header">
      <div class="header-left">
        <h1 class="page-title">Quản lý Thể loại & Thẻ truyện</h1>
        <p class="page-subtitle">Phân loại danh mục truyện, gán nhãn chủ đề và điều khiển trạng thái hiển thị.</p>
      </div>
      <div class="header-actions">
        <button type="button" class="btn btn-primary" @click="openCreateModal">
          ➕ Thêm {{ activeTab === 'genres' ? 'Thể loại mới' : 'Thẻ mới' }}
        </button>
      </div>
    </header>

    <!-- Tabs Nav -->
    <div class="tabs-container">
      <button
        type="button"
        class="tab-btn"
        :class="{ active: activeTab === 'genres' }"
        @click="handleTabChange('genres')"
      >
        🏷️ Thể loại ({{ genres.length }})
      </button>
      <button
        type="button"
        class="tab-btn"
        :class="{ active: activeTab === 'tags' }"
        @click="handleTabChange('tags')"
      >
        🔖 Thẻ truyện ({{ tags.length }})
      </button>
    </div>

    <!-- Filter Toolbar -->
    <div class="toolbar">
      <div class="search-wrap">
        <input
          v-model="searchQuery"
          type="text"
          class="search-input"
          :placeholder="activeTab === 'genres' ? 'Tìm theo tên thể loại, slug...' : 'Tìm theo tên thẻ, slug...'"
          @keyup.enter="handleSearch"
        />
        <button type="button" class="btn btn-secondary btn-sm" @click="handleSearch">
          🔍 Tìm kiếm
        </button>
      </div>

      <div v-if="activeTab === 'genres'" class="filter-group">
        <label class="filter-label">Trạng thái:</label>
        <select v-model="genreStatusFilter" class="filter-select" @change="fetchGenres">
          <option value="all">Tất cả</option>
          <option value="1">Hoạt động</option>
          <option value="0">Tạm ngưng</option>
        </select>
      </div>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="handleTabChange(activeTab)">Thử lại</button>
    </div>

    <!-- Table Container -->
    <div class="table-container">
      <div v-if="isLoading" class="loading-state">
        <span class="spinner"></span>
        <p>Đang tải dữ liệu...</p>
      </div>

      <!-- Genres Table -->
      <table v-else-if="activeTab === 'genres' && genres.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 60px">ID</th>
            <th>Tên thể loại</th>
            <th>Slug</th>
            <th>Mô tả</th>
            <th style="width: 120px">Số truyện</th>
            <th style="width: 130px">Trạng thái</th>
            <th style="width: 140px; text-align: right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="g in genres" :key="g.id">
            <td>#{{ g.id }}</td>
            <td><strong>{{ g.name }}</strong></td>
            <td><code>{{ g.slug }}</code></td>
            <td class="text-muted">{{ g.description || '—' }}</td>
            <td>
              <span class="badge-count">{{ g.comics_count ?? 0 }} truyện</span>
            </td>
            <td>
              <button
                type="button"
                class="status-pill"
                :class="{ active: g.is_active }"
                @click="handleToggleGenreStatus(g)"
              >
                <span class="status-dot"></span>
                <span>{{ g.is_active ? 'Hoạt động' : 'Tạm ngưng' }}</span>
              </button>
            </td>
            <td style="text-align: right">
              <button type="button" class="btn-icon" title="Chỉnh sửa" @click="openEditGenreModal(g)">✏️</button>
              <button type="button" class="btn-icon danger" title="Xóa" @click="confirmDelete('genre', g)">🗑️</button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Tags Table -->
      <table v-else-if="activeTab === 'tags' && tags.length > 0" class="data-table">
        <thead>
          <tr>
            <th style="width: 60px">ID</th>
            <th>Tên thẻ</th>
            <th>Slug</th>
            <th>Mô tả</th>
            <th style="width: 120px">Số truyện</th>
            <th style="width: 140px; text-align: right">Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in tags" :key="t.id">
            <td>#{{ t.id }}</td>
            <td><strong>{{ t.name }}</strong></td>
            <td><code>{{ t.slug }}</code></td>
            <td class="text-muted">{{ t.description || '—' }}</td>
            <td>
              <span class="badge-count">{{ t.comics_count ?? 0 }} truyện</span>
            </td>
            <td style="text-align: right">
              <button type="button" class="btn-icon" title="Chỉnh sửa" @click="openEditTagModal(t)">✏️</button>
              <button type="button" class="btn-icon danger" title="Xóa" @click="confirmDelete('tag', t)">🗑️</button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Empty State -->
      <div v-else class="empty-state">
        <span class="empty-icon">📭</span>
        <h3>Không tìm thấy dữ liệu</h3>
        <p>Không có {{ activeTab === 'genres' ? 'thể loại' : 'thẻ' }} nào phù hợp với bộ lọc hiện tại.</p>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="isModalOpen" class="modal-backdrop" @click.self="isModalOpen = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>{{ modalMode === 'create' ? 'Tạo mới' : 'Chỉnh sửa' }} {{ modalType === 'genre' ? 'Thể loại' : 'Thẻ truyện' }}</h3>
          <button type="button" class="btn-close" @click="isModalOpen = false">✕</button>
        </div>

        <form @submit.prevent="handleSave">
          <div class="modal-body">
            <div v-if="formErrors.general" class="alert-error" role="alert">
              {{ formErrors.general }}
            </div>

            <div class="form-group">
              <label class="form-label">Tên <span class="req">*</span></label>
              <input v-model="formName" type="text" class="form-input" placeholder="Ví dụ: Hành Động" required />
              <p v-if="formErrors.name" class="form-error">{{ formErrors.name }}</p>
            </div>

            <div class="form-group">
              <label class="form-label">Slug (đường dẫn tĩnh)</label>
              <input v-model="formSlug" type="text" class="form-input" placeholder="Tự động tạo nếu để trống (ví dụ: hanh-dong)" />
            </div>

            <div class="form-group">
              <label class="form-label">Mô tả chi tiết</label>
              <textarea v-model="formDescription" class="form-textarea" rows="3" placeholder="Nhập tóm tắt mô tả nội dung..."></textarea>
            </div>

            <div v-if="modalType === 'genre'" class="form-group checkbox-group">
              <label class="checkbox-label">
                <input v-model="formIsActive" type="checkbox" />
                <span>Kích hoạt hiển thị cho độc giả và Creator</span>
              </label>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" :disabled="isSaving" @click="isModalOpen = false">Hủy</button>
            <button type="submit" class="btn btn-primary" :disabled="isSaving">
              {{ isSaving ? 'Đang lưu...' : 'Lưu thay đổi' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div v-if="isDeleteModalOpen" class="modal-backdrop" @click.self="isDeleteModalOpen = false">
      <div class="modal-box modal-sm">
        <div class="modal-header">
          <h3>Xác nhận xóa</h3>
          <button type="button" class="btn-close" @click="isDeleteModalOpen = false">✕</button>
        </div>
        <div class="modal-body">
          <div v-if="deleteError" class="alert-error" role="alert">
            {{ deleteError }}
          </div>
          <p>
            Bạn có chắc chắn muốn xóa {{ deleteTarget?.type === 'genre' ? 'thể loại' : 'thẻ' }}
            <strong>"{{ deleteTarget?.name }}"</strong> không?
          </p>
          <div v-if="(deleteTarget?.comicsCount ?? 0) > 0" class="alert-warning">
            ⚠️ Mục này hiện đang có <strong>{{ deleteTarget?.comicsCount }} bộ truyện</strong> liên kết. Hệ thống sẽ chặn xóa để bảo toàn tính toàn vẹn dữ liệu. Bạn nên chuyển sang trạng thái Tạm ngưng thay vì xóa.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="isDeleting" @click="isDeleteModalOpen = false">Hủy</button>
          <button type="button" class="btn btn-danger" :disabled="isDeleting" @click="handleDelete">
            {{ isDeleting ? 'Đang xóa...' : 'Đồng ý xóa' }}
          </button>
        </div>
      </div>
    </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-genres-tags-page {
  padding: 0;
  color: var(--text-main, #e2e8f0);
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  gap: 16px;
}

.page-title {
  font-size: 24px;
  font-weight: 700;
  color: #fff;
}

.page-subtitle {
  color: #94a3b8;
  font-size: 14px;
  margin-top: 4px;
}

.tabs-container {
  display: flex;
  gap: 12px;
  border-bottom: 1px solid #334155;
  margin-bottom: 20px;
}

.tab-btn {
  background: transparent;
  border: none;
  color: #94a3b8;
  padding: 12px 20px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.2s;
}

.tab-btn.active {
  color: #38bdf8;
  border-bottom-color: #38bdf8;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  gap: 16px;
  flex-wrap: wrap;
}

.search-wrap {
  display: flex;
  gap: 8px;
  flex: 1;
  max-width: 450px;
}

.search-input {
  flex: 1;
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 14px;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-label {
  font-size: 13px;
  color: #94a3b8;
}

.filter-select {
  background: #1e293b;
  border: 1px solid #334155;
  color: #fff;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 14px;
}

.table-container {
  background: #1e293b;
  border-radius: 12px;
  border: 1px solid #334155;
  overflow: hidden;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 14px;
}

.data-table th,
.data-table td {
  padding: 14px 18px;
  border-bottom: 1px solid #334155;
}

.data-table th {
  background: #0f172a;
  color: #94a3b8;
  font-weight: 600;
  text-transform: uppercase;
  font-size: 12px;
  letter-spacing: 0.05em;
}

.badge-count {
  background: #334155;
  color: #38bdf8;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #334155;
  border: none;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 12px;
  color: #94a3b8;
  cursor: pointer;
  transition: all 0.2s;
}

.status-pill.active {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #94a3b8;
}

.status-pill.active .status-dot {
  background: #10b981;
}

.btn-icon {
  background: transparent;
  border: none;
  font-size: 16px;
  cursor: pointer;
  padding: 6px;
  border-radius: 6px;
  margin-left: 4px;
  transition: background 0.2s;
}

.btn-icon:hover {
  background: #334155;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 18px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-primary {
  background: #0284c7;
  color: #fff;
}
.btn-primary:hover {
  background: #0369a1;
}

.btn-secondary {
  background: #334155;
  color: #e2e8f0;
}

.btn-danger {
  background: #ef4444;
  color: #fff;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}

.modal-box {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  width: 100%;
  max-width: 500px;
  overflow: hidden;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 18px 24px;
  border-bottom: 1px solid #334155;
}

.modal-header h3 {
  font-size: 18px;
  font-weight: 700;
  color: #fff;
}

.btn-close {
  background: transparent;
  border: none;
  color: #94a3b8;
  font-size: 18px;
  cursor: pointer;
}

.modal-body {
  padding: 20px 24px;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid #334155;
  background: #0f172a;
}

.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
  margin-bottom: 6px;
}

.form-input,
.form-textarea {
  width: 100%;
  background: #0f172a;
  border: 1px solid #334155;
  color: #fff;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 14px;
}

.checkbox-group {
  margin-top: 12px;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  font-size: 14px;
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid #ef4444;
  color: #fca5a5;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 16px;
}

.alert-warning {
  background: rgba(245, 158, 11, 0.15);
  border: 1px solid #f59e0b;
  color: #fcd34d;
  padding: 12px 16px;
  border-radius: 8px;
  margin-top: 12px;
  font-size: 13px;
}

.loading-state,
.empty-state {
  padding: 48px 24px;
  text-align: center;
  color: #94a3b8;
}

.toast-wrapper {
  position: fixed;
  top: 24px;
  right: 24px;
  background: #0f172a;
  border: 1px solid #334155;
  padding: 14px 20px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  z-index: 1100;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
}

.toast-wrapper.success {
  border-color: #10b981;
}

.toast-wrapper.error {
  border-color: #ef4444;
}
</style>
