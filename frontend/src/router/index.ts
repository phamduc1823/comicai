import { createRouter, createWebHistory } from 'vue-router'
import HomePage from '@/pages/HomePage.vue'
import { useAuthStore } from '@/stores/auth'
import type { UserRole } from '@/types/auth'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    requiresAuth?: boolean
    guestOnly?: boolean
    roles?: UserRole[]
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomePage,
      meta: {
        title: 'BlueToon — Nền tảng đọc truyện tranh trực tuyến',
      },
    },
    {
      path: '/comics',
      name: 'comics',
      component: () => import('@/pages/ComicsPage.vue'),
      meta: {
        title: 'Danh mục truyện — BlueToon',
      },
    },
    {
      path: '/comics/:slug',
      name: 'comic-detail',
      component: () => import('@/pages/ComicDetailPage.vue'),
      meta: {
        title: 'Chi tiết truyện — BlueToon',
      },
    },
    {
      path: '/comics/:slug/chapters/:chapterNumber',
      name: 'comic-reader',
      component: () => import('@/pages/ComicReaderPage.vue'),
      meta: {
        title: 'Đọc truyện — BlueToon',
      },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/LoginPage.vue'),
      meta: {
        title: 'Đăng nhập — BlueToon',
        guestOnly: true,
      },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/pages/RegisterPage.vue'),
      meta: {
        title: 'Đăng ký — BlueToon',
        guestOnly: true,
      },
    },
  ],
})

/**
 * Validate that a redirect target is a safe internal route and does not cause a redirect loop.
 */
export function getSafeGuestRedirect(target: unknown, currentPath: string, currentFullPath: string): string | null {
  if (typeof target !== 'string' || !target.startsWith('/') || target.startsWith('//')) {
    return null
  }

  // Extract pathname part without query string or hash fragment
  const targetPath = target.split('?')[0].split('#')[0]

  // Reject if redirect target matches current route path or fullPath
  if (targetPath === currentPath || target === currentFullPath) {
    return null
  }

  // Inspect resolved route destination through router
  try {
    const resolved = router.resolve(target)
    // Reject if destination route is also guestOnly (prevents redirect chains/loops)
    if (resolved.meta?.guestOnly) {
      return null
    }
    // Reject if resolved path matches current route
    if (resolved.path === currentPath) {
      return null
    }
    return target
  } catch {
    return null
  }
}

router.beforeEach(async (to) => {
  // Update document title if specified
  if (to.meta.title && typeof to.meta.title === 'string') {
    document.title = to.meta.title
  }

  const authStore = useAuthStore()

  // Ensure authentication state has been initialized before evaluating route access
  if (!authStore.isInitialized) {
    await authStore.initialize()
  }

  // Handle routes requiring authentication
  if (to.meta.requiresAuth) {
    if (!authStore.isAuthenticated) {
      // If verification failed due to network or server error
      if (authStore.sessionStatus === 'error') {
        if (to.path === '/') return true
        return {
          path: '/',
          query: {
            auth_error: 'network',
            redirect: to.fullPath !== '/' ? to.fullPath : undefined,
          },
        }
      }

      // Check if login route exists (Step 4.4 will create it)
      if (router.hasRoute('login')) {
        return {
          name: 'login',
          query: { redirect: to.fullPath !== '/' ? to.fullPath : undefined },
        }
      }

      // If login route does not exist yet, safely redirect to home preserving redirect query
      if (to.path === '/') return true
      return {
        path: '/',
        query: { redirect: to.fullPath !== '/' ? to.fullPath : undefined },
      }
    }

    // Authenticated: check required roles if specified
    if (to.meta.roles && to.meta.roles.length > 0) {
      const userRole = authStore.userRole
      if (!userRole || !to.meta.roles.includes(userRole)) {
        if (to.path === '/') return true
        return { path: '/' }
      }
    }
  }

  // Handle guest-only routes (e.g. login / register)
  if (to.meta.guestOnly && authStore.isAuthenticated) {
    const safeRedirect = getSafeGuestRedirect(to.query.redirect, to.path, to.fullPath)
    if (safeRedirect) {
      return safeRedirect
    }
    if (to.path === '/') return true
    return { path: '/' }
  }

  return true
})

export default router
