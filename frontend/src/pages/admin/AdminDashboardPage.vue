<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AdminLayout from '@/layouts/AdminLayout.vue'
import { AdminService } from '@/services/adminService'
import type { AdminDashboardStats } from '@/types/admin'
import { getErrorMessage } from '@/services/httpClient'

const stats = ref<AdminDashboardStats | null>(null)
const isLoading = ref(true)
const errorMessage = ref<string | null>(null)

async function fetchStats() {
  isLoading.value = true
  errorMessage.value = null
  try {
    const res = await AdminService.getDashboardStats()
    stats.value = res.data
  } catch (err: unknown) {
    errorMessage.value = getErrorMessage(err, 'Không thể tải số liệu thống kê Dashboard.')
  } finally {
    isLoading.value = false
  }
}

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
  })
}

onMounted(() => {
  fetchStats()
})
</script>

<template>
  <AdminLayout>
    <div class="admin-dashboard-page">
    <!-- Header -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Bảng Điều Khiển Quản Trị (Dashboard)</h1>
        <p class="page-subtitle">Tổng quan chỉ số vận hành, hàng đợi kiểm duyệt và tình hình tài chính nền tảng ComicAI.</p>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" :disabled="isLoading" @click="fetchStats">
        🔄 Làm mới dữ liệu
      </button>
    </header>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="alert-error" role="alert">
      <span>⚠️ {{ errorMessage }}</span>
      <button type="button" class="btn btn-sm btn-outline" @click="fetchStats">Thử lại</button>
    </div>

    <!-- Loading State -->
    <div v-if="isLoading" class="loading-state">
      <span class="spinner"></span>
      <p>Đang tổng hợp dữ liệu thống kê...</p>
    </div>

    <div v-else-if="stats" class="dashboard-content">
      <!-- KPI Cards Grid -->
      <div class="kpi-grid">
        <!-- Moderation Queue -->
        <div class="kpi-card highlight-orange">
          <div class="kpi-header">
            <span class="kpi-title">Hàng đợi kiểm duyệt</span>
            <span class="kpi-icon">⏳</span>
          </div>
          <div class="kpi-value">{{ stats.queue.total_pending }}</div>
          <div class="kpi-breakdown">
            <RouterLink to="/admin/moderation/comics" class="breakdown-item">
              <span>Truyện chờ duyệt:</span>
              <strong>{{ stats.queue.pending_comics }}</strong>
            </RouterLink>
            <RouterLink to="/admin/moderation/chapters" class="breakdown-item">
              <span>Chương chờ duyệt:</span>
              <strong>{{ stats.queue.pending_chapters }}</strong>
            </RouterLink>
            <RouterLink to="/admin/creator-applications" class="breakdown-item">
              <span>Hồ sơ Creator:</span>
              <strong>{{ stats.queue.pending_creator_applications }}</strong>
            </RouterLink>
            <RouterLink to="/admin/reports" class="breakdown-item">
              <span>Báo cáo vi phạm:</span>
              <strong>{{ stats.queue.pending_reports }}</strong>
            </RouterLink>
          </div>
        </div>

        <!-- Users KPI -->
        <div class="kpi-card highlight-blue">
          <div class="kpi-header">
            <span class="kpi-title">Tổng người dùng</span>
            <span class="kpi-icon">👥</span>
          </div>
          <div class="kpi-value">{{ stats.users.total }}</div>
          <div class="kpi-breakdown">
            <div class="breakdown-item">
              <span>Mới 7 ngày qua:</span>
              <strong class="text-green">+{{ stats.users.new_7_days }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Mới 30 ngày qua:</span>
              <strong class="text-green">+{{ stats.users.new_30_days }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Tác giả (Creator):</span>
              <strong>{{ stats.users.creators }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Tài khoản bị khóa:</span>
              <strong class="text-red">{{ stats.users.locked }}</strong>
            </div>
          </div>
        </div>

        <!-- Catalog KPI -->
        <div class="kpi-card highlight-purple">
          <div class="kpi-header">
            <span class="kpi-title">Kho nội dung truyện</span>
            <span class="kpi-icon">📚</span>
          </div>
          <div class="kpi-value">{{ stats.catalog.total_comics }}</div>
          <div class="kpi-breakdown">
            <div class="breakdown-item">
              <span>Đã xuất bản (Approved):</span>
              <strong class="text-green">{{ stats.catalog.approved_comics }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Truyện VIP thu phí:</span>
              <strong class="text-amber">{{ stats.catalog.vip_comics }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Tổng số chương:</span>
              <strong>{{ stats.catalog.total_chapters }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Chương đã phát hành:</span>
              <strong>{{ stats.catalog.published_chapters }}</strong>
            </div>
          </div>
        </div>

        <!-- Finance KPI -->
        <div class="kpi-card highlight-green">
          <div class="kpi-header">
            <span class="kpi-title">Kinh tế & Doanh thu Coin</span>
            <span class="kpi-icon">💎</span>
          </div>
          <div class="kpi-value">{{ stats.finance.vip_revenue_coins }} <small>Coin</small></div>
          <div class="kpi-breakdown">
            <div class="breakdown-item">
              <span>Coin lưu thông trong ví:</span>
              <strong>{{ stats.finance.coins_in_circulation }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Lượt mua truyện VIP:</span>
              <strong>{{ stats.finance.total_vip_purchases }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Giao dịch hoàn tiền:</span>
              <strong class="text-red">{{ stats.finance.revoked_vip_purchases }}</strong>
            </div>
            <div class="breakdown-item">
              <span>Tỷ lệ mua hiệu lực:</span>
              <strong class="text-green">
                {{ stats.finance.total_vip_purchases > 0 ? Math.round((stats.finance.active_vip_purchases / stats.finance.total_vip_purchases) * 100) : 100 }}%
              </strong>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Action Buttons -->
      <div class="quick-actions-bar">
        <h3>Lối tắt quản trị nhanh</h3>
        <div class="actions-buttons-grid">
          <RouterLink to="/admin/moderation/comics" class="action-btn">
            <span>🛡️</span>
            <span>Duyệt truyện tranh</span>
          </RouterLink>
          <RouterLink to="/admin/moderation/chapters" class="action-btn">
            <span>📑</span>
            <span>Duyệt chương mới</span>
          </RouterLink>
          <RouterLink to="/admin/reports" class="action-btn">
            <span>⚠️</span>
            <span>Xử lý báo cáo</span>
          </RouterLink>
          <RouterLink to="/admin/broadcast-notifications" class="action-btn">
            <span>📢</span>
            <span>Phát thông báo hệ thống</span>
          </RouterLink>
          <RouterLink to="/admin/vip-purchases" class="action-btn">
            <span>💎</span>
            <span>Hoàn tiền mua VIP</span>
          </RouterLink>
          <RouterLink to="/admin/genres-tags" class="action-btn">
            <span>🏷️</span>
            <span>Thể loại & Thẻ</span>
          </RouterLink>
        </div>
      </div>

      <!-- Recent Activities Section -->
      <div class="recent-grid">
        <!-- Recent Pending Comics -->
        <div class="recent-card">
          <div class="recent-header">
            <h3>📚 Truyện đang chờ duyệt</h3>
            <RouterLink to="/admin/moderation/comics" class="view-all-link">Xem tất cả →</RouterLink>
          </div>
          <div v-if="stats.recent.pending_comics.length" class="items-list">
            <div v-for="c in stats.recent.pending_comics" :key="c.id" class="list-item">
              <div class="item-info">
                <strong>{{ c.title }}</strong>
                <span>Tác giả: {{ c.user?.name || 'Ẩn danh' }}</span>
              </div>
              <span class="item-time">{{ formatDate(c.created_at) }}</span>
            </div>
          </div>
          <p v-else class="text-muted empty-text">Hàng đợi truyện đang trống!</p>
        </div>

        <!-- Recent Pending Reports -->
        <div class="recent-card">
          <div class="recent-header">
            <h3>⚠️ Báo cáo vi phạm mới</h3>
            <RouterLink to="/admin/reports" class="view-all-link">Xem tất cả →</RouterLink>
          </div>
          <div v-if="stats.recent.pending_reports.length" class="items-list">
            <div v-for="r in stats.recent.pending_reports" :key="r.id" class="list-item">
              <div class="item-info">
                <strong>{{ r.reason }}</strong>
                <span>Người báo cáo: {{ r.reporter?.name || 'Ẩn danh' }}</span>
              </div>
              <span class="item-time">{{ formatDate(r.created_at) }}</span>
            </div>
          </div>
          <p v-else class="text-muted empty-text">Không có báo cáo vi phạm nào đang chờ!</p>
        </div>

        <!-- Recent VIP Purchases -->
        <div class="recent-card">
          <div class="recent-header">
            <h3>💎 Giao dịch mua VIP gần nhất</h3>
            <RouterLink to="/admin/vip-purchases" class="view-all-link">Xem tất cả →</RouterLink>
          </div>
          <div v-if="stats.recent.vip_purchases.length" class="items-list">
            <div v-for="p in stats.recent.vip_purchases" :key="p.id" class="list-item">
              <div class="item-info">
                <strong>{{ p.comic?.title || 'Truyện VIP' }}</strong>
                <span>Người mua: {{ p.user?.name || 'Độc giả' }}</span>
              </div>
              <div class="item-right">
                <span class="badge-price">{{ p.price_paid }} Coin</span>
                <span class="item-time">{{ formatDate(p.purchased_at) }}</span>
              </div>
            </div>
          </div>
          <p v-else class="text-muted empty-text">Chưa có giao dịch mua VIP gần đây.</p>
        </div>
      </div>
    </div>
    </div>
  </AdminLayout>
</template>

<style scoped>
.admin-dashboard-page {
  padding: 0;
  color: var(--text-main, #e2e8f0);
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
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

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 20px;
  margin-bottom: 28px;
}

.kpi-card {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  padding: 20px;
  display: flex;
  flex-direction: column;
}

.kpi-card.highlight-orange {
  border-top: 4px solid #f97316;
}
.kpi-card.highlight-blue {
  border-top: 4px solid #38bdf8;
}
.kpi-card.highlight-purple {
  border-top: 4px solid #a855f7;
}
.kpi-card.highlight-green {
  border-top: 4px solid #10b981;
}

.kpi-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.kpi-title {
  font-size: 14px;
  font-weight: 600;
  color: #94a3b8;
}

.kpi-icon {
  font-size: 20px;
}

.kpi-value {
  font-size: 32px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 16px;
}

.kpi-value small {
  font-size: 16px;
  font-weight: 500;
  color: #94a3b8;
}

.kpi-breakdown {
  display: flex;
  flex-direction: column;
  gap: 8px;
  border-top: 1px solid #334155;
  padding-top: 12px;
  font-size: 13px;
}

.breakdown-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  color: #cbd5e1;
  text-decoration: none;
}

a.breakdown-item:hover {
  color: #38bdf8;
}

.quick-actions-bar {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 28px;
}

.quick-actions-bar h3 {
  font-size: 16px;
  font-weight: 700;
  color: #fff;
  margin-bottom: 14px;
}

.actions-buttons-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.action-btn {
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  color: #e2e8f0;
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s;
}

.action-btn:hover {
  border-color: #38bdf8;
  background: rgba(56, 189, 248, 0.08);
  color: #38bdf8;
}

.recent-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
}

.recent-card {
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 12px;
  padding: 20px;
}

.recent-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.recent-header h3 {
  font-size: 15px;
  font-weight: 700;
  color: #fff;
}

.view-all-link {
  font-size: 12px;
  color: #38bdf8;
  text-decoration: none;
}

.view-all-link:hover {
  text-decoration: underline;
}

.items-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.list-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px;
  background: #0f172a;
  border-radius: 8px;
  border: 1px solid #334155;
}

.item-info strong {
  display: block;
  font-size: 13px;
  color: #fff;
}

.item-info span {
  font-size: 12px;
  color: #94a3b8;
}

.item-time {
  font-size: 11px;
  color: #64748b;
}

.badge-price {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
}

.item-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.empty-text {
  text-align: center;
  padding: 24px 0;
  font-size: 13px;
}

.text-green {
  color: #34d399;
}
.text-red {
  color: #f87171;
}
.text-amber {
  color: #fbbf24;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 8px;
  border: none;
  cursor: pointer;
}

.btn-secondary {
  background: #334155;
  color: #e2e8f0;
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid #ef4444;
  color: #fca5a5;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
}

.loading-state {
  padding: 60px 24px;
  text-align: center;
  color: #94a3b8;
}
</style>
