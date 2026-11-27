/**
 * ComicAI Tag Utilities
 * Implements community tag normalization rules:
 * - Lowercase
 * - Strip Vietnamese accents
 * - Strip leading '#'
 * - Strip whitespace
 * - Safe alphanumeric characters [a-z0-9]
 * - Max 50 characters
 */
export function normalizeCommunityTag(input: string): string {
  if (!input) return ''
  let text = input.trim()

  // Remove leading '#' characters
  text = text.replace(/^#+/, '')

  // Normalize unicode (decomposed NFD) and strip diacritical marks
  text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '')

  // Replace Vietnamese 'đ' / 'Đ'
  text = text.replace(/[đĐ]/g, 'd')

  // Lowercase
  text = text.toLowerCase()

  // Remove all whitespace
  text = text.replace(/\s+/g, '')

  // Keep only alphanumeric characters [a-z0-9]
  text = text.replace(/[^a-z0-9]/g, '')

  // Truncate to max 50 chars
  return text.slice(0, 50)
}

export interface TagLike {
  id: number
  name: string
  slug: string
  description?: string | null
  [key: string]: unknown
}

/**
 * Organizes tags for Creator Form UI:
 * - Selected tags are always placed first, in the order they were selected.
 * - Followed by at most `maxUnselected` unselected tags (default 6 items, i.e. 5-6 tags).
 * - Remaining unselected tags are hidden to keep UI clean and compact.
 * - Does not delete any tags from the database or loaded memory list.
 */
export function getOrganizedTags<T extends TagLike>(
  allTags: T[],
  selectedIds: number[],
  maxUnselected: number = 6
): T[] {
  const tagMap = new Map<number, T>()
  allTags.forEach((t) => tagMap.set(t.id, t))

  // 1. Maintain selected tags first, matching selection order
  const selectedTags: T[] = []
  for (const id of selectedIds) {
    const t = tagMap.get(id)
    if (t && !selectedTags.some((item) => item.id === t.id)) {
      selectedTags.push(t)
    }
  }

  // 2. Unselected tags limited to maxUnselected (5-6 items)
  const unselectedTags = allTags.filter((t) => !selectedIds.includes(t.id))
  const displayedUnselected = unselectedTags.slice(0, maxUnselected)

  return [...selectedTags, ...displayedUnselected]
}

