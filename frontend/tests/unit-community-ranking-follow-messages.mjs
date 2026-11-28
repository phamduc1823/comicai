/**
 * Unit Test Suite for CAI-COMMUNITY-01:
 * Community Ranking, Follow & Message Requests (ComicAI / BlueToon)
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-COMMUNITY-01: Community Ranking, Follow & Message Requests Suite', () => {
  // Read source files
  const communityTypesFile = fs.readFileSync(path.join(projectRoot, 'src/types/community.ts'), 'utf8')
  const chatTypesFile = fs.readFileSync(path.join(projectRoot, 'src/types/chat.ts'), 'utf8')
  const communityServiceFile = fs.readFileSync(path.join(projectRoot, 'src/services/communityService.ts'), 'utf8')
  const chatServiceFile = fs.readFileSync(path.join(projectRoot, 'src/services/chatService.ts'), 'utf8')
  const chatStoreFile = fs.readFileSync(path.join(projectRoot, 'src/stores/chat.ts'), 'utf8')
  const rankingsSidebarFile = fs.readFileSync(
    path.join(projectRoot, 'src/components/community/CommunityRankingsSidebar.vue'),
    'utf8'
  )
  const connectionsSidebarFile = fs.readFileSync(
    path.join(projectRoot, 'src/components/community/CommunityConnectionsSidebar.vue'),
    'utf8'
  )
  const communityPageFile = fs.readFileSync(path.join(projectRoot, 'src/views/CommunityPage.vue'), 'utf8')
  const conversationSidebarFile = fs.readFileSync(
    path.join(projectRoot, 'src/components/chat/ConversationSidebar.vue'),
    'utf8'
  )
  const chatPageFile = fs.readFileSync(path.join(projectRoot, 'src/pages/ChatPage.vue'), 'utf8')

  // ---------------------------------------------------------------------------
  // 1. Types & Services
  // ---------------------------------------------------------------------------
  it('1.1 Community types declare RankingPostItem, SuggestedUserItem, and FollowingUserItem', () => {
    assert.match(communityTypesFile, /export interface RankingPostItem/, 'Must declare RankingPostItem interface')
    assert.match(communityTypesFile, /rank:\s*number/, 'RankingPostItem must have rank number')
    assert.match(communityTypesFile, /weekly_count:\s*number/, 'RankingPostItem must have weekly_count')
    assert.match(communityTypesFile, /export interface SuggestedUserItem/, 'Must declare SuggestedUserItem interface')
    assert.match(communityTypesFile, /export interface FollowingUserItem/, 'Must declare FollowingUserItem interface')
  })

  it('1.2 Chat types declare conversation status, initiator_id, and is_pending_request', () => {
    assert.match(chatTypesFile, /status\?:\s*['"]pending['"]\s*\|\s*['"]accepted['"]\s*\|\s*['"]rejected['"]/)
    assert.match(chatTypesFile, /initiator_id\?:\s*number\s*\|\s*null/)
    assert.match(chatTypesFile, /is_pending_request\?:\s*boolean/)
  })

  it('1.3 CommunityService exposes rankings, suggested users, following, follow, and unfollow endpoints', () => {
    assert.match(communityServiceFile, /getTopLikedPosts\s*\(/)
    assert.match(communityServiceFile, /\/community\/rankings\/top-liked/)
    assert.match(communityServiceFile, /getTopCommentedPosts\s*\(/)
    assert.match(communityServiceFile, /\/community\/rankings\/top-commented/)
    assert.match(communityServiceFile, /getSuggestedUsers\s*\(/)
    assert.match(communityServiceFile, /\/community\/suggested-users/)
    assert.match(communityServiceFile, /getFollowingUsers\s*\(/)
    assert.match(communityServiceFile, /\/user\/following/)
    assert.match(communityServiceFile, /followUser\s*\(/)
    assert.match(communityServiceFile, /unfollowUser\s*\(/)
  })

  it('1.4 ChatService exposes message requests count, accept, reject, and tab parameter', () => {
    assert.match(chatServiceFile, /getMessageRequestsCount\s*\(/)
    assert.match(chatServiceFile, /\/chat\/conversations\/requests-count/)
    assert.match(chatServiceFile, /acceptConversation\s*\(/)
    assert.match(chatServiceFile, /rejectConversation\s*\(/)
    assert.match(chatServiceFile, /tab:\s*['"]main['"]\s*\|\s*['"]requests['"]/)
  })

  // ---------------------------------------------------------------------------
  // 2. Left Column: Community Rankings Sidebar
  // ---------------------------------------------------------------------------
  it('2.1 CommunityRankingsSidebar displays top 5 liked and top 5 commented sections', () => {
    assert.match(rankingsSidebarFile, /Top Yêu Thích Tuần/, 'Must display top liked heading')
    assert.match(rankingsSidebarFile, /Top Bình Luận Tuần/, 'Must display top commented heading')
    assert.match(rankingsSidebarFile, /7 ngày qua/, 'Must display 7 days timeframe badge')
  })

  it('2.2 CommunityRankingsSidebar displays rank badges, snippets, counts, and click navigation', () => {
    assert.match(rankingsSidebarFile, /rank-number-box/, 'Must display rank number box')
    assert.match(rankingsSidebarFile, /post\.snippet/, 'Must display post content snippet')
    assert.match(rankingsSidebarFile, /lượt thích tuần này/, 'Must display weekly liked count')
    assert.match(rankingsSidebarFile, /bình luận tuần này/, 'Must display weekly commented count')
    assert.match(rankingsSidebarFile, /router\.push\(`\/posts\/\$\{postId\}`\)/, 'Must navigate to post detail')
  })

  // ---------------------------------------------------------------------------
  // 3. Right Column: Community Connections Sidebar
  // ---------------------------------------------------------------------------
  it('3.1 CommunityConnectionsSidebar handles guest state with call-to-action', () => {
    assert.match(connectionsSidebarFile, /guest-cta-card/, 'Must render guest call-to-action card')
    assert.match(connectionsSidebarFile, /\/login\?redirect=\/community/, 'Must have login redirect button')
  })

  it('3.2 CommunityConnectionsSidebar renders suggested users with Follow action', () => {
    assert.match(connectionsSidebarFile, /Gợi ý theo dõi/, 'Must display suggested users section')
    assert.match(connectionsSidebarFile, /btn-follow/, 'Must have follow button')
    assert.match(connectionsSidebarFile, /handleFollow/, 'Must call handleFollow')
  })

  it('3.3 CommunityConnectionsSidebar renders following users with Unfollow action', () => {
    assert.match(connectionsSidebarFile, /Đang theo dõi/, 'Must display following users section')
    assert.match(connectionsSidebarFile, /btn-unfollow/, 'Must have unfollow button')
    assert.match(connectionsSidebarFile, /handleUnfollow/, 'Must call handleUnfollow')
  })

  it('3.4 CommunityConnectionsSidebar renders message requests shortcut with badge', () => {
    assert.match(connectionsSidebarFile, /Tin nhắn chờ/, 'Must display message requests shortcut')
    assert.match(connectionsSidebarFile, /unread-requests-badge/, 'Must display unread requests badge')
    assert.match(connectionsSidebarFile, /navigateToChatRequests/, 'Must navigate to chat requests')
  })

  // ---------------------------------------------------------------------------
  // 4. Community 3-Column Layout
  // ---------------------------------------------------------------------------
  it('4.1 CommunityPage wraps left, center and right columns in community-layout-grid', () => {
    assert.match(communityPageFile, /class="community-layout-grid"/, 'Must contain 3-column layout grid')
    assert.match(communityPageFile, /class="community-sidebar-left"/, 'Must contain left sidebar')
    assert.match(communityPageFile, /<CommunityRankingsSidebar\s*\/>/, 'Must render CommunityRankingsSidebar in left sidebar')
    assert.match(communityPageFile, /class="community-main-column"/, 'Must contain center main column')
    assert.match(communityPageFile, /class="community-sidebar-right"/, 'Must contain right sidebar')
    assert.match(communityPageFile, /<CommunityConnectionsSidebar\s*\/>/, 'Must render CommunityConnectionsSidebar in right sidebar')
  })

  it('4.2 CommunityPage preserves existing feed controls, post cards, and pagination', () => {
    assert.match(communityPageFile, /controls-bar/, 'Must preserve controls-bar')
    assert.match(communityPageFile, /sort-tabs/, 'Must preserve sort-tabs')
    assert.match(communityPageFile, /filter-comic-select/, 'Must preserve comic filter dropdown')
    assert.match(communityPageFile, /<PostCard/, 'Must preserve PostCard rendering')
    assert.match(communityPageFile, /pagination-nav/, 'Must preserve pagination')
  })

  // ---------------------------------------------------------------------------
  // 5. Message Requests in Chat Sidebar & Chat Page
  // ---------------------------------------------------------------------------
  it('5.1 ConversationSidebar renders Hộp thư and Tin nhắn chờ tabs with count badge', () => {
    assert.match(conversationSidebarFile, /sidebar-tabs/, 'Must render sidebar-tabs')
    assert.match(conversationSidebarFile, /Hộp thư/, 'Must have Hộp thư tab')
    assert.match(conversationSidebarFile, /Tin nhắn chờ/, 'Must have Tin nhắn chờ tab')
    assert.match(conversationSidebarFile, /tab-count-badge/, 'Must have tab count badge')
  })

  it('5.2 ChatPage displays contextual banner with Accept and Reject actions for pending requests', () => {
    assert.match(chatPageFile, /message-request-banner/, 'Must have message request action banner')
    assert.match(chatPageFile, /chatStore\.activeConversation\?\.is_pending_request/, 'Must check is_pending_request')
    assert.match(chatPageFile, /btn-request-accept/, 'Must have Accept button')
    assert.match(chatPageFile, /handleAcceptRequest/, 'Must handle Accept')
    assert.match(chatPageFile, /btn-request-reject/, 'Must have Reject button')
    assert.match(chatPageFile, /handleRejectRequest/, 'Must handle Reject')
  })

  it('5.3 chat store manages activeTab, messageRequestsCount, accept, and reject', () => {
    assert.match(chatStoreFile, /activeTab\s*=\s*ref/, 'Chat store must have activeTab state')
    assert.match(chatStoreFile, /messageRequestsCount\s*=\s*ref/, 'Chat store must have messageRequestsCount state')
    assert.match(chatStoreFile, /fetchMessageRequestsCount/, 'Chat store must export fetchMessageRequestsCount')
    assert.match(chatStoreFile, /acceptActiveConversation/, 'Chat store must export acceptActiveConversation')
    assert.match(chatStoreFile, /rejectActiveConversation/, 'Chat store must export rejectActiveConversation')
  })
})
