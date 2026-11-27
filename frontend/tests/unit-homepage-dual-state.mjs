import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

test('CAI-HOME-02: Homepage Dual State & 5 Discovery Sections Suite', async (t) => {
  const projectRoot = process.cwd()
  const homePageFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/pages/HomePage.vue'),
    'utf-8'
  )
  const homeSearchFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/components/HomeSearchSection.vue'),
    'utf-8'
  )
  const discoveryFile = fs.readFileSync(
    path.resolve(projectRoot, 'frontend/src/components/HomeDiscoverySections.vue'),
    'utf-8'
  )

  await t.test('1. Exact order of 5 Discovery Sections in State A', () => {
    // Check headings presence and exact sequence
    const order = [
      'Truyện mới cập nhật',
      'Truyện hoàn thành',
      'Top truyện tuần',
      'Truyện mới đăng',
      'Truyện nổi bật',
    ]

    let lastIndex = -1
    for (const title of order) {
      const idx = discoveryFile.indexOf(title)
      assert.ok(idx > -1, `Discovery sections must contain section "${title}"`)
      assert.ok(
        idx > lastIndex,
        `Section "${title}" must appear after preceding section in exact required order`
      )
      lastIndex = idx
    }
  })

  await t.test('2. Dual-State Toggle between State A and State B in HomePage.vue', () => {
    // Must import and use HomeDiscoverySections
    assert.match(homePageFile, /HomeDiscoverySections/)
    // Must bind event search-state-change
    assert.match(homePageFile, /@search-state-change/)
    // Must toggle v-show or v-if based on isSearchActive
    assert.match(homePageFile, /v-show="!isSearchActive"/)
  })

  await t.test('3. HomeSearchSection preserves exact search input, filters, card layout, and pagination', () => {
    // Preserves search form
    assert.match(homeSearchFile, /class="search-form"/)
    assert.match(homeSearchFile, /class="search-mode-select"/)
    assert.match(homeSearchFile, /class="search-input"/)

    // Preserves all filter dropdowns
    assert.match(homeSearchFile, /id="filter-genre"/)
    assert.match(homeSearchFile, /id="filter-tag"/)
    assert.match(homeSearchFile, /id="filter-status"/)
    assert.match(homeSearchFile, /id="filter-sort"/)

    // Preserves pagination
    assert.match(homeSearchFile, /class="pagination-nav"/)
    assert.match(homeSearchFile, /btn-prev/)
    assert.match(homeSearchFile, /btn-next/)

    // Preserves ComicCard rendering
    assert.match(homeSearchFile, /<ComicCard/)

    // Emits search-state-change
    assert.match(homeSearchFile, /emit\('search-state-change'/)
  })

  await t.test('4. Top truyện tuần displays up to 6 items with rank badges', () => {
    assert.match(discoveryFile, /weekly-top-grid/)
    assert.match(discoveryFile, /class="rank-badge"/)
    assert.match(discoveryFile, /rank-crown/)
  })

  await t.test('5. Backend ComicController supports sort=updated, weekly_views and is_featured', () => {
    const controllerFile = fs.readFileSync(
      path.resolve(projectRoot, 'backend/app/Http/Controllers/Api/ComicController.php'),
      'utf-8'
    )
    const requestFile = fs.readFileSync(
      path.resolve(projectRoot, 'backend/app/Http/Requests/ListComicsRequest.php'),
      'utf-8'
    )

    assert.match(requestFile, /'is_featured'/)
    assert.match(requestFile, /'sort' => \['sometimes', 'string', 'in:latest,views,follows,updated,weekly_views'\]/)
    assert.match(controllerFile, /'is_featured'/)
    assert.match(controllerFile, /'updated'/)
  })
})
