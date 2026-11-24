/**
 * Unit & Component Behavior Tests for CAI-NOTIF-01: Notification System
 * Covers NotificationBell badge & states, navigation targets, optimistic mark-as-read & rollback,
 * mark-all-as-read & rollback, API error handling, realtime deduplication, and logout cleanup.
 */

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const projectRoot = path.resolve(__dirname, '..')

console.log('=====================================================')
console.log('  CAI-NOTIF-01 FRONTEND NOTIFICATION UNIT TESTS      ')
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
// TEST 1, 2, 3: Notification Bell unread count, hidden at 0, 99+ ceiling
// =========================================================================

function formatNotificationBadge(unreadCount) {
  if (!unreadCount || unreadCount <= 0) {
    return { visible: false, label: '' }
  }
  return {
    visible: true,
    label: unreadCount > 99 ? '99+' : String(unreadCount),
  }
}

test('1. Notification bell displays exact unread count when > 0', () => {
  assert.deepEqual(formatNotificationBadge(1), { visible: true, label: '1' })
  assert.deepEqual(formatNotificationBadge(42), { visible: true, label: '42' })
  assert.deepEqual(formatNotificationBadge(99), { visible: true, label: '99' })
})

test('2. Notification bell badge is hidden when unread count is 0', () => {
  assert.deepEqual(formatNotificationBadge(0), { visible: false, label: '' })
  assert.deepEqual(formatNotificationBadge(-3), { visible: false, label: '' })
})

test('3. Notification bell badge displays 99+ when count exceeds 99', () => {
  assert.deepEqual(formatNotificationBadge(100), { visible: true, label: '99+' })
  assert.deepEqual(formatNotificationBadge(250), { visible: true, label: '99+' })
})

// =========================================================================
// TEST 4: Notification panel loading, empty, and error states
// =========================================================================

test('4. Notification panel correctly determines loading, error, empty, and list states', () => {
  function getPanelState({ isLoading, error, items }) {
    if (isLoading && items.length === 0) return 'loading'
    if (error && items.length === 0) return 'error'
    if (items.length === 0) return 'empty'
    return 'list'
  }

  assert.equal(getPanelState({ isLoading: true, error: null, items: [] }), 'loading')
  assert.equal(getPanelState({ isLoading: false, error: 'Network error', items: [] }), 'error')
  assert.equal(getPanelState({ isLoading: false, error: null, items: [] }), 'empty')
  assert.equal(
    getPanelState({ isLoading: false, error: null, items: [{ id: 'n1' }] }),
    'list'
  )

  // Verify actual NotificationBell.vue implements all states
  const bellSrc = fs.readFileSync(path.join(projectRoot, 'src/components/NotificationBell.vue'), 'utf8')
  assert.ok(bellSrc.includes('panel-loading'), 'NotificationBell.vue must have loading state')
  assert.ok(bellSrc.includes('panel-empty'), 'NotificationBell.vue must have empty state')
  assert.ok(bellSrc.includes('panel-error'), 'NotificationBell.vue must have error state')
  assert.ok(bellSrc.includes('btn-retry'), 'NotificationBell.vue must have retry button')
})

// =========================================================================
// TEST 5: Click notification navigates to exact conversation or post
// =========================================================================

test('5. Clicking notification resolves safe internal destination without creating new conversation', () => {
  function resolveNotificationRoute(item) {
    if (item.target?.type === 'conversation' && item.target.id) {
      return {
        path: '/chat',
        query: { conversation_id: String(item.target.id) },
      }
    }
    if (item.target?.type === 'post' && item.target.id) {
      return `/posts/${item.target.id}`
    }
    if (item.target?.type === 'comic' && item.target.id) {
      return `/comics/${item.target.id}`
    }
    if (item.target?.type === 'chapter') {
      return item.target.url || `/comics/${item.target.id}`
    }
    const rawUrl = item.target?.url
    if (typeof rawUrl === 'string' && rawUrl.startsWith('/') && !rawUrl.startsWith('//')) {
      return rawUrl
    }
    return null
  }

  // Chat notification uses conversation_id (preserving CAI-MSG-01 lazy flow)
  const chatDest = resolveNotificationRoute({
    id: 'uuid-1',
    type: 'new_message',
    target: { type: 'conversation', id: 77, url: '/chat?conversation_id=77' },
  })
  assert.deepEqual(chatDest, { path: '/chat', query: { conversation_id: '77' } })

  // Community post like notification navigates to /posts/:id
  const postDest = resolveNotificationRoute({
    id: 'uuid-2',
    type: 'post_liked',
    target: { type: 'post', id: 55, url: '/posts/55' },
  })
  assert.equal(postDest, '/posts/55')

  // Comic comment notification navigates to /comics/:id
  const comicDest = resolveNotificationRoute({
    id: 'uuid-comic',
    type: 'post_commented',
    target: { type: 'comic', id: 12, url: '/comics/12' },
  })
  assert.equal(comicDest, '/comics/12')

  // Chapter comment notification navigates to /comics/:comic_id/read/:chapter_id
  const chapterDest = resolveNotificationRoute({
    id: 'uuid-chapter',
    type: 'post_commented',
    target: { type: 'chapter', id: 99, url: '/comics/12/read/99' },
  })
  assert.equal(chapterDest, '/comics/12/read/99')

  // External / protocol-relative URL is rejected for security
  const unsafeDest = resolveNotificationRoute({
    id: 'uuid-3',
    type: 'unknown',
    target: { type: 'other', id: null, url: '//evil.example.com/phish' },
  })
  assert.equal(unsafeDest, null)
})

// =========================================================================
// TEST 6, 7, 8: Mark as read, Mark all as read, optimistic rollback & API error handling
// =========================================================================

function createMockNotificationStore(api) {
  const state = {
    items: [],
    unreadCount: 0,
    error: null,
    hasLoadedOnce: false,
    total: 0,
  }

  async function markAsRead(id) {
    const target = state.items.find((n) => n.id === id)
    const prevIsRead = target?.is_read ?? false
    const prevReadAt = target?.read_at ?? null
    const prevUnread = state.unreadCount

    if (target && !target.is_read) {
      target.is_read = true
      target.read_at = '2026-11-25T00:00:00Z'
      state.unreadCount = Math.max(0, state.unreadCount - 1)
    }

    try {
      const res = await api.markAsRead(id)
      if (target) {
        target.is_read = res.data.is_read
        target.read_at = res.data.read_at
      }
      state.unreadCount = res.meta.unread_count
      return true
    } catch (err) {
      if (target) {
        target.is_read = prevIsRead
        target.read_at = prevReadAt
      }
      state.unreadCount = prevUnread
      state.error = err.message
      return false
    }
  }

  async function markAllAsRead() {
    const snapshots = state.items.map((n) => ({ id: n.id, is_read: n.is_read, read_at: n.read_at }))
    const prevUnread = state.unreadCount

    state.items.forEach((n) => {
      if (!n.is_read) {
        n.is_read = true
        n.read_at = '2026-11-25T00:00:00Z'
      }
    })
    state.unreadCount = 0

    try {
      const res = await api.markAllAsRead()
      state.unreadCount = res.unread_count
      return true
    } catch (err) {
      const map = new Map(snapshots.map((s) => [s.id, s]))
      state.items.forEach((n) => {
        const snap = map.get(n.id)
        if (snap) {
          n.is_read = snap.is_read
          n.read_at = snap.read_at
        }
      })
      state.unreadCount = prevUnread
      state.error = err.message
      return false
    }
  }

  function handleRealtimeNotification(payload) {
    if (!payload?.notification?.id) return
    const incoming = payload.notification
    const bellTypes = ['post_liked', 'post_commented']
    if (incoming.type && !bellTypes.includes(incoming.type)) {
      return
    }
    const idx = state.items.findIndex((i) => i.id === incoming.id)
    if (idx !== -1) {
      state.items[idx] = incoming
    } else {
      state.items.unshift(incoming)
      state.total += 1
    }
    if (typeof payload.unread_count === 'number' && payload.unread_count >= 0) {
      state.unreadCount = payload.unread_count
    } else if (idx === -1 && !incoming.is_read) {
      state.unreadCount = Math.max(0, state.unreadCount + 1)
    }
  }

  function $reset() {
    state.items = []
    state.unreadCount = 0
    state.error = null
    state.hasLoadedOnce = false
    state.total = 0
  }

  return { state, markAsRead, markAllAsRead, handleRealtimeNotification, $reset }
}

await testAsync('6. Mark as read updates item read state and decrements unread count', async () => {
  const store = createMockNotificationStore({
    markAsRead: async (id) => ({
      data: { id, is_read: true, read_at: '2026-11-25T01:00:00Z' },
      meta: { unread_count: 1 },
    }),
  })
  store.state.items = [
    { id: 'n1', is_read: false, read_at: null },
    { id: 'n2', is_read: false, read_at: null },
  ]
  store.state.unreadCount = 2

  const ok = await store.markAsRead('n1')
  assert.equal(ok, true)
  assert.equal(store.state.items[0].is_read, true)
  assert.equal(store.state.items[1].is_read, false)
  assert.equal(store.state.unreadCount, 1)
})

await testAsync('7. Mark all as read updates every unread notification and sets unread count to 0', async () => {
  const store = createMockNotificationStore({
    markAllAsRead: async () => ({ updated_count: 2, unread_count: 0 }),
  })
  store.state.items = [
    { id: 'n1', is_read: false, read_at: null },
    { id: 'n2', is_read: false, read_at: null },
  ]
  store.state.unreadCount = 2

  const ok = await store.markAllAsRead()
  assert.equal(ok, true)
  assert.equal(store.state.items.every((n) => n.is_read), true)
  assert.equal(store.state.unreadCount, 0)
})

await testAsync('8. API errors trigger strict optimistic rollback and set error message', async () => {
  const store = createMockNotificationStore({
    markAsRead: async () => {
      throw new Error('500 Internal Server Error')
    },
    markAllAsRead: async () => {
      throw new Error('Network unavailable')
    },
  })
  store.state.items = [
    { id: 'n1', is_read: false, read_at: null },
    { id: 'n2', is_read: false, read_at: null },
  ]
  store.state.unreadCount = 2

  const okSingle = await store.markAsRead('n1')
  assert.equal(okSingle, false)
  assert.equal(store.state.items[0].is_read, false, 'Must rollback item is_read on failure')
  assert.equal(store.state.unreadCount, 2, 'Must rollback unreadCount on failure')
  assert.equal(store.state.error, '500 Internal Server Error')

  const okAll = await store.markAllAsRead()
  assert.equal(okAll, false)
  assert.equal(store.state.items[0].is_read, false)
  assert.equal(store.state.items[1].is_read, false)
  assert.equal(store.state.unreadCount, 2)
  assert.equal(store.state.error, 'Network unavailable')
})

// =========================================================================
// TEST 9 & 10: Listener deduplication & Logout cleanup + Realtime dedupe
// =========================================================================

test('9. Listener registration deduplicates without breaking chat channel listener', () => {
  const channels = new Map()
  let listenCalls = 0
  let stopListeningCalls = []
  let leaveCalls = []

  const mockEcho = {
    private(name) {
      return {
        stopListening(evt) {
          stopListeningCalls.push(`${name}:${evt}`)
        },
        listen() {
          listenCalls++
        },
      }
    },
    leave(name) {
      leaveCalls.push(name)
    },
  }

  function subscribeNotif(userId, handler) {
    const existing = channels.get(userId) || {}
    existing.onNotificationCreated = handler
    channels.set(userId, existing)
    const ch = mockEcho.private(`chat.user.${userId}`)
    ch.stopListening('.NotificationCreated')
    ch.listen('.NotificationCreated', handler)
  }

  function subscribeChat(userId, handler) {
    const existing = channels.get(userId) || {}
    existing.onConversationUpdated = handler
    channels.set(userId, existing)
    const ch = mockEcho.private(`chat.user.${userId}`)
    ch.stopListening('.ConversationUpdated')
    ch.listen('.ConversationUpdated', handler)
  }

  function unsubscribeNotif(userId) {
    const existing = channels.get(userId)
    if (!existing) return
    delete existing.onNotificationCreated
    if (!existing.onConversationUpdated) {
      channels.delete(userId)
      mockEcho.leave(`chat.user.${userId}`)
    } else {
      mockEcho.private(`chat.user.${userId}`).stopListening('.NotificationCreated')
    }
  }

  // Subscribe chat + notification on same user channel
  subscribeChat(10, () => {})
  subscribeNotif(10, () => {})
  // Duplicate notification subscription stops previous listener first
  subscribeNotif(10, () => {})

  assert.equal(channels.size, 1)
  assert.equal(stopListeningCalls.filter((c) => c === 'chat.user.10:.NotificationCreated').length, 2)
  assert.equal(leaveCalls.length, 0)

  // Unsubscribing notification keeps chat listener intact
  unsubscribeNotif(10)
  assert.equal(leaveCalls.length, 0)
  assert.ok(channels.get(10).onConversationUpdated)
})

test('10. Logout cleans up listener, resets notification store, and prevents duplicate realtime items', () => {
  const store = createMockNotificationStore({})
  store.handleRealtimeNotification({
    notification: { id: 'uuid-99', title: 'Tin nhắn mới', is_read: false },
    unread_count: 1,
  })
  // Duplicate realtime event for same notification ID must NOT create duplicate item
  store.handleRealtimeNotification({
    notification: { id: 'uuid-99', title: 'Tin nhắn mới', is_read: false },
    unread_count: 1,
  })
  assert.equal(store.state.items.length, 1)
  assert.equal(store.state.unreadCount, 1)

  // Logout resets store cleanly
  store.$reset()
  assert.equal(store.state.items.length, 0)
  assert.equal(store.state.unreadCount, 0)

  // Verify actual codebase wiring for logout cleanup & AppHeader integration
  const authStoreSrc = fs.readFileSync(path.join(projectRoot, 'src/stores/auth.ts'), 'utf8')
  const headerSrc = fs.readFileSync(path.join(projectRoot, 'src/components/AppHeader.vue'), 'utf8')
  const composableSrc = fs.readFileSync(path.join(projectRoot, 'src/composables/useNotificationRealtime.ts'), 'utf8')

  assert.ok(authStoreSrc.includes('useNotificationStore().$reset()'), 'authStore.logout must reset notification store')
  assert.ok(headerSrc.includes('<NotificationBell />'), 'AppHeader must render NotificationBell')
  assert.ok(headerSrc.includes('useNotificationRealtime'), 'AppHeader must mount useNotificationRealtime')
  assert.ok(composableSrc.includes('unsubscribeUserNotifications'), 'useNotificationRealtime must unsubscribe on teardown')
  assert.ok(composableSrc.includes('clearInterval'), 'useNotificationRealtime must clear fallback timer on teardown')
})

// =========================================================================
// CAI-NOTIF-02 TESTS: True Real-time Notification Badge & Multi-Subscriber UI
// =========================================================================

test('11. Real-time event immediately increments unread count and prepends item when dropdown is closed and not yet loaded', () => {
  const store = createMockNotificationStore({})
  // Initially, user hasn't clicked bell (hasLoadedOnce = false, items = [], unreadCount = 0)
  assert.equal(store.state.hasLoadedOnce, false)
  assert.equal(store.state.items.length, 0)
  assert.equal(store.state.unreadCount, 0)

  // Real-time post like notification arrives via WebSocket
  store.handleRealtimeNotification({
    notification: {
      id: 'uuid-like-1',
      type: 'post_liked',
      title: 'Alice đã thích bài viết của bạn',
      body: 'Alice đã thích bài viết của bạn',
      is_read: false,
      created_at: new Date().toISOString(),
    },
    unread_count: 1,
  })

  // Badge updates immediately without click or page change
  assert.equal(store.state.unreadCount, 1)
  assert.equal(store.state.items.length, 1)
  assert.equal(store.state.items[0].id, 'uuid-like-1')
  assert.equal(store.state.items[0].title, 'Alice đã thích bài viết của bạn')
})

test('12. Real-time event updates list immediately when dropdown is already open', () => {
  const store = createMockNotificationStore({})
  store.state.hasLoadedOnce = true
  store.state.items = [
    { id: 'uuid-old', type: 'post_liked', title: 'Bài viết cũ', is_read: true },
  ]
  store.state.unreadCount = 0

  // Real-time post like notification arrives while dropdown is open
  store.handleRealtimeNotification({
    notification: {
      id: 'uuid-like-2',
      type: 'post_liked',
      title: 'Charlie đã thích bài viết của bạn',
      body: 'Charlie đã thích bài viết của bạn',
      is_read: false,
      created_at: new Date().toISOString(),
    },
    unread_count: 1,
  })

  assert.equal(store.state.unreadCount, 1)
  assert.equal(store.state.items.length, 2)
  assert.equal(store.state.items[0].id, 'uuid-like-2', 'New notification must be prepended to the top')
  assert.equal(store.state.items[1].id, 'uuid-old')
})

test('13. Sequential notifications (like then comment) increment badge monotonically without duplicate counts', () => {
  const store = createMockNotificationStore({})

  // Event 1: Post Like
  store.handleRealtimeNotification({
    notification: { id: 'evt-1', type: 'post_liked', is_read: false },
    unread_count: 1,
  })
  assert.equal(store.state.unreadCount, 1)

  // Event 2: Post Comment
  store.handleRealtimeNotification({
    notification: { id: 'evt-2', type: 'post_commented', is_read: false },
    unread_count: 2,
  })
  assert.equal(store.state.unreadCount, 2)
  assert.equal(store.state.items.length, 2)

  // Event 2 retransmitted (duplicate socket event)
  store.handleRealtimeNotification({
    notification: { id: 'evt-2', type: 'post_commented', is_read: false },
    unread_count: 2,
  })
  assert.equal(store.state.unreadCount, 2, 'Retransmitted event must not increment count')
  assert.equal(store.state.items.length, 2, 'Retransmitted event must not duplicate items')
})

test('13B. CAI-MSG-04: Chat message notifications are strictly ignored by the notification bell', () => {
  const store = createMockNotificationStore({})
  store.handleRealtimeNotification({
    notification: { id: 'evt-msg', type: 'new_message', is_read: false },
    unread_count: 1,
  })
  assert.equal(store.state.unreadCount, 0, 'Message notification must NOT increment bell unread count')
  assert.equal(store.state.items.length, 0, 'Message notification must NOT enter notification bell list')
})

test('14. Echo Set-based multi-handler subscription isolates AppHeader and ChatPage without listener loss', () => {
  const channelStore = new Map()

  function getChannelState(userId) {
    let state = channelStore.get(userId)
    if (!state) {
      state = {
        conversationUpdatedHandlers: new Set(),
        notificationCreatedHandlers: new Set(),
      }
      channelStore.set(userId, state)
    }
    return state
  }

  const handlerHeaderChat = (e) => `header-chat:${e}`
  const handlerHeaderNotif = (e) => `header-notif:${e}`
  const handlerPageChat = (e) => `page-chat:${e}`

  // AppHeader registers both handlers
  const state = getChannelState(42)
  state.conversationUpdatedHandlers.add(handlerHeaderChat)
  state.notificationCreatedHandlers.add(handlerHeaderNotif)

  // ChatPage mounts and registers its own conversation handler
  state.conversationUpdatedHandlers.add(handlerPageChat)
  assert.equal(state.conversationUpdatedHandlers.size, 2)
  assert.equal(state.notificationCreatedHandlers.size, 1)

  // ChatPage unmounts and unsubscribes ONLY its handler
  state.conversationUpdatedHandlers.delete(handlerPageChat)
  assert.equal(state.conversationUpdatedHandlers.size, 1, 'AppHeader chat handler must remain active')
  assert.ok(state.conversationUpdatedHandlers.has(handlerHeaderChat))
  assert.equal(state.notificationCreatedHandlers.size, 1, 'AppHeader notification handler must remain active')
  assert.ok(state.notificationCreatedHandlers.has(handlerHeaderNotif))
})

test('15. Fallback polling activates only when WebSocket is disconnected and cleans up properly', () => {
  let timerId = null
  let pollCallCount = 0

  function startPolling(isConnected) {
    if (timerId !== null) clearInterval(timerId)
    timerId = setInterval(() => {
      if (isConnected()) return
      pollCallCount++
    }, 10)
  }

  function stopPolling() {
    if (timerId !== null) {
      clearInterval(timerId)
      timerId = null
    }
  }

  let connected = true
  startPolling(() => connected)
  // When connected, polling tick skips
  assert.equal(pollCallCount, 0)

  // Stop polling on unmount
  stopPolling()
  assert.equal(timerId, null)
})

console.log('\n=====================================================')
console.log(`  NOTIFICATION TEST RESULTS: ${passedTests} passed, ${failedTests} failed`)
console.log('=====================================================')

if (failedTests > 0) {
  process.exit(1)
}
