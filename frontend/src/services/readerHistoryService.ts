/**
 * Client-side Reading History Service (localStorage Multi-user Scoped MVP).
 * Tracks the last read chapter per comic identified by comic slug,
 * partitioned by user identity (guest vs. authenticated user ID).
 */

export interface ReadingHistoryEntry {
  comicSlug: string
  chapterNumber: string
  updatedAt: string
}

export const GUEST_STORAGE_KEY = 'bluetoon_history_guest'
export const LEGACY_STORAGE_KEY = 'bluetoon_reading_history'

/**
 * Returns the isolated localStorage key for a given user identity.
 * - Authenticated user: 'bluetoon_history_user_${userId}'
 * - Guest / Unauthenticated: 'bluetoon_history_guest'
 */
export function getStorageKey(userId?: number | null): string {
  if (typeof userId === 'number' && Number.isInteger(userId) && userId > 0) {
    return `bluetoon_history_user_${userId}`
  }
  return GUEST_STORAGE_KEY
}

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

function getHistoryFromStorage(key: string): Record<string, ReadingHistoryEntry> {
  if (!isStorageAvailable()) return {}
  try {
    const raw = window.localStorage.getItem(key)
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

function validateEntry(entry: unknown, expectedSlug: string): ReadingHistoryEntry | null {
  if (!entry || typeof entry !== 'object' || Array.isArray(entry)) return null
  const item = entry as Partial<ReadingHistoryEntry>
  if (
    typeof item.comicSlug !== 'string' ||
    item.comicSlug !== expectedSlug ||
    (typeof item.chapterNumber !== 'string' && typeof item.chapterNumber !== 'number') ||
    String(item.chapterNumber).trim() === ''
  ) {
    return null
  }
  return {
    comicSlug: item.comicSlug,
    chapterNumber: String(item.chapterNumber).trim(),
    updatedAt: typeof item.updatedAt === 'string' ? item.updatedAt : new Date().toISOString(),
  }
}

export class ReaderHistoryService {
  /**
   * Retrieve the last read chapter record for a comic by its slug within the specified user scope.
   * - If userId is specified (> 0), strictly queries `bluetoon_history_user_${userId}`.
   * - If userId is null/undefined/0, queries `bluetoon_history_guest`, with safe legacy fallback.
   */
  static getLastRead(
    comicSlug: string | null | undefined,
    userId?: number | null
  ): ReadingHistoryEntry | null {
    if (!comicSlug || typeof comicSlug !== 'string') return null
    const slug = comicSlug.trim()
    if (!slug) return null

    const isAuthenticatedUser = typeof userId === 'number' && Number.isInteger(userId) && userId > 0
    const targetKey = getStorageKey(userId)
    const history = getHistoryFromStorage(targetKey)

    const entry = validateEntry(history[slug], slug)
    if (entry) {
      return entry
    }

    // For authenticated users, strictly do NOT look at legacy un-scoped storage
    if (isAuthenticatedUser) {
      return null
    }

    // For guest users only: safe fallback to legacy storage if present
    const legacyHistory = getHistoryFromStorage(LEGACY_STORAGE_KEY)
    return validateEntry(legacyHistory[slug], slug)
  }

  /**
   * Save the last read chapter for a comic within the specified user scope.
   */
  static saveLastRead(
    comicSlug: string | null | undefined,
    chapterNumber: string | number | null | undefined,
    userId?: number | null
  ): boolean {
    if (!comicSlug || chapterNumber === null || chapterNumber === undefined) return false
    const slug = String(comicSlug).trim()
    const num = String(chapterNumber).trim()
    if (!slug || !num || !isStorageAvailable()) return false

    try {
      const targetKey = getStorageKey(userId)
      const all = getHistoryFromStorage(targetKey)
      all[slug] = {
        comicSlug: slug,
        chapterNumber: num,
        updatedAt: new Date().toISOString(),
      }
      window.localStorage.setItem(targetKey, JSON.stringify(all))
      return true
    } catch {
      return false
    }
  }

  /**
   * Remove history entry for a given comic slug within the specified user scope.
   */
  static clearLastRead(
    comicSlug: string | null | undefined,
    userId?: number | null
  ): void {
    if (!comicSlug || !isStorageAvailable()) return
    const slug = String(comicSlug).trim()
    if (!slug) return
    try {
      const targetKey = getStorageKey(userId)
      const all = getHistoryFromStorage(targetKey)
      if (all[slug]) {
        delete all[slug]
        window.localStorage.setItem(targetKey, JSON.stringify(all))
      }
    } catch {
      // ignore
    }
  }
}
