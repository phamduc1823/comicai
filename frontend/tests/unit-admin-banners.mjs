/**
 * Unit tests for Admin Banner Management (CAI-QA)
 * Verifies Frontend Services, Router configuration, Sidebar navigation, and Form state logic.
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-QA: Admin Banner Management Frontend Suite', () => {
  it('1. Router registers /admin/banners with admin role requirement', () => {
    const routerFile = fs.readFileSync(path.join(projectRoot, 'src/router/index.ts'), 'utf8')
    assert.match(routerFile, /path:\s*['"]\/admin\/banners['"]/, 'Route path /admin/banners must be registered')
    assert.match(routerFile, /name:\s*['"]admin-banners['"]/, 'Route name admin-banners must be registered')
    assert.match(routerFile, /roles:\s*\[['"]admin['"]\]/, 'Route must require admin role')
    assert.match(routerFile, /AdminBannersPage\.vue/, 'Route must import AdminBannersPage.vue')
  })

  it('2. AdminLayout includes Quản lý Banner Slider in sidebar navigation', () => {
    const layoutFile = fs.readFileSync(path.join(projectRoot, 'src/layouts/AdminLayout.vue'), 'utf8')
    assert.match(layoutFile, /to=['"]\/admin\/banners['"]/, 'Sidebar must contain link to /admin/banners')
    assert.match(layoutFile, /Quản lý Banner Slider/, 'Sidebar must display text "Quản lý Banner Slider"')
    assert.match(layoutFile, /route\.path\.startsWith\(['"]\/admin\/banners['"]\)/, 'Sidebar item must have active state check')
  })

  it('3. AdminService exports banner management CRUD methods', () => {
    const serviceFile = fs.readFileSync(path.join(projectRoot, 'src/services/adminService.ts'), 'utf8')
    assert.match(serviceFile, /static async getBanners\(\)/, 'Must export getBanners')
    assert.match(serviceFile, /static async createBanner\(/, 'Must export createBanner')
    assert.match(serviceFile, /static async updateBanner\(/, 'Must export updateBanner')
    assert.match(serviceFile, /static async deleteBanner\(/, 'Must export deleteBanner')
    assert.match(serviceFile, /static async toggleBannerStatus\(/, 'Must export toggleBannerStatus')
    assert.match(serviceFile, /static async reorderBanners\(/, 'Must export reorderBanners')
  })

  it('4. Types definition includes AdminBanner and ReorderBannersPayload', () => {
    const typesFile = fs.readFileSync(path.join(projectRoot, 'src/types/admin.ts'), 'utf8')
    assert.match(typesFile, /export interface AdminBanner/, 'Must export AdminBanner interface')
    assert.match(typesFile, /desktop_banner_path:\s*string/, 'AdminBanner must have desktop_banner_path')
    assert.match(typesFile, /desktop_banner_url:\s*string\s*\|\s*null/, 'AdminBanner must have desktop_banner_url')
    assert.match(typesFile, /mobile_banner_path:\s*string\s*\|\s*null/, 'AdminBanner must have mobile_banner_path')
    assert.match(typesFile, /mobile_banner_url:\s*string\s*\|\s*null/, 'AdminBanner must have mobile_banner_url')
    assert.match(typesFile, /is_active:\s*boolean/, 'AdminBanner must have is_active boolean')
    assert.match(typesFile, /sort_order:\s*number/, 'AdminBanner must have sort_order number')
    assert.match(typesFile, /export interface ReorderBannersPayload/, 'Must export ReorderBannersPayload interface')
  })

  it('5. AdminBannersPage implements required modal, table, and confirmation dialogs', () => {
    const pageFile = fs.readFileSync(path.join(projectRoot, 'src/pages/admin/AdminBannersPage.vue'), 'utf8')
    assert.match(pageFile, /ConfirmModal/, 'Must use ConfirmModal for deletion confirmation')
    assert.match(pageFile, /AdminService\.getBanners/, 'Must call AdminService.getBanners on mount')
    assert.match(pageFile, /AdminService\.createBanner/, 'Must call AdminService.createBanner on submit')
    assert.match(pageFile, /AdminService\.updateBanner/, 'Must call AdminService.updateBanner on edit')
    assert.match(pageFile, /AdminService\.deleteBanner/, 'Must call AdminService.deleteBanner on delete')
    assert.match(pageFile, /AdminService\.toggleBannerStatus/, 'Must call AdminService.toggleBannerStatus on toggle')
    assert.match(pageFile, /handleDesktopFileChange/, 'Must handle desktop file change with preview')
    assert.match(pageFile, /handleMobileFileChange/, 'Must handle mobile file change with preview')
    assert.match(pageFile, /approvedComics/, 'Must fetch and display approved comics in select dropdown')
    assert.match(pageFile, /per_page:\s*50/, 'Must request per_page: 50 to comply with backend ListComicsRequest validation')
    assert.match(pageFile, /comicsLoadError/, 'Must track and render comicsLoadError state with retry mechanism')
    assert.match(pageFile, /btn-retry-comics/, 'Must provide retry button for fetching approved comics')
  })
})
