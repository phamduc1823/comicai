import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

test('CAI-USER-02: AppHeader account dropdown menu optimization', () => {
  const headerPath = path.resolve('frontend/src/components/AppHeader.vue')
  const content = fs.readFileSync(headerPath, 'utf-8')

  // 1. Hồ sơ cá nhân must be present
  assert.ok(content.includes('Hồ sơ cá nhân'), 'Must keep Hồ sơ cá nhân in dropdown menu')
  assert.ok(content.includes('to="/profile"'), 'Hồ sơ cá nhân must link to /profile')

  // 2. Cập nhật thông tin must NOT be a separate item in dropdown menu
  assert.ok(
    !content.includes('to="/profile#settings"'),
    'Must remove Cập nhật thông tin from dropdown menu'
  )

  // 3. Đổi mật khẩu must NOT be a separate item in dropdown menu
  assert.ok(
    !content.includes('to="/profile#password"'),
    'Must remove Đổi mật khẩu from dropdown menu'
  )

  // 4. Kênh sáng tác must be preserved
  assert.ok(content.includes('Kênh sáng tác (Studio)'), 'Must preserve Kênh sáng tác')
  assert.ok(content.includes('Đăng ký trở thành Creator'), 'Must preserve Become Creator CTA')

  // 5. Trang quản trị must be preserved
  assert.ok(content.includes('to="/admin/moderation/comics"'), 'Must preserve Trang quản trị')

  // 6. Đăng xuất must be preserved at bottom
  assert.ok(content.includes('handleLogout'), 'Must preserve Đăng xuất action')
  assert.ok(content.includes('Đăng xuất'), 'Must preserve Đăng xuất label')
})

test('CAI-USER-02: AccountProfilePage has following management tab and local search', () => {
  const profilePath = path.resolve('frontend/src/pages/AccountProfilePage.vue')
  const content = fs.readFileSync(profilePath, 'utf-8')

  // 1. Tab definition includes 'following'
  assert.ok(
    content.includes("'profile' | 'following' | 'password' | 'creator'"),
    'activeTab must support following'
  )

  // 2. Hash watcher includes #following
  assert.ok(
    content.includes("hash === '#following'"),
    'Must support #following hash routing'
  )

  // 3. Tab button exists in template
  assert.ok(
    content.includes("activeTab === 'following'"),
    'Must have button for following tab'
  )
  assert.ok(
    content.includes('Đang theo dõi'),
    'Must have Đang theo dõi tab label'
  )

  // 4. Following list search and actions
  assert.ok(
    content.includes('followingSearchQuery'),
    'Must have search input model for following list'
  )
  assert.ok(
    content.includes('filteredFollowingList'),
    'Must have computed filteredFollowingList'
  )
  assert.ok(
    content.includes('handleProfileUnfollow'),
    'Must have handleProfileUnfollow function'
  )

  // 5. Navigation to profile
  assert.ok(
    content.includes('to="`/users/${user.id}`"'),
    'Must link each followed user to their public profile /users/:id'
  )

  // 6. Preserves existing password and update profile functionality
  assert.ok(
    content.includes('handleUpdateProfile'),
    'Must preserve handleUpdateProfile functionality'
  )
  assert.ok(
    content.includes('handleUpdatePassword'),
    'Must preserve handleUpdatePassword functionality'
  )
})

test('CAI-USER-02: CommunityConnectionsSidebar has user search box and toggle follow', () => {
  const sidebarPath = path.resolve('frontend/src/components/community/CommunityConnectionsSidebar.vue')
  const content = fs.readFileSync(sidebarPath, 'utf-8')

  // 1. Search box exists in Suggested section
  assert.ok(
    content.includes('sidebar-search-box'),
    'Must have sidebar search box container'
  )
  assert.ok(
    content.includes('userSearchQuery'),
    'Must bind search query'
  )
  assert.ok(
    content.includes('handleUserSearchInput'),
    'Must have user search input handler'
  )
  assert.ok(
    content.includes('clearUserSearch'),
    'Must have clear search button/handler'
  )

  // 2. Search results toggle follow
  assert.ok(
    content.includes('handleToggleFollowSearchResult'),
    'Must support toggling follow on search results'
  )

  // 3. Preserves sections: Message requests, Suggested, Following
  assert.ok(
    content.includes('following-users-heading'),
    'Must preserve Đang theo dõi section'
  )
  assert.ok(
    content.includes('suggested-users-heading'),
    'Must preserve Gợi ý theo dõi section'
  )
})

test('CAI-USER-02: CommunityService has searchUsers method and routes configured', () => {
  const servicePath = path.resolve('frontend/src/services/communityService.ts')
  const serviceContent = fs.readFileSync(servicePath, 'utf-8')
  assert.ok(
    serviceContent.includes('searchUsers(query: string)'),
    'CommunityService must have searchUsers method'
  )

  const routesPath = path.resolve('backend/routes/api.php')
  const routesContent = fs.readFileSync(routesPath, 'utf-8')
  assert.ok(
    routesContent.includes('/community/users/search'),
    'API routes must register /community/users/search'
  )
})
