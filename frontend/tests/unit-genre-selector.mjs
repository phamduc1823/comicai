import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import {
  normalizeVietnameseText,
  filterAndRankGenres,
  getOrganizedGenres,
  isFuzzyMatch,
} from '../src/utils/genreUtils.ts'

test('Genre Selector & Search Unit Test Suite', async (t) => {
  const sampleGenres = [
    { id: 1, name: 'Hành Động', slug: 'action' },
    { id: 2, name: 'Tình Cảm', slug: 'romance' },
    { id: 3, name: 'Huyền Huyễn', slug: 'fantasy' },
    { id: 4, name: 'Hài Hước', slug: 'comedy' },
    { id: 5, name: 'Đời Thường', slug: 'slice-of-life' },
    { id: 6, name: 'Kịch Tính', slug: 'drama' },
    { id: 7, name: 'Bí Ẩn', slug: 'mystery' },
    { id: 8, name: 'Khoa Học Viễn Tưởng', slug: 'sci-fi' },
    { id: 9, name: 'Hành Động Giả Tưởng', slug: 'hanh-dong-gia-tuong' },
    { id: 10, name: 'Kiếm Hiệp', slug: 'kiem-hiep' },
    { id: 11, name: 'Võng Du', slug: 'vong-du' },
    { id: 12, name: 'Kinh Dị', slug: 'kinh-di' },
  ]

  await t.test('1. Normalization strips Vietnamese accents and collapses spaces', () => {
    assert.equal(normalizeVietnameseText('Hành Động'), 'hanh dong')
    assert.equal(normalizeVietnameseText('Khoa Học Viễn Tưởng'), 'khoa hoc vien tuong')
    assert.equal(normalizeVietnameseText('Đời Thường'), 'doi thuong')
    assert.equal(normalizeVietnameseText('   Huyền    Huyễn  '), 'huyen huyen')
  })

  await t.test('2. Fuzzy matching correctly handles slight typos', () => {
    assert.equal(isFuzzyMatch('Hành Động', 'hanh dong'), true)
    assert.equal(isFuzzyMatch('Hành Động', 'hanhdong'), true)
    // Word prefix and single edit distance typo
    assert.equal(isFuzzyMatch('Kiếm Hiệp', 'kiem'), true)
    assert.equal(isFuzzyMatch('Kiếm Hiệp', 'kiemhiep'), true)
    assert.equal(isFuzzyMatch('Khoa Học Viễn Tưởng', 'khoahoc'), true)
  })

  await t.test('3. Search ranks prefix matches first, then contains, unaccented supported', () => {
    const results = filterAndRankGenres(sampleGenres, 'hanh dong')
    assert.ok(results.length >= 2)
    // 'Hành Động' is exact/prefix match and shorter than 'Hành Động Giả Tưởng'
    assert.equal(results[0].name, 'Hành Động')
    assert.equal(results[1].name, 'Hành Động Giả Tưởng')

    // Searching unaccented "khoa hoc"
    const sciFiResults = filterAndRankGenres(sampleGenres, 'khoa hoc')
    assert.equal(sciFiResults.length, 1)
    assert.equal(sciFiResults[0].name, 'Khoa Học Viễn Tưởng')
  })

  await t.test('4. Selected-first ordering: selected genres always displayed at the top', () => {
    // Select Sci-Fi (id: 8) then Action (id: 1)
    const selectedIds = [8, 1]
    const state = getOrganizedGenres(sampleGenres, selectedIds, '', false, 6)

    // First two must be Sci-Fi then Action in that order
    assert.equal(state.displayedGenres[0].id, 8)
    assert.equal(state.displayedGenres[0].name, 'Khoa Học Viễn Tưởng')
    assert.equal(state.displayedGenres[1].id, 1)
    assert.equal(state.displayedGenres[1].name, 'Hành Động')

    // Unselected genres follow (limited to 6)
    assert.equal(state.selectedCount, 2)
    assert.equal(state.unselectedTotal, 10)
    assert.equal(state.unselectedDisplayedCount, 6)
    assert.equal(state.hasMoreUnselected, true)
    assert.equal(state.displayedGenres.length, 8) // 2 selected + 6 unselected
  })

  await t.test('5. Expanding/collapsing unselected genres works without losing data', () => {
    const selectedIds = [8, 1]
    // Expanded = true
    const expandedState = getOrganizedGenres(sampleGenres, selectedIds, '', true, 6)
    assert.equal(expandedState.displayedGenres.length, 12) // all 12 genres
    assert.equal(expandedState.unselectedDisplayedCount, 10)
    assert.equal(expandedState.displayedGenres[0].id, 8)
    assert.equal(expandedState.displayedGenres[1].id, 1)

    // Collapsed = false
    const collapsedState = getOrganizedGenres(sampleGenres, selectedIds, '', false, 6)
    assert.equal(collapsedState.displayedGenres.length, 8)
  })

  await t.test('6. Search filters both selected and unselected, keeping selected matching first', () => {
    // Both 'Hành Động' (id: 1) and 'Hành Động Giả Tưởng' (id: 9) match 'hanh dong'
    // User selected id: 9 first
    const selectedIds = [9]
    const searchState = getOrganizedGenres(sampleGenres, selectedIds, 'hanh dong', false, 6)

    // Both should appear, but selected (id: 9) is first
    assert.equal(searchState.displayedGenres.length, 2)
    assert.equal(searchState.displayedGenres[0].id, 9)
    assert.equal(searchState.displayedGenres[0].name, 'Hành Động Giả Tưởng')
    assert.equal(searchState.displayedGenres[1].id, 1)
    assert.equal(searchState.displayedGenres[1].name, 'Hành Động')
  })

  await t.test('7. CreatorComicFormPage template integrates genre search and selected-first UI', () => {
    const formFile = fs.readFileSync(
      path.resolve(process.cwd(), 'frontend/src/pages/creator/CreatorComicFormPage.vue'),
      'utf-8'
    )

    // Check title and label
    assert.ok(formFile.includes('Thể loại truyện') && formFile.includes('(chọn ít nhất 1)'))

    // Check search input placeholder
    assert.match(formFile, /placeholder="Tìm kiếm thể loại\.\.\."/)

    // Check search icon and clear button
    assert.match(formFile, /class="genre-search-icon"/)
    assert.match(formFile, /class="genre-search-clear-btn"/)

    // Check getOrganizedGenres computed usage
    assert.match(formFile, /getOrganizedGenres/)
    assert.match(formFile, /displayedGenres/)

    // Check checkmark / plus toggle symbol
    assert.match(formFile, /\{\{ selectedGenres\.includes\(genre\.id\) \? '✓' : '\+' \}\}/)

    // Check expand/collapse button
    assert.match(formFile, /toggleExpandGenres/)
    assert.match(formFile, /Thu gọn bớt/)
    assert.match(formFile, /Xem thêm thể loại/)
  })
})
