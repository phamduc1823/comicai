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
  scrollBehavior(to, from, savedPosition) {
    // CAI-ADMIN-03: Strict protection for Admin UI and Admin Sidebar scroll state.
    // Preserves Admin layout and existing useAdminSidebarScroll composable behaviour.
    if (to.path.startsWith('/admin') || from.path.startsWith('/admin')) {
      return false
    }

    if (savedPosition) {
      return savedPosition
    }

    if (to.hash) {
      return { el: to.hash, behavior: 'smooth' }
    }

    // Reset window scroll to top when opening a comic from home page or navigating between public pages
    if (
      to.name === 'comic-detail' ||
      to.name === 'comic-reader' ||
      to.path.startsWith('/comics')
    ) {
      return { top: 0, left: 0 }
    }

    return { top: 0, left: 0 }
  },
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
      path: '/community',
      name: 'community',
      component: () => import('@/views/CommunityPage.vue'),
      meta: {
        title: 'Cộng đồng — BlueToon',
      },
    },
    {
      path: '/posts/:id',
      name: 'post-detail',
      component: () => import('@/views/PostDetailPage.vue'),
      meta: {
        title: 'Chi tiết bài viết — BlueToon',
      },
    },
    {
      path: '/users/:id',
      name: 'user-profile',
      component: () => import('@/pages/UserProfilePage.vue'),
      meta: {
        title: 'Hồ sơ người dùng — BlueToon',
      },
    },
    {
      path: '/profile',
      name: 'account-profile',
      component: () => import('@/pages/AccountProfilePage.vue'),
      meta: {
        title: 'Quản lý tài khoản — BlueToon',
        requiresAuth: true,
      },
    },
    {
      path: '/account',
      redirect: '/profile',
    },
    {
      path: '/library',
      name: 'library',
      component: () => import('@/pages/LibraryPage.vue'),
      meta: {
        title: 'Tủ sách của tôi — BlueToon',
        requiresAuth: true,
      },
    },
    {
      path: '/chat',
      name: 'chat',
      component: () => import('@/pages/ChatPage.vue'),
      meta: {
        title: 'Trò chuyện — BlueToon',
        requiresAuth: true,
      },
    },
    {
      path: '/wallet',
      name: 'wallet',
      component: () => import('@/pages/WalletPage.vue'),
      meta: {
        title: 'Ví Coin của tôi — BlueToon',
        requiresAuth: true,
      },
    },
    {
      path: '/become-creator',
      name: 'creator-application',
      component: () => import('@/pages/CreatorApplicationPage.vue'),
      meta: {
        title: 'Đăng ký Tác giả — BlueToon',
      },
    },
    {
      path: '/creator-application',
      redirect: '/become-creator',
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
    {
      path: '/creator',
      redirect: '/creator/comics',
    },
    {
      path: '/creator/comics',
      name: 'creator-comics',
      component: () => import('@/pages/creator/CreatorComicsPage.vue'),
      meta: {
        title: 'Truyện của tôi — BlueToon Studio',
        requiresAuth: true,
        roles: ['creator', 'admin'],
      },
    },
    {
      path: '/creator/comics/create',
      name: 'creator-comic-create',
      component: () => import('@/pages/creator/CreatorComicFormPage.vue'),
      meta: {
        title: 'Đăng truyện mới — BlueToon Studio',
        requiresAuth: true,
        roles: ['creator', 'admin'],
      },
    },
    {
      path: '/creator/comics/:id/edit',
      name: 'creator-comic-edit',
      component: () => import('@/pages/creator/CreatorComicFormPage.vue'),
      meta: {
        title: 'Chỉnh sửa truyện — BlueToon Studio',
        requiresAuth: true,
        roles: ['creator', 'admin'],
      },
    },
    {
      path: '/creator/comics/:comicId/chapters',
      name: 'creator-comic-chapters',
      component: () => import('@/pages/creator/CreatorChaptersPage.vue'),
      meta: {
        title: 'Quản lý chương — BlueToon Studio',
        requiresAuth: true,
        roles: ['creator', 'admin'],
      },
    },
    {
      path: '/creator/chapters/:id/images',
      name: 'creator-chapter-images',
      component: () => import('@/pages/creator/CreatorChapterImagesPage.vue'),
      meta: {
        title: 'Quản lý ảnh chương — BlueToon Studio',
        requiresAuth: true,
        roles: ['creator', 'admin'],
      },
    },
    {
      path: '/admin',
      redirect: '/admin/moderation/comics',
    },
    {
      path: '/admin/moderation',
      redirect: '/admin/moderation/comics',
    },
    {
      path: '/admin/moderation/comics',
      name: 'admin-moderation-comics',
      component: () => import('@/pages/admin/AdminComicsModerationPage.vue'),
      meta: {
        title: 'Duyệt truyện — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/moderation/chapters',
      name: 'admin-moderation-chapters',
      component: () => import('@/pages/admin/AdminChaptersModerationPage.vue'),
      meta: {
        title: 'Duyệt chương — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/banners',
      name: 'admin-banners',
      component: () => import('@/pages/admin/AdminBannersPage.vue'),
      meta: {
        title: 'Quản lý Banner Slider — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/reports',
      name: 'admin-reports',
      component: () => import('@/pages/admin/AdminReportsPage.vue'),
      meta: {
        title: 'Quản lý Báo cáo vi phạm — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/creator-applications',
      name: 'admin-creator-applications',
      component: () => import('@/pages/admin/AdminCreatorApplicationsPage.vue'),
      meta: {
        title: 'Quản lý Hồ sơ Creator — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/creator-applications/:id',
      name: 'admin-creator-application-detail',
      component: () => import('@/pages/admin/AdminCreatorApplicationDetailPage.vue'),
      meta: {
        title: 'Chi tiết Hồ sơ Creator — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/creators',
      name: 'admin-creators',
      component: () => import('@/pages/admin/AdminCreatorsPage.vue'),
      meta: {
        title: 'Quản lý Creator — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/admin/creators/:id',
      name: 'admin-creator-detail',
      component: () => import('@/pages/admin/AdminCreatorDetailPage.vue'),
      meta: {
        title: 'Chi tiết Creator — BlueToon Admin',
        requiresAuth: true,
        roles: ['admin'],
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
