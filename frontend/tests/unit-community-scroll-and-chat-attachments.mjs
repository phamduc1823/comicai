import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const FRONTEND_DIR = path.resolve(import.meta.dirname, '..')
const ROOT_DIR = path.resolve(FRONTEND_DIR, '..')

test('CAI-COMMUNITY-03: Pinia store communityFeed.ts implements state caching and clearing', () => {
  const storePath = path.join(FRONTEND_DIR, 'src/stores/communityFeed.ts')
  assert.ok(fs.existsSync(storePath), 'communityFeed.ts must exist')
  const content = fs.readFileSync(storePath, 'utf8')

  assert.ok(content.includes('defineStore(\'communityFeed\''), 'Must define communityFeed store')
  assert.ok(content.includes('saveFeedState'), 'Must have saveFeedState action')
  assert.ok(content.includes('clearFeedState'), 'Must have clearFeedState action')
  assert.ok(content.includes('setLastActivePostId'), 'Must have setLastActivePostId action')
  assert.ok(content.includes('hasSavedState'), 'Must have hasSavedState flag')
  assert.ok(content.includes('savedScrollY'), 'Must have savedScrollY ref')
})

test('CAI-COMMUNITY-03: CommunityPage and PostCard preserve scroll position and anchor targeting', () => {
  const communityPagePath = path.join(FRONTEND_DIR, 'src/views/CommunityPage.vue')
  const postCardPath = path.join(FRONTEND_DIR, 'src/components/community/PostCard.vue')
  const postDetailPath = path.join(FRONTEND_DIR, 'src/views/PostDetailPage.vue')
  const routerPath = path.join(FRONTEND_DIR, 'src/router/index.ts')

  const commContent = fs.readFileSync(communityPagePath, 'utf8')
  assert.ok(commContent.includes('useCommunityFeedStore'), 'CommunityPage must import useCommunityFeedStore')
  assert.ok(commContent.includes('onBeforeRouteLeave'), 'CommunityPage must hook onBeforeRouteLeave')
  assert.ok(commContent.includes('hasSavedState'), 'CommunityPage must restore cached state when hasSavedState is true')
  assert.ok(commContent.includes('scrollIntoView') || commContent.includes('window.scrollTo'), 'Must restore scroll position on mount')

  const postCardContent = fs.readFileSync(postCardPath, 'utf8')
  assert.ok(postCardContent.includes(':id="\'post-\' + post.id"'), 'PostCard must render element ID anchor')
  assert.ok(postCardContent.includes('handleDetailNavigation'), 'PostCard must track active post navigation')

  const postDetailContent = fs.readFileSync(postDetailPath, 'utf8')
  assert.ok(postDetailContent.includes('handleGoBack'), 'PostDetailPage must implement handleGoBack')
  assert.ok(postDetailContent.includes('btn-back'), 'PostDetailPage must have btn-back')

  const routerContent = fs.readFileSync(routerPath, 'utf8')
  assert.ok(routerContent.includes('to.path === \'/community\''), 'Router must guard /community scroll')
  assert.ok(routerContent.includes('from.path.startsWith(\'/posts/\')'), 'Router must check /posts/ origin')
})

test('CAI-MESSAGING-01: MessageComposer supports image and comic attachments with limits', () => {
  const composerPath = path.join(FRONTEND_DIR, 'src/components/chat/MessageComposer.vue')
  const composerContent = fs.readFileSync(composerPath, 'utf8')

  assert.ok(composerContent.includes('AttachComicModal'), 'Must import AttachComicModal')
  assert.ok(composerContent.includes('selectedImages'), 'Must track selected image files')
  assert.ok(composerContent.includes('selectedComic'), 'Must track selected comic')
  assert.ok(composerContent.includes('5 * 1024 * 1024'), 'Must validate max 5MB image size')
  assert.ok(composerContent.includes('5'), 'Must enforce max 5 images limit')
  assert.ok(composerContent.includes('attachments-strip'), 'Must render attachment preview strip')
})

test('CAI-MESSAGING-01: MessageList renders attachments and provides image lightbox modal', () => {
  const listPath = path.join(FRONTEND_DIR, 'src/components/chat/MessageList.vue')
  const listContent = fs.readFileSync(listPath, 'utf8')

  assert.ok(listContent.includes('msg-attachments-images'), 'Must render attached images grid')
  assert.ok(listContent.includes('msg-attached-comic'), 'Must render attached comic card')
  assert.ok(listContent.includes('msg-attached-post'), 'Must render attached post card')
  assert.ok(listContent.includes('openImageLightbox'), 'Must provide lightbox preview click handler')
  assert.ok(listContent.includes('lightbox-dialog'), 'Must render lightbox dialog')
})

test('CAI-SHARE-01: ShareRecipientModal and share buttons on cards and detail pages', () => {
  const modalPath = path.join(FRONTEND_DIR, 'src/components/chat/ShareRecipientModal.vue')
  assert.ok(fs.existsSync(modalPath), 'ShareRecipientModal.vue must exist')
  const modalContent = fs.readFileSync(modalPath, 'utf8')

  assert.ok(modalContent.includes('getShareRecipients'), 'Must fetch accepted recipients')
  assert.ok(modalContent.includes('shareToRecipients'), 'Must call share endpoint')
  assert.ok(modalContent.includes('toggleRecipient'), 'Must support multi-selection toggle')
  assert.ok(modalContent.includes('recipient-chip'), 'Must show selected chips')

  const comicCardPath = path.join(FRONTEND_DIR, 'src/components/ComicCard.vue')
  const comicCardContent = fs.readFileSync(comicCardPath, 'utf8')
  assert.ok(comicCardContent.includes('btn-card-share'), 'ComicCard must have share button')
  assert.ok(comicCardContent.includes('@click.stop.prevent'), 'ComicCard share must stop propagation')

  const detailPath = path.join(FRONTEND_DIR, 'src/pages/ComicDetailPage.vue')
  const detailContent = fs.readFileSync(detailPath, 'utf8')
  assert.ok(detailContent.includes('btn-share'), 'ComicDetailPage must have share button')
  assert.ok(detailContent.includes('ShareRecipientModal'), 'ComicDetailPage must mount ShareRecipientModal')

  const postCardPath = path.join(FRONTEND_DIR, 'src/components/community/PostCard.vue')
  const postCardContent = fs.readFileSync(postCardPath, 'utf8')
  assert.ok(postCardContent.includes('btn-share'), 'PostCard must have share button')
})

test('UI Tab Contrast: ConversationSidebar has high contrast inactive text and blue active text', () => {
  const sidebarPath = path.join(FRONTEND_DIR, 'src/components/chat/ConversationSidebar.vue')
  const sidebarContent = fs.readFileSync(sidebarPath, 'utf8')

  assert.ok(sidebarContent.includes('#e2e8f0') || sidebarContent.includes('#f1f5f9'), 'Sidebar tabs container must have light grey background')
  assert.ok(sidebarContent.includes('#1e293b') || sidebarContent.includes('#0f172a'), 'Inactive tab must have dark slate/charcoal text for high contrast')
  assert.ok(sidebarContent.includes('#38bdf8'), 'Active tab must have blue text')
})
