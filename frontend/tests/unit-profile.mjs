import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

console.log('--- RUNNING CAI-PROFILE-01 UNIT TESTS ---')

// [TEST 1] Route configuration
{
  console.log('[TEST 1] Verify /users/:id route is registered in router/index.ts')
  const routerPath = path.join(projectRoot, 'src/router/index.ts')
  const routerContent = fs.readFileSync(routerPath, 'utf8')

  assert.ok(
    routerContent.includes("path: '/users/:id'") || routerContent.includes('path: "/users/:id"'),
    'Route /users/:id must be registered in router'
  )
  assert.ok(
    routerContent.includes("name: 'user-profile'") || routerContent.includes('name: "user-profile"'),
    'Route name user-profile must be registered'
  )
  assert.ok(
    routerContent.includes('UserProfilePage.vue'),
    'Route /users/:id must lazily import UserProfilePage.vue'
  )
  console.log('✓ [TEST 1] PASS: /users/:id route correctly configured')
}

// [TEST 2] UserProfileService and Types
{
  console.log('[TEST 2] Verify UserProfileService and types')
  const servicePath = path.join(projectRoot, 'src/services/userProfileService.ts')
  const serviceContent = fs.readFileSync(servicePath, 'utf8')

  assert.ok(serviceContent.includes('getUserProfile'), 'Service must have getUserProfile method')
  assert.ok(serviceContent.includes('getUserComics'), 'Service must have getUserComics method')
  assert.ok(serviceContent.includes('getUserPosts'), 'Service must have getUserPosts method')
  assert.ok(serviceContent.includes("`/users/${userId}`"), 'getUserProfile must target /users/:id')
  assert.ok(serviceContent.includes("`/users/${userId}/comics`"), 'getUserComics must target /users/:id/comics')
  assert.ok(serviceContent.includes("`/users/${userId}/posts`"), 'getUserPosts must target /users/:id/posts')

  const typesPath = path.join(projectRoot, 'src/types/userProfile.ts')
  const typesContent = fs.readFileSync(typesPath, 'utf8')
  assert.ok(typesContent.includes('interface UserProfile'), 'UserProfile interface must exist')
  assert.ok(typesContent.includes('posts_count'), 'UserProfileStats must have posts_count')
  assert.ok(typesContent.includes('comics_count'), 'UserProfileStats must have comics_count')
  console.log('✓ [TEST 2] PASS: UserProfileService and types correctly defined')
}

// [TEST 3] Role-based Tab Logic in UserProfilePage.vue
{
  console.log('[TEST 3] Verify Creator vs Reader Tab logic in UserProfilePage.vue')
  const pagePath = path.join(projectRoot, 'src/pages/UserProfilePage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  // Role computed check
  assert.ok(
    pageContent.includes("profile.value?.role === 'creator'") &&
    pageContent.includes("profile.value?.role === 'admin'"),
    'isCreator computed must check creator and admin roles'
  )

  // Tab display check
  assert.ok(
    pageContent.includes("v-if=\"isCreator\"") && pageContent.includes("Truyện đã đăng"),
    'Comics tab button must be conditioned on isCreator'
  )
  assert.ok(
    pageContent.includes("v-if=\"activeTab === 'comics' && isCreator\""),
    'Comics tab panel must only render for creators'
  )
  assert.ok(
    pageContent.includes("activeTab.value = 'posts'"),
    'Reader profile must default activeTab to posts'
  )
  console.log('✓ [TEST 3] PASS: Creator vs Reader tab rules strictly enforced')
}

// [TEST 4] Messaging Action Button Rules
{
  console.log('[TEST 4] Verify Messaging button rules in UserProfilePage.vue')
  const pagePath = path.join(projectRoot, 'src/pages/UserProfilePage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  // Own profile check
  assert.ok(
    pageContent.includes("v-if=\"!isOwnProfile\""),
    'Nhắn tin button must only be shown when !isOwnProfile'
  )
  assert.ok(
    pageContent.includes("authStore.isAuthenticated && authStore.user?.id === profile.value?.id"),
    'isOwnProfile must check authStore.user.id against profile.id'
  )

  // Click handler check
  assert.ok(
    pageContent.includes("handleStartMessage"),
    'handleStartMessage function must be attached to message button'
  )
  assert.ok(
    pageContent.includes("path: '/login'") && pageContent.includes("redirect: route.fullPath"),
    'Unauthenticated click on message button must redirect to login with return path'
  )
  assert.ok(
    pageContent.includes("path: '/chat'") && pageContent.includes("user_id: profile.value.id"),
    'Authenticated click on message button must navigate to /chat with user_id query'
  )
  console.log('✓ [TEST 4] PASS: Messaging action button rules verified')
}

// [TEST 5] Community and ComicDetail Profile Links
{
  console.log('[TEST 5] Verify avatar and name profile links across Community and ComicDetail')
  
  // PostCard.vue
  const postCardPath = path.join(projectRoot, 'src/components/community/PostCard.vue')
  const postCardContent = fs.readFileSync(postCardPath, 'utf8')
  assert.ok(
    postCardContent.includes(":to=\"'/users/' + post.user.id\""),
    'PostCard author avatar and name must link to /users/:id'
  )

  // CommentItem.vue
  const commentItemPath = path.join(projectRoot, 'src/components/community/CommentItem.vue')
  const commentItemContent = fs.readFileSync(commentItemPath, 'utf8')
  assert.ok(
    commentItemContent.includes(":to=\"'/users/' + comment.user.id\""),
    'CommentItem author avatar and name must link to /users/:id'
  )

  // ComicDetailPage.vue
  const comicDetailPath = path.join(projectRoot, 'src/pages/ComicDetailPage.vue')
  const comicDetailContent = fs.readFileSync(comicDetailPath, 'utf8')
  assert.ok(
    comicDetailContent.includes(":to=\"'/users/' + comic.creator.id\""),
    'ComicDetailPage creator info must link to /users/:id'
  )
  console.log('✓ [TEST 5] PASS: Profile links correctly implemented across all required locations')
}

// [TEST 6] Backend UserProfileController Security & Data Policy
{
  console.log('[TEST 6] Verify Backend UserProfileController security and privacy policy')
  const controllerPath = path.join(projectRoot, '../backend/app/Http/Controllers/Api/UserProfileController.php')
  const controllerContent = fs.readFileSync(controllerPath, 'utf8')

  assert.ok(
    controllerContent.includes("where('approval_status', 'approved')"),
    'Comics query must strictly filter for approval_status === approved'
  )
  assert.ok(
    controllerContent.includes("where('is_active', true)"),
    'UserProfileController must strictly check is_active === true'
  )

  const resourcePath = path.join(projectRoot, '../backend/app/Http/Resources/UserProfileResource.php')
  const resourceContent = fs.readFileSync(resourcePath, 'utf8')
  assert.ok(!resourceContent.includes("'email'"), 'UserProfileResource must NOT expose email')
  assert.ok(!resourceContent.includes("'password'"), 'UserProfileResource must NOT expose password')
  assert.ok(!resourceContent.includes("'wallet'"), 'UserProfileResource must NOT expose wallet')

  console.log('✓ [TEST 6] PASS: Backend UserProfileController security and privacy policy intact')
}

console.log('--- ALL 6 FRONTEND PROFILE UNIT TESTS PASSED ---')
