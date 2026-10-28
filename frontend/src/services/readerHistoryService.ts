/**
 * Client-side Reading History Service (localStorage MVP).
 * Tracks the last read chapter per comic identified by comic slug.
 */

export interface ReadingHistoryEntry {
  comicSlug: string
  chapterNumber: string
  updatedAt: string
}

const STORAGE_KEY = 'bluetoon_reading_history'

function isStorageAvailable(): boolean {
  try {
    if (typeof window === 'undefined' || !window.localStorage) {
      return false
    }
    const testKey = '__storage_test__'
    window.localStorage.setItem(testKey, testKey)
    window.localStorage.removeItem(testKey)
    return true
  } catch {
    return false
  }
}

function getAllHistory(): Record<string, ReadingHistoryEntry> {
  if (!isStorageAvailable()) return {}
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw)
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
      return {}
    }
    return parsed as Record<string, ReadingHistoryEntry>
  } catch {
    return {}
  }
}

export class ReaderHistoryService {
  /**
   * Retrieve the last read chapter record for a comic by its slug.
   * Returns null if missing, corrupted, or storage is inaccessible.
   */
  static getLastRead(comicSlug: string | null | undefined): ReadingHistoryEntry | null {
    if (!comicSlug || typeof comicSlug !== 'string') return null
    const slug = comicSlug.trim()
    if (!slug) return null

    const all = getAllHistory()
    const entry = all[slug]
    if (!entry || typeof entry !== 'object') return null

    // Validate fields strictly
    if (
      typeof entry.comicSlug !== 'string' ||
      entry.comicSlug !== slug ||
      (typeof entry.chapterNumber !== 'string' && typeof entry.chapterNumber !== 'number') ||
      String(entry.chapterNumber).trim() === ''
    ) {
      return null
    }

    return {
      comicSlug: entry.comicSlug,
      chapterNumber: String(entry.chapterNumber).trim(),
      updatedAt: typeof entry.updatedAt === 'string' ? entry.updatedAt : new Date().toISOString(),
    }
  }

  /**
   * Save the last read chapter for a comic without altering other comics' records.
   */
  static saveLastRead(
    comicSlug: string | null | undefined,
    chapterNumber: string | number | null | undefined
  ): boolean {
    if (!comicSlug || chapterNumber === null || chapterNumber === undefined) return false
    const slug = String(comicSlug).trim()
    const num = String(chapterNumber).trim()
    if (!slug || !num || !isStorageAvailable()) return false

    try {
      const all = getAllHistory()
      all[slug] = {
        comicSlug: slug,
        chapterNumber: num,
        updatedAt: new Date().toISOString(),
      }
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(all))
      return true
    } catch {
      return false
    }
  }

  /**
   * Remove history entry for a given comic slug.
   */
  static clearLastRead(comicSlug: string | null | undefined): void {
    if (!comicSlug || !isStorageAvailable()) return
    const slug = String(comicSlug).trim()
    if (!slug) return
    try {
      const all = getAllHistory()
      if (all[slug]) {
        delete all[slug]
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(all))
      }
    } catch {
      // ignore
    }
  }
}
