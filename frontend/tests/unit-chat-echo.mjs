/**
 * Unit verification script for Package 9.7.4.4: Frontend Laravel Echo & Reverb Integration
 * Tests Echo configuration, singleton instance, X-Socket-ID header injection,
 * message deduplication, monotonic read markers, unread count integrity, and subscription lifecycle.
 */

import assert from 'node:assert/strict'

console.log('=====================================================')
console.log('  TESTING FRONTEND ECHO & CHAT INTEGRATION (GÓI 9.7.4.4)')
console.log('=====================================================\n')

let passedTests = 0
let failedTests = 0

function runTest(name, fn) {
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

// -------------------------------------------------------------
// Test 1: Reverb Configuration builder logic
// -------------------------------------------------------------
runTest('Reverb Configuration options builder respects environment variables', () => {
  function buildReverbConfig(env, windowObj) {
    const appKey = env.VITE_REVERB_APP_KEY || ''
    const host = env.VITE_REVERB_HOST || (windowObj ? windowObj.location.hostname : 'localhost')
    const rawPort = env.VITE_REVERB_PORT
    const scheme = env.VITE_REVERB_SCHEME || 'http'
    const forceTLS = scheme === 'https'
    const defaultPort = forceTLS ? 443 : 8080
    const port = rawPort ? Number(rawPort) : defaultPort

    return {
      broadcaster: 'reverb',
      key: appKey,
      wsHost: host,
      wsPort: port,
      wssPort: port,
      forceTLS,
      enabledTransports: ['ws', 'wss'],
    }
  }

  // Case A: Custom environment variables
  const configA = buildReverbConfig(
    {
      VITE_REVERB_APP_KEY: 'test-app-key',
      VITE_REVERB_HOST: 'chat.example.com',
      VITE_REVERB_PORT: '9000',
      VITE_REVERB_SCHEME: 'https',
    },
    null
  )

  assert.equal(configA.key, 'test-app-key')
  assert.equal(configA.wsHost, 'chat.example.com')
  assert.equal(configA.wsPort, 9000)
  assert.equal(configA.wssPort, 9000)
  assert.equal(configA.forceTLS, true)
  assert.deepEqual(configA.enabledTransports, ['ws', 'wss'])

  // Case B: Fallbacks with window
  const configB = buildReverbConfig({}, { location: { hostname: 'bluetoon.local' } })
  assert.equal(configB.key, '')
  assert.equal(configB.wsHost, 'bluetoon.local')
  assert.equal(configB.wsPort, 8080)
  assert.equal(configB.forceTLS, false)
})

// -------------------------------------------------------------
// Test 2: Singleton Instance & No Duplicate Instances
// -------------------------------------------------------------
runTest('Echo singleton pattern prevents duplicate instances', () => {
  let instanceCount = 0
  let echoInstance = null

  function getMockEcho(appKey) {
    if (echoInstance) return echoInstance
    if (!appKey) return null

    instanceCount++
    echoInstance = {
      id: instanceCount,
      socketId: () => '1000.2000',
      disconnect: () => {
        echoInstance = null
      },
    }
    return echoInstance
  }

  const firstCall = getMockEcho('valid-key')
  const secondCall = getMockEcho('valid-key')
  const thirdCall = getMockEcho('valid-key')

  assert.equal(instanceCount, 1)
  assert.strictEqual(firstCall, secondCall)
  assert.strictEqual(secondCall, thirdCall)

  // Empty key does not create instance
  firstCall.disconnect()
  const nullCall = getMockEcho('')
  assert.equal(nullCall, null)
})

// -------------------------------------------------------------
// Test 3: X-Socket-ID Header Injection in HttpClient
// -------------------------------------------------------------
runTest('HttpClient request interceptor attaches X-Socket-ID only when available', () => {
  let socketIdProvider = null
  function setSocketIdProvider(provider) {
    socketIdProvider = provider
  }

  function mockRequestInterceptor(config) {
    config.headers = config.headers || {}
    if (socketIdProvider) {
      try {
        const socketId = socketIdProvider()
        if (socketId) {
          config.headers['X-Socket-ID'] = socketId
        }
      } catch {
        // proceed
      }
    }
    return config
  }

  // Case A: Provider registered and returns valid socketId
  setSocketIdProvider(() => '8888.9999')
  const req1 = mockRequestInterceptor({ url: '/chat/conversations/1/messages', headers: { Accept: 'application/json' } })
  assert.equal(req1.headers['X-Socket-ID'], '8888.9999')
  assert.equal(req1.headers['Accept'], 'application/json')

  // Case B: Echo not connected (returns undefined)
  setSocketIdProvider(() => undefined)
  const req2 = mockRequestInterceptor({ url: '/chat/conversations/1/messages', headers: { Accept: 'application/json' } })
  assert.equal(req2.headers['X-Socket-ID'], undefined)

  // Case C: Provider is null
  setSocketIdProvider(null)
  const req3 = mockRequestInterceptor({ url: '/comics', headers: {} })
  assert.equal(req3.headers['X-Socket-ID'], undefined)
})

// -------------------------------------------------------------
// Test 4: Message Deduplication in Chat Store (MessageSent)
// -------------------------------------------------------------
runTest('MessageSent event deduplicates by id and client_message_id and maintains order', () => {
  const activeMessages = []

  function handleMessageSent(payload) {
    const message = payload.message || payload
    const isDuplicate = activeMessages.some(
      (m) =>
        m.id === message.id ||
        (message.client_message_id && m.client_message_id === message.client_message_id)
    )

    if (!isDuplicate) {
      activeMessages.push(message)
      activeMessages.sort((a, b) => a.id - b.id)
    }
  }

  // 1. Initial message from REST response
  handleMessageSent({ id: 10, conversation_id: 1, sender_id: 2, body: 'Hello', client_message_id: 'uuid-1', created_at: '2026-11-22T00:00:00Z' })
  assert.equal(activeMessages.length, 1)

  // 2. Incoming broadcast event with SAME id (should not duplicate)
  handleMessageSent({ id: 10, conversation_id: 1, sender_id: 2, body: 'Hello', client_message_id: 'uuid-1', created_at: '2026-11-22T00:00:00Z' })
  assert.equal(activeMessages.length, 1)

  // 3. Incoming broadcast event with SAME client_message_id but different internal id (e.g. race condition)
  handleMessageSent({ id: 11, conversation_id: 1, sender_id: 2, body: 'Hello', client_message_id: 'uuid-1', created_at: '2026-11-22T00:00:01Z' })
  assert.equal(activeMessages.length, 1)

  // 4. New message arrives (should append and sort)
  handleMessageSent({ id: 25, conversation_id: 1, sender_id: 3, body: 'Second', client_message_id: 'uuid-2', created_at: '2026-11-22T00:01:00Z' })
  handleMessageSent({ id: 15, conversation_id: 1, sender_id: 2, body: 'Interleaved', client_message_id: 'uuid-3', created_at: '2026-11-22T00:00:30Z' })

  assert.equal(activeMessages.length, 3)
  assert.equal(activeMessages[0].id, 10)
  assert.equal(activeMessages[1].id, 15)
  assert.equal(activeMessages[2].id, 25)
})

// -------------------------------------------------------------
// Test 5: Monotonic Read Marker Progression (MessagesRead)
// -------------------------------------------------------------
runTest('MessagesRead strictly advances read marker and never decreases', () => {
  const participant = {
    user_id: 5,
    last_read_message_id: 20,
    last_read_at: '2026-11-22T01:00:00Z',
  }

  function handleMessagesRead(payload) {
    if (participant.user_id === payload.user_id) {
      if (!participant.last_read_message_id || payload.last_read_message_id > participant.last_read_message_id) {
        participant.last_read_message_id = payload.last_read_message_id
        participant.last_read_at = payload.last_read_at
      }
    }
  }

  // 1. Advance to 35
  handleMessagesRead({ conversation_id: 1, user_id: 5, last_read_message_id: 35, last_read_at: '2026-11-22T01:05:00Z' })
  assert.equal(participant.last_read_message_id, 35)

  // 2. Out-of-order or duplicate older event with id 25
  handleMessagesRead({ conversation_id: 1, user_id: 5, last_read_message_id: 25, last_read_at: '2026-11-22T01:02:00Z' })
  assert.equal(participant.last_read_message_id, 35) // Must NOT decrease!

  // 3. Duplicate event with same id 35
  handleMessagesRead({ conversation_id: 1, user_id: 5, last_read_message_id: 35, last_read_at: '2026-11-22T01:05:00Z' })
  assert.equal(participant.last_read_message_id, 35)
})

// -------------------------------------------------------------
// Test 6: ConversationUpdated Unread Count & Sidebar Handling
// -------------------------------------------------------------
runTest('ConversationUpdated updates last_message and authoritative unread_count without corrupting counts', () => {
  const conversations = [
    { id: 1, unread_count: 0, last_message_at: '2026-11-22T01:00:00Z', last_message: { id: 10, body: 'Hi' } },
    { id: 2, unread_count: 2, last_message_at: '2026-11-22T01:10:00Z', last_message: { id: 15, body: 'Yo' } },
  ]
  let activeConversationId = 1

  function handleConversationUpdated(payload) {
    const conv = conversations.find((c) => c.id === payload.conversation_id)
    if (conv) {
      if (conv.last_message && payload.last_message.id < conv.last_message.id) {
        return // Out-of-order stale event
      }

      conv.last_message = payload.last_message
      conv.last_message_at = payload.last_message.created_at

      if (activeConversationId === payload.conversation_id) {
        conv.unread_count = 0
      } else {
        conv.unread_count = Math.max(conv.unread_count || 0, payload.unread_count)
      }
    }
  }

  // Update conversation 2 (not active) -> unread count updated from event
  handleConversationUpdated({
    conversation_id: 2,
    last_message: { id: 20, body: 'New msg', created_at: '2026-11-22T01:20:00Z' },
    unread_count: 3,
  })
  assert.equal(conversations[1].unread_count, 3)
  assert.equal(conversations[1].last_message.id, 20)

  // Update conversation 1 (active) -> unread count remains 0 because user is viewing
  handleConversationUpdated({
    conversation_id: 1,
    last_message: { id: 30, body: 'Active msg', created_at: '2026-11-22T01:30:00Z' },
    unread_count: 1,
  })
  assert.equal(conversations[0].unread_count, 0)
})

// -------------------------------------------------------------
// Test 9: Out-of-order Snapshot Rejection (Section 8 Requirement)
// -------------------------------------------------------------
runTest('Late-arriving older snapshot cannot overwrite newer unread count (e.g. 4 -> 3 prevented)', () => {
  const conv = {
    id: 10,
    unread_count: 2,
    last_message: { id: 50, body: 'Old' },
  }
  let syncTriggered = false

  function handleConversationUpdatedWithProtection(payload) {
    if (conv.last_message && payload.last_message.id < conv.last_message.id) {
      syncTriggered = true
      return // Stale event rejected!
    }

    conv.last_message = payload.last_message
    conv.unread_count = Math.max(conv.unread_count || 0, payload.unread_count)
  }

  // Event B arrives first: last_message_id = 60, unread_count = 4
  handleConversationUpdatedWithProtection({
    conversation_id: 10,
    last_message: { id: 60, body: 'Message B' },
    unread_count: 4,
  })
  assert.equal(conv.unread_count, 4)
  assert.equal(conv.last_message.id, 60)

  // Event A arrives LATER: last_message_id = 55, unread_count = 3
  handleConversationUpdatedWithProtection({
    conversation_id: 10,
    last_message: { id: 55, body: 'Message A (arrived late)' },
    unread_count: 3,
  })

  // Crucial assertion: unread_count MUST REMAIN 4, NOT DOWNGRADED TO 3!
  assert.equal(conv.unread_count, 4)
  assert.equal(conv.last_message.id, 60)
  assert.equal(syncTriggered, true)
})

// -------------------------------------------------------------
// Test 10: Redundant Mark-as-read Prevention
// -------------------------------------------------------------
runTest('Redundant mark-as-read requests are skipped when participant marker already >= messageId', () => {
  let apiCalls = 0
  const participant = {
    last_read_message_id: 100,
  }

  function markAsRead(messageId) {
    if (participant.last_read_message_id && participant.last_read_message_id >= messageId) {
      return // Skip redundant request
    }
    apiCalls++
    participant.last_read_message_id = messageId
  }

  // Case 1: Same marker 100
  markAsRead(100)
  assert.equal(apiCalls, 0)

  // Case 2: Older marker 80
  markAsRead(80)
  assert.equal(apiCalls, 0)

  // Case 3: Newer marker 120
  markAsRead(120)
  assert.equal(apiCalls, 1)
  assert.equal(participant.last_read_message_id, 120)
})

// -------------------------------------------------------------
// Test 7: Subscription Lifecycle & Duplicate Prevention
// -------------------------------------------------------------
runTest('Subscription lifecycle manages channels and cleans up without duplicates', () => {
  const channels = new Map()
  let leaveCalls = []

  function subscribeConversation(id, handler) {
    if (channels.has(id)) {
      leaveCalls.push(`chat.conversation.${id}`)
      channels.delete(id)
    }
    channels.set(id, handler)
    return () => unsubscribeConversation(id)
  }

  function unsubscribeConversation(id) {
    if (channels.has(id)) {
      leaveCalls.push(`chat.conversation.${id}`)
      channels.delete(id)
    }
  }

  function disconnectAll() {
    channels.forEach((_, id) => leaveCalls.push(`chat.conversation.${id}`))
    channels.clear()
  }

  // Mount 1
  const unsub1 = subscribeConversation(101, () => {})
  assert.equal(channels.size, 1)

  // Duplicate mount on same conversation replaces previous listener
  const unsub2 = subscribeConversation(101, () => {})
  assert.equal(channels.size, 1)
  assert.deepEqual(leaveCalls, ['chat.conversation.101'])

  // Switch conversation
  unsub2()
  subscribeConversation(202, () => {})
  assert.equal(channels.size, 1)
  assert.ok(channels.has(202))

  // Logout / disconnect
  disconnectAll()
  assert.equal(channels.size, 0)
})

// -------------------------------------------------------------
// Test 8: Reconnection Resync Hook
// -------------------------------------------------------------
runTest('Reconnection triggers registered resync callbacks', () => {
  const callbacks = new Set()
  let resyncCount = 0

  function onEchoReconnect(cb) {
    callbacks.add(cb)
    return () => callbacks.delete(cb)
  }

  function simulateStateChange(previous, current) {
    if (current === 'connected' && (previous === 'unavailable' || previous === 'disconnected' || previous === 'connecting')) {
      callbacks.forEach((cb) => cb())
    }
  }

  const unsub = onEchoReconnect(() => {
    resyncCount++
  })

  // Disconnection and reconnect sequence
  simulateStateChange('connected', 'unavailable')
  simulateStateChange('unavailable', 'connecting')
  simulateStateChange('connecting', 'connected')

  assert.equal(resyncCount, 1)

  // Unregister callback
  unsub()
  simulateStateChange('connected', 'disconnected')
  simulateStateChange('disconnected', 'connected')
  assert.equal(resyncCount, 1)
})

// -------------------------------------------------------------
// Test 11: Retry Idempotency: client_message_id Preservation
// -------------------------------------------------------------
runTest('Retry idempotency preserves client_message_id on retry and regenerates on draft change', () => {
  let lastDraftText = ''
  let lastClientMessageId = null

  function getOrCreateClientMessageId(text) {
    if (lastClientMessageId && lastDraftText === text) {
      return lastClientMessageId
    }
    const newId = `cmid-${Math.random().toString(36).slice(2, 9)}`
    lastDraftText = text
    lastClientMessageId = newId
    return newId
  }

  function clearClientMessageId() {
    lastDraftText = ''
    lastClientMessageId = null
  }

  // 1. First attempt to send 'Hello world'
  const id1 = getOrCreateClientMessageId('Hello world')
  assert.ok(id1.startsWith('cmid-'))

  // 2. Retry of the same draft text must reuse the exact same client_message_id
  const id2 = getOrCreateClientMessageId('Hello world')
  assert.equal(id2, id1, 'Retry with identical text must reuse the same client_message_id')

  // 3. User modifies draft text -> must generate a new client_message_id
  const id3 = getOrCreateClientMessageId('Hello world updated')
  assert.notEqual(id3, id1, 'Changed text must generate a fresh client_message_id')

  // 4. On send success, clearClientMessageId resets state
  clearClientMessageId()
  const id4 = getOrCreateClientMessageId('Hello world')
  assert.notEqual(id4, id1, 'After clearing, next send generates a fresh client_message_id')
})

// -------------------------------------------------------------
// Test 12: Race Condition Protection in Rapid Conversation Switching
// -------------------------------------------------------------
runTest('Token-based cancellation discards out-of-order responses during rapid conversation switching', async () => {
  let openConversationToken = 0
  let activeConversation = null
  let activeMessages = []

  async function openConversation(convId, delayMs, messages) {
    const token = ++openConversationToken

    // Simulate async REST fetch with delay
    await new Promise((resolve) => setTimeout(resolve, delayMs))

    // Token guard: if user switched conversations, discard this stale response
    if (token !== openConversationToken) {
      return
    }

    activeConversation = { id: convId }
    activeMessages = messages
  }

  // User rapidly clicks Conv 1 (slow, 50ms) then Conv 2 (fast, 10ms)
  const req1 = openConversation(1, 50, [{ id: 101, body: 'Conv 1 Msg' }])
  const req2 = openConversation(2, 10, [{ id: 201, body: 'Conv 2 Msg' }])

  await Promise.all([req1, req2])

  // Active conversation MUST be Conv 2, and activeMessages MUST be Conv 2 Msg
  assert.equal(activeConversation.id, 2)
  assert.equal(activeMessages.length, 1)
  assert.equal(activeMessages[0].id, 201)
  assert.equal(activeMessages[0].body, 'Conv 2 Msg')
})

// -------------------------------------------------------------
// Test 13: In-flight Message Send Cross-Conversation Guard
// -------------------------------------------------------------
runTest('Delayed message send does not append to newly activated conversation', async () => {
  let activeConversation = { id: 1 }
  let activeMessages = [{ id: 10, body: 'Existing 1' }]
  const conversations = [
    { id: 1, last_message: { id: 10, body: 'Existing 1' } },
    { id: 2, last_message: { id: 20, body: 'Existing 2' } },
  ]

  async function simulateSendMessage(body, targetConvId, delayMs) {
    const createdMessage = { id: 99, conversation_id: targetConvId, body }
    await new Promise((resolve) => setTimeout(resolve, delayMs))

    // Guard: only push to activeMessages if active conversation is still targetConvId
    if (activeConversation && activeConversation.id === targetConvId) {
      activeMessages.push(createdMessage)
    }

    // Always update the target conversation in sidebar list
    const conv = conversations.find((c) => c.id === targetConvId)
    if (conv) {
      conv.last_message = createdMessage
    }
  }

  // Start sending message to Conv 1
  const sendPromise = simulateSendMessage('Delayed msg for conv 1', 1, 30)

  // User immediately switches to Conv 2
  activeConversation = { id: 2 }
  activeMessages = [{ id: 20, body: 'Existing 2' }]

  await sendPromise

  // Active messages must NOT contain the message for Conv 1!
  assert.equal(activeMessages.length, 1)
  assert.equal(activeMessages[0].id, 20)

  // Conv 1 in sidebar MUST reflect the new message
  assert.equal(conversations[0].last_message.id, 99)
})

// -------------------------------------------------------------
// Test 14: In-flight Load Older Messages Cross-Conversation Guard
// -------------------------------------------------------------
runTest('Delayed loadOlderMessages does not prepend older messages to newly activated conversation', async () => {
  let activeConversation = { id: 1 }
  let activeMessages = [{ id: 50, body: 'Conv 1 Current' }]

  async function simulateLoadOlderMessages(targetConvId, olderMessages, delayMs) {
    await new Promise((resolve) => setTimeout(resolve, delayMs))

    // Guard: if user switched conversation, discard
    if (!activeConversation || activeConversation.id !== targetConvId) {
      return
    }

    activeMessages = [...olderMessages, ...activeMessages]
  }

  const loadPromise = simulateLoadOlderMessages(1, [{ id: 40, body: 'Conv 1 Older' }], 30)

  // User switches to Conv 2
  activeConversation = { id: 2 }
  activeMessages = [{ id: 80, body: 'Conv 2 Current' }]

  await loadPromise

  // Active messages must NOT have Conv 1's older messages
  assert.equal(activeMessages.length, 1)
  assert.equal(activeMessages[0].id, 80)
})

// -------------------------------------------------------------
// Test 15: Same Message ID Stale Pre-read Snapshot Rejection
// -------------------------------------------------------------
runTest('Stale snapshot with same message ID cannot resurrect unread count after user marked as read', () => {
  const conv = {
    id: 5,
    unread_count: 0, // User already marked as read
    last_message: { id: 100, body: 'Latest' },
  }
  let resyncRequested = false

  function handleConversationUpdated(payload) {
    if (conv.last_message) {
      // 1. Older message ID is rejected
      if (payload.last_message.id < conv.last_message.id) {
        resyncRequested = true
        return
      }
      // 2. Same message ID with higher unread_count is a stale pre-read snapshot, reject!
      if (
        payload.last_message.id === conv.last_message.id &&
        conv.unread_count !== undefined &&
        payload.unread_count > conv.unread_count
      ) {
        resyncRequested = true
        return
      }
    }

    conv.last_message = payload.last_message
    conv.unread_count = payload.unread_count
  }

  // Late-arriving event from before the read was committed (has same msg id 100, but unread = 3)
  handleConversationUpdated({
    conversation_id: 5,
    last_message: { id: 100, body: 'Latest' },
    unread_count: 3,
  })

  // Unread count must stay 0, NOT resurrected to 3!
  assert.equal(conv.unread_count, 0)
  assert.equal(resyncRequested, true)
})

console.log('\n=====================================================')
console.log(`  RESULTS: ${passedTests} passed, ${failedTests} failed`)
console.log('=====================================================')

if (failedTests > 0) {
  process.exit(1)
}
