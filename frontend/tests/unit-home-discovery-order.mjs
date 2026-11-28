import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

test('CAI-HOME-04: Update Homepage Discovery Order & Featured Catalog Navigation Suite', async (t) => {
  const projectRoot = process.cwd()
  const discoveryFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/components/HomeDiscoverySections.vue'),
    'utf-8'
  )
  const homeSearchFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/components/HomeSearchSection.vue'),
    'utf-8'
  )
  const comicsPageFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/pages/ComicsPage.vue'),
    'utf-8'
  )
  const typesFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/types/catalog.ts'),
    'utf-8'
  )
  const requestFile = fs.readFileSync(
    path.resolve(projectRoot, 'backend/app/Http/Requests/ListComicsRequest.php'),
    'utf-8'
  )
  const controllerFile = fs.readFileSync(
    path.resolve(projectRoot, 'backend/app/Http/Controllers/Api/ComicController.php'),
    'utf-8'
  )

  await t.test('1. Discovery sections exact order: Top truyện tuần -> Truyện nổi bật -> Truyện mới cập nhật -> Truyện hoàn thành', () => {
    const requiredOrder = [
      'Top truyện tuần',
      'Truyện nổi bật',
      'Truyện mới cập nhật',
      'Truyện hoàn thành',
    ]

    let prevPos = -1
    for (const title of requiredOrder) {
      const pos = discoveryFile.indexOf(title)
      assert.ok(pos > -1, `Section "${title}" must be present in HomeDiscoverySections.vue`)
      assert.ok(
        pos > prevPos,
        `Section "${title}" must appear in exact required sequence after previous section`
      )
      prevPos = pos
    }
  })

  await t.test('2. Removal of section "Truyện mới đăng" from template, state and API calls', () => {
    assert.strictEqual(
      discoveryFile.includes('Truyện mới đăng'),
      false,
      'Section "Truyện mới đăng" must not be present in template'
    )
    assert.strictEqual(
      discoveryFile.includes('section-new-comics'),
      false,
      'CSS class section-new-comics must not be present'
    )
    assert.strictEqual(
      discoveryFile.includes('newComics'),
      false,
      'newComics state must not be present in script'
    )
    assert.strictEqual(
      /CatalogService\.getComics\(\{\s*sort:\s*['"]latest['"]/.test(discoveryFile),
      false,
      'API call for latest new comics must not be executed in discovery sections'
    )
  })

  await t.test('3. Featured "Xem tất cả" link directs to /comics?featured=1', () => {
    // Must navigate to /comics?featured=1 or /comics?is_featured=1 instead of sort=views
    assert.match(
      discoveryFile,
      /<RouterLink\s+to="\/comics\?(?:featured|is_featured)=1"\s+class="btn-view-all">/,
      'Featured section View All button must point to /comics?featured=1'
    )
    // Must NOT point to sort=views in featured section
    const featuredSection = discoveryFile.substring(
      discoveryFile.indexOf('heading-featured') - 100,
      discoveryFile.indexOf('heading-featured') + 300
    )
    assert.strictEqual(
      featuredSection.includes('sort=views'),
      false,
      'Featured section header must not link to sort=views'
    )
  })

  await t.test('4. Limit strictly to 6 comics per section and deduplicate', () => {
    const perPageMatches = discoveryFile.match(/per_page:\s*6/g)
    assert.ok(
      perPageMatches && perPageMatches.length === 4,
      'Exactly 4 discovery sections must each request per_page: 6'
    )
    assert.match(discoveryFile, /dedupeAndLimit\(.*,\s*6\)/)
  })

  await t.test('5. Catalog filter "Truyện nổi bật" checkbox in HomeSearchSection and ComicsPage', () => {
    // Both files have isFeatured ref
    assert.match(homeSearchFile, /const\s+isFeatured\s*=\s*ref\(false\)/)
    assert.match(comicsPageFile, /const\s+isFeatured\s*=\s*ref\(false\)/)

    // Both files have toggleFeatured function
    assert.match(homeSearchFile, /function\s+toggleFeatured\(\)/)
    assert.match(comicsPageFile, /function\s+toggleFeatured\(\)/)

    // Both files have featured checkbox inside sort popup (CAI-USER-03)
    assert.match(homeSearchFile, /filter-featured-option/)
    assert.match(comicsPageFile, /filter-featured-option/)
    assert.match(homeSearchFile, /Truyện nổi bật/)
    assert.match(comicsPageFile, /Truyện nổi bật/)
  })

  await t.test('6. Filter chip "Truyện nổi bật" and query param synchronization', () => {
    // Both files generate chip for featured
    assert.match(homeSearchFile, /label:\s*['"]Truyện nổi bật['"]/)
    assert.match(comicsPageFile, /label:\s*['"]Truyện nổi bật['"]/)

    // Both files read route.query.featured and route.query.is_featured
    assert.match(homeSearchFile, /route\.query\.featured\s*\?\?\s*route\.query\.is_featured/)
    assert.match(comicsPageFile, /route\.query\.featured\s*\?\?\s*route\.query\.is_featured/)

    // Both files pass is_featured to API
    assert.match(homeSearchFile, /params\.is_featured\s*=\s*1/)
    assert.match(comicsPageFile, /params\.is_featured\s*=\s*1/)
  })

  await t.test('7. Types and Backend API compatibility for is_featured and featured alias', () => {
    // Types support both
    assert.match(typesFile, /is_featured\?:\s*boolean\s*\|\s*number\s*\|\s*string/)
    assert.match(typesFile, /featured\?:\s*boolean\s*\|\s*number\s*\|\s*string/)

    // Backend Request validates both
    assert.match(requestFile, /'is_featured'\s*=>\s*\[.*'boolean'.*\]/)
    assert.match(requestFile, /'featured'\s*=>\s*\[.*'boolean'.*\]/)

    // Backend Controller filters by featured
    assert.match(controllerFile, /\$query->where\('is_featured',/)
  })
})
