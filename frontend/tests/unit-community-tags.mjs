/**
 * Unit tests for Community Tag Input & Autocomplete (ComicAI / BlueToon)
 * Tests UI structure, normalization rules, autocomplete behavior, debounce, limits, and service contracts.
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { normalizeCommunityTag } from '../src/utils/tagUtils.ts'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('Community Tag Input & Autocomplete Frontend Suite', () => {
  it('1. Normalization helper conforms strictly to ComicAI rules', () => {
    assert.equal(normalizeCommunityTag('#Hành Động Giật Gân'), 'hanhdonggiatgan')
    assert.equal(normalizeCommunityTag('#Siêu Trộm Đẳng Cấp'), 'sieutromdangcap')
    assert.equal(normalizeCommunityTag('Xuyên Không'), 'xuyenkhong')
    assert.equal(normalizeCommunityTag('   #main-bao-dao!   '), 'mainbaodao')
    assert.equal(normalizeCommunityTag('phục huyên'), 'phuchuyen')
    assert.equal(normalizeCommunityTag('###Ăn Cơm###'), 'ancom')
    assert.equal(normalizeCommunityTag(''), '')
  })

  it('2. CreatorComicFormPage renders title "Tag" without extra subheadings', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /<label\s+for="comic-tag-input"\s+class="form-label">\s*Tag\s*<\/label>/, 'Label must be "Tag"')
    assert.doesNotMatch(file, /Nhãn đặc điểm \(Tags\)/, 'Legacy title "Nhãn đặc điểm (Tags)" must be removed')
    assert.doesNotMatch(file, /Tag tự thêm|Tag cộng đồng/, 'No extra subheadings like "Tag tự thêm" allowed')
  })

  it('3. Input is located immediately beneath the Tag label with placeholder "Nhập Tag..."', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /id="comic-tag-input"/, 'Input element with id comic-tag-input must exist')
    assert.match(file, /placeholder="Nhập Tag\.\.\."/, 'Input must have placeholder "Nhập Tag..."')
    assert.match(file, /class="tag-add-btn"/, 'Must have small add button on the right of input')
  })

  it('4. Available system tag chips remain functional below the input', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /class="chips-selector-grid"/, 'Must render chips-selector-grid')
    assert.match(file, /v-for="tag in (displayedTags|availableTags)"/, 'Must iterate over displayedTags or availableTags')
    assert.match(file, /@click="toggleTag\(tag\.id\)"/, 'Must toggle tags on click')
  })

  it('5. Autocomplete dropdown implements role="listbox", options, loading, empty, and error states', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /class="tag-autocomplete-dropdown"/, 'Dropdown container must exist')
    assert.match(file, /class="tag-dropdown-status"/, 'Loading state container must exist')
    assert.match(file, /class="tag-dropdown-empty"/, 'Empty state container must exist')
    assert.match(file, /class="tag-dropdown-error"/, 'Error state container must exist')
    assert.match(file, /role="listbox"/, 'Dropdown must have role="listbox"')
    assert.match(file, /role="option"/, 'Items must have role="option"')
  })

  it('6. Debounce is implemented with 250-300ms timer and out-of-order request protection', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /tagSearchTimer/, 'Must declare tagSearchTimer')
    assert.match(file, /tagSearchRequestId/, 'Must track request IDs against race conditions')
    assert.match(file, /setTimeout\(\s*async\s*\(\)\s*=>\s*\{[\s\S]*?,\s*2[5-9]\d\)/, 'Debounce must be between 250ms and 300ms')
  })

  it('7. Selection of existing suggestions and creation of new tags on Enter or click', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /function selectSuggestion\(/, 'Must define selectSuggestion')
    assert.match(file, /function createAndAddCommunityTag\(/, 'Must define createAndAddCommunityTag')
    assert.match(file, /function handleTagInputConfirm\(/, 'Must define handleTagInputConfirm on Enter')
    assert.match(file, /@keydown\.enter\.prevent="handleTagInputConfirm"/, 'Input must listen to Enter key')
    assert.match(file, /Tạo mới:\s*<strong[^>]*>#\{\{\s*normalizedInputTag\s*\}\}<\/strong>/, 'Dropdown must display "+ Tạo mới: #[tag chuẩn hóa]"')
  })

  it('8. Does not create tag while typing; prevents duplicates and enforces 10 tags limit', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /const MAX_TAGS = 10/, 'Must enforce MAX_TAGS = 10')
    assert.match(file, /Đã chọn:\s*\{\{\s*selectedTags\.length\s*\}\}\s*\/\s*\{\{\s*MAX_TAGS\s*\}\}\s*tags/, 'Counter must display "Đã chọn: X / 10 tags"')
    assert.match(file, /canCreateNewTag/, 'Must compute canCreateNewTag')
    assert.match(file, /matchesSuggestion/, 'Must check for duplicate suggestions before allowing creation')
    assert.match(file, /matchesSelected/, 'Must check already selected tags before allowing creation')
  })

  it('9. Services export getTagSuggestions and createCommunityTag API calls', () => {
    const catalogServiceFile = fs.readFileSync(path.join(projectRoot, 'src/services/catalogService.ts'), 'utf8')
    const creatorServiceFile = fs.readFileSync(path.join(projectRoot, 'src/services/creatorService.ts'), 'utf8')

    assert.match(catalogServiceFile, /static async getTagSuggestions\(/, 'CatalogService must export getTagSuggestions')
    assert.match(catalogServiceFile, /\/tags\/suggestions/, 'CatalogService must call /tags/suggestions')
    assert.match(creatorServiceFile, /static async createCommunityTag\(/, 'CreatorService must export createCommunityTag')
    assert.match(creatorServiceFile, /\/creator\/tags/, 'CreatorService must call /creator/tags')
  })

  it('10. getOrganizedTags limits unselected tags to 5-6 items and shows all selected tags first', async () => {
    const { getOrganizedTags } = await import('../src/utils/tagUtils.ts')
    const sampleTags = [
      { id: 1, name: 'Học đường', slug: 'hoc-duong' },
      { id: 2, name: 'Xuyên không', slug: 'xuyen-khong' },
      { id: 3, name: 'Hệ thống', slug: 'he-thong' },
      { id: 4, name: 'Trọng sinh', slug: 'trong-sinh' },
      { id: 5, name: 'Kiếm thuật', slug: 'kiem-thuat' },
      { id: 6, name: 'Ma pháp', slug: 'ma-phap' },
      { id: 7, name: 'Sinh tồn', slug: 'sinh-ton' },
      { id: 8, name: 'Quái vật', slug: 'quai-vat' },
      { id: 9, name: 'Đô thị', slug: 'do-thi' },
      { id: 10, name: 'Cổ trang', slug: 'co-trang' },
    ]

    // When none selected: exactly 6 unselected tags shown (5-6 limit)
    const initialList = getOrganizedTags(sampleTags, [], 6)
    assert.equal(initialList.length, 6)
    assert.deepEqual(initialList.map((t) => t.id), [1, 2, 3, 4, 5, 6])

    // When 2 tags selected (e.g. id 8 and 3):
    // Selected tags appear first in selection order [8, 3] + 6 unselected tags = 8 total
    const withSelected = getOrganizedTags(sampleTags, [8, 3], 6)
    assert.equal(withSelected[0].id, 8)
    assert.equal(withSelected[1].id, 3)
    assert.equal(withSelected.length, 8) // 2 selected + 6 unselected
    // Make sure no duplicates
    const uniqueIds = new Set(withSelected.map((t) => t.id))
    assert.equal(uniqueIds.size, withSelected.length)
  })

  it('11. CreatorComicFormPage renders displayedTags with selected-first and limit', () => {
    const file = fs.readFileSync(path.join(projectRoot, 'src/pages/creator/CreatorComicFormPage.vue'), 'utf8')
    assert.match(file, /getOrganizedTags/, 'Must import and use getOrganizedTags')
    assert.match(file, /displayedTags/, 'Must define displayedTags computed')
    assert.match(file, /v-for="tag in displayedTags"/, 'Template must iterate over displayedTags')
  })
})
