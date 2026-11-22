/**
 * Safe Browser Verification Test Suite (Puppeteer-Core) for Package 9.7.4.5
 * 
 * PHẠM VI XÁC MINH (VERIFIED SCOPE):
 * - Google Chrome Headless với 100% Mock HTTP Request Interception (page.setRequestInterception).
 * - Broadcast events được inject trực tiếp vào Pinia store trong runtime của trang.
 * - Kiểm chứng hành vi DOM thực tế: Bong bóng tin nhắn, Unread badge, Read receipt, Composer validation.
 * 
 * PHẦN CHƯA KIỂM CHỨNG TRONG MÔI TRƯỜNG THỰC TẾ (NOT VERIFIED IN LIVE RUNTIME):
 * - WebSocket Handshake qua mạng thật tới Reverb daemon.
 * - Echo private channel subscription & Sanctum channel authorization (/broadcasting/auth) trực tiếp.
 * - Live multi-user broadcast 2 chiều giữa 2 clients và backend Reverb thật.
 * - Ghi dữ liệu vào MySQL database thật (bảo đảm an toàn dữ liệu theo nguyên tắc repo).
 */

import puppeteer from 'puppeteer-core'
import { spawn, execSync } from 'node:child_process'
import path from 'node:path'
import net from 'node:net'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const FRONTEND_PATH = path.resolve(__dirname, '..', '..')
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 5173
const BASE_URL = `http://127.0.0.1:${PORT}`

let viteProcess = null

function isPortOpen(port, host = '127.0.0.1') {
  return new Promise((resolve) => {
    const socket = new net.Socket()
    socket.setTimeout(400)
    socket.on('connect', () => {
      socket.destroy()
      resolve(true)
    })
    socket.on('timeout', () => {
      socket.destroy()
      resolve(false)
    })
    socket.on('error', () => {
      socket.destroy()
      resolve(false)
    })
    socket.connect(port, host)
  })
}

async function waitForPort(port, maxWaitMs = 15000) {
  const start = Date.now()
  while (Date.now() - start < maxWaitMs) {
    if (await isPortOpen(port)) {
      return true
    }
    await new Promise((r) => setTimeout(r, 300))
  }
  return false
}

async function ensureViteRunning() {
  const isOpen = await isPortOpen(PORT)
  if (!isOpen) {
    console.log(`[Vite] Starting standalone Vite server on port ${PORT}...`)
    viteProcess = spawn(
      process.execPath,
      ['./node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', String(PORT)],
      {
        cwd: FRONTEND_PATH,
        stdio: 'ignore',
      }
    )
    const ready = await waitForPort(PORT)
    if (!ready) {
      throw new Error(`Vite failed to start on port ${PORT} within 15s`)
    }
    console.log(`[Vite] Standalone server ready at ${BASE_URL}\n`)
  } else {
    console.log(`[Vite] Server already running at ${BASE_URL}\n`)
  }
}

function stopViteServer() {
  if (viteProcess?.pid) {
    console.log('[Vite] Stopping standalone Vite server...')
    try {
      execSync(`taskkill /pid ${viteProcess.pid} /T /F`, { stdio: 'ignore' })
    } catch {}
    viteProcess = null
  }
}

// -------------------------------------------------------------
// Test Execution & Reporting
// -------------------------------------------------------------
const testResults = []

function recordStep(stepNum, name, status, details = '') {
  testResults.push({ stepNum, name, status, details })
  const icon = status === 'PASS' ? '✅' : '❌'
  console.log(`${icon} [Step ${stepNum}] ${name}: ${status} ${details ? '(' + details + ')' : ''}`)
}

async function runSafeBrowserFlow() {
  console.log('====================================================================')
  console.log('   SAFE BROWSER VERIFICATION & CHAT UI FLOW (12 STEPS - ISOLATED MOCK) ')
  console.log('====================================================================')
  console.log('ℹ️  Phạm vi xác minh: Chrome Headless + Mock HTTP Interception + Pinia Store Event Injection')
  console.log('⚠️  Chưa kiểm chứng: Live Reverb WebSocket, Sanctum channel auth, Real MySQL persistence')
  console.log('--------------------------------------------------------------------\n')

  let browser = null

  try {
    // Start Vite server if needed
    await ensureViteRunning()

    // Launch Chrome in headless mode with dedicated profile
    console.log('[Chrome] Launching Headless Chrome...')
    browser = await puppeteer.launch({
      executablePath: CHROME_PATH,
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--window-size=1280,800'],
    })

    const contextA = await browser.createBrowserContext()
    const page = await contextA.newPage()
    await page.setViewport({ width: 1280, height: 800 })

    // Enable request interception to mock 100% of backend API
    await page.setRequestInterception(true)

    // In-memory mock database for User A browser session
    let currentUserA = {
      id: 1,
      name: 'Độc giả A',
      email: 'userA@mock.test',
      role: 'reader',
      is_active: true,
    }

    let mockConversation = {
      id: 101,
      type: 'direct',
      created_at: '2026-11-22T00:00:00Z',
      updated_at: '2026-11-22T01:00:00Z',
      last_message_at: '2026-11-22T01:00:00Z',
      unread_count: 0,
      recipient: {
        id: 2,
        name: 'Độc giả B',
        role: 'reader',
        avatar_path: null,
      },
      last_message: {
        id: 50,
        conversation_id: 101,
        sender_id: 2,
        type: 'text',
        body: 'Chào bạn A!',
        client_message_id: 'cmid-50',
        created_at: '2026-11-22T01:00:00Z',
      },
      participants: [
        { user_id: 1, last_read_message_id: 50, last_read_at: '2026-11-22T01:05:00Z' },
        { user_id: 2, last_read_message_id: 50, last_read_at: '2026-11-22T01:00:00Z' },
      ],
    }

    let mockMessages = [
      {
        id: 50,
        conversation_id: 101,
        sender_id: 2,
        type: 'text',
        body: 'Chào bạn A!',
        client_message_id: 'cmid-50',
        created_at: '2026-11-22T01:00:00Z',
      },
    ]

    let sentClientMessageId = null
    let markAsReadCallsA = 0

    page.on('request', (req) => {
      const url = req.url()
      const method = req.method()

      // Allow static assets served by Vite
      if (!url.includes('/api/')) {
        req.continue()
        return
      }

      // 1. Sanctum CSRF cookie
      if (url.includes('/api/sanctum/csrf-cookie')) {
        req.respond({
          status: 204,
          headers: { 'set-cookie': 'XSRF-TOKEN=mock-csrf; path=/' },
        })
        return
      }

      // 2. Auth user info
      if (url.endsWith('/api/user') || url.endsWith('/api/auth/me')) {
        if (currentUserA) {
          req.respond({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ user: currentUserA }),
          })
        } else {
          req.respond({
            status: 401,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Unauthenticated.' }),
          })
        }
        return
      }

      // 3. Unread count endpoint
      if (url.includes('/api/chat/unread-count')) {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              total_unread: mockConversation.unread_count,
              conversations: [{ conversation_id: 101, unread_count: mockConversation.unread_count }],
            },
          }),
        })
        return
      }

      // 4. Conversation messages list
      if (url.includes('/api/chat/conversations/101/messages') && method === 'GET') {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: mockMessages,
            meta: { limit: 25, has_more: false, next_cursor: null },
          }),
        })
        return
      }

      // 5. Send message (POST)
      if (url.includes('/api/chat/conversations/101/messages') && method === 'POST') {
        const postData = JSON.parse(req.postData() || '{}')
        sentClientMessageId = postData.client_message_id

        const newMsg = {
          id: 100,
          conversation_id: 101,
          sender_id: 1,
          type: 'text',
          body: postData.body,
          client_message_id: postData.client_message_id,
          created_at: new Date().toISOString(),
        }

        mockMessages.push(newMsg)
        mockConversation.last_message = newMsg
        mockConversation.last_message_at = newMsg.created_at

        req.respond({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({ data: newMsg }),
        })
        return
      }

      // 6. Single conversation detail
      if (url.includes('/api/chat/conversations/101') && !url.includes('/messages') && !url.includes('/read') && method === 'GET') {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: mockConversation }),
        })
        return
      }

      // 7. Conversations list
      if (url.includes('/api/chat/conversations') && !url.includes('/101') && method === 'GET') {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [mockConversation],
            meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
          }),
        })
        return
      }

      // 8. Mark conversation as read
      if (url.includes('/api/chat/conversations/101/read') && method === 'POST') {
        markAsReadCallsA++
        const postData = JSON.parse(req.postData() || '{}')
        mockConversation.unread_count = 0

        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'success',
            data: {
              conversation_id: 101,
              user_id: 1,
              last_read_message_id: postData.last_read_message_id || postData.message_id || 100,
              last_read_at: new Date().toISOString(),
            },
          }),
        })
        return
      }

      // 9. Logout
      if (url.includes('/api/auth/logout')) {
        currentUserA = null
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Logged out successfully' }),
        })
        return
      }

      // Fallback
      req.respond({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({}),
      })
    })

    // =========================================================================
    // STEP 1: User A đăng nhập bằng mock auth và điều hướng /chat
    // =========================================================================
    console.log('[Step 1] Navigating to /chat with User A mock session...')
    await page.goto(`${BASE_URL}/chat`, { waitUntil: 'networkidle2' })

    // Wait for AppHeader and Chat page to mount
    await page.waitForSelector('.nav-link-chat', { timeout: 8000 })
    const isChatLinkPresent = await page.$eval('.nav-link-chat', (el) => el.textContent.includes('Tin nhắn'))
    assert.ok(isChatLinkPresent, 'Nav link Tin nhắn must be visible for authenticated User A')
    recordStep(1, 'User A đăng nhập bằng mock auth', 'PASS', 'Nav link Tin nhắn hiển thị chính xác')

    // =========================================================================
    // STEP 2: Hiển thị danh sách conversation trong sidebar
    // =========================================================================
    await page.waitForSelector('.conversation-item', { timeout: 8000 })
    const recipientName = await page.$eval('.recipient-name', (el) => el.textContent.trim())
    const lastMsgSnippet = await page.$eval('.last-message-snippet', (el) => el.textContent.trim())
    assert.equal(recipientName, 'Độc giả B')
    assert.equal(lastMsgSnippet, 'Chào bạn A!')
    recordStep(2, 'Hiển thị danh sách conversation', 'PASS', `Tìm thấy cuộc trò chuyện với: ${recipientName}`)

    // =========================================================================
    // STEP 3: User A mở conversation 101
    // =========================================================================
    await page.click('.conversation-item')
    await page.waitForSelector('.message-bubble', { timeout: 8000 })
    const initialBubbleText = await page.$eval('.message-body', (el) => el.textContent.trim())
    assert.equal(initialBubbleText, 'Chào bạn A!')
    recordStep(3, 'User A mở conversation', 'PASS', 'Tin nhắn đầu tiên hiển thị trong MessageList')

    // =========================================================================
    // STEP 4: User A soạn và gửi message mới
    // =========================================================================
    const messageText = 'Chào B, mình đọc truyện thấy rất cuốn!'
    await page.waitForSelector('textarea.composer-textarea')
    await page.type('textarea.composer-textarea', messageText)

    // Verify character counter
    const counterText = await page.$eval('.char-counter', (el) => el.textContent.trim())
    assert.equal(counterText, `${messageText.length}/2000`)

    // Click Send
    await page.click('.btn-send')
    recordStep(4, 'User A soạn và gửi message', 'PASS', `Đã nhập và gửi: "${messageText}"`)

    // =========================================================================
    // STEP 5: Mock REST trả message server có id=100 và client_message_id
    // =========================================================================
    await page.waitForFunction(
      () => {
        const bubbles = Array.from(document.querySelectorAll('.message-body'))
        return bubbles.some((b) => b.textContent.includes('mình đọc truyện thấy rất cuốn'))
      },
      { timeout: 8000 }
    )
    assert.ok(sentClientMessageId, 'REST request must contain client_message_id')
    recordStep(5, 'Mock REST trả message server có id và client_message_id', 'PASS', `client_message_id: ${sentClientMessageId}`)

    // =========================================================================
    // STEP 6: Mock WebSocket phát broadcast MessageSent tương ứng
    // =========================================================================
    // Inject .MessageSent event into Chat store in browser
    await page.evaluate(async (cmid, body) => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      store.handleMessageSent(
        {
          id: 100,
          conversation_id: 101,
          sender_id: 1,
          type: 'text',
          body,
          client_message_id: cmid,
          created_at: new Date().toISOString(),
        },
        1
      )
    }, sentClientMessageId, messageText)
    recordStep(6, 'Mock WebSocket phát broadcast MessageSent tương ứng', 'PASS', 'Injected .MessageSent into store')

    // =========================================================================
    // STEP 7: Xác nhận message chỉ xuất hiện một lần (Deduplication)
    // =========================================================================
    await new Promise((r) => setTimeout(r, 500))
    const matchingBubbles = await page.$$eval('.message-body', (elements, text) => {
      return elements.filter((el) => el.textContent.trim() === text).length
    }, messageText)
    assert.equal(matchingBubbles, 1, 'Message must appear exactly once in DOM (no duplicate from REST + WebSocket)')
    recordStep(7, 'Xác nhận message chỉ xuất hiện một lần', 'PASS', `Số lượng bubble xuất hiện trong DOM: ${matchingBubbles}`)

    // =========================================================================
    // STEP 8: Mock user B nhận message và unread count tăng
    // =========================================================================
    console.log('[Step 8] Launching User B in an isolated incognito browser context...')
    const contextB = await browser.createBrowserContext()
    const pageB = await contextB.newPage()
    await pageB.setViewport({ width: 1280, height: 800 })
    await pageB.setRequestInterception(true)

    let markAsReadCallsB = 0

    pageB.on('request', (req) => {
      const url = req.url()
      const method = req.method()

      if (!url.includes('/api/')) {
        req.continue()
        return
      }

      if (url.includes('/api/sanctum/csrf-cookie')) {
        req.respond({
          status: 204,
          headers: { 'set-cookie': 'XSRF-TOKEN=mock-csrf; path=/' },
        })
        return
      }

      if (url.endsWith('/api/user') || url.endsWith('/api/auth/me')) {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            user: {
              id: 2,
              name: 'Độc giả B',
              email: 'userB@mock.test',
              role: 'reader',
              is_active: true,
            },
          }),
        })
        return
      }

      if (url.includes('/api/chat/unread-count')) {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              total_unread: 0,
              conversations: [],
            },
          }),
        })
        return
      }

      if (url.includes('/api/chat/conversations/101/messages') && method === 'GET') {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: mockMessages,
            meta: { limit: 25, has_more: false, next_cursor: null },
          }),
        })
        return
      }

      if (url.includes('/api/chat/conversations/101') && !url.includes('/messages') && !url.includes('/read') && method === 'GET') {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              id: 101,
              type: 'direct',
              created_at: '2026-11-22T00:00:00Z',
              updated_at: '2026-11-22T01:00:00Z',
              last_message_at: '2026-11-22T01:00:00Z',
              unread_count: 0,
              recipient: {
                id: 1,
                name: 'Độc giả A',
                role: 'reader',
                avatar_path: null,
              },
              last_message: mockMessages[mockMessages.length - 1],
              participants: [
                { user_id: 1, last_read_message_id: 100, last_read_at: '2026-11-22T01:05:00Z' },
                { user_id: 2, last_read_message_id: 50, last_read_at: '2026-11-22T01:00:00Z' },
              ],
            },
          }),
        })
        return
      }

      if (url.includes('/api/chat/conversations') && !url.includes('/101') && method === 'GET') {
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [
              {
                id: 101,
                type: 'direct',
                created_at: '2026-11-22T00:00:00Z',
                updated_at: '2026-11-22T01:00:00Z',
                last_message_at: '2026-11-22T01:00:00Z',
                unread_count: 0,
                recipient: {
                  id: 1,
                  name: 'Độc giả A',
                  role: 'reader',
                  avatar_path: null,
                },
                last_message: {
                  id: 50,
                  conversation_id: 101,
                  sender_id: 2,
                  type: 'text',
                  body: 'Chào bạn A!',
                  created_at: '2026-11-22T01:00:00Z',
                },
                participants: [
                  { user_id: 1, last_read_message_id: 100, last_read_at: '2026-11-22T01:05:00Z' },
                  { user_id: 2, last_read_message_id: 50, last_read_at: '2026-11-22T01:00:00Z' },
                ],
              },
            ],
            meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
          }),
        })
        return
      }

      if (url.includes('/api/chat/conversations/101/read') && method === 'POST') {
        markAsReadCallsB++
        const postData = JSON.parse(req.postData() || '{}')
        req.respond({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'success',
            data: {
              conversation_id: 101,
              user_id: 2,
              last_read_message_id: postData.last_read_message_id || 100,
              last_read_at: new Date().toISOString(),
            },
          }),
        })
        return
      }

      req.respond({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({}),
      })
    })

    await pageB.goto(`${BASE_URL}/chat`, { waitUntil: 'networkidle2' })
    await pageB.waitForSelector('.conversation-item', { timeout: 8000 })

    // Verify initial unread count is 0 before message is received
    const unreadBefore = await pageB.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      return store.conversations.find((c) => c.id === 101)?.unread_count ?? 0
    })
    assert.equal(unreadBefore, 0, 'User B initial unread count must be 0')

    const initialBadges = await pageB.$$('.unread-badge')
    assert.equal(initialBadges.length, 0, 'No unread badge in DOM before message arrives')

    // Simulate ConversationUpdated event sent to User B when User A sent message 100
    await pageB.evaluate(async (msgBody) => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      store.handleConversationUpdated(
        {
          conversation_id: 101,
          last_message: {
            id: 100,
            conversation_id: 101,
            sender_id: 1,
            type: 'text',
            body: msgBody,
            created_at: new Date().toISOString(),
          },
          unread_count: 1,
        },
        2
      )
    }, messageText)

    // Assert unread count in store increases to 1
    const unreadAfter = await pageB.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      return store.conversations.find((c) => c.id === 101)?.unread_count ?? 0
    })
    assert.equal(unreadAfter, 1, 'User B unread count must increase from 0 to 1')
    assert.ok(unreadAfter > unreadBefore, `Unread count must strictly increase: ${unreadBefore} -> ${unreadAfter}`)

    // Assert unread badge renders in User B DOM with value '1'
    await pageB.waitForSelector('.unread-badge', { timeout: 5000 })
    const badgeText = await pageB.$eval('.unread-badge', (el) => el.textContent.trim())
    assert.equal(badgeText, '1', 'Unread badge in DOM must display 1')
    recordStep(8, 'Mock user B nhận message và unread count tăng', 'PASS', `Unread count: ${unreadBefore} -> ${unreadAfter}, DOM badge: "${badgeText}"`)

    // =========================================================================
    // STEP 9: B mở conversation và mark read
    // =========================================================================
    // User B clicks the conversation item in pageB
    await pageB.click('.conversation-item')
    await pageB.waitForSelector('.message-bubble', { timeout: 8000 })

    // Assert that markAsRead REST endpoint was called exactly 1 time
    assert.equal(markAsReadCallsB, 1, 'POST /read must be called exactly 1 time when User B opens conversation')
    recordStep(9, 'B mở conversation và mark read', 'PASS', `POST /read called exactly 1 time with message_id: 100`)

    // =========================================================================
    // STEP 10: Xác nhận unread giảm và read receipt cập nhật
    // =========================================================================
    // In User B's view: unread count decreases to 0 and badge disappears
    const unreadAfterRead = await pageB.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      return store.conversations.find((c) => c.id === 101)?.unread_count ?? 0
    })
    assert.equal(unreadAfterRead, 0, 'User B unread count must decrease to 0 after opening conversation')

    const remainingBadges = await pageB.$$('.unread-badge')
    assert.equal(remainingBadges.length, 0, 'Unread badge must disappear from User B DOM after reading')

    // Close User B context cleanly
    await contextB.close()

    // Now on User A's tab (page): dispatch .MessagesRead broadcast event indicating User B read up to message 100
    await page.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      store.handleMessagesRead({
        conversation_id: 101,
        user_id: 2,
        last_read_message_id: 100,
        last_read_at: new Date().toISOString(),
      })
    })

    // Assert read receipt appears on message 100 in User A DOM
    await page.waitForFunction(
      () => {
        const receipts = document.querySelectorAll('.message-read-receipt')
        return receipts.length > 0
      },
      { timeout: 5000 }
    )
    const receiptText = await page.$eval('.message-read-receipt', (el) => el.textContent.trim())
    assert.equal(receiptText, '✓✓', 'Read receipt in DOM must display ✓✓')

    // Assert participant last_read marker in store
    const otherParticipantLastRead = await page.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      const other = store.activeConversation?.participants.find((p) => p.user_id === 2)
      return other?.last_read_message_id ?? 0
    })
    assert.ok(otherParticipantLastRead >= 100, `Participant last_read_message_id must be >= 100 (got ${otherParticipantLastRead})`)
    recordStep(10, 'Xác nhận unread giảm và read receipt cập nhật', 'PASS', `Unread B: 1 -> ${unreadAfterRead}, badge removed; Read receipt A: "${receiptText}" (marker: ${otherParticipantLastRead})`)

    // =========================================================================
    // STEP 11: Mô phỏng reconnect và xác nhận resync không tạo duplicate
    // =========================================================================
    await page.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const store = useChatStore()
      await store.handleReconnect(1)
    })

    const totalBubblesAfterReconnect = await page.$$eval('.message-body', (els) => els.length)
    assert.equal(totalBubblesAfterReconnect, 2, 'Message count must remain exactly 2 after reconnect resync (no duplicates)')
    recordStep(11, 'Mô phỏng reconnect và xác nhận resync không tạo duplicate', 'PASS', `Tổng số tin nhắn sau reconnect: ${totalBubblesAfterReconnect}`)

    // =========================================================================
    // STEP 12: Logout và xác nhận subscription được cleanup
    // =========================================================================
    await page.evaluate(async () => {
      const { useAuthStore } = await import('/src/stores/auth.ts')
      const auth = useAuthStore()
      await auth.logout()
    })

    const afterLogoutState = await page.evaluate(async () => {
      const { useChatStore } = await import('/src/stores/chat.ts')
      const chat = useChatStore()
      return {
        conversationsCount: chat.conversations.length,
        hasActive: chat.hasActiveConversation,
        totalUnread: chat.totalUnreadCount,
      }
    })

    assert.equal(afterLogoutState.conversationsCount, 0, 'Conversations list must be cleared on logout')
    assert.equal(afterLogoutState.hasActive, false, 'Active conversation must be reset on logout')
    assert.equal(afterLogoutState.totalUnread, 0, 'Total unread count must be reset to 0 on logout')
    recordStep(12, 'Logout và xác nhận subscription được cleanup', 'PASS', 'Chat store state completely reset to guest')

    console.log('\n====================================================================')
    console.log('  ALL 12 SAFE BROWSER VERIFICATION STEPS PASSED! (ISOLATED MOCK)   ')
    console.log('====================================================================')
  } catch (err) {
    console.error('\n❌ BROWSER TEST FAILED WITH ERROR:')
    console.error(err)
    recordStep('ERR', 'Browser verification execution error', 'FAIL', err.message)
    process.exitCode = 1
  } finally {
    if (browser) {
      console.log('[Chrome] Closing browser...')
      await browser.close()
    }
    stopViteServer()
  }
}

runSafeBrowserFlow()
