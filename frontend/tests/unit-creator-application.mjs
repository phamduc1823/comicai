import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

console.log('--- RUNNING CAI-CREATOR-01 PHASE 3 FRONTEND READER FLOW UNIT TESTS ---')

// [TEST 1] Route configuration in router/index.ts
{
  console.log('[TEST 1] Verify /become-creator route and redirect in router/index.ts')
  const routerPath = path.join(projectRoot, 'src/router/index.ts')
  const routerContent = fs.readFileSync(routerPath, 'utf8')

  assert.ok(
    routerContent.includes("path: '/become-creator'"),
    'Route /become-creator must be registered in router'
  )
  assert.ok(
    routerContent.includes("name: 'creator-application'"),
    'Route name creator-application must be registered'
  )
  assert.ok(
    routerContent.includes('CreatorApplicationPage.vue'),
    'Route /become-creator must dynamically import CreatorApplicationPage.vue'
  )
  assert.ok(
    routerContent.includes("path: '/creator-application'") && routerContent.includes("redirect: '/become-creator'"),
    'Route /creator-application must redirect to /become-creator'
  )
  console.log('✓ [TEST 1] PASS: /become-creator routes correctly registered')
}

// [TEST 2] Types definition in types/creatorApplication.ts
{
  console.log('[TEST 2] Verify Creator Application types')
  const typesPath = path.join(projectRoot, 'src/types/creatorApplication.ts')
  const typesContent = fs.readFileSync(typesPath, 'utf8')

  assert.ok(
    typesContent.includes("'pending'") &&
    typesContent.includes("'approved'") &&
    typesContent.includes("'rejected'") &&
    typesContent.includes("'canceled'"),
    'CreatorApplicationStatus must include pending, approved, rejected, canceled'
  )

  assert.ok(typesContent.includes('interface CreatorApplicationHistory'), 'CreatorApplicationHistory interface must exist')
  assert.ok(typesContent.includes('interface CreatorApplication'), 'CreatorApplication interface must exist')
  assert.ok(typesContent.includes('application_code: string'), 'CreatorApplication must have application_code')
  assert.ok(typesContent.includes('pen_name: string'), 'CreatorApplication must have pen_name')
  assert.ok(typesContent.includes('bio: string'), 'CreatorApplication must have bio')
  assert.ok(typesContent.includes('reason: string'), 'CreatorApplication must have reason')
  assert.ok(typesContent.includes('sample_works: string[] | null'), 'CreatorApplication must have sample_works')
  assert.ok(typesContent.includes('rejection_reason: string | null'), 'CreatorApplication must have rejection_reason')
  assert.ok(typesContent.includes('version: number'), 'CreatorApplication must have version')

  assert.ok(typesContent.includes('interface CreatorProfileInfo'), 'CreatorProfileInfo interface must exist')
  assert.ok(typesContent.includes('creator_code: string'), 'CreatorProfileInfo must have creator_code')
  assert.ok(typesContent.includes('can_reapply: boolean'), 'CreatorProfileInfo must have can_reapply')

  assert.ok(typesContent.includes('interface MyApplicationResponse'), 'MyApplicationResponse interface must exist')
  assert.ok(typesContent.includes('interface SubmitApplicationPayload'), 'SubmitApplicationPayload interface must exist')
  assert.ok(typesContent.includes('interface ApplicationActionResponse'), 'ApplicationActionResponse interface must exist')
  console.log('✓ [TEST 2] PASS: Creator Application types correctly defined')
}

// [TEST 3] Service implementation in services/creatorApplicationService.ts
{
  console.log('[TEST 3] Verify CreatorApplicationService endpoints and HTTP methods')
  const servicePath = path.join(projectRoot, 'src/services/creatorApplicationService.ts')
  const serviceContent = fs.readFileSync(servicePath, 'utf8')

  assert.ok(
    serviceContent.includes('getMyApplication'),
    'Service must have getMyApplication method'
  )
  assert.ok(
    serviceContent.includes("get<MyApplicationResponse>('/creator-applications/my')"),
    'getMyApplication must target GET /creator-applications/my'
  )

  assert.ok(
    serviceContent.includes('submitApplication'),
    'Service must have submitApplication method'
  )
  assert.ok(
    serviceContent.includes("post<ApplicationActionResponse>(\n      '/creator-applications'"),
    'submitApplication must target POST /creator-applications'
  )

  assert.ok(
    serviceContent.includes('cancelApplication'),
    'Service must have cancelApplication method'
  )
  assert.ok(
    serviceContent.includes('/cancel'),
    'cancelApplication must target POST /creator-applications/{id}/cancel'
  )

  assert.ok(
    serviceContent.includes('resubmitApplication'),
    'Service must have resubmitApplication method'
  )
  assert.ok(
    serviceContent.includes('/resubmit'),
    'resubmitApplication must target PUT /creator-applications/{id}/resubmit'
  )
  console.log('✓ [TEST 3] PASS: CreatorApplicationService methods correctly defined')
}

// [TEST 4] Eligibility & State Guards in CreatorApplicationPage.vue
{
  console.log('[TEST 4] Verify eligibility guards in CreatorApplicationPage.vue')
  const pagePath = path.join(projectRoot, 'src/pages/CreatorApplicationPage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  // Guest guard
  assert.ok(
    pageContent.includes('guard-guest') && pageContent.includes('Yêu cầu Đăng nhập'),
    'Must have guest login requirement guard'
  )
  assert.ok(
    pageContent.includes('/login?redirect=/become-creator'),
    'Guest guard must redirect to login with become-creator redirect'
  )

  // Unverified email guard
  assert.ok(
    pageContent.includes('guard-unverified') && pageContent.includes('Email Chưa Được Xác Minh'),
    'Must have unverified email guard warning'
  )

  // Already creator guard
  assert.ok(
    pageContent.includes('guard-creator') && pageContent.includes('Bạn Đã Là Tác Giả Chính Thức'),
    'Must have already creator banner with link to Studio'
  )

  // Revoked guard without reapply
  assert.ok(
    pageContent.includes('guard-revoked') && pageContent.includes('Quyền Creator Đã Bị Thu Hồi'),
    'Must have revoked creator warning'
  )

  // Revoked WITH reapply grant notice
  assert.ok(
    pageContent.includes('reapply-notice-banner') && pageContent.includes('Admin đã cấp phép cho bạn đăng ký lại Creator'),
    'Must have reapply granted notice banner'
  )
  console.log('✓ [TEST 4] PASS: Eligibility guards verified')
}

// [TEST 5] Application Tracking View & Status Specific Views
{
  console.log('[TEST 5] Verify Application tracking views and status badges')
  const pagePath = path.join(projectRoot, 'src/pages/CreatorApplicationPage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  // Status badges
  assert.ok(
    pageContent.includes('badge-pending') &&
    pageContent.includes('badge-approved') &&
    pageContent.includes('badge-rejected') &&
    pageContent.includes('badge-canceled'),
    'Must have styling classes for all 4 application statuses'
  )

  // Pending view & cancel action
  assert.ok(
    pageContent.includes('info-pending') && pageContent.includes('Hồ sơ đang chờ xét duyệt'),
    'Must display pending notice banner'
  )
  assert.ok(
    pageContent.includes('showCancelModal = true'),
    'Pending view must have cancel button triggering confirmation modal'
  )

  // Rejected view & resubmit action
  assert.ok(
    pageContent.includes('info-rejected') && pageContent.includes('Hồ sơ chưa được phê duyệt'),
    'Must display rejected notice banner'
  )
  assert.ok(
    pageContent.includes('application.rejection_reason'),
    'Rejected view must display rejection reason from Admin'
  )
  assert.ok(
    pageContent.includes('startEditing') && pageContent.includes('Chỉnh sửa & Gửi lại hồ sơ'),
    'Rejected view must provide resubmit edit button'
  )

  // Canceled view
  assert.ok(
    pageContent.includes('info-canceled') && pageContent.includes('Hồ sơ đã được hủy'),
    'Must display canceled notice banner'
  )

  // Approved view
  assert.ok(
    pageContent.includes('info-approved') && pageContent.includes('Đến Creator Studio'),
    'Approved view must have shortcut to Creator Studio'
  )

  // Histories timeline
  assert.ok(
    pageContent.includes('histories-timeline') && pageContent.includes('showHistories'),
    'Must have histories accordion timeline'
  )
  console.log('✓ [TEST 5] PASS: Application tracking and status views verified')
}

// [TEST 6] Cancel Application Confirmation Modal
{
  console.log('[TEST 6] Verify cancel application confirmation modal')
  const pagePath = path.join(projectRoot, 'src/pages/CreatorApplicationPage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  assert.ok(
    pageContent.includes('cancel-modal-title') && pageContent.includes('Xác nhận hủy hồ sơ'),
    'Modal must have confirmation title'
  )
  assert.ok(
    pageContent.includes('handleCancelApplication'),
    'Modal must call handleCancelApplication on confirmation'
  )
  assert.ok(
    pageContent.includes('showCancelModal = false'),
    'Modal must allow closing without canceling'
  )
  console.log('✓ [TEST 6] PASS: Cancel confirmation modal verified')
}

// [TEST 7] Form Fields, Validation & Character Counters
{
  console.log('[TEST 7] Verify form fields, validation, and character counters')
  const pagePath = path.join(projectRoot, 'src/pages/CreatorApplicationPage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  // Pen name
  assert.ok(pageContent.includes('id="pen_name"'), 'Must have pen_name input')
  assert.ok(pageContent.includes('/50'), 'Must have 50-character limit counter for pen_name')
  assert.ok(pageContent.includes('penNameRegex'), 'Must have Unicode NFC regex validation for pen_name')

  // Bio
  assert.ok(pageContent.includes('id="bio"'), 'Must have bio textarea')
  assert.ok(pageContent.includes('/1000'), 'Must have 1000-character limit counter for bio')

  // Reason
  assert.ok(pageContent.includes('id="reason"'), 'Must have reason textarea')

  // Sample Works
  assert.ok(pageContent.includes('sample_works'), 'Must have sample_works inputs')
  assert.ok(pageContent.includes('addSampleWorkLink'), 'Must support adding sample work links up to 3')
  assert.ok(pageContent.includes('removeSampleWorkLink'), 'Must support removing sample work links')

  // Error display
  assert.ok(pageContent.includes('formErrors.pen_name'), 'Must display pen_name error message')
  assert.ok(pageContent.includes('formErrors.bio'), 'Must display bio error message')
  assert.ok(pageContent.includes('formErrors.reason'), 'Must display reason error message')
  assert.ok(pageContent.includes('generalError'), 'Must display general API error banner')
  assert.ok(pageContent.includes('successMessage'), 'Must display success message banner')
  console.log('✓ [TEST 7] PASS: Form controls, validation, and counters verified')
}

// [TEST 8] Navigation and Profile Entry Points
{
  console.log('[TEST 8] Verify navigation entry points in AppHeader and UserProfilePage')
  const headerPath = path.join(projectRoot, 'src/components/AppHeader.vue')
  const headerContent = fs.readFileSync(headerPath, 'utf8')

  assert.ok(
    headerContent.includes("to=\"/become-creator\""),
    'AppHeader must link to /become-creator'
  )
  assert.ok(
    headerContent.includes('authStore.isReader'),
    'AppHeader must display Creator registration link for reader users'
  )

  const profilePath = path.join(projectRoot, 'src/pages/UserProfilePage.vue')
  const profileContent = fs.readFileSync(profilePath, 'utf8')

  assert.ok(
    profileContent.includes("to=\"/become-creator\""),
    'UserProfilePage must link to /become-creator for reader users'
  )
  assert.ok(
    profileContent.includes('btn-creator-register'),
    'UserProfilePage must have action button for creator registration'
  )
  console.log('✓ [TEST 8] PASS: Navigation entry points verified')
}

console.log('--- ALL CAI-CREATOR-01 PHASE 3 UNIT TESTS PASSED (8/8) ---')
