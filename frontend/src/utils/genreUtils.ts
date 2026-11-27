/**
 * ComicAI Genre Utilities
 * Supports unaccented search, prefix matching, substring matching, and fuzzy search for genres.
 */

export interface GenreLike {
  id: number
  name: string
  slug: string
  description?: string | null
  [key: string]: unknown
}

/**
 * Remove Vietnamese accents and convert to lowercase standard ASCII string.
 */
export function normalizeVietnameseText(input: string): string {
  if (!input) return ''
  let text = input.trim().toLowerCase()
  // Normalize unicode (decomposed NFD) and strip diacritical marks
  text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
  // Replace Vietnamese 'đ' / 'Đ'
  text = text.replace(/[đĐ]/g, 'd')
  // Collapse whitespace
  text = text.replace(/\s+/g, ' ')
  return text
}

/**
 * Calculate Levenshtein distance between two strings.
 */
export function levenshteinDistance(a: string, b: string): number {
  const m = a.length
  const n = b.length
  if (m === 0) return n
  if (n === 0) return m

  const d: number[][] = []
  for (let i = 0; i <= m; i++) {
    d[i] = [i]
  }
  for (let j = 0; j <= n; j++) {
    d[0][j] = j
  }

  for (let i = 1; i <= m; i++) {
    for (let j = 1; j <= n; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1
      d[i][j] = Math.min(
        d[i - 1][j] + 1, // deletion
        d[i][j - 1] + 1, // insertion
        d[i - 1][j - 1] + cost // substitution
      )
    }
  }

  return d[m][n]
}

/**
 * Check if target matches query using fuzzy matching (allowing slight typos).
 * True if word distance is within reasonable threshold based on query length.
 */
export function isFuzzyMatch(target: string, query: string): boolean {
  if (!query || !target) return false
  const normTarget = normalizeVietnameseText(target)
  const normQuery = normalizeVietnameseText(query)

  // Direct contains
  if (normTarget.includes(normQuery)) return true

  // Word-by-word prefix or fuzzy match
  const targetWords = normTarget.split(' ')
  const queryWords = normQuery.split(' ')

  // If query is a single word and length >= 3, check words in target
  if (queryWords.length === 1 && normQuery.length >= 3) {
    const maxDistance = normQuery.length <= 4 ? 1 : 2
    for (const tw of targetWords) {
      if (tw.startsWith(normQuery)) return true
      if (Math.abs(tw.length - normQuery.length) <= maxDistance) {
        if (levenshteinDistance(tw, normQuery) <= maxDistance) {
          return true
        }
      }
    }
    // Also compare against target without spaces
    const noSpaceTarget = normTarget.replace(/\s+/g, '')
    if (noSpaceTarget.includes(normQuery)) return true
  }

  // Full string edit distance for multi-word
  if (normQuery.length >= 4) {
    const maxFullDist = normQuery.length <= 7 ? 1 : 2
    if (Math.abs(normTarget.length - normQuery.length) <= maxFullDist) {
      if (levenshteinDistance(normTarget, normQuery) <= maxFullDist) {
        return true
      }
    }
  }

  return false
}

/**
 * Filter and sort genres based on a search keyword:
 * 1. Exact match (case/accent insensitive) -> priority 1
 * 2. Prefix match (starts with keyword) -> priority 2
 * 3. Substring match (contains keyword) -> priority 3
 * 4. Word-level prefix or fuzzy match -> priority 4
 * Non-matching items are excluded.
 */
export function filterAndRankGenres<T extends GenreLike>(genres: T[], query: string): T[] {
  const q = normalizeVietnameseText(query)
  if (!q) return [...genres]

  const scored: { item: T; score: number }[] = []

  for (const genre of genres) {
    const normName = normalizeVietnameseText(genre.name)
    const normSlug = normalizeVietnameseText(genre.slug.replace(/-/g, ' '))

    // 1. Exact match
    if (normName === q || normSlug === q) {
      scored.push({ item: genre, score: 0 })
      continue
    }

    // 2. Starts with query
    if (normName.startsWith(q) || normSlug.startsWith(q)) {
      scored.push({ item: genre, score: 10 })
      continue
    }

    // 3. Any word in genre starts with query
    const words = normName.split(' ')
    const anyWordPrefix = words.some((w) => w.startsWith(q))
    if (anyWordPrefix) {
      scored.push({ item: genre, score: 20 })
      continue
    }

    // 4. Substring contains
    if (normName.includes(q) || normSlug.includes(q)) {
      scored.push({ item: genre, score: 30 })
      continue
    }

    // 5. Fuzzy match for slight typos (e.g., 'kiêm' -> 'kiếm', 'hanhdong' -> 'hành động')
    const noSpaceName = normName.replace(/\s+/g, '')
    const noSpaceQ = q.replace(/\s+/g, '')
    if (noSpaceName.includes(noSpaceQ)) {
      scored.push({ item: genre, score: 35 })
      continue
    }

    if (isFuzzyMatch(genre.name, query) || isFuzzyMatch(genre.slug, query)) {
      scored.push({ item: genre, score: 50 })
    }
  }

  // Sort by score ascending (lowest score = highest relevance), then name length, then alphabetical
  scored.sort((a, b) => {
    if (a.score !== b.score) return a.score - b.score
    if (a.item.name.length !== b.item.name.length) return a.item.name.length - b.item.name.length
    return a.item.name.localeCompare(b.item.name, 'vi')
  })

  return scored.map((s) => s.item)
}

/**
 * Organizes genres for Selected-First UI display:
 * - Selected genres are always placed first, in the order they were selected.
 * - If a search query is active, only matching genres are shown (selected matching first, then unselected matching).
 * - If no search query is active:
 *   - Selected genres are all shown first.
 *   - Unselected genres follow, with a limit (e.g. 8-10 default) unless expanded.
 *
 * @param allGenres Full available genres list
 * @param selectedIds IDs of currently selected genres (ordered by selection time)
 * @param query Search query text
 * @param isExpanded Whether user clicked "Xem thêm"
 * @param defaultUnselectedLimit Max unselected items to show when not expanded (default 8)
 */
export function getOrganizedGenres<T extends GenreLike>(
  allGenres: T[],
  selectedIds: number[],
  query: string,
  isExpanded: boolean = false,
  defaultUnselectedLimit: number = 8
): {
  displayedGenres: T[]
  selectedCount: number
  unselectedTotal: number
  unselectedDisplayedCount: number
  hasMoreUnselected: boolean
} {
  const normQuery = query.trim()

  // Map for fast ID lookup
  const genreMap = new Map<number, T>()
  allGenres.forEach((g) => genreMap.set(g.id, g))

  // 1. Maintain ordered selected genres based on selectedIds
  const selectedGenres: T[] = []
  for (const id of selectedIds) {
    const g = genreMap.get(id)
    if (g && !selectedGenres.some((item) => item.id === g.id)) {
      selectedGenres.push(g)
    }
  }

  // 2. Unselected genres
  const unselectedGenres = allGenres.filter((g) => !selectedIds.includes(g.id))

  if (normQuery) {
    // When searching:
    // Rank both selected and unselected according to search relevance
    const matchingSelected = filterAndRankGenres(selectedGenres, normQuery)
    const matchingUnselected = filterAndRankGenres(unselectedGenres, normQuery)

    // Selected always first, followed by unselected matching
    const displayed = [...matchingSelected, ...matchingUnselected]

    return {
      displayedGenres: displayed,
      selectedCount: matchingSelected.length,
      unselectedTotal: matchingUnselected.length,
      unselectedDisplayedCount: matchingUnselected.length,
      hasMoreUnselected: false,
    }
  }

  // When not searching:
  // All selected genres are shown
  // Unselected genres are limited unless expanded
  const unselectedTotal = unselectedGenres.length
  const limit = isExpanded ? unselectedTotal : defaultUnselectedLimit
  const displayedUnselected = unselectedGenres.slice(0, limit)
  const hasMoreUnselected = unselectedTotal > defaultUnselectedLimit

  return {
    displayedGenres: [...selectedGenres, ...displayedUnselected],
    selectedCount: selectedGenres.length,
    unselectedTotal,
    unselectedDisplayedCount: displayedUnselected.length,
    hasMoreUnselected,
  }
}
