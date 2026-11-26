import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

console.log('--- RUNNING ACCOUNT MANAGEMENT & USER DROPDOWN UNIT TESTS ---')

// [TEST 1] AccountService and Types
{
  console.log('[TEST 1] Verify AccountService and Auth Types')
  const servicePath = path.join(projectRoot, 'src/services/accountService.ts')
  assert.ok(fs.existsSync(servicePath), 'accountService.ts must exist')
  const serviceContent = fs.readFileSync(servicePath, 'utf8')

  assert.ok(serviceContent.includes('getProfile()'), 'AccountService must have getProfile method')
  assert.ok(serviceContent.includes('updateProfile('), 'AccountService must have updateProfile method')
  assert.ok(serviceContent.includes('uploadAvatar('), 'AccountService must have uploadAvatar method')
  assert.ok(serviceContent.includes('deleteAvatar()'), 'AccountService must have deleteAvatar method')
  assert.ok(serviceContent.includes('updatePassword('), 'AccountService must have updatePassword method')

  assert.ok(serviceContent.includes("'/user/profile'"), 'Must target /user/profile endpoint')
  assert.ok(serviceContent.includes("'/user/avatar'"), 'Must target /user/avatar endpoint')
  assert.ok(serviceContent.includes("'/user/password'"), 'Must target /user/password endpoint')

  const typesPath = path.join(projectRoot, 'src/types/auth.ts')
  const typesContent = fs.readFileSync(typesPath, 'utf8')
  assert.ok(typesContent.includes('avatar_url'), 'User interface must include avatar_url')
  assert.ok(typesContent.includes('avatar_path'), 'User interface must include avatar_path')
  assert.ok(typesContent.includes('UpdateProfilePayload'), 'UpdateProfilePayload interface must exist')
  assert.ok(typesContent.includes('UpdatePasswordPayload'), 'UpdatePasswordPayload interface must exist')
  assert.ok(typesContent.includes('AvatarUploadResponse'), 'AvatarUploadResponse interface must exist')

  console.log('✓ [TEST 1] PASS: AccountService and Types correctly defined')
}

// [TEST 2] Router Configuration for /profile
{
  console.log('[TEST 2] Verify /profile route and /account redirect in router/index.ts')
  const routerPath = path.join(projectRoot, 'src/router/index.ts')
  const routerContent = fs.readFileSync(routerPath, 'utf8')

  assert.ok(
    routerContent.includes("path: '/profile'"),
    'Route /profile must be registered in router'
  )
  assert.ok(
    routerContent.includes("name: 'account-profile'"),
    'Route name account-profile must be registered'
  )
  assert.ok(
    routerContent.includes('AccountProfilePage.vue'),
    'Route /profile must import AccountProfilePage.vue'
  )
  assert.ok(
    routerContent.includes("path: '/account'") && routerContent.includes("redirect: '/profile'"),
    'Route /account must redirect to /profile'
  )
  console.log('✓ [TEST 2] PASS: Router configuration verified')
}

// [TEST 3] AppHeader.vue Dropdown Component Structure
{
  console.log('[TEST 3] Verify User Dropdown in AppHeader.vue')
  const headerPath = path.join(projectRoot, 'src/components/AppHeader.vue')
  const headerContent = fs.readFileSync(headerPath, 'utf8')

  // Dropdown container and trigger accessibility
  assert.ok(headerContent.includes('user-dropdown-container'), 'Must have user-dropdown-container')
  assert.ok(headerContent.includes('user-dropdown-trigger'), 'Must have user-dropdown-trigger button')
  assert.ok(headerContent.includes('aria-haspopup="true"'), 'Trigger must have aria-haspopup="true"')
  assert.ok(headerContent.includes(':aria-expanded="isDropdownOpen"'), 'Trigger must have dynamic aria-expanded')
  assert.ok(headerContent.includes('dropdown-chevron'), 'Trigger must include dropdown chevron icon')

  // Hover and click interaction handlers
  assert.ok(headerContent.includes('@mouseenter="handleMouseEnter"'), 'Container must handle mouseenter')
  assert.ok(headerContent.includes('@mouseleave="handleMouseLeave"'), 'Container must handle mouseleave')
  assert.ok(headerContent.includes('@click="toggleDropdown"'), 'Trigger button must handle toggleDropdown')
  assert.ok(headerContent.includes('handleClickOutside'), 'Must handle click outside to close dropdown')
  assert.ok(headerContent.includes('Escape'), 'Must support Escape key to close dropdown')

  // Avatar fallback logic
  assert.ok(headerContent.includes('userAvatarSrc'), 'Must compute userAvatarSrc')
  assert.ok(headerContent.includes('avatarLoadError'), 'Must handle avatar image load error')
  assert.ok(headerContent.includes('userInitials'), 'Must have userInitials fallback')

  // Header of dropdown
  assert.ok(headerContent.includes('dropdown-header-banner'), 'Dropdown must have header banner')
  assert.ok(headerContent.includes('dropdown-avatar-circle'), 'Dropdown must have avatar circle')
  assert.ok(headerContent.includes('dropdown-user-name'), 'Dropdown must show user name')
  assert.ok(headerContent.includes('dropdown-role-pill'), 'Dropdown must show role badge')
  assert.ok(headerContent.includes('dropdown-user-email'), 'Dropdown must show user email')

  // Menu navigation items
  assert.ok(headerContent.includes('to="/profile"'), 'Dropdown must link to /profile')
  assert.ok(headerContent.includes('to="/profile#settings"'), 'Dropdown must link to /profile#settings')
  assert.ok(headerContent.includes('to="/profile#password"'), 'Dropdown must link to /profile#password')

  // Creator state integration in dropdown
  assert.ok(headerContent.includes('dropdown-creator-cta'), 'Must have Creator CTA for reader without application')
  assert.ok(headerContent.includes('dropdown-creator-pending'), 'Must handle pending Creator application')
  assert.ok(headerContent.includes('dropdown-creator-rejected'), 'Must handle rejected Creator application')
  assert.ok(headerContent.includes('to="/creator/comics"'), 'Must link to Creator Studio for creators')

  // Logout button
  assert.ok(headerContent.includes('dropdown-logout-btn'), 'Must have logout button in dropdown')

  console.log('✓ [TEST 3] PASS: AppHeader.vue Dropdown fully verified')
}

// [TEST 4] AccountProfilePage.vue Structure & Tabs
{
  console.log('[TEST 4] Verify AccountProfilePage.vue features')
  const pagePath = path.join(projectRoot, 'src/pages/AccountProfilePage.vue')
  assert.ok(fs.existsSync(pagePath), 'AccountProfilePage.vue must exist')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  // Tabs
  assert.ok(pageContent.includes("activeTab = ref<'profile' | 'password' | 'creator'>"), 'Must support 3 tabs')
  assert.ok(pageContent.includes('Thông tin cá nhân'), 'Must have Profile info tab')
  assert.ok(pageContent.includes('Đổi mật khẩu'), 'Must have Password change tab')
  assert.ok(pageContent.includes('Hồ sơ Tác giả'), 'Must have Creator status tab')

  // Avatar upload & validation
  assert.ok(pageContent.includes('triggerAvatarFileSelect'), 'Must have avatar file selector trigger')
  assert.ok(pageContent.includes('handleAvatarFileChange'), 'Must validate avatar file format and size')
  assert.ok(pageContent.includes('2 * 1024 * 1024'), 'Must validate 2MB max file size')
  assert.ok(pageContent.includes('handleSaveAvatar'), 'Must have handleSaveAvatar function')
  assert.ok(pageContent.includes('handleDeleteAvatar'), 'Must have handleDeleteAvatar function')

  // Form Profile Update
  assert.ok(pageContent.includes('handleUpdateProfile'), 'Must have handleUpdateProfile function')
  assert.ok(pageContent.includes('handleResendVerification'), 'Must allow resending verification email')

  // Password Update
  assert.ok(pageContent.includes('handleUpdatePassword'), 'Must have handleUpdatePassword function')
  assert.ok(pageContent.includes('showCurrentPassword'), 'Must support toggle current password visibility')
  assert.ok(pageContent.includes('showNewPassword'), 'Must support toggle new password visibility')
  assert.ok(pageContent.includes('showConfirmPassword'), 'Must support toggle confirm password visibility')

  // Creator Status Cards
  assert.ok(pageContent.includes('approved-card'), 'Must render approved creator status card')
  assert.ok(pageContent.includes('pending-card'), 'Must render pending creator status card')
  assert.ok(pageContent.includes('rejected-card'), 'Must render rejected creator status card')
  assert.ok(pageContent.includes('reader-cta-card'), 'Must render reader registration CTA card')

  console.log('✓ [TEST 4] PASS: AccountProfilePage.vue verified')
}

// [TEST 5] UserProfilePage.vue Integration
{
  console.log('[TEST 5] Verify UserProfilePage.vue integrates link to /profile')
  const userProfilePath = path.join(projectRoot, 'src/pages/UserProfilePage.vue')
  const content = fs.readFileSync(userProfilePath, 'utf8')

  assert.ok(
    content.includes('to="/profile"') && content.includes('btn-edit-profile'),
    'UserProfilePage must link to /profile when viewing own profile'
  )
  console.log('✓ [TEST 5] PASS: UserProfilePage.vue integration verified')
}

// [TEST 6] Realtime Notification hook updates user role
{
  console.log('[TEST 6] Verify useNotificationRealtime triggers fetchUser on creator approval')
  const hookPath = path.join(projectRoot, 'src/composables/useNotificationRealtime.ts')
  const content = fs.readFileSync(hookPath, 'utf8')

  assert.ok(
    content.includes("type === 'creator_application_approved'") &&
    content.includes('useAuthStore().fetchUser()'),
    'Hook must call fetchUser when creator_application_approved event arrives'
  )
  console.log('✓ [TEST 6] PASS: Realtime notification hook integration verified')
}

console.log('\n--- ALL ACCOUNT DROPDOWN UNIT TESTS PASSED (6/6) ---')
