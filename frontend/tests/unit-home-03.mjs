import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

test('CAI-HOME-03: Homepage Sections, Featured Comics & Integer Chapter Numbering Suite', async (t) => {
  const projectRoot = process.cwd()

  const discoveryFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/components/HomeDiscoverySections.vue'),
    'utf-8'
  )
  const cardFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/components/ComicCard.vue'),
    'utf-8'
  )
  const creatorChaptersPage = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/pages/creator/CreatorChaptersPage.vue'),
    'utf-8'
  )
  const adminComicsPage = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/pages/admin/AdminComicsCatalogPage.vue'),
    'utf-8'
  )
  const adminDetailPage = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/pages/admin/AdminComicDetailPage.vue'),
    'utf-8'
  )
  const adminServiceFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/services/adminService.ts'),
    'utf-8'
  )
  const creatorServiceFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/services/creatorService.ts'),
    'utf-8'
  )
  const chapterUtilsFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/utils/chapter.ts'),
    'utf-8'
  )
  const adminComicController = fs.readFileSync(
    path.resolve(projectRoot, 'backend/app/Http/Controllers/Api/AdminComicCatalogController.php'),
    'utf-8'
  )
  const creatorChapterController = fs.readFileSync(
    path.resolve(projectRoot, 'backend/app/Http/Controllers/Api/CreatorChapterController.php'),
    'utf-8'
  )
  const chapterModel = fs.readFileSync(
    path.resolve(projectRoot, 'backend/app/Models/Chapter.php'),
    'utf-8'
  )
  const storeChapterRequest = fs.readFileSync(
    path.resolve(projectRoot, 'backend/app/Http/Requests/StoreCreatorChapterRequest.php'),
    'utf-8'
  )

  await t.test('1. Admin featured comic toggle endpoint, service and UI buttons', () => {
    // Backend controller has updateFeatured method
    assert.match(adminComicController, /function updateFeatured\(Request \$request, int \$id\)/)
    // AdminService has updateComicFeatured
    assert.match(adminServiceFile, /updateComicFeatured/)
    // AdminComicsCatalogPage has toggle button and updating state
    assert.match(adminComicsPage, /btn-featured-toggle/)
    assert.match(adminComicsPage, /handleToggleFeatured/)
    // AdminComicDetailPage has featured toggle
    assert.match(adminDetailPage, /handleToggleFeaturedDetail/)
  })

  await t.test('2. 5 Homepage discovery sections strictly limited to 6 comics', () => {
    // Requests must specify per_page: 6 for all 5 sections
    const matches = discoveryFile.match(/per_page:\s*6/g)
    assert.ok(matches && matches.length >= 5, 'All 5 discovery sections must request per_page: 6')
    // Deduplication & limit helper
    assert.match(discoveryFile, /dedupeAndLimit/)
    // CSS grid supports 6 columns
    assert.match(discoveryFile, /repeat\(6,\s*1fr\)/)
  })

  await t.test('3. ComicCard standardized height, view count stability, and crisp SVG eye icon', () => {
    // Eye icon is SVG
    assert.match(cardFile, /stat-icon-svg/)
    assert.match(cardFile, /<svg[\s\S]*?<circle cx="12" cy="12" r="3"/)
    // Uniform stats height even without views
    assert.match(cardFile, /class="comic-stats"/)
    assert.match(cardFile, /stat-item-empty/)
    assert.match(cardFile, /min-height:\s*22px/)
    // Chapter badge in latest updated section has uniform height
    assert.match(discoveryFile, /min-height:\s*28px/)
    assert.match(discoveryFile, /formatChapterNumber/)
  })

  await t.test('4. Integer chapter numbering and auto-increment mechanics', () => {
    // Model casts chapter_number to integer
    assert.match(chapterModel, /'chapter_number'\s*=>\s*'integer'/)
    // Store request accepts integer only (rejects decimals)
    assert.match(storeChapterRequest, /'integer'/)
    // Chapter utils formats to clean integer
    assert.match(chapterUtilsFile, /Math\.round\(num\)\.toString\(\)/)
  })

  await t.test('5. Creator gap detection and missing chapter selection', () => {
    // Backend nextChapterNumber endpoint
    assert.match(creatorChapterController, /function nextChapterNumber/)
    assert.match(creatorChapterController, /missing_chapter_numbers/)
    // Creator service method
    assert.match(creatorServiceFile, /getNextChapterNumber/)
    // Creator UI has dropdown for gaps
    assert.match(creatorChaptersPage, /nextNumberData\.missing_chapter_numbers/)
    assert.match(creatorChaptersPage, /auto-number-box/)
    assert.match(creatorChaptersPage, /Chương tiếp theo/)
  })

  await t.test('6. Concurrency locking during chapter creation', () => {
    // Transaction and row-level locking
    assert.match(creatorChapterController, /DB::transaction/)
    assert.match(creatorChapterController, /lockForUpdate/)
  })
})
