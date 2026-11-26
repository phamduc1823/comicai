/**
 * Unit tests for Admin Comic & Chapter Management (CAI-ADMIN-COMIC-CHAPTERS)
 * Verifies Frontend Services, Router configuration, Navigation hierarchy, and Component states.
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('Admin Comic & Chapter Management Frontend Suite', () => {
  it('1. Router registers /admin/comics and nested comic detail & chapters routes', () => {
    const routerFile = fs.readFileSync(path.join(projectRoot, 'src/router/index.ts'), 'utf8')
    assert.match(routerFile, /path:\s*['"]\/admin\/comics['"]/, 'Route /admin/comics must be registered')
    assert.match(routerFile, /path:\s*['"]\/admin\/comics\/:comicId['"]/, 'Route /admin/comics/:comicId must be registered')
    assert.match(routerFile, /path:\s*['"]\/admin\/comics\/:comicId\/chapters['"]/, 'Route /admin/comics/:comicId/chapters must be registered')
    assert.match(routerFile, /path:\s*['"]\/admin\/comics\/:comicId\/chapters\/:chapterId['"]/, 'Route /admin/comics/:comicId/chapters/:chapterId must be registered')
    assert.match(routerFile, /AdminComicDetailPage\.vue/, 'Routes must load AdminComicDetailPage.vue')
  })

  it('2. AdminLayout structures navigation hierarchy (Quản lý truyện -> Chi tiết truyện -> Tab Chương)', () => {
    const layoutFile = fs.readFileSync(path.join(projectRoot, 'src/layouts/AdminLayout.vue'), 'utf8')
    assert.match(layoutFile, /to=['"]\/admin\/comics['"]/, 'Sidebar must contain link to /admin/comics')
    assert.match(layoutFile, /Quản lý truyện/, 'Sidebar must display "Quản lý truyện"')
    assert.match(layoutFile, /route\.path\.startsWith\(['"]\/admin\/comics['"]\)/, 'Active check must cover all /admin/comics sub-routes')
    assert.doesNotMatch(layoutFile, /to=['"]\/admin\/chapters['"]/, 'Standalone chapters catalog must not be a primary sidebar menu item')
  })

  it('3. AdminService exports required comic detail and contextual chapter API methods', () => {
    const serviceFile = fs.readFileSync(path.join(projectRoot, 'src/services/adminService.ts'), 'utf8')
    assert.match(serviceFile, /static async getComicsCatalog\(/, 'Must export getComicsCatalog')
    assert.match(serviceFile, /static async getComicDetail\(/, 'Must export getComicDetail')
    assert.match(serviceFile, /static async getComicChapters\(/, 'Must export getComicChapters')
    assert.match(serviceFile, /static async getComicChapterDetail\(/, 'Must export getComicChapterDetail')
    assert.match(serviceFile, /static async updateComicChapterStatus\(/, 'Must export updateComicChapterStatus')
  })

  it('4. Types definition includes comic detail and chapter management interfaces', () => {
    const typesFile = fs.readFileSync(path.join(projectRoot, 'src/types/admin.ts'), 'utf8')
    assert.match(typesFile, /export interface AdminComicDetail/, 'Must export AdminComicDetail')
    assert.match(typesFile, /export interface AdminComicChapterItem/, 'Must export AdminComicChapterItem')
    assert.match(typesFile, /export interface AdminComicChapterDetail/, 'Must export AdminComicChapterDetail')
    assert.match(typesFile, /export interface ListAdminComicChaptersParams/, 'Must export ListAdminComicChaptersParams')
    assert.match(typesFile, /export interface ListAdminComicsCatalogParams/, 'Must export ListAdminComicsCatalogParams')
  })

  it('5. AdminComicsCatalogPage implements creator filter and navigation to comic detail', () => {
    const catalogFile = fs.readFileSync(path.join(projectRoot, 'src/pages/admin/AdminComicsCatalogPage.vue'), 'utf8')
    assert.match(catalogFile, /creatorQuery/, 'Must have creatorQuery filter state')
    assert.match(catalogFile, /Lọc theo Creator/, 'Must have placeholder or label for creator filter')
    assert.match(catalogFile, /to="['"]\/admin\/comics\/['"]\s*\+\s*c\.id/, 'Must link directly to /admin/comics/:id')
    assert.match(catalogFile, /formatDate/, 'Must format dates properly')
  })

  it('6. AdminComicDetailPage implements Overview and Chapters tabs, pagination, modals, and states', () => {
    const detailFile = fs.readFileSync(path.join(projectRoot, 'src/pages/admin/AdminComicDetailPage.vue'), 'utf8')
    assert.match(detailFile, /Quay lại Danh sách truyện/, 'Must have back link to comic catalog')
    assert.match(detailFile, /switchTab\(['"]overview['"]\)/, 'Must support overview tab')
    assert.match(detailFile, /switchTab\(['"]chapters['"]\)/, 'Must support chapters tab')
    assert.match(detailFile, /fetchChapters/, 'Must fetch chapters with backend pagination')
    assert.match(detailFile, /perPage/, 'Must allow perPage configuration')
    assert.match(detailFile, /isChapterDetailModalOpen/, 'Must have chapter detail modal')
    assert.match(detailFile, /isChapterStatusModalOpen/, 'Must have chapter status modal')
    assert.match(detailFile, /webtoon-gallery/, 'Must have webtoon gallery for reading pages')
    assert.match(detailFile, /loading-state/, 'Must render loading state')
    assert.match(detailFile, /empty-state/, 'Must render empty state')
    assert.match(detailFile, /alert-error/, 'Must render error state with retry')
  })
})
