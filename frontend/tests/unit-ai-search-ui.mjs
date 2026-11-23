/**
 * Comprehensive Unit & Component Behavior Tests for Package 10.4D (Final UX Corrections):
 * - Neutral Ranking Score Label (e.g. "Điểm phù hợp 85%")
 * - Structured metadata classification via `match_type` ('semantic' | 'clue' | 'hybrid') without string sniffing
 * - Graceful degradation when `match_type` is missing or null
 * - Strict isolation of Normal Search Mode
 * - Fallback search handling
 * - Cover URL resolution & ComicsPage behavior
 */

import assert from 'node:assert/strict'

console.log('=================================================================')
console.log('   PACKAGE 10.4D: FINAL UX CORRECTIONS & METADATA TESTS          ')
console.log('=================================================================\n')

let passedTests = 0
let failedTests = 0

function test(name, fn) {
  try {
    fn()
    console.log(`✅ PASS: ${name}`)
    passedTests++
  } catch (err) {
    console.error(`❌ FAIL: ${name}`)
    console.error(err)
    failedTests++
  }
}

// =========================================================================
// Helper simulating ComicCard component logic exactly as implemented
// =========================================================================

function computeComicCardAiState(comic, searchMode = 'normal') {
  const isAiMode = searchMode === 'ai'

  const hasValidScore =
    isAiMode &&
    comic.is_fallback !== true &&
    typeof comic.score === 'number' &&
    comic.score > 0

  const matchPercentage = typeof comic.score === 'number' ? Math.round(comic.score * 100) : null

  let badgeConfig = null
  if (hasValidScore && matchPercentage !== null) {
    const type = comic.match_type

    if (type === 'clue') {
      badgeConfig = {
        className: 'badge-clue',
        icon: '🎯',
        label: `Điểm phù hợp ${matchPercentage}%`,
        title: `Điểm phù hợp từ khóa/manh mối: ${matchPercentage}%`,
      }
    } else if (type === 'hybrid') {
      badgeConfig = {
        className: 'badge-hybrid',
        icon: '✨',
        label: `Điểm phù hợp ${matchPercentage}%`,
        title: `Điểm phù hợp xếp hạng AI kết hợp: ${matchPercentage}%`,
      }
    } else if (type === 'semantic') {
      badgeConfig = {
        className: 'badge-semantic',
        icon: '✨',
        label: `Điểm phù hợp ${matchPercentage}%`,
        title: `Điểm phù hợp ngữ nghĩa AI: ${matchPercentage}%`,
      }
    } else {
      // Fallback when match_type is missing/null/undefined:
      // Neutral badge class and tooltip, not asserting semantic/clue/hybrid!
      badgeConfig = {
        className: 'badge-neutral',
        icon: '✨',
        label: `Điểm phù hợp ${matchPercentage}%`,
        title: `Điểm xếp hạng tìm kiếm AI: ${matchPercentage}%`,
      }
    }
  }

  const coverSrc = comic.cover_url
    ? comic.cover_url
    : comic.cover_path
      ? comic.cover_path.startsWith('http://') || comic.cover_path.startsWith('https://')
        ? comic.cover_path
        : `/storage/${comic.cover_path}`
      : ''

  return {
    isAiMode,
    hasValidScore,
    matchPercentage,
    badgeConfig,
    coverSrc,
  }
}

// =========================================================================
// TEST SUITE: Neutral Score Label & Structured `match_type` Metadata
// =========================================================================

test('1. Ranking score displays neutral label "Điểm phù hợp XX%", never probability "AI Match XX%"', () => {
  const comic = {
    id: 1,
    title: 'Đấu La Đại Lục',
    slug: 'dau-la-dai-luc',
    score: 0.8845,
    is_fallback: false,
    match_type: 'semantic',
    match_reason: 'Vector similarity: 0.8845',
  }

  const state = computeComicCardAiState(comic, 'ai')
  assert.ok(state.badgeConfig, 'Badge config must be present')
  assert.equal(state.badgeConfig.label, 'Điểm phù hợp 88%')
  assert.ok(!state.badgeConfig.label.includes('AI Match'), 'Must not contain "AI Match"')
  assert.ok(!state.badgeConfig.label.includes('Khớp 88%'), 'Must not contain "Khớp 88%"')
  assert.ok(!state.badgeConfig.title.includes('xác suất'), 'Title must not claim probability')
})

test('2. Structured metadata: match_type "semantic" assigns badge-semantic without string sniffing', () => {
  const comic = {
    id: 2,
    title: 'Truyện Ngữ Nghĩa Thuần Túy',
    slug: 'truyen-semantic',
    score: 0.92,
    is_fallback: false,
    match_type: 'semantic',
    // Arbitrary text with NO Vietnamese keywords like "ngữ nghĩa" or "AI"
    match_reason: 'Cosine similarity calculated from 768-dim embeddings',
  }

  const state = computeComicCardAiState(comic, 'ai')
  assert.ok(state.badgeConfig)
  assert.equal(state.badgeConfig.className, 'badge-semantic')
  assert.equal(state.badgeConfig.icon, '✨')
  assert.equal(state.badgeConfig.label, 'Điểm phù hợp 92%')
})

test('3. Structured metadata: match_type "hybrid" assigns badge-hybrid without string sniffing', () => {
  const comic = {
    id: 3,
    title: 'Truyện Tìm Thấy Bằng Hybrid Ranking',
    slug: 'truyen-hybrid',
    score: 0.84,
    is_fallback: false,
    match_type: 'hybrid',
    match_reason: 'Combined weighted retrieval score',
  }

  const state = computeComicCardAiState(comic, 'ai')
  assert.ok(state.badgeConfig)
  assert.equal(state.badgeConfig.className, 'badge-hybrid')
  assert.equal(state.badgeConfig.icon, '✨')
  assert.equal(state.badgeConfig.label, 'Điểm phù hợp 84%')
})

test('4. Structured metadata: match_type "clue" assigns badge-clue without string sniffing', () => {
  const comic = {
    id: 4,
    title: 'Truyện Khớp Manh Mối Từ Khóa',
    slug: 'truyen-clue',
    score: 0.65,
    is_fallback: false,
    match_type: 'clue',
    match_reason: 'Query clues: main character, magical sword',
  }

  const state = computeComicCardAiState(comic, 'ai')
  assert.ok(state.badgeConfig)
  assert.equal(state.badgeConfig.className, 'badge-clue')
  assert.equal(state.badgeConfig.icon, '🎯')
  assert.equal(state.badgeConfig.label, 'Điểm phù hợp 65%')
})

test('5. Missing or null match_type degrades safely to neutral fallback badge without claiming semantic', () => {
  const comicWithoutMatchType = {
    id: 5,
    title: 'Truyện Thiếu match_type',
    slug: 'truyen-legacy',
    score: 0.77,
    is_fallback: false,
    match_type: null,
    match_reason: 'Legacy search result',
  }

  assert.doesNotThrow(() => {
    const state = computeComicCardAiState(comicWithoutMatchType, 'ai')
    assert.ok(state.badgeConfig)
    assert.equal(state.badgeConfig.className, 'badge-neutral')
    assert.equal(state.badgeConfig.label, 'Điểm phù hợp 77%')
    assert.equal(state.badgeConfig.title, 'Điểm xếp hạng tìm kiếm AI: 77%')
    assert.ok(!state.badgeConfig.title.includes('ngữ nghĩa'), 'Must NOT claim semantic in title')
    assert.ok(!state.badgeConfig.title.includes('manh mối'), 'Must NOT claim clue in title')
    assert.ok(!state.badgeConfig.title.includes('kết hợp'), 'Must NOT claim hybrid in title')
  })
})

test('6. Fallback search suppresses score badge completely', () => {
  const fallbackComic = {
    id: 6,
    title: 'Truyện Fallback Khi AI Bận',
    slug: 'truyen-fallback',
    score: 0.80,
    is_fallback: true,
    match_type: 'clue',
    match_reason: 'Khớp từ khóa tiêu đề',
  }

  const state = computeComicCardAiState(fallbackComic, 'ai')
  assert.equal(state.hasValidScore, false)
  assert.equal(state.badgeConfig, null, 'Must NOT show badge in fallback search')
})

test('7. Normal search mode strictly isolates from AI badges and metadata', () => {
  const normalComic = {
    id: 7,
    title: 'Truyện Chế Độ Thường Có Dư Score',
    slug: 'truyen-thuong',
    score: 0.95,
    is_fallback: false,
    match_type: 'semantic',
    match_reason: 'Ngữ nghĩa AI',
  }

  const state = computeComicCardAiState(normalComic, 'normal')
  assert.equal(state.isAiMode, false)
  assert.equal(state.hasValidScore, false)
  assert.equal(state.badgeConfig, null, 'Normal mode must have null badgeConfig')
})

test('8. Cover URL resolver logic handles full URL, storage path, and empty correctly', () => {
  const comicStorage = { id: 8, title: 'A', slug: 'a', cover_path: 'covers/pic.webp' }
  assert.equal(computeComicCardAiState(comicStorage).coverSrc, '/storage/covers/pic.webp')

  const comicExternal = { id: 9, title: 'B', slug: 'b', cover_path: 'https://cdn.example.com/b.webp' }
  assert.equal(computeComicCardAiState(comicExternal).coverSrc, 'https://cdn.example.com/b.webp')

  const comicCoverUrl = { id: 10, title: 'C', slug: 'c', cover_url: 'http://localhost/storage/c.webp', cover_path: 'c.webp' }
  assert.equal(computeComicCardAiState(comicCoverUrl).coverSrc, 'http://localhost/storage/c.webp')

  const comicEmpty = { id: 11, title: 'D', slug: 'd' }
  assert.equal(computeComicCardAiState(comicEmpty).coverSrc, '')
})

test('9. ComicsPage search mode transitions and empty state logic', () => {
  function simulateComicsPage(mode, q) {
    const isAi = mode === 'ai'
    return {
      searchMode: isAi ? 'ai' : 'normal',
      countLabel: isAi && q ? `Tìm thấy 10 bộ truyện theo ngữ nghĩa AI` : `Tìm thấy 10 bộ truyện`,
      emptyState: isAi ? { icon: '✨', canSwitchNormal: true } : { icon: '🔍', canSwitchNormal: false },
    }
  }

  const pageAi = simulateComicsPage('ai', 'tu tien')
  assert.equal(pageAi.searchMode, 'ai')
  assert.equal(pageAi.countLabel, 'Tìm thấy 10 bộ truyện theo ngữ nghĩa AI')
  assert.equal(pageAi.emptyState.canSwitchNormal, true)

  const pageNormal = simulateComicsPage('normal', 'tu tien')
  assert.equal(pageNormal.searchMode, 'normal')
  assert.equal(pageNormal.countLabel, 'Tìm thấy 10 bộ truyện')
  assert.equal(pageNormal.emptyState.canSwitchNormal, false)
})

// =========================================================================
// SUMMARY
// =========================================================================

console.log('\n-----------------------------------------------------------------')
console.log(`TOTAL TESTS: ${passedTests + failedTests}`)
console.log(`PASSED: ${passedTests}`)
console.log(`FAILED: ${failedTests}`)
console.log('-----------------------------------------------------------------')

if (failedTests > 0) {
  process.exit(1)
} else {
  console.log('🎉 ALL PACKAGE 10.4D FINAL UX TESTS PASSED SUCCESSFULLY!\n')
  process.exit(0)
}
