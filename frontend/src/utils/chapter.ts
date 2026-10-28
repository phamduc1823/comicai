/**
 * Utility helpers for chapter numbers, formatting, and numeric comparisons.
 */

/**
 * Normalizes a chapter number string or number to a clean, user-friendly string.
 * Strips unnecessary trailing decimal zeroes while preserving actual decimals.
 * Examples:
 * - '1.00' -> '1'
 * - '1.50' -> '1.5'
 * - '10.25' -> '10.25'
 * - 2 -> '2'
 */
export function formatChapterNumber(raw: string | number | null | undefined): string {
  if (raw === null || raw === undefined) return ''
  const num = typeof raw === 'number' ? raw : parseFloat(String(raw).trim())
  if (isNaN(num)) return String(raw).trim()
  return num.toString()
}

/**
 * Parses chapter number to float for mathematical comparisons.
 */
export function parseChapterNumber(raw: string | number | null | undefined): number {
  if (raw === null || raw === undefined) return 0
  const num = typeof raw === 'number' ? raw : parseFloat(String(raw).trim())
  return isNaN(num) ? 0 : num
}

/**
 * Compares two chapter numbers numerically ascending (e.g. 1, 1.5, 2, 10).
 */
export function compareChapterNumbers(
  a: string | number | null | undefined,
  b: string | number | null | undefined
): number {
  return parseChapterNumber(a) - parseChapterNumber(b)
}

/**
 * Finds the first (lowest numeric chapter number) chapter from a list.
 */
export function getFirstChapter<T extends { chapter_number: string | number }>(
  chapters: T[] | null | undefined
): T | null {
  if (!chapters || chapters.length === 0) return null
  return [...chapters].sort((a, b) => compareChapterNumbers(a.chapter_number, b.chapter_number))[0]
}

/**
 * Finds the latest (highest numeric chapter number) chapter from a list.
 */
export function getLatestChapter<T extends { chapter_number: string | number }>(
  chapters: T[] | null | undefined
): T | null {
  if (!chapters || chapters.length === 0) return null
  return [...chapters].sort((a, b) => compareChapterNumbers(b.chapter_number, a.chapter_number))[0]
}

/**
 * Builds a clean canonical URL path for reading a chapter.
 * Example: buildChapterUrl('conan', '1.00') -> '/comics/conan/chapters/1'
 */
export function buildChapterUrl(comicSlug: string, chapterNumber: string | number): string {
  const cleanNum = formatChapterNumber(chapterNumber)
  return `/comics/${encodeURIComponent(comicSlug)}/chapters/${encodeURIComponent(cleanNum)}`
}
