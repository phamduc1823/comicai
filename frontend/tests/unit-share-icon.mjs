/**
 * Unit Test Suite for CAI-UI-SHARE-ICON:
 * Thay icon chia sẻ theo phong cách Facebook (BlueToon / ComicAI)
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const FRONTEND_DIR = path.resolve(import.meta.dirname, '..')

const FACEBOOK_CURVED_PATH = 'M13 4v4c-6.575 1.028-9.02 6.788-10 12-.037.206 5.384-5.962 10-6v4l8-7-8-7z'

test('1. ComicCard.vue uses Facebook-style outline share icon', () => {
  const filePath = path.join(FRONTEND_DIR, 'src/components/ComicCard.vue')
  const content = fs.readFileSync(filePath, 'utf8')

  // Button checks
  assert.ok(content.includes('class="btn-card-share"'), 'Must have btn-card-share class')
  assert.ok(content.includes('title="Chia sẻ"'), 'Must have title="Chia sẻ"')
  assert.ok(content.includes('aria-label="Chia sẻ"'), 'Must have aria-label="Chia sẻ"')
  assert.ok(content.includes('@click.stop.prevent="handleShareClick"'), 'Must stop propagation on click')

  // SVG checks
  assert.ok(content.includes('share-icon-svg'), 'Must use share-icon-svg class')
  assert.ok(content.includes(FACEBOOK_CURVED_PATH), 'Must use Facebook curved share arrow path')
  assert.ok(content.includes('viewBox="0 0 24 24"'), 'Must have viewBox 0 0 24 24')
  assert.ok(content.includes('fill="none"'), 'Must be outline style (fill=none)')
  assert.ok(content.includes('stroke="currentColor"'), 'Must use stroke="currentColor"')

  // Dimensions & CSS checks
  assert.ok(content.includes('width="20"'), 'SVG width must be in 20-22px range')
  assert.ok(content.includes('height="20"'), 'SVG height must be in 20-22px range')
  assert.ok(content.includes('.btn-card-share:hover'), 'Must have hover effect styling')
  assert.ok(content.includes('scale('), 'Must have hover scale transition')
})

test('2. ComicDetailPage.vue uses Facebook-style outline share icon', () => {
  const filePath = path.join(FRONTEND_DIR, 'src/pages/ComicDetailPage.vue')
  const content = fs.readFileSync(filePath, 'utf8')

  // Button checks
  assert.ok(content.includes('class="btn-interact btn-share"'), 'Must have btn-interact btn-share classes')
  assert.ok(content.includes('title="Chia sẻ"'), 'Must have title="Chia sẻ"')
  assert.ok(content.includes('aria-label="Chia sẻ"'), 'Must have aria-label="Chia sẻ"')
  assert.ok(content.includes('@click="handleShareComic"'), 'Must call handleShareComic')

  // SVG checks
  assert.ok(content.includes(FACEBOOK_CURVED_PATH), 'Must use Facebook curved share arrow path')
  assert.ok(content.includes('fill="none"'), 'Must be outline style (fill=none)')
  assert.ok(content.includes('stroke="currentColor"'), 'Must use stroke="currentColor"')
  assert.ok(content.includes('width="20"'), 'SVG width must be in 20-22px range')
  assert.ok(content.includes('height="20"'), 'SVG height must be in 20-22px range')

  // CSS hover checks
  assert.ok(content.includes('.btn-share .share-icon-svg'), 'Must have dedicated share icon CSS')
  assert.ok(content.includes('.btn-share:hover:not(:disabled) .share-icon-svg'), 'Must scale and brighten on hover')
})

test('3. PostCard.vue uses Facebook-style outline share icon', () => {
  const filePath = path.join(FRONTEND_DIR, 'src/components/community/PostCard.vue')
  const content = fs.readFileSync(filePath, 'utf8')

  // Button checks
  assert.ok(content.includes('class="btn-stat btn-share"'), 'Must have btn-stat btn-share classes')
  assert.ok(content.includes('title="Chia sẻ"'), 'Must have title="Chia sẻ"')
  assert.ok(content.includes('aria-label="Chia sẻ"'), 'Must have aria-label="Chia sẻ"')
  assert.ok(content.includes('@click.stop.prevent="handleShareClick"'), 'Must prevent navigation and stop propagation')

  // SVG checks
  assert.ok(content.includes(FACEBOOK_CURVED_PATH), 'Must use Facebook curved share arrow path')
  assert.ok(content.includes('fill="none"'), 'Must be outline style (fill=none)')
  assert.ok(content.includes('stroke="currentColor"'), 'Must use stroke="currentColor"')
  assert.ok(content.includes('width="20"'), 'SVG width must be in 20-22px range')
  assert.ok(content.includes('height="20"'), 'SVG height must be in 20-22px range')

  // CSS hover checks
  assert.ok(content.includes('.btn-share .share-icon-svg'), 'Must have dedicated share icon CSS')
  assert.ok(content.includes('.btn-share:hover .share-icon-svg'), 'Must scale and brighten on hover')
})

test('4. No external sprite or URL dependencies are added', () => {
  const pkgPath = path.join(FRONTEND_DIR, 'package.json')
  const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'))

  assert.equal(pkg.dependencies['lucide-vue-next'], undefined, 'No unneeded lucide-vue-next dependency')
  assert.equal(pkg.dependencies['@fortawesome/fontawesome-svg-core'], undefined, 'No unneeded fontawesome dependency')
})

test('5. ShareRecipientModal initializes and loads recipients immediately on mount (immediate watch & onMounted)', () => {
  const modalPath = path.join(FRONTEND_DIR, 'src/components/chat/ShareRecipientModal.vue')
  const modalContent = fs.readFileSync(modalPath, 'utf8')

  assert.ok(modalContent.includes('immediate: true'), 'Watch on props.isOpen must have immediate: true')
  assert.ok(modalContent.includes('onMounted'), 'Must import and use onMounted lifecycle hook')
  assert.ok(modalContent.includes('initModal'), 'Must have initModal function to load recipients')

  const homeSearchPath = path.join(FRONTEND_DIR, 'src/components/HomeSearchSection.vue')
  const homeSearchContent = fs.readFileSync(homeSearchPath, 'utf8')
  assert.ok(homeSearchContent.includes('ShareRecipientModal'), 'HomeSearchSection must mount ShareRecipientModal')
  assert.ok(homeSearchContent.includes('@share="handleShareComic"'), 'HomeSearchSection must handle comic share')
})
