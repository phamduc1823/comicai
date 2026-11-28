import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

test('CAI-USER-03: AppHeader account menu has Người dùng right below Hồ sơ cá nhân', () => {
  const headerPath = path.resolve('frontend/src/components/AppHeader.vue')
  const content = fs.readFileSync(headerPath, 'utf-8')

  // 1. Check order: Hồ sơ cá nhân -> Người dùng -> Creator -> Logout
  const profileIndex = content.indexOf('to="/profile"')
  const userConnectionsIndex = content.indexOf('to="/account/users"')
  const creatorIndex = content.indexOf('to="/become-creator"')
  const logoutIndex = content.indexOf('dropdown-logout-btn')

  assert.ok(profileIndex > -1, 'Must have Hồ sơ cá nhân link')
  assert.ok(userConnectionsIndex > -1, 'Must have Người dùng link (/account/users)')
  assert.ok(profileIndex < userConnectionsIndex, 'Hồ sơ cá nhân must come before Người dùng')
  assert.ok(userConnectionsIndex < creatorIndex, 'Người dùng must come before Creator items')
  assert.ok(creatorIndex < logoutIndex, 'Creator items must come before Logout')

  // Check title
  assert.ok(content.includes('Người dùng'), 'Must have label Người dùng')
  assert.ok(content.includes('Người theo dõi & Đang theo dõi'), 'Must describe followers & following')
})

test('CAI-USER-03: UserConnectionsPage has two tabs and user list interface', () => {
  const pagePath = path.resolve('frontend/src/pages/UserConnectionsPage.vue')
  assert.ok(fs.existsSync(pagePath), 'UserConnectionsPage.vue must exist')

  const content = fs.readFileSync(pagePath, 'utf-8')

  // Title
  assert.ok(content.includes('Người dùng'), 'Must have page title Người dùng')

  // Two tabs
  assert.ok(content.includes('Người theo dõi'), 'Must have Tab 1: Người theo dõi')
  assert.ok(content.includes('Đang theo dõi'), 'Must have Tab 2: Đang theo dõi')
  assert.ok(content.includes("'followers' | 'following'"), 'activeTab must support followers and following')

  // Following list features
  assert.ok(content.includes('CommunityService.getFollowingUsers'), 'Must fetch following users')
  assert.ok(content.includes('followingSearchQuery'), 'Must have search input for following')
  assert.ok(content.includes('handleUnfollowUser'), 'Must support unfollow action')
  assert.ok(content.includes('to="`/users/${user.id}`"'), 'Must link to user profile /users/:id')

  // Followers tab search and empty state
  assert.ok(content.includes('followersSearchQuery'), 'Must have search input for followers')
  assert.ok(content.includes('CommunityService.getFollowerUsers'), 'Must call getFollowerUsers')
  assert.ok(content.includes('Chưa có người theo dõi nào'), 'Must have empty state for followers')
  assert.ok(content.includes('Không tìm thấy người dùng phù hợp'), 'Must have search empty state for followers')
})

test('CAI-USER-03: Router configuration registers /account/users route', () => {
  const routerPath = path.resolve('frontend/src/router/index.ts')
  const content = fs.readFileSync(routerPath, 'utf-8')

  assert.ok(content.includes("path: '/account/users'"), 'Must register route /account/users')
  assert.ok(content.includes('UserConnectionsPage.vue'), 'Must point to UserConnectionsPage.vue')
  assert.ok(content.includes("requiresAuth: true"), 'Must require authentication')
})

test('CAI-USER-03: ComicsPage has Truyện nổi bật inside Sort popup under Lọc nhanh', () => {
  const catalogPath = path.resolve('frontend/src/pages/ComicsPage.vue')
  const content = fs.readFileSync(catalogPath, 'utf-8')

  // External checkbox group removed
  assert.ok(
    !content.includes('class="filter-group featured-filter-group"'),
    'External featured filter group must be removed'
  )

  // Inside sort dropdown
  assert.ok(content.includes('Sắp xếp theo'), 'Popup must have Sắp xếp theo group header')
  assert.ok(content.includes('Lọc nhanh'), 'Popup must have Lọc nhanh group header')
  assert.ok(content.includes('Truyện nổi bật'), 'Popup must contain Truyện nổi bật option')
  assert.ok(content.includes('toggleFeatured'), 'Truyện nổi bật must bind toggleFeatured')
  assert.ok(content.includes('isFeatured'), 'Truyện nổi bật must bind isFeatured')
})

test('CAI-USER-03: HomeSearchSection has Truyện nổi bật inside Sort popup under Lọc nhanh', () => {
  const searchPath = path.resolve('frontend/src/components/HomeSearchSection.vue')
  const content = fs.readFileSync(searchPath, 'utf-8')

  // External checkbox group removed
  assert.ok(
    !content.includes('class="filter-group featured-filter-group"'),
    'External featured filter group must be removed from HomeSearchSection'
  )

  // Inside sort dropdown
  assert.ok(content.includes('Sắp xếp theo'), 'Popup must have Sắp xếp theo group header')
  assert.ok(content.includes('Lọc nhanh'), 'Popup must have Lọc nhanh group header')
  assert.ok(content.includes('Truyện nổi bật'), 'Popup must contain Truyện nổi bật option')
  assert.ok(content.includes('toggleFeatured'), 'Truyện nổi bật must bind toggleFeatured')
  assert.ok(content.includes('isFeatured'), 'Truyện nổi bật must bind isFeatured')
})
