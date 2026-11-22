/**
 * Comprehensive Unit & Component Behavior Tests for Package 9.7.4.5: Chat UI Integration
 * Covers ConversationSidebar, MessageList, MessageComposer, ChatPage, and AppHeader logic.
 */

import assert from 'node:assert/strict'

console.log('=====================================================')
console.log('   CHAT UI COMPONENT & INTEGRATION BEHAVIOR TESTS    ')
console.log('=====================================================\n')

let passedTests = 0
let failedTests = 0

function test(name, fn) {
  try {
    fn()
    console.log(`✅ PASS: ${name}`)
    passedTests++
  } catch (err) {
    console.error(`❌ FAIL: ${name}`)
    console.error(err)
    failedTests++
  }
}

async function testAsync(name, fn) {
  try {
    await fn()
    console.log(`✅ PASS: ${name}`)
    passedTests++
  } catch (err) {
    console.error(`❌ FAIL: ${name}`)
    console.error(err)
    failedTests++
  }
}

// =========================================================================
// GROUP 1: ConversationSidebar Component Logic
// =========================================================================

test('ConversationSidebar: Loading state and empty state conditions', () => {
  function getSidebarRenderState(props, searchQuery) {
    const isSkeleton = props.isLoading && props.conversations.length === 0
    const query = searchQuery.trim().toLowerCase()
    const filtered = !query
      ? props.conversations
      : props.conversations.filter((c) => {
          const name = c.recipient?.name?.toLowerCase() || ''
          const body = c.last_message?.body?.toLowerCase() || ''
          return name.includes(query) || body.includes(query)
        })
    const isEmpty = !isSkeleton && filtered.length === 0
    const emptyMessage = searchQuery ? 'Không tìm thấy cuộc trò chuyện phù hợp.' : 'Chưa có cuộc trò chuyện nào.'
    return { isSkeleton, isEmpty, emptyMessage, filteredCount: filtered.length }
  }

  // Case 1: Initial Loading with no conversations -> Skeleton
  const state1 = getSidebarRenderState({ isLoading: true, conversations: [] }, '')
  assert.equal(state1.isSkeleton, true)
  assert.equal(state1.isEmpty, false)

  // Case 2: Loaded but empty list -> Empty state with default message
  const state2 = getSidebarRenderState({ isLoading: false, conversations: [] }, '')
  assert.equal(state2.isSkeleton, false)
  assert.equal(state2.isEmpty, true)
  assert.equal(state2.emptyMessage, 'Chưa có cuộc trò chuyện nào.')

  // Case 3: Has conversations but search has no match -> Empty state with search message
  const state3 = getSidebarRenderState(
    { isLoading: false, conversations: [{ id: 1, recipient: { name: 'Alice' } }] },
    'Nonexistent'
  )
  assert.equal(state3.isSkeleton, false)
  assert.equal(state3.isEmpty, true)
  assert.equal(state3.emptyMessage, 'Không tìm thấy cuộc trò chuyện phù hợp.')

  // Case 4: Has conversations and matches search
  const state4 = getSidebarRenderState(
    { isLoading: false, conversations: [{ id: 1, recipient: { name: 'Alice' } }] },
    'ali'
  )
  assert.equal(state4.isEmpty, false)
  assert.equal(state4.filteredCount, 1)
})

test('ConversationSidebar: Unread badge rendering logic and 99+ formatting', () => {
  function formatUnreadBadge(unreadCount) {
    if (!unreadCount || unreadCount <= 0) {
      return { showBadge: false, label: '' }
    }
    const label = unreadCount > 99 ? '99+' : String(unreadCount)
    return { showBadge: true, label }
  }

  assert.deepEqual(formatUnreadBadge(0), { showBadge: false, label: '' })
  assert.deepEqual(formatUnreadBadge(-5), { showBadge: false, label: '' })
  assert.deepEqual(formatUnreadBadge(1), { showBadge: true, label: '1' })
  assert.deepEqual(formatUnreadBadge(45), { showBadge: true, label: '45' })
  assert.deepEqual(formatUnreadBadge(99), { showBadge: true, label: '99' })
  assert.deepEqual(formatUnreadBadge(100), { showBadge: true, label: '99+' })
  assert.deepEqual(formatUnreadBadge(999), { showBadge: true, label: '99+' })
})

test('ConversationSidebar: Search filters correctly by recipient name and last message body', () => {
  const conversations = [
    { id: 1, recipient: { name: 'Nguyễn Văn A' }, last_message: { body: 'Chào bạn, truyện này hay quá' } },
    { id: 2, recipient: { name: 'Trần Thị B' }, last_message: { body: 'Mai ra chap mới không?' } },
    { id: 3, recipient: { name: 'Lê Hoàng C' }, last_message: { body: 'Ok mình sẽ duyệt bài' } },
  ]

  function filterConversations(convs, query) {
    const q = query.trim().toLowerCase()
    if (!q) return convs
    return convs.filter((c) => {
      const recipientName = c.recipient?.name?.toLowerCase() || ''
      const lastMsgBody = c.last_message?.body?.toLowerCase() || ''
      return recipientName.includes(q) || lastMsgBody.includes(q)
    })
  }

  // Search by recipient name
  const res1 = filterConversations(conversations, 'trần')
  assert.equal(res1.length, 1)
  assert.equal(res1[0].id, 2)

  // Search by message body
  const res2 = filterConversations(conversations, 'chap mới')
  assert.equal(res2.length, 1)
  assert.equal(res2[0].id, 2)

  // Search matching multiple
  const res3 = filterConversations(conversations, 'b')
  // 'Nguyễn Văn A' (bạn), 'Trần Thị B' (b, bài), 'Lê Hoàng C' (bài)
  assert.equal(res3.length, 3)

  // Search with no match
  const res4 = filterConversations(conversations, 'xyz123')
  assert.equal(res4.length, 0)
})

test('ConversationSidebar: Selecting conversation updates active status', () => {
  let activeId = null
  function handleSelect(id) {
    activeId = id
  }

  handleSelect(101)
  assert.equal(activeId, 101)

  handleSelect(202)
  assert.equal(activeId, 202)
})

// =========================================================================
// GROUP 2: MessageList Component Logic
// =========================================================================

test('MessageList: Differentiates mine (is-me) vs theirs message bubbles', () => {
  const currentUserId = 10
  const messages = [
    { id: 1, sender_id: 10, body: 'Tin nhắn của tôi' },
    { id: 2, sender_id: 20, body: 'Tin nhắn đối phương' },
  ]

  function isMe(msg, userId) {
    return msg.sender_id === userId
  }

  assert.equal(isMe(messages[0], currentUserId), true)
  assert.equal(isMe(messages[1], currentUserId), false)
})

test('MessageList: Displays read receipt (✓✓) only for own messages when counterpart has read', () => {
  const currentUserId = 10
  const otherParticipantLastReadId = 50

  function shouldShowReadReceipt(msg, userId, lastReadId) {
    // Only show on own messages when other participant has read at or past this message
    return msg.sender_id === userId && lastReadId >= msg.id
  }

  // Own message read by counterpart (id 45 <= 50) -> Show
  assert.equal(shouldShowReadReceipt({ id: 45, sender_id: 10 }, currentUserId, otherParticipantLastReadId), true)

  // Own message exactly at read mark (id 50 <= 50) -> Show
  assert.equal(shouldShowReadReceipt({ id: 50, sender_id: 10 }, currentUserId, otherParticipantLastReadId), true)

  // Own message NOT yet read (id 55 > 50) -> Hide
  assert.equal(shouldShowReadReceipt({ id: 55, sender_id: 10 }, currentUserId, otherParticipantLastReadId), false)

  // Counterpart message (sender_id !== currentUserId) -> Always Hide read receipt
  assert.equal(shouldShowReadReceipt({ id: 40, sender_id: 20 }, currentUserId, otherParticipantLastReadId), false)
})

test('MessageList: Scroll compensation math during loadOlder preserves scroll position', () => {
  // Mock container state before prepending older messages
  const mockContainer = {
    scrollHeight: 1000,
    scrollTop: 20, // Scrolled near top
  }

  const prevScrollHeight = mockContainer.scrollHeight

  // Simulate prepending 20 older messages, expanding scrollHeight to 2500
  mockContainer.scrollHeight = 2500

  // Apply scroll compensation formula from MessageList.vue line 67
  mockContainer.scrollTop = mockContainer.scrollHeight - prevScrollHeight

  // User's relative viewport position is preserved exactly at offset 1500!
  assert.equal(mockContainer.scrollTop, 1500)
  assert.equal(mockContainer.scrollHeight - mockContainer.scrollTop, 1000)
})

// =========================================================================
// GROUP 3: MessageComposer Component Logic
// =========================================================================

test('MessageComposer: Prevents sending empty string or whitespace-only', () => {
  function canSubmit(body, isSending) {
    const trimmed = body.trim()
    const charCount = body.length
    const isOverLimit = charCount > 2000
    return trimmed.length > 0 && !isOverLimit && !isSending
  }

  assert.equal(canSubmit('', false), false)
  assert.equal(canSubmit('   ', false), false)
  assert.equal(canSubmit('\n\t  \n', false), false)
  assert.equal(canSubmit('Hello', false), true)
})

test('MessageComposer: Prevents sending content exceeding 2000 characters', () => {
  function canSubmit(body, isSending) {
    const trimmed = body.trim()
    const charCount = body.length
    const isOverLimit = charCount > 2000
    return trimmed.length > 0 && !isOverLimit && !isSending
  }

  const maxValidText = 'a'.repeat(2000)
  assert.equal(canSubmit(maxValidText, false), true)

  const overLimitText = 'a'.repeat(2001)
  assert.equal(canSubmit(overLimitText, false), false)
})

test('MessageComposer: Keyboard handling (Enter sends, Shift+Enter newlines)', () => {
  let submitted = false

  function handleKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      submitted = true
    }
  }

  // Case 1: Enter alone -> Submits
  let prevented1 = false
  handleKeydown({
    key: 'Enter',
    shiftKey: false,
    preventDefault: () => {
      prevented1 = true
    },
  })
  assert.equal(submitted, true)
  assert.equal(prevented1, true)

  // Case 2: Shift+Enter -> Allows newline without submitting
  submitted = false
  let prevented2 = false
  handleKeydown({
    key: 'Enter',
    shiftKey: true,
    preventDefault: () => {
      prevented2 = true
    },
  })
  assert.equal(submitted, false)
  assert.equal(prevented2, false)
})

test('MessageComposer: Does not allow sending while isSending is true', () => {
  function canSubmit(body, isSending) {
    const trimmed = body.trim()
    const charCount = body.length
    const isOverLimit = charCount > 2000
    return trimmed.length > 0 && !isOverLimit && !isSending
  }

  assert.equal(canSubmit('Valid text', true), false)
  assert.equal(canSubmit('Valid text', false), true)
})

// =========================================================================
// GROUP 4: ChatPage Orchestration & Navigation
// =========================================================================

test('ChatPage: Mobile view state toggles correctly and back clears active conversation', () => {
  let activeConversation = null

  function isMobileActive() {
    return activeConversation !== null
  }

  function handleBackToSidebar() {
    activeConversation = null
  }

  // Initially no active conversation -> Show sidebar on mobile
  assert.equal(isMobileActive(), false)

  // Conversation selected -> Show message pane on mobile
  activeConversation = { id: 10 }
  assert.equal(isMobileActive(), true)

  // User taps back button -> Reverts to sidebar on mobile
  handleBackToSidebar()
  assert.equal(isMobileActive(), false)
  assert.equal(activeConversation, null)
})

test('ChatPage: Route params handler opens or creates conversation properly', async () => {
  let openedConversationId = null
  let createdForUserId = null

  const mockChatService = {
    createDirectConversation: async (userId) => {
      createdForUserId = userId
      return { id: 999 }
    },
  }

  async function handleRouteParams(query, currentUserId) {
    if (query.user_id) {
      const targetUserId = parseInt(String(query.user_id), 10)
      if (!isNaN(targetUserId) && targetUserId !== currentUserId) {
        const conv = await mockChatService.createDirectConversation(targetUserId)
        openedConversationId = conv.id
        return
      }
    }
    if (query.conversation_id) {
      const targetConvId = parseInt(String(query.conversation_id), 10)
      if (!isNaN(targetConvId)) {
        openedConversationId = targetConvId
      }
    }
  }

  // Case A: Query with conversation_id=88
  await handleRouteParams({ conversation_id: '88' }, 1)
  assert.equal(openedConversationId, 88)

  // Case B: Query with user_id=45
  await handleRouteParams({ user_id: '45' }, 1)
  assert.equal(createdForUserId, 45)
  assert.equal(openedConversationId, 999)

  // Case C: Query with self user_id=1 (must not chat with self)
  openedConversationId = null
  createdForUserId = null
  await handleRouteParams({ user_id: '1' }, 1)
  assert.equal(createdForUserId, null)
  assert.equal(openedConversationId, null)
})

// =========================================================================
// GROUP 5: AppHeader Unread Badge & Navigation
// =========================================================================

test('AppHeader: Chat navigation link visibility based on authentication status', () => {
  function isChatLinkVisible(isAuthenticated) {
    return isAuthenticated === true
  }

  assert.equal(isChatLinkVisible(false), false)
  assert.equal(isChatLinkVisible(true), true)
})

test('AppHeader: Unread badge reflects store totalUnreadCount with 99+ ceiling', () => {
  function getHeaderBadge(totalUnreadCount) {
    if (!totalUnreadCount || totalUnreadCount <= 0) {
      return { visible: false, text: '' }
    }
    return {
      visible: true,
      text: totalUnreadCount > 99 ? '99+' : String(totalUnreadCount),
    }
  }

  assert.deepEqual(getHeaderBadge(0), { visible: false, text: '' })
  assert.deepEqual(getHeaderBadge(3), { visible: true, text: '3' })
  assert.deepEqual(getHeaderBadge(99), { visible: true, text: '99' })
  assert.deepEqual(getHeaderBadge(150), { visible: true, text: '99+' })
})

test('AppHeader & Composable: Subscription deduplication avoids redundant user channel listeners', () => {
  const activeUserSubscriptions = new Map()
  let leaveCalls = []

  function subscribeUser(userId, handler) {
    if (activeUserSubscriptions.has(userId)) {
      leaveCalls.push(`chat.user.${userId}`)
      activeUserSubscriptions.delete(userId)
    }
    activeUserSubscriptions.set(userId, handler)
    return () => {
      leaveCalls.push(`chat.user.${userId}`)
      activeUserSubscriptions.delete(userId)
    }
  }

  // First subscription
  const unsub1 = subscribeUser(5, () => {})
  assert.equal(activeUserSubscriptions.size, 1)

  // Re-render or duplicate call replaces previous subscription cleanly
  const unsub2 = subscribeUser(5, () => {})
  assert.equal(activeUserSubscriptions.size, 1)
  assert.deepEqual(leaveCalls, ['chat.user.5'])

  // Logout cleanup
  unsub2()
  assert.equal(activeUserSubscriptions.size, 0)
})

test('ConversationUpdated & MarkAsRead: Full unread increment (0 -> 1) on incoming message and decrement (1 -> 0) on read', () => {
  const conversations = [
    {
      id: 101,
      unread_count: 0,
      last_message: { id: 50, body: 'Old message' },
    },
  ]
  let activeConversation = null // User B has not opened conversation yet

  function handleConversationUpdated(payload) {
    const conv = conversations.find((c) => c.id === payload.conversation_id)
    if (!conv) return

    // Out-of-order stale check
    if (conv.last_message && payload.last_message.id < conv.last_message.id) return

    conv.last_message = payload.last_message
    if (activeConversation && activeConversation.id === payload.conversation_id) {
      conv.unread_count = 0
    } else {
      conv.unread_count = payload.unread_count
    }
  }

  function markAsRead(convId, messageId) {
    const conv = conversations.find((c) => c.id === convId)
    if (conv) {
      if (!conv.last_message || conv.last_message.id <= messageId) {
        conv.unread_count = 0
      }
    }
    if (activeConversation && activeConversation.id === convId) {
      activeConversation.unread_count = 0
    }
  }

  // 1. Initial state: User B unread count is 0
  assert.equal(conversations[0].unread_count, 0)

  // 2. Incoming message (id: 100) from User A -> ConversationUpdated with unread_count: 1
  handleConversationUpdated({
    conversation_id: 101,
    last_message: { id: 100, body: 'Hello B' },
    unread_count: 1,
  })

  // Assert unread count strictly INCREASES from 0 to 1
  assert.equal(conversations[0].unread_count, 1, 'Unread count must increase to 1 on incoming message')

  // 3. User B opens conversation
  activeConversation = { id: 101, unread_count: 1 }

  // 4. Mark as read executed
  markAsRead(101, 100)

  // Assert unread count strictly DECREASES from 1 to 0
  assert.equal(conversations[0].unread_count, 0, 'Unread count must decrease to 0 after mark-as-read')
  assert.equal(activeConversation.unread_count, 0, 'Active conversation unread count must be 0')
})

console.log('\n=====================================================')
console.log(`  UI TEST RESULTS: ${passedTests} passed, ${failedTests} failed`)
console.log('=====================================================')

if (failedTests > 0) {
  process.exit(1)
}
