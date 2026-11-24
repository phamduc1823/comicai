/**
 * Comprehensive Unit Tests for CAI-COMM-01:
 * Mở quyền đăng bài Community cho Reader và hoàn thiện trải nghiệm Cộng đồng
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-COMM-01: Community Reader Posting & Feed Experience Suite', () => {
  it('1. CommunityPage.vue opens post creation permission to all authenticated users (including Reader)', () => {
    const communityFile = fs.readFileSync(path.join(projectRoot, 'src/views/CommunityPage.vue'), 'utf8')

    // canCreatePost must only require isAuthenticated
    assert.match(
      communityFile,
      /canCreatePost\s*=\s*computed\(\(\)\s*=>\s*\{\s*return\s+authStore\.isAuthenticated\s*\}\)/,
      'canCreatePost must permit all authenticated users without role restrictions'
    )

    // Must NOT restrict to creator or admin
    assert.doesNotMatch(
      communityFile,
      /canCreatePost[\s\S]*authStore\.isCreator\s*\|\|\s*authStore\.isAdmin/,
      'canCreatePost must not restrict to creator or admin only'
    )
  })

  it('2. CommunityPage.vue handles guest click on "Đăng bài viết" by redirecting to login with redirect path', () => {
    const communityFile = fs.readFileSync(path.join(projectRoot, 'src/views/CommunityPage.vue'), 'utf8')

    // Must have handleCreatePostClick handler checking authentication
    assert.match(
      communityFile,
      /function\s+handleCreatePostClick\(\)\s*\{[\s\S]*if\s*\(!authStore\.isAuthenticated\)[\s\S]*router\.push\(\{[\s\S]*path:\s*['"]\/login['"][\s\S]*query:\s*\{\s*redirect:\s*route\.fullPath\s*\}[\s\S]*return[\s\S]*openCreateModal\(\)/,
      'handleCreatePostClick must redirect unauthenticated guests to login'
    )

    // Button in header calls handleCreatePostClick
    assert.match(
      communityFile,
      /<button[^>]*class="btn-create-post"[^>]*@click="handleCreatePostClick"/,
      'Header create button must call handleCreatePostClick'
    )
  })

  it('3. CommunityPage.vue empty state provides clear filter reset and first post CTA for all users', () => {
    const communityFile = fs.readFileSync(path.join(projectRoot, 'src/views/CommunityPage.vue'), 'utf8')

    // Empty state container
    assert.match(communityFile, /class="empty-state"/, 'Must have empty-state container')

    // Explanatory messages for filtered vs unfiltered feed
    assert.match(
      communityFile,
      /hasActiveFilter\s*\?\s*['"]Không tìm thấy bài viết nào gắn kèm bộ truyện này\.['"]\s*:\s*['"]Chưa có bài viết nào được đăng tải trên bảng tin cộng đồng\.['"]/,
      'Empty state must distinguish between comic-filtered and empty feed'
    )

    // Clear filter button in empty state
    assert.match(
      communityFile,
      /<button[^>]*v-if="hasActiveFilter"[^>]*@click="clearFilter"[^>]*>\s*Xem tất cả bài viết\s*<\/button>/,
      'Empty state must provide "Xem tất cả bài viết" button when filtering'
    )

    // CTA "Tạo bài viết đầu tiên" available to users via handleCreatePostClick
    assert.match(
      communityFile,
      /<button[^>]*class="btn-action-primary"[^>]*@click="handleCreatePostClick"[^>]*>\s*Tạo bài viết đầu tiên\s*<\/button>/,
      'Empty state must provide "Tạo bài viết đầu tiên" CTA'
    )
  })

  it('4. PostFormModal.vue handles content, optional comic association and image upload with client validations', () => {
    const modalFile = fs.readFileSync(path.join(projectRoot, 'src/components/community/PostFormModal.vue'), 'utf8')

    // Validation constants
    assert.match(modalFile, /MAX_CONTENT_LENGTH\s*=\s*5000/, 'Content limit must be 5000 characters')
    assert.match(modalFile, /MAX_IMAGE_SIZE_BYTES\s*=\s*2\s*\*\s*1024\s*\*\s*1024/, 'Max image size must be 2MB')
    assert.match(modalFile, /ALLOWED_MIME_TYPES\s*=\s*\['image\/jpeg',\s*'image\/png',\s*'image\/jpg',\s*'image\/webp'\]/, 'Allowed image mime types')

    // Calls CommunityService
    assert.match(modalFile, /CommunityService\.createPost/, 'Must call createPost for new posts')
    assert.match(modalFile, /CommunityService\.updatePost/, 'Must call updatePost when editing')
  })

  it('5. PostCard.vue respects author-only editing and role badges', () => {
    const cardFile = fs.readFileSync(path.join(projectRoot, 'src/components/community/PostCard.vue'), 'utf8')

    // isAuthor check: author can edit
    assert.match(cardFile, /isAuthor\s*=\s*computed\(\(\)\s*=>\s*\{[\s\S]*authStore\.user\?\.id\s*===\s*props\.post\.user\.id/, 'isAuthor must verify matching user ID')

    // canDelete: author or admin
    assert.match(cardFile, /canDelete\s*=\s*computed\(\(\)\s*=>\s*\{[\s\S]*isAuthor\.value\s*\|\|\s*authStore\.isAdmin/, 'canDelete must allow author or admin only')

    // Edit button strictly for author
    assert.match(cardFile, /v-if="isAuthor"[^>]*class="btn-action btn-edit"/, 'Edit button must only be shown to author')

    // Delete button for author or admin
    assert.match(cardFile, /v-if="canDelete"[^>]*class="btn-action btn-delete"/, 'Delete button must be shown to author or admin')

    // Report button for non-authors
    assert.match(cardFile, /v-if="!isAuthor"[^>]*class="btn-action btn-report"/, 'Report button must be shown to non-authors')

    // Like button
    assert.match(cardFile, /class="btn-stat btn-like"/, 'Must have like button')

    // Detail/comments link
    assert.match(cardFile, /:to="`\/posts\/\$\{post\.id\}`"/, 'Must link to post detail')
  })

  it('6. Backend PostPolicy permits active readers, creators and admins to create, and restricts update/delete', () => {
    const policyFile = fs.readFileSync(path.join(projectRoot, '../backend/app/Policies/PostPolicy.php'), 'utf8')

    // create: active and in roles
    assert.match(policyFile, /public\s+function\s+create\(User\s+\$user\):\s*bool\s*\{[\s\S]*\$user->is_active[\s\S]*\['reader',\s*'creator',\s*'admin'\]/, 'Policy must allow active readers, creators, and admins to create')

    // update: active and author only
    assert.match(policyFile, /public\s+function\s+update\(User\s+\$user,\s*Post\s+\$post\):\s*bool\s*\{[\s\S]*\$user->is_active[\s\S]*\(int\)\s*\$user->id\s*===\s*\(int\)\s*\$post->user_id/, 'Policy must restrict update to active author')

    // delete: active and author or admin
    assert.match(policyFile, /public\s+function\s+delete\(User\s+\$user,\s*Post\s+\$post\):\s*bool\s*\{[\s\S]*\$user->is_active[\s\S]*\(int\)\s*\$user->id\s*===\s*\(int\)\s*\$post->user_id\s*\|\|\s*\$user->role\s*===\s*'admin'/, 'Policy must allow active author or admin to delete')
  })
})
