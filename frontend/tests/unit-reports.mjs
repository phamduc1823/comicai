/**
 * Unit tests for Violation Reporting System (CAI-QB1)
 * Verifies Frontend Services, Router configuration, Admin Layout, Admin UI,
 * Reader Report Modal, and integration in PostCard, CommentItem, ComicDetailPage.
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-QB1: Violation Reporting System Frontend Suite', () => {
  it('1. Router registers /admin/reports with admin role requirement', () => {
    const routerFile = fs.readFileSync(path.join(projectRoot, 'src/router/index.ts'), 'utf8')
    assert.match(routerFile, /path:\s*['"]\/admin\/reports['"]/, 'Route path /admin/reports must be registered')
    assert.match(routerFile, /name:\s*['"]admin-reports['"]/, 'Route name admin-reports must be registered')
    assert.match(routerFile, /roles:\s*\[['"]admin['"]\]/, 'Route must require admin role')
    assert.match(routerFile, /AdminReportsPage\.vue/, 'Route must dynamically import AdminReportsPage.vue')
  })

  it('2. AdminLayout includes Báo cáo vi phạm in sidebar and header titles', () => {
    const layoutFile = fs.readFileSync(path.join(projectRoot, 'src/layouts/AdminLayout.vue'), 'utf8')
    assert.match(layoutFile, /to=['"]\/admin\/reports['"]/, 'Sidebar must contain link to /admin/reports')
    assert.match(layoutFile, /Báo cáo vi phạm/, 'Sidebar must display text "Báo cáo vi phạm"')
    assert.match(layoutFile, /route\.path\.startsWith\(['"]\/admin\/reports['"]\)/, 'Sidebar item must have active state check')
    assert.match(layoutFile, /Quản lý Báo cáo vi phạm/, 'currentSectionTitle must map /reports to "Quản lý Báo cáo vi phạm"')
  })

  it('3. Types definition exports Report, ReportableType, and payloads', () => {
    const typesFile = fs.readFileSync(path.join(projectRoot, 'src/types/report.ts'), 'utf8')
    assert.match(typesFile, /export type ReportableType/, 'Must export ReportableType')
    assert.match(typesFile, /export type ReportStatus/, 'Must export ReportStatus')
    assert.match(typesFile, /export interface Report/, 'Must export Report interface')
    assert.match(typesFile, /export interface StoreReportPayload/, 'Must export StoreReportPayload')
    assert.match(typesFile, /export interface UpdateReportPayload/, 'Must export UpdateReportPayload')
    assert.match(typesFile, /export interface ListReportsParams/, 'Must export ListReportsParams')
  })

  it('4. ReportService and AdminService provide reporting API methods', () => {
    const reportServiceFile = fs.readFileSync(path.join(projectRoot, 'src/services/reportService.ts'), 'utf8')
    assert.match(reportServiceFile, /static async submitReport\(/, 'ReportService must provide submitReport')
    assert.match(reportServiceFile, /static async getReports\(/, 'ReportService must provide getReports')
    assert.match(reportServiceFile, /static async getReport\(/, 'ReportService must provide getReport')
    assert.match(reportServiceFile, /static async updateReport\(/, 'ReportService must provide updateReport')

    const adminServiceFile = fs.readFileSync(path.join(projectRoot, 'src/services/adminService.ts'), 'utf8')
    assert.match(adminServiceFile, /static async getReports\(/, 'AdminService must provide getReports')
    assert.match(adminServiceFile, /static async getReport\(/, 'AdminService must provide getReport')
    assert.match(adminServiceFile, /static async updateReport\(/, 'AdminService must provide updateReport')
  })

  it('5. AdminReportsPage implements moderation table, filters, and action modals', () => {
    const pageFile = fs.readFileSync(path.join(projectRoot, 'src/pages/admin/AdminReportsPage.vue'), 'utf8')
    assert.match(pageFile, /AdminService\.getReports/, 'Must call AdminService.getReports')
    assert.match(pageFile, /AdminService\.updateReport/, 'Must call AdminService.updateReport')
    assert.match(pageFile, /statusFilter/, 'Must support filtering by report status')
    assert.match(pageFile, /typeFilter/, 'Must support filtering by reportable_type')
    assert.match(pageFile, /reasonFilter/, 'Must support searching/filtering by reason')
    assert.match(pageFile, /isDetailModalOpen/, 'Must provide detailed view modal for reported target')
    assert.match(pageFile, /isActionModalOpen/, 'Must provide resolution action modal (resolved/dismissed)')
    assert.match(pageFile, /actionAdminNotes/, 'Must include admin notes input with character validation')
  })

  it('6. ReportModal provides accessible reader reporting form', () => {
    const modalFile = fs.readFileSync(path.join(projectRoot, 'src/components/common/ReportModal.vue'), 'utf8')
    assert.match(modalFile, /ReportService\.submitReport/, 'Must submit report via ReportService')
    assert.match(modalFile, /PRESET_REASONS/, 'Must define preset reason options')
    assert.match(modalFile, /description/, 'Must provide detailed description textarea')
    assert.match(modalFile, /authStore\.isAuthenticated/, 'Must check user authentication')
    assert.match(modalFile, /handleLoginRedirect/, 'Must provide redirect to login for guest users')
  })

  it('7. Community PostCard integrates ReportModal for non-authors', () => {
    const postCardFile = fs.readFileSync(path.join(projectRoot, 'src/components/community/PostCard.vue'), 'utf8')
    assert.match(postCardFile, /import ReportModal from ['"]@\/components\/common\/ReportModal\.vue['"]/, 'Must import ReportModal')
    assert.match(postCardFile, /btn-report/, 'Must render btn-report button')
    assert.match(postCardFile, /!isAuthor/, 'Report button must be restricted to non-authors')
    assert.match(postCardFile, /<ReportModal/, 'Must include ReportModal component in template')
    assert.match(postCardFile, /reportable-type=['"]post['"]/, 'Must bind reportable-type as post')
  })

  it('8. Community CommentItem integrates ReportModal for non-authors', () => {
    const commentFile = fs.readFileSync(path.join(projectRoot, 'src/components/community/CommentItem.vue'), 'utf8')
    assert.match(commentFile, /import ReportModal from ['"]@\/components\/common\/ReportModal\.vue['"]/, 'Must import ReportModal')
    assert.match(commentFile, /btn-report/, 'Must render btn-report button')
    assert.match(commentFile, /!isAuthor/, 'Report button must be restricted to non-authors')
    assert.match(commentFile, /<ReportModal/, 'Must include ReportModal component in template')
    assert.match(commentFile, /reportable-type=['"]comment['"]/, 'Must bind reportable-type as comment')
  })

  it('9. ComicDetailPage integrates ReportModal for non-creators', () => {
    const comicDetailFile = fs.readFileSync(path.join(projectRoot, 'src/pages/ComicDetailPage.vue'), 'utf8')
    assert.match(comicDetailFile, /import ReportModal from ['"]@\/components\/common\/ReportModal\.vue['"]/, 'Must import ReportModal')
    assert.match(comicDetailFile, /btn-report/, 'Must render btn-report button in user library actions')
    assert.match(comicDetailFile, /!isCreatorOfComic/, 'Report button must be restricted to non-creators')
    assert.match(comicDetailFile, /<ReportModal/, 'Must include ReportModal component in template')
    assert.match(comicDetailFile, /reportable-type=['"]comic['"]/, 'Must bind reportable-type as comic')
  })
})
