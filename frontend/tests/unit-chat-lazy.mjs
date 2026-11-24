import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

console.log('--- RUNNING CAI-MSG-01 UNIT TESTS (LAZY CONVERSATION & CHAT LIST CLEANUP) ---')

// [TEST 1] ChatService methods
{
  console.log('[TEST 1] Verify ChatService.findDirectConversation and sendDirectMessage')
  const servicePath = path.join(projectRoot, 'src/services/chatService.ts')
  const serviceContent = fs.readFileSync(servicePath, 'utf8')

  assert.ok(
    serviceContent.includes('findDirectConversation'),
    'ChatService must have findDirectConversation method'
  )
  assert.ok(
    serviceContent.includes('`/chat/direct/${userId}`'),
    'findDirectConversation must call GET /chat/direct/:userId'
  )
  assert.ok(
    serviceContent.includes('sendDirectMessage'),
    'ChatService must have sendDirectMessage method'
  )
  assert.ok(
    serviceContent.includes('`/chat/direct/${userId}/messages`'),
    'sendDirectMessage must call POST /chat/direct/:userId/messages'
  )
  console.log('✓ [TEST 1] PASS: ChatService lazy endpoints correctly implemented')
}

// [TEST 2] Chat Store openDraftConversation & Lazy sendMessage
{
  console.log('[TEST 2] Verify useChatStore draft conversation and lazy creation')
  const storePath = path.join(projectRoot, 'src/stores/chat.ts')
  const storeContent = fs.readFileSync(storePath, 'utf8')

  assert.ok(
    storeContent.includes('function openDraftConversation'),
    'chatStore must define openDraftConversation action'
  )
  assert.ok(
    storeContent.includes('id: 0'),
    'openDraftConversation must initialize draft with id: 0'
  )
  assert.ok(
    storeContent.includes('activeMessages.value = []'),
    'openDraftConversation must initialize activeMessages to empty array'
  )
  assert.ok(
    storeContent.includes('ChatService.sendDirectMessage'),
    'chatStore.sendMessage must call ChatService.sendDirectMessage when activeConversation.id === 0'
  )
  assert.ok(
    storeContent.includes('openDraftConversation,'),
    'chatStore must export openDraftConversation in return object'
  )
  console.log('✓ [TEST 2] PASS: useChatStore supports draft state and lazy send')
}

// [TEST 3] ChatPage route handling
{
  console.log('[TEST 3] Verify ChatPage.vue uses findDirectConversation instead of creating prematurely')
  const pagePath = path.join(projectRoot, 'src/pages/ChatPage.vue')
  const pageContent = fs.readFileSync(pagePath, 'utf8')

  assert.ok(
    pageContent.includes('ChatService.findDirectConversation(targetUserId)'),
    'handleRouteParams must call ChatService.findDirectConversation'
  )
  assert.ok(
    !pageContent.includes('ChatService.createDirectConversation(targetUserId)'),
    'handleRouteParams must NEVER call createDirectConversation on mount'
  )
  assert.ok(
    pageContent.includes('chatStore.openDraftConversation(res.recipient)'),
    'handleRouteParams must open draft conversation if no conversation exists yet'
  )
  assert.ok(
    pageContent.includes('wasDraft'),
    'handleSendMessage must track draft state to update route after first message'
  )
  console.log('✓ [TEST 3] PASS: ChatPage handles lazy direct flow correctly')
}

// [TEST 4] ConversationSidebar filters empty conversations
{
  console.log('[TEST 4] Verify ConversationSidebar filters out conversations without messages')
  const sidebarPath = path.join(projectRoot, 'src/components/chat/ConversationSidebar.vue')
  const sidebarContent = fs.readFileSync(sidebarPath, 'utf8')

  assert.ok(
    sidebarContent.includes('conv.id !== 0'),
    'ConversationSidebar must exclude draft conversations (id === 0)'
  )
  assert.ok(
    sidebarContent.includes('conv.last_message !== null || conv.last_message_at !== null'),
    'ConversationSidebar must exclude empty conversations without last_message'
  )
  console.log('✓ [TEST 4] PASS: ConversationSidebar filters out empty/draft conversations')
}

console.log('\nALL 4 FRONTEND UNIT TESTS PASSED!')
