/**
 * Comprehensive Unit Tests for CAI-QD1:
 * Đưa Danh mục và Tìm kiếm truyện lên trang chủ theo giao diện đầy đủ nhất (BlueToon)
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-QD1: Homepage Comic Search & Full Catalog Integration Suite', () => {
  it('1. Navbar in AppHeader.vue no longer contains "Danh mục" link, while preserving other links and router route', () => {
    const headerFile = fs.readFileSync(path.join(projectRoot, 'src/components/AppHeader.vue'), 'utf8')
    const routerFile = fs.readFileSync(path.join(projectRoot, 'src/router/index.ts'), 'utf8')

    // AppHeader navbar links must NOT include "Danh mục"
    assert.doesNotMatch(
      headerFile,
      /<RouterLink\s+to="\/comics"[^>]*>Danh mục<\/RouterLink>/,
      'AppHeader nav-links must not contain "Danh mục"'
    )

    // Other nav links must be intact
    assert.match(headerFile, /to="\/"[^>]*>Trang chủ<\/RouterLink>/, 'Must keep Trang chủ link')
    assert.match(headerFile, /to="\/community"[^>]*>Cộng đồng<\/RouterLink>/, 'Must keep Cộng đồng link')
    assert.match(headerFile, /to="\/library"[^>]*>Tủ sách<\/RouterLink>/, 'Must keep Tủ sách link')

    // Route /comics must still exist in router
    assert.match(routerFile, /path:\s*['"]\/comics['"]/, 'Route /comics must still be registered in router')
    assert.match(routerFile, /name:\s*['"]comics['"]/, 'Route name comics must still be registered')
  })

  it('2. HomePage.vue places HomeSearchSection right below BannerSlider as the main complete catalog section', () => {
    const homeFile = fs.readFileSync(path.join(projectRoot, 'src/pages/HomePage.vue'), 'utf8')

    // Must import HomeSearchSection
    assert.match(
      homeFile,
      /import\s+HomeSearchSection\s+from\s+['"]@\/components\/HomeSearchSection\.vue['"]/,
      'HomePage must import HomeSearchSection'
    )

    // Position verification: BannerSlider -> HomeSearchSection
    const bannerIndex = homeFile.indexOf('<BannerSlider')
    const searchIndex = homeFile.indexOf('<HomeSearchSection')

    assert.ok(bannerIndex !== -1, 'BannerSlider must exist')
    assert.ok(searchIndex !== -1, 'HomeSearchSection must exist')
    assert.ok(bannerIndex < searchIndex, 'HomeSearchSection must be placed below BannerSlider')
  })

  it('3. HomeSearchSection implements exact screenshot header and search bar with mode dropdown', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    // Hero Header matching screenshot
    assert.match(searchFile, /Danh mục\s+<span class="highlight">Truyện tranh<\/span>/, 'Must match exact title: Danh mục Truyện tranh')
    assert.match(searchFile, /Tìm kiếm và lọc các bộ truyện yêu thích theo thể loại, từ khóa và trạng thái phát hành/, 'Must match exact subtitle')

    // Search bar with mode dropdown
    assert.match(searchFile, /search-mode-select/, 'Must have search-mode-select dropdown')
    assert.match(searchFile, /Tìm kiếm thông thường/, 'Must have option "Tìm kiếm thông thường"')
    assert.match(searchFile, /Tìm kiếm bằng AI/, 'Must have option "Tìm kiếm bằng AI"')
    assert.match(searchFile, /btn-search-submit/, 'Must have search submit button')
    assert.match(searchFile, /@keydown\.enter="submitSearch"/, 'Must trigger search on Enter key')
  })

  it('4. Filters panel implements 4 select dropdowns matching exact screenshot (Thể loại, Tag chủ đề, Trạng thái, Sắp xếp)', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    // Filters row & labels
    assert.match(searchFile, /filters-panel/, 'Must have filters-panel container')
    assert.match(searchFile, /<label[^>]*>Thể loại<\/label>/, 'Must have "Thể loại" filter label')
    assert.match(searchFile, /<label[^>]*>Tag chủ đề<\/label>/, 'Must have "Tag chủ đề" filter label')
    assert.match(searchFile, /<label[^>]*>Trạng thái<\/label>/, 'Must have "Trạng thái" filter label')
    assert.match(searchFile, /<label[^>]*>Sắp xếp<\/label>/, 'Must have "Sắp xếp" filter label')

    // Select dropdowns
    assert.match(searchFile, /id="filter-genre"/, 'Must have genre select with id filter-genre')
    assert.match(searchFile, /id="filter-tag"/, 'Must have tag select with id filter-tag')
    assert.match(searchFile, /id="filter-status"/, 'Must have status select with id filter-status')
    assert.match(searchFile, /id="filter-sort"/, 'Must have sort select with id filter-sort')

    // Reset button
    assert.match(searchFile, /btn-reset-filters/, 'Must have btn-reset-filters button')
    assert.match(searchFile, /Đặt lại/, 'Reset button must display "Đặt lại"')
  })

  it('5. Active filter chips row displays active filters with individual remove and "Xóa tất cả" buttons', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    assert.match(searchFile, /active-chips-row/, 'Must have active-chips-row container')
    assert.match(searchFile, /Đang lọc:/, 'Must have label "Đang lọc:"')
    assert.match(searchFile, /filter-chip/, 'Must render filter-chip elements')
    assert.match(searchFile, /chip-remove-btn/, 'Must have individual chip remove buttons')
    assert.match(searchFile, /btn-clear-all-chips/, 'Must have "Xóa tất cả" button')
  })

  it('6. Results counter and lifecycle states (Loading, Empty, Error, AI Fallback) are completely handled', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    // Results counter
    assert.match(searchFile, /results-meta-bar/, 'Must have results-meta-bar')
    assert.match(searchFile, /Tìm thấy.*bộ truyện/, 'Must display "Tìm thấy X bộ truyện"')

    // Loading skeletons
    assert.match(searchFile, /card-skeleton/, 'Must render skeleton cards on loading')

    // Empty state
    assert.match(searchFile, /empty-state/, 'Must have empty-state container')
    assert.match(searchFile, /Không tìm thấy truyện phù hợp/, 'Must have empty state title')

    // Error state
    assert.match(searchFile, /error-state/, 'Must have error-state container')

    // AI Fallback alert
    assert.match(searchFile, /ai-fallback-alert/, 'Must have ai-fallback-alert banner')
  })

  it('7. Comics Grid renders ComicCard with searchMode and pagination controls', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    assert.match(searchFile, /<ComicCard/, 'Must render ComicCard component for results')
    assert.match(searchFile, /:search-mode="searchMode"/, 'Must pass search-mode prop to ComicCard')
    assert.match(searchFile, /pagination-nav/, 'Must have pagination-nav bar')
    assert.match(searchFile, /btn-prev/, 'Must have previous page button')
    assert.match(searchFile, /btn-next/, 'Must have next page button')
  })

  it('8. Responsive layout and design tokens match BlueToon styling', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    assert.match(searchFile, /var\(--color-bg-card\)/, 'Must use --color-bg-card token')
    assert.match(searchFile, /var\(--color-primary\)/, 'Must use --color-primary token')
    assert.match(searchFile, /@media\s*\(max-width:\s*640px\)/, 'Must have responsive breakpoint for mobile')
    assert.match(searchFile, /@media\s*\(min-width:\s*640px\)/, 'Must have responsive breakpoint for tablet')
  })

  it('9. Manual Search Only: No debounce auto-search on input, only submits on click/enter, loading guard', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    // Must NOT contain debounced onSearchInput
    assert.doesNotMatch(searchFile, /@input="onSearchInput"/, 'Input must not have onSearchInput listener')
    assert.doesNotMatch(searchFile, /function\s+onSearchInput/, 'Must not define onSearchInput debounce function')
    assert.doesNotMatch(searchFile, /searchDebounceTimer/, 'Must not use searchDebounceTimer')

    // Mode change must not auto-trigger search
    assert.doesNotMatch(
      searchFile,
      /function\s+onModeChange\(\)[^{]*\{[^}]*fetchComics/,
      'onModeChange must not auto-fetch comics'
    )

    // Submit button disabled when loading
    assert.match(searchFile, /:disabled="isLoading"/, 'Search button must be disabled when loading')
  })

  it('10. Fix Duplicate Clear Button: Suppresses native cancel button and provides single custom X button', () => {
    const searchFile = fs.readFileSync(path.join(projectRoot, 'src/components/HomeSearchSection.vue'), 'utf8')

    // Input uses type="text" role="searchbox" to prevent browser native clear button
    assert.match(searchFile, /type="text"/, 'Input must use type="text" to prevent native clear button')
    assert.match(searchFile, /role="searchbox"/, 'Input must have role="searchbox"')

    // CSS suppresses webkit clear button
    assert.match(searchFile, /-webkit-search-cancel-button/, 'CSS must suppress -webkit-search-cancel-button')

    // Clear function resets input only
    assert.match(searchFile, /function\s+clearSearch\(\)\s*\{\s*searchInput\.value\s*=\s*['"]\s*['"]/, 'clearSearch must only clear searchInput')
    assert.doesNotMatch(
      searchFile,
      /function\s+clearSearch\(\)\s*\{[\s\S]*submitSearch\(\)/,
      'clearSearch must not automatically call submitSearch'
    )
  })
})
