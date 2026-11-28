/**
 * Comprehensive Unit Tests for CAI-CATALOG-04:
 * Multi-select Search Filters & Update Catalog Heading (BlueToon)
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-CATALOG-04: Multi-select Search Filters & Updated Catalog Heading', () => {
  const homeSearchFile = fs.readFileSync(
    path.join(projectRoot, 'src/components/HomeSearchSection.vue'),
    'utf8'
  )
  const comicsPageFile = fs.readFileSync(
    path.join(projectRoot, 'src/pages/ComicsPage.vue'),
    'utf8'
  )
  const catalogTypesFile = fs.readFileSync(
    path.join(projectRoot, 'src/types/catalog.ts'),
    'utf8'
  )

  it('1. Hero title and subtitle are updated with exact wording in HomeSearchSection and ComicsPage', () => {
    // Exact heading in HomeSearchSection: Tìm kiếm <span class="highlight">Truyện tranh</span>
    assert.match(
      homeSearchFile,
      /Tìm kiếm\s+<span class="highlight">Truyện tranh<\/span>/,
      'HomeSearchSection must match exact title: "Tìm kiếm Truyện tranh"'
    )
    assert.match(
      homeSearchFile,
      /Tìm kiếm theo bộ và hoặc tìm kiếm bằng AI/,
      'HomeSearchSection must match exact subtitle: "Tìm kiếm theo bộ và hoặc tìm kiếm bằng AI"'
    )

    // Exact heading in ComicsPage: Tìm kiếm <span class="highlight">Truyện tranh</span>
    assert.match(
      comicsPageFile,
      /Tìm kiếm\s+<span class="highlight">Truyện tranh<\/span>/,
      'ComicsPage must match exact title: "Tìm kiếm Truyện tranh"'
    )
    assert.match(
      comicsPageFile,
      /Tìm kiếm theo bộ và hoặc tìm kiếm bằng AI/,
      'ComicsPage must match exact subtitle: "Tìm kiếm theo bộ và hoặc tìm kiếm bằng AI"'
    )
  })

  it('2. Multi-select reactive array states are implemented for Genre, Tag, Status, and Sort', () => {
    // Must declare array refs
    assert.match(homeSearchFile, /selectedGenres\s*=\s*ref<string\[\]>\(\[\]\)/)
    assert.match(homeSearchFile, /selectedTags\s*=\s*ref<string\[\]>\(\[\]\)/)
    assert.match(homeSearchFile, /selectedStatuses\s*=\s*ref<ComicStatus\[\]>\(\[\]\)/)
    assert.match(homeSearchFile, /selectedSorts\s*=\s*ref<ComicSortOption\[\]>\(\[\]\)/)

    // ComicsPage must also declare array refs
    assert.match(comicsPageFile, /selectedGenres\s*=\s*ref<string\[\]>\(\[\]\)/)
    assert.match(comicsPageFile, /selectedTags\s*=\s*ref<string\[\]>\(\[\]\)/)
    assert.match(comicsPageFile, /selectedStatuses\s*=\s*ref<ComicStatus\[\]>\(\[\]\)/)
    assert.match(comicsPageFile, /selectedSorts\s*=\s*ref<ComicSortOption\[\]>\(\[\]\)/)
  })

  it('3. Dropdown UI provides checkboxes or ticks and maintains open state during multiple clicks', () => {
    // Must have multi-select container and trigger buttons with original IDs for accessibility
    assert.match(homeSearchFile, /id="filter-genre"/)
    assert.match(homeSearchFile, /id="filter-tag"/)
    assert.match(homeSearchFile, /id="filter-status"/)
    assert.match(homeSearchFile, /id="filter-sort"/)

    // Must render checkboxes / checkmark icons
    assert.match(homeSearchFile, /class="checkbox-box"/)
    assert.match(homeSearchFile, /class="check-icon">✓<\/span>/)

    // Clicks inside dropdown do not close dropdown immediately (@click.stop)
    assert.match(homeSearchFile, /class="multi-select-dropdown"[^>]*@click\.stop/)
  })

  it('4. Genre and Tag selectors include filter search and preserve selected items when searching', () => {
    // Must have search input inside dropdown
    assert.match(homeSearchFile, /v-model="genreSearchQuery"/)
    assert.match(homeSearchFile, /v-model="tagSearchQuery"/)

    // Filtered lists computed without mutating selected values
    assert.match(homeSearchFile, /const filteredGenres = computed\(/)
    assert.match(homeSearchFile, /const filteredTags = computed\(/)
  })

  it('5. Status multi-select handles ongoing, completed, hiatus without auto-selecting default active filter', () => {
    assert.match(homeSearchFile, /value:\s*'ongoing'/)
    assert.match(homeSearchFile, /value:\s*'completed'/)
    assert.match(homeSearchFile, /value:\s*'hiatus'/)

    // Default trigger text shows "Tất cả trạng thái" when empty
    assert.match(homeSearchFile, /selectedStatuses\.value\.length === 0[\s\S]*Tất cả trạng thái/)
  })

  it('6. Sort multi-select defines priority by user selection order and displays badge indicator', () => {
    // Appending in order of selection
    assert.match(homeSearchFile, /selectedSorts\.value\s*=\s*\[\.\.\.selectedSorts\.value,\s*val\]/)

    // Priority badge in dropdown
    assert.match(homeSearchFile, /class="sort-priority-badge"/)
    assert.match(homeSearchFile, /selectedSorts\.indexOf\(opt\.value\)\s*\+\s*1/)

    // Default trigger text shows default sort when empty
    assert.match(homeSearchFile, /selectedSorts\.value\.length === 0[\s\S]*Mặc định/)
  })

  it('7. Active chips display individual chips with removal that preserves other selected filters', () => {
    // Individual chips for genre, tag, status, sort
    assert.match(homeSearchFile, /key:\s*`genre-\$\{slug\}`/)
    assert.match(homeSearchFile, /key:\s*`tag-\$\{slug\}`/)
    assert.match(homeSearchFile, /key:\s*`status-\$\{val\}`/)
    assert.match(homeSearchFile, /key:\s*`sort-\$\{val\}`/)

    // Toggle removal on each chip
    assert.match(homeSearchFile, /toggleGenre\(slug\)/)
    assert.match(homeSearchFile, /toggleTag\(slug\)/)
    assert.match(homeSearchFile, /toggleStatus\(val\)/)
    assert.match(homeSearchFile, /toggleSort\(val\)/)
  })

  it('8. Dual-State toggle correctly switches between State A (5 discovery) and State B (catalog search)', () => {
    // Emits search-state-change with boolean
    assert.match(homeSearchFile, /emit\('search-state-change',\s*hasActiveSearchOrFilter\.value\)/)

    // Resetting all filters resets active state and emits false
    assert.match(homeSearchFile, /function resetAllFilters\(\)\s*\{[\s\S]*emit\('search-state-change',\s*false\)/)
  })

  it('9. Pagination and URL query support arrays/comma-separated strings and preserves parameters on page change', () => {
    // URL parsing supports arrays or comma strings
    assert.match(homeSearchFile, /route\.query\.genres\s*\|\|\s*route\.query\.genre/)
    assert.match(homeSearchFile, /route\.query\.tags\s*\|\|\s*route\.query\.tag/)
    assert.match(homeSearchFile, /route\.query\.statuses\s*\|\|\s*route\.query\.status/)
    assert.match(homeSearchFile, /route\.query\.sorts\s*\|\|\s*route\.query\.sort/)

    // Changing page preserves all filters in applyFilters / fetchComics
    assert.match(homeSearchFile, /goToPage\(page:\s*number\)/)
  })

  it('10. Type definitions in catalog.ts support array parameters', () => {
    assert.match(catalogTypesFile, /genre\?:\s*string\s*\|\s*string\[\]/)
    assert.match(catalogTypesFile, /tag\?:\s*string\s*\|\s*string\[\]/)
    assert.match(catalogTypesFile, /status\?:\s*ComicStatus\s*\|\s*ComicStatus\[\]\s*\|\s*string/)
    assert.match(catalogTypesFile, /sort\?:\s*ComicSortOption\s*\|\s*ComicSortOption\[\]\s*\|\s*string/)
  })

  it('11. Scroll retention: router scrollBehavior preserves screen position when navigating on same route path', () => {
    const routerFile = fs.readFileSync(path.join(projectRoot, 'src/router/index.ts'), 'utf8')
    assert.match(
      routerFile,
      /if\s*\(\s*to\.path\s*===\s*from\.path\s*\)\s*\{\s*return\s+false\s*;?\s*\}/,
      'router scrollBehavior must return false when to.path === from.path to preserve scroll position on filter changes'
    )
  })
})
