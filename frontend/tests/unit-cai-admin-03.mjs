/**
 * Unit Test Suite for Task CAI-ADMIN-03:
 * - Scroll behavior (Public reset to top, Admin protection)
 * - Creator submit review with chapter selection
 * - Admin review with chapter selection
 * - Consolidated comic notifications & NotificationBell routing
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

test('CAI-ADMIN-03: 1. Router scrollBehavior strictly protects Admin and resets public reader to top', () => {
  const routerPath = path.join(projectRoot, 'src/router/index.ts')
  const content = fs.readFileSync(routerPath, 'utf8')

  assert.ok(content.includes('scrollBehavior(to, from, savedPosition)'), 'Must configure scrollBehavior in createRouter')
  assert.ok(
    content.includes("to.path.startsWith('/admin') || from.path.startsWith('/admin')"),
    'Must strictly protect admin navigation by checking /admin routes'
  )
  assert.ok(content.includes('return false'), 'Admin routes must return false to preserve sidebar and admin layout scroll')
  assert.ok(
    content.includes("to.name === 'comic-detail'") || content.includes("to.path.startsWith('/comics')"),
    'Must reset scroll to top { top: 0, left: 0 } when opening comic from home page or navigating to reader'
  )
})

test('CAI-ADMIN-03: 2. Public reader and comic detail pages assert window scroll to top on mount', () => {
  const readerPath = path.join(projectRoot, 'src/pages/ComicReaderPage.vue')
  const detailPath = path.join(projectRoot, 'src/pages/ComicDetailPage.vue')

  const readerContent = fs.readFileSync(readerPath, 'utf8')
  const detailContent = fs.readFileSync(detailPath, 'utf8')

  assert.ok(readerContent.includes('window.scrollTo(0, 0)'), 'ComicReaderPage must reset scroll to top on mount')
  assert.ok(readerContent.includes('scrollRestoration'), 'ComicReaderPage must configure manual scrollRestoration on refresh')
  assert.ok(detailContent.includes('window.scrollTo(0, 0)'), 'ComicDetailPage must reset scroll to top on mount')
})

test('CAI-ADMIN-03: 3. CreatorComicsPage provides chapter selection for submitting review', () => {
  const creatorPagePath = path.join(projectRoot, 'src/pages/creator/CreatorComicsPage.vue')
  const content = fs.readFileSync(creatorPagePath, 'utf8')

  assert.ok(content.includes('isSubmitReviewModalOpen'), 'Must have isSubmitReviewModalOpen state')
  assert.ok(content.includes('selectedChapterIds'), 'Must track selectedChapterIds')
  assert.ok(content.includes('selectAllReviewableChapters'), 'Must have selectAllReviewableChapters')
  assert.ok(content.includes('deselectAllReviewableChapters'), 'Must have deselectAllReviewableChapters')
  assert.ok(content.includes('toggleReviewableChapter'), 'Must have toggleReviewableChapter')
  assert.ok(content.includes('CreatorService.submitReview'), 'Must call CreatorService.submitReview with selected chapters')
})

test('CAI-ADMIN-03: 4. AdminComicsModerationPage and AdminComicDetailPage support chapter selection on approval', () => {
  const modPagePath = path.join(projectRoot, 'src/pages/admin/AdminComicsModerationPage.vue')
  const detailPagePath = path.join(projectRoot, 'src/pages/admin/AdminComicDetailPage.vue')

  const modContent = fs.readFileSync(modPagePath, 'utf8')
  const detailContent = fs.readFileSync(detailPagePath, 'utf8')

  assert.ok(modContent.includes('pendingChaptersForApprove'), 'AdminComicsModerationPage must track pendingChaptersForApprove')
  assert.ok(modContent.includes('selectedApproveChapterIds'), 'AdminComicsModerationPage must track selectedApproveChapterIds')
  assert.ok(modContent.includes('selectAllApproveChapters'), 'AdminComicsModerationPage must support select all chapters')
  assert.ok(modContent.includes('AdminService.approveComic'), 'AdminComicsModerationPage must call approveComic with chapter IDs')

  assert.ok(detailContent.includes('pendingChaptersForDetailStatus'), 'AdminComicDetailPage must track pending chapters for status modal')
  assert.ok(detailContent.includes('selectedDetailStatusChapterIds'), 'AdminComicDetailPage must track selected chapters for status modal')
})

test('CAI-ADMIN-03: 5. NotificationBell and notification types support consolidated comic review routing', () => {
  const bellPath = path.join(projectRoot, 'src/components/NotificationBell.vue')
  const typesPath = path.join(projectRoot, 'src/types/notification.ts')

  const bellContent = fs.readFileSync(bellPath, 'utf8')
  const typesContent = fs.readFileSync(typesPath, 'utf8')

  assert.ok(bellContent.includes("item.target?.type === 'comic_moderation'"), 'NotificationBell must handle comic_moderation target')
  assert.ok(bellContent.includes("item.target?.type === 'creator_comic'"), 'NotificationBell must handle creator_comic target')

  assert.ok(typesContent.includes('comic_submitted_for_review'), 'Types must declare comic_submitted_for_review')
  assert.ok(typesContent.includes('comic_review_result'), 'Types must declare comic_review_result')
})
