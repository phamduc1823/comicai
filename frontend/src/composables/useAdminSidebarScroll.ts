/**
 * Composable for managing and preserving the Admin Sidebar navigation scroll position.
 *
 * Requirements:
 * 1. Maintain exact scroll position across client-side Vue Router navigation in the Admin area.
 * 2. Reset scroll position to top (scrollTop = 0) only on full page reload (F5 / browser refresh).
 *
 * Using an in-memory module-level variable:
 * - Stored in JS memory across component unmount/mount cycles during SPA transitions.
 * - Naturally re-initializes to 0 whenever the page is fully reloaded.
 */

let savedAdminSidebarScrollTop = 0

export function useAdminSidebarScroll() {
  function getSavedScrollTop(): number {
    return savedAdminSidebarScrollTop
  }

  function setSavedScrollTop(scrollTop: number): void {
    savedAdminSidebarScrollTop = Math.max(0, scrollTop)
  }

  function resetScrollTop(): void {
    savedAdminSidebarScrollTop = 0
  }

  return {
    getSavedScrollTop,
    setSavedScrollTop,
    resetScrollTop,
  }
}
