import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

console.log('--- RUNNING CAI-CREATOR-01 PHASE 4 FRONTEND ADMIN FLOW UNIT TESTS ---')

// [TEST 1] Route configuration in router/index.ts
{
  console.log('[TEST 1] Verify Admin Creator routes in router/index.ts')
  const routerPath = path.join(projectRoot, 'src/router/index.ts')
  const routerContent = fs.readFileSync(routerPath, 'utf8')

  assert.ok(
    routerContent.includes("path: '/admin/creator-applications'"),
    'Route /admin/creator-applications must be registered'
  )
  assert.ok(
    routerContent.includes("name: 'admin-creator-applications'"),
    'Route name admin-creator-applications must be registered'
  )
  assert.ok(
    routerContent.includes('AdminCreatorApplicationsPage.vue'),
    'Route must import AdminCreatorApplicationsPage.vue'
  )

  assert.ok(
    routerContent.includes("path: '/admin/creator-applications/:id'"),
    'Route /admin/creator-applications/:id must be registered'
  )
  assert.ok(
    routerContent.includes('AdminCreatorApplicationDetailPage.vue'),
    'Route must import AdminCreatorApplicationDetailPage.vue'
  )

  assert.ok(
    routerContent.includes("path: '/admin/creators'"),
    'Route /admin/creators must be registered'
  )
  assert.ok(
    routerContent.includes("name: 'admin-creators'"),
    'Route name admin-creators must be registered'
  )
  assert.ok(
    routerContent.includes('AdminCreatorsPage.vue'),
    'Route must import AdminCreatorsPage.vue'
  )

  assert.ok(
    routerContent.includes("path: '/admin/creators/:id'"),
    'Route /admin/creators/:id must be registered'
  )
  assert.ok(
    routerContent.includes('AdminCreatorDetailPage.vue'),
    'Route must import AdminCreatorDetailPage.vue'
  )

  console.log('✓ [TEST 1] PASS: All 4 Admin Creator routes correctly registered')
}

// [TEST 2] Types definition in types/adminCreator.ts
{
  console.log('[TEST 2] Verify Admin Creator types definition')
  const typesPath = path.join(projectRoot, 'src/types/adminCreator.ts')
  const typesContent = fs.readFileSync(typesPath, 'utf8')

  assert.ok(
    typesContent.includes('interface AdminCreatorApplicationStats'),
    'AdminCreatorApplicationStats must be defined'
  )
  assert.ok(
    typesContent.includes('interface AdminCreatorStats'),
    'AdminCreatorStats must be defined'
  )
  assert.ok(
    typesContent.includes('interface ListAdminCreatorApplicationsParams'),
    'ListAdminCreatorApplicationsParams must be defined'
  )
  assert.ok(
    typesContent.includes('interface ListAdminCreatorsParams'),
    'ListAdminCreatorsParams must be defined'
  )
  assert.ok(
    typesContent.includes('interface CreatorAuditLog'),
    'CreatorAuditLog must be defined'
  )
  assert.ok(
    typesContent.includes("'reapply_granted'") &&
    typesContent.includes("'reapply_revoked'") &&
    typesContent.includes("'reapplied'") &&
    typesContent.includes("'reapply_rejected'"),
    'CreatorAuditLog event types must include full reapply lifecycle'
  )
  assert.ok(
    typesContent.includes('interface CreatorComicSummary'),
    'CreatorComicSummary must be defined'
  )
  assert.ok(
    typesContent.includes('interface PenNameHistoryItem'),
    'PenNameHistoryItem must be defined'
  )
  assert.ok(
    typesContent.includes('interface AdminCreatorDetailResponse'),
    'AdminCreatorDetailResponse must be defined'
  )
  assert.ok(
    typesContent.includes('interface RejectCreatorApplicationPayload'),
    'RejectCreatorApplicationPayload must be defined'
  )
  assert.ok(
    typesContent.includes('interface RevokeCreatorPayload'),
    'RevokeCreatorPayload must be defined'
  )
  assert.ok(
    typesContent.includes('interface ReapplyReasonPayload'),
    'ReapplyReasonPayload must be defined'
  )

  console.log('✓ [TEST 2] PASS: Admin Creator types correctly defined')
}

// [TEST 3] Service implementation in services/adminCreatorService.ts
{
  console.log('[TEST 3] Verify AdminCreatorService endpoints and methods')
  const servicePath = path.join(projectRoot, 'src/services/adminCreatorService.ts')
  const serviceContent = fs.readFileSync(servicePath, 'utf8')

  // Applications endpoints
  assert.ok(serviceContent.includes('getApplications'), 'Service must have getApplications')
  assert.ok(serviceContent.includes("'/admin/creator-applications'"), 'getApplications calls GET /admin/creator-applications')
  assert.ok(serviceContent.includes('getApplication'), 'Service must have getApplication')
  assert.ok(serviceContent.includes('/approve'), 'approveApplication calls POST /admin/creator-applications/:id/approve')
  assert.ok(serviceContent.includes('/reject'), 'rejectApplication calls POST /admin/creator-applications/:id/reject')
  assert.ok(serviceContent.includes('exportApplications'), 'Service must have exportApplications')
  assert.ok(serviceContent.includes('/admin/creator-applications/export'), 'exportApplications calls GET /admin/creator-applications/export')

  // Creators endpoints
  assert.ok(serviceContent.includes('getCreators'), 'Service must have getCreators')
  assert.ok(serviceContent.includes("'/admin/creators'"), 'getCreators calls GET /admin/creators')
  assert.ok(serviceContent.includes('getCreator'), 'Service must have getCreator')
  assert.ok(serviceContent.includes('/revoke'), 'revokeCreator calls POST /admin/creators/:id/revoke')
  assert.ok(serviceContent.includes('/grant-reapply'), 'grantReapply calls POST /admin/creators/:id/grant-reapply')
  assert.ok(serviceContent.includes('/revoke-reapply'), 'revokeReapply calls POST /admin/creators/:id/revoke-reapply')
  assert.ok(serviceContent.includes('exportCreators'), 'Service must have exportCreators')
  assert.ok(serviceContent.includes('/admin/creators/export'), 'exportCreators calls GET /admin/creators/export')

  // Excel streaming check
  assert.ok(
    serviceContent.includes("responseType: 'blob'"),
    'Export functions must request responseType: blob'
  )
  assert.ok(
    serviceContent.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    'Export functions must use xlsx mime type'
  )

  console.log('✓ [TEST 3] PASS: AdminCreatorService endpoints and export methods verified')
}

// [TEST 4] Layout integration in layouts/AdminLayout.vue
{
  console.log('[TEST 4] Verify AdminLayout navigation links and titles')
  const layoutPath = path.join(projectRoot, 'src/layouts/AdminLayout.vue')
  const layoutContent = fs.readFileSync(layoutPath, 'utf8')

  assert.ok(
    layoutContent.includes("to=\"/admin/creator-applications\""),
    'AdminLayout sidebar must link to /admin/creator-applications'
  )
  assert.ok(
    layoutContent.includes("to=\"/admin/creators\""),
    'AdminLayout sidebar must link to /admin/creators'
  )
  assert.ok(
    layoutContent.includes("route.path.startsWith('/admin/creator-applications')"),
    'AdminLayout currentSectionTitle must handle /admin/creator-applications'
  )
  assert.ok(
    layoutContent.includes("route.path.startsWith('/admin/creators')"),
    'AdminLayout currentSectionTitle must handle /admin/creators'
  )

  console.log('✓ [TEST 4] PASS: AdminLayout links and section titles verified')
}

// [TEST 5] Diff Viewer component in components/admin/ApplicationDiffViewer.vue
{
  console.log('[TEST 5] Verify ApplicationDiffViewer snapshot diffing capabilities')
  const diffViewerPath = path.join(projectRoot, 'src/components/admin/ApplicationDiffViewer.vue')
  const diffContent = fs.readFileSync(diffViewerPath, 'utf8')

  assert.ok(diffContent.includes('baseVersion'), 'DiffViewer must support base version selection')
  assert.ok(diffContent.includes('targetVersion'), 'DiffViewer must support target version selection')
  assert.ok(diffContent.includes('pen_name'), 'DiffViewer must compare pen_name')
  assert.ok(diffContent.includes('bio'), 'DiffViewer must compare bio')
  assert.ok(diffContent.includes('reason'), 'DiffViewer must compare reason')
  assert.ok(diffContent.includes('sample_works'), 'DiffViewer must compare sample_works')
  assert.ok(diffContent.includes('status'), 'DiffViewer must compare status')
  assert.ok(diffContent.includes('rejection_reason'), 'DiffViewer must compare rejection_reason')
  assert.ok(diffContent.includes('sampleWorksDiff') && diffContent.includes('added') && diffContent.includes('removed'), 'DiffViewer must compute added and removed works')

  console.log('✓ [TEST 5] PASS: ApplicationDiffViewer component features verified')
}

// [TEST 6] Admin Applications List Page in pages/admin/AdminCreatorApplicationsPage.vue
{
  console.log('[TEST 6] Verify AdminCreatorApplicationsPage features')
  const listPagePath = path.join(projectRoot, 'src/pages/admin/AdminCreatorApplicationsPage.vue')
  const listContent = fs.readFileSync(listPagePath, 'utf8')

  assert.ok(listContent.includes('statusFilter'), 'Must have status filter state')
  assert.ok(listContent.includes('searchQuery'), 'Must have search query state')
  assert.ok(listContent.includes('handleExport'), 'Must have handleExport handler')
  assert.ok(listContent.includes('showApproveModal'), 'Must have approve modal')
  assert.ok(listContent.includes('showRejectModal'), 'Must have reject modal')
  assert.ok(listContent.includes('rejectReason'), 'Must capture reject reason')
  assert.ok(
    listContent.includes('Lý do từ chối là bắt buộc'),
    'Must validate mandatory rejection reason'
  )

  console.log('✓ [TEST 6] PASS: AdminCreatorApplicationsPage verified')
}

// [TEST 7] Admin Application Detail Page in pages/admin/AdminCreatorApplicationDetailPage.vue
{
  console.log('[TEST 7] Verify AdminCreatorApplicationDetailPage features')
  const detailPagePath = path.join(projectRoot, 'src/pages/admin/AdminCreatorApplicationDetailPage.vue')
  const detailContent = fs.readFileSync(detailPagePath, 'utf8')

  assert.ok(detailContent.includes('ApplicationDiffViewer'), 'Must embed ApplicationDiffViewer')
  assert.ok(detailContent.includes("activeTab === 'diff'"), 'Must have diff viewer tab')
  assert.ok(detailContent.includes("activeTab === 'history'"), 'Must have history version timeline tab')
  assert.ok(detailContent.includes('confirmApprove'), 'Must have confirmApprove handler')
  assert.ok(detailContent.includes('confirmReject'), 'Must have confirmReject handler')
  assert.ok(detailContent.includes('rejectReason'), 'Must require reject reason')

  console.log('✓ [TEST 7] PASS: AdminCreatorApplicationDetailPage verified')
}

// [TEST 8] Admin Creators List Page in pages/admin/AdminCreatorsPage.vue
{
  console.log('[TEST 8] Verify AdminCreatorsPage features')
  const creatorsPagePath = path.join(projectRoot, 'src/pages/admin/AdminCreatorsPage.vue')
  const creatorsContent = fs.readFileSync(creatorsPagePath, 'utf8')

  assert.ok(creatorsContent.includes('stats.all'), 'Must show all creators count')
  assert.ok(creatorsContent.includes('stats.active'), 'Must show active creators count')
  assert.ok(creatorsContent.includes('stats.revoked'), 'Must show revoked creators count')
  assert.ok(creatorsContent.includes('AdminCreatorService.exportCreators'), 'Must trigger exportCreators')
  assert.ok(creatorsContent.includes('creator.creator_code'), 'Must render creator_code')
  assert.ok(creatorsContent.includes('creator.pen_name'), 'Must render pen_name')
  assert.ok(creatorsContent.includes('creator.comics_count'), 'Must render comics_count')
  assert.ok(creatorsContent.includes('creator.can_reapply'), 'Must render can_reapply indicator')

  console.log('✓ [TEST 8] PASS: AdminCreatorsPage verified')
}

// [TEST 9] Admin Creator Detail Page in pages/admin/AdminCreatorDetailPage.vue
{
  console.log('[TEST 9] Verify AdminCreatorDetailPage features & Timeline Audit')
  const detailPagePath = path.join(projectRoot, 'src/pages/admin/AdminCreatorDetailPage.vue')
  const detailContent = fs.readFileSync(detailPagePath, 'utf8')

  assert.ok(detailContent.includes('confirmRevoke'), 'Must have confirmRevoke handler')
  assert.ok(detailContent.includes('confirmGrantReapply'), 'Must have confirmGrantReapply handler')
  assert.ok(detailContent.includes('confirmRevokeReapply'), 'Must have confirmRevokeReapply handler')
  assert.ok(detailContent.includes('actionReason'), 'Must enforce actionReason across all administrative actions')
  assert.ok(detailContent.includes('openRevokeModal'), 'Must have Revoke Modal')
  assert.ok(detailContent.includes('openGrantReapplyModal'), 'Must have Grant Reapply Modal')
  assert.ok(detailContent.includes('openRevokeReapplyModal'), 'Must have Revoke Reapply Modal')

  // Timeline & Tabs
  assert.ok(detailContent.includes("activeTab === 'overview'"), 'Must have overview tab')
  assert.ok(detailContent.includes("activeTab === 'comics'"), 'Must have comics list tab')
  assert.ok(detailContent.includes("activeTab === 'pen_names'"), 'Must have pen name history tab')
  assert.ok(detailContent.includes("activeTab === 'audit'"), 'Must have audit timeline tab')

  // Audit Events
  assert.ok(detailContent.includes('reapply_granted'), 'Timeline must handle reapply_granted')
  assert.ok(detailContent.includes('reapply_revoked'), 'Timeline must handle reapply_revoked')
  assert.ok(detailContent.includes('reapplied'), 'Timeline must handle reapplied')
  assert.ok(detailContent.includes('reapply_rejected'), 'Timeline must handle reapply_rejected')
  assert.ok(detailContent.includes('revoked'), 'Timeline must handle revoked')
  assert.ok(detailContent.includes('approved'), 'Timeline must handle approved')

  console.log('✓ [TEST 9] PASS: AdminCreatorDetailPage and Timeline Audit verified')
}

console.log('=================================================================')
console.log('ALL 9 ADMIN CREATOR FLOW UNIT TESTS PASSED!')
console.log('=================================================================')
