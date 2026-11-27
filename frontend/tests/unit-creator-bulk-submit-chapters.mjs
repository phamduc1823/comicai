import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(process.cwd())

test('Bulk Chapter Review Suite — Frontend & Service Verification', async (t) => {
  const creatorServicePath = path.join(projectRoot, 'frontend', 'src', 'services', 'creatorService.ts')
  const creatorChaptersPagePath = path.join(projectRoot, 'frontend', 'src', 'pages', 'creator', 'CreatorChaptersPage.vue')
  const apiRoutesPath = path.join(projectRoot, 'backend', 'routes', 'api.php')
  const controllerPath = path.join(projectRoot, 'backend', 'app', 'Http', 'Controllers', 'Api', 'CreatorChapterController.php')

  assert.ok(fs.existsSync(creatorServicePath), 'creatorService.ts must exist')
  assert.ok(fs.existsSync(creatorChaptersPagePath), 'CreatorChaptersPage.vue must exist')
  assert.ok(fs.existsSync(apiRoutesPath), 'api.php must exist')
  assert.ok(fs.existsSync(controllerPath), 'CreatorChapterController.php must exist')

  const creatorServiceContent = fs.readFileSync(creatorServicePath, 'utf8')
  const creatorChaptersContent = fs.readFileSync(creatorChaptersPagePath, 'utf8')
  const apiRoutesContent = fs.readFileSync(apiRoutesPath, 'utf8')
  const controllerContent = fs.readFileSync(controllerPath, 'utf8')

  await t.test('1. Backend route and controller support bulkSubmitReview', () => {
    assert.match(
      apiRoutesContent,
      /Route::post\('\/comics\/\{comicId\}\/chapters\/bulk-submit-review',\s*\[CreatorChapterController::class,\s*'bulkSubmitReview'\]\);/,
      'Route bulk-submit-review must be declared in api.php'
    )
    assert.match(
      controllerContent,
      /public function bulkSubmitReview\(Request \$request, int\|string \$comicId, NotificationService \$notificationService\): JsonResponse/,
      'CreatorChapterController must implement bulkSubmitReview with NotificationService'
    )
    assert.match(
      controllerContent,
      /Chapter::whereIn\('id', \$selectedChapterIds\)\s*->lockForUpdate\(\)\s*->update\(\[/,
      'bulkSubmitReview must use lockForUpdate inside transaction'
    )
  })

  await t.test('2. CreatorService exports bulkSubmitChapterReview', () => {
    assert.match(
      creatorServiceContent,
      /static async bulkSubmitChapterReview\(/,
      'CreatorService must export bulkSubmitChapterReview method'
    )
    assert.match(
      creatorServiceContent,
      /\/creator\/comics\/\$\{encodedComicId\}\/chapters\/bulk-submit-review/,
      'bulkSubmitChapterReview must POST to correct endpoint'
    )
  })

  await t.test('3. CreatorChaptersPage header contains Bulk Submit button', () => {
    assert.match(
      creatorChaptersContent,
      /<button[^>]*class="[^"]*btn-bulk-submit[^"]*"[^>]*@click="openBulkSubmitModal"[^>]*>/,
      'Header actions must contain Gửi duyệt hàng loạt button triggering openBulkSubmitModal'
    )
    assert.match(
      creatorChaptersContent,
      /Gửi duyệt hàng loạt/,
      'Button label must read "Gửi duyệt hàng loạt"'
    )
    assert.match(
      creatorChaptersContent,
      /selectedChapterIds\.length/,
      'Button should display count badge when chapters are selected'
    )
  })

  await t.test('4. Chapters table includes selection checkbox column and select-all in header', () => {
    assert.match(
      creatorChaptersContent,
      /<th scope="col" class="th-select">/,
      'Table header must include th-select column'
    )
    assert.match(
      creatorChaptersContent,
      /<input[^>]*type="checkbox"[^>]*@change="toggleSelectAllVisible"[^>]*\/>/,
      'Header th-select must contain select-all checkbox'
    )
    assert.match(
      creatorChaptersContent,
      /<td class="td-select">/,
      'Table row must include td-select'
    )
    assert.match(
      creatorChaptersContent,
      /class="chapter-row-checkbox"/,
      'Selectable row must have chapter-row-checkbox'
    )
    assert.match(
      creatorChaptersContent,
      /is-row-selected/,
      'Row must highlight when selected'
    )
  })

  await t.test('5. Bulk Submit Modal provides chapter checklist, select all, deselect all, and confirmation', () => {
    assert.match(
      creatorChaptersContent,
      /isBulkSubmitModalOpen/,
      'Modal must be conditioned on isBulkSubmitModalOpen'
    )
    assert.match(
      creatorChaptersContent,
      /Gửi duyệt chương hàng loạt:/,
      'Modal title must announce bulk chapter review'
    )
    assert.match(
      creatorChaptersContent,
      /@click="selectAllBulkChapters"/,
      'Modal must provide selectAllBulkChapters button'
    )
    assert.match(
      creatorChaptersContent,
      /@click="deselectAllBulkChapters"/,
      'Modal must provide deselectAllBulkChapters button'
    )
    assert.match(
      creatorChaptersContent,
      /class="chapter-check-item"/,
      'Modal checklist must render chapter-check-item elements'
    )
    assert.match(
      creatorChaptersContent,
      /@click="handleConfirmBulkSubmit"/,
      'Modal footer confirm button must call handleConfirmBulkSubmit'
    )
  })

  await t.test('6. Chapters with 0 images are prevented from being submitted', () => {
    assert.match(
      creatorChaptersContent,
      /\(chap\.images_count\s*\|\|\s*0\)\s*===\s*0/,
      'Chapters with 0 images must be marked disabled or warning'
    )
    assert.match(
      controllerContent,
      /\$chapter->images_count === 0/,
      'Backend controller must strictly check images_count > 0'
    )
  })
})
