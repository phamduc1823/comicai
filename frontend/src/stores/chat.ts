import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { ChatService } from '@/services/chatService'
import { useAuthStore } from '@/stores/auth'
import type {
  Conversation,
  Message,
  MessagesPaginationMeta,
  MessageSentPayload,
  ConversationUpdatedPayload,
  MessagesReadPayload,
} from '@/types/chat'
import { getErrorMessage } from '@/services/httpClient'

export const useChatStore = defineStore('chat', () => {
  // State
  const conversations = ref<Conversation[]>([])
  const activeConversation = ref<Conversation | null>(null)
  const activeMessages = ref<Message[]>([])
  const messagesPagination = ref<MessagesPaginationMeta>({
    limit: 25,
    has_more: false,
    next_cursor: null,
  })
  const totalUnreadCount = ref<number>(0)
  const isChatViewActive = ref<boolean>(false)
  const isWindowVisible = ref<boolean>(
    typeof document !== 'undefined' ? document.visibilityState === 'visible' : true
  )
  const isLoadingConversations = ref<boolean>(false)
  const isLoadingMessages = ref<boolean>(false)
  const isSendingMessage = ref<boolean>(false)
  const error = ref<string | null>(null)

  // In-flight sync flag to prevent concurrent duplicate sync requests
  let isSyncingUnread = false
  let debouncedSyncTimer: ReturnType<typeof setTimeout> | null = null
  let openConversationToken = 0

  // Idempotency state: preserve client_message_id across retries for the same draft text
  let lastDraftText = ''
  let lastClientMessageId: string | null = null

  function getOrCreateClientMessageId(text: string): string {
    if (lastClientMessageId && lastDraftText === text) {
      return lastClientMessageId
    }
    const newId = typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : `cmid-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
    lastDraftText = text
    lastClientMessageId = newId
    return newId
  }

  function clearClientMessageId(): void {
    lastDraftText = ''
    lastClientMessageId = null
  }

  function debouncedSyncUnread(): void {
    if (debouncedSyncTimer) {
      clearTimeout(debouncedSyncTimer)
    }
    debouncedSyncTimer = setTimeout(() => {
      fetchUnreadCount()
      debouncedSyncTimer = null
    }, 1500)
  }

  // Computed
  const activeConversationId = computed(() => activeConversation.value?.id ?? null)
  const hasActiveConversation = computed(() => activeConversation.value !== null)

  /**
   * Sort conversations list with newest last_message_at at the top.
   */
  function sortConversations(): void {
    conversations.value.sort((a, b) => {
      const timeA = a.last_message_at ? new Date(a.last_message_at).getTime() : 0
      const timeB = b.last_message_at ? new Date(b.last_message_at).getTime() : 0
      if (timeA !== timeB) {
        return timeB - timeA
      }
      return b.id - a.id
    })
  }

  /**
   * Recalculate total unread count from existing conversations list.
   */
  function updateComputedTotalUnread(): void {
    if (conversations.value.length > 0) {
      totalUnreadCount.value = conversations.value.reduce(
        (sum, conv) => sum + (conv.unread_count || 0),
        0
      )
    }
  }

  /**
   * Fetch conversations list from REST API.
   */
  async function fetchConversations(page = 1, perPage = 20): Promise<void> {
    isLoadingConversations.value = true
    error.value = null

    try {
      const response = await ChatService.getConversations(page, perPage)
      conversations.value = response.data
      sortConversations()
      updateComputedTotalUnread()
    } catch (err) {
      error.value = getErrorMessage(err, 'Không thể tải danh sách cuộc trò chuyện.')
    } finally {
      isLoadingConversations.value = false
    }
  }

  /**
   * Open a conversation, fetch its messages, and mark as read.
   */
  async function openConversation(conversationId: number, currentUserId?: number): Promise<void> {
    const token = ++openConversationToken
    isLoadingMessages.value = true
    error.value = null

    try {
      const [convData, messagesData] = await Promise.all([
        ChatService.getConversation(conversationId),
        ChatService.getMessages(conversationId),
      ])

      // If user switched to another conversation while loading, discard this response
      if (token !== openConversationToken) {
        return
      }

      activeConversation.value = convData
      activeMessages.value = messagesData.data
      messagesPagination.value = messagesData.meta

      // Update in conversations list if present
      const index = conversations.value.findIndex((c) => c.id === conversationId)
      if (index !== -1) {
        conversations.value[index] = { ...conversations.value[index], ...convData, unread_count: 0 }
      } else {
        conversations.value.push({ ...convData, unread_count: 0 })
        sortConversations()
      }
      updateComputedTotalUnread()

      // If there are messages and the latest message is from the other user, mark as read
      if (messagesData.data.length > 0 && currentUserId) {
        const latestMessage = messagesData.data[messagesData.data.length - 1]
        if (Number(latestMessage.sender_id) !== Number(currentUserId)) {
          await markAsRead(latestMessage.id, currentUserId)
        }
      }
    } catch (err) {
      if (token === openConversationToken) {
        error.value = getErrorMessage(err, 'Không thể mở cuộc trò chuyện.')
      }
    } finally {
      if (token === openConversationToken) {
        isLoadingMessages.value = false
      }
    }
  }

  /**
   * Open a draft conversation with a recipient without creating a database record.
   * Enables lazy conversation creation upon first message send.
   */
  function openDraftConversation(recipient: import('@/types/chat').ChatUserSummary): void {
    ++openConversationToken
    activeConversation.value = {
      id: 0,
      type: 'direct',
      recipient,
      last_message: null,
      last_message_at: null,
      participants: [],
      unread_count: 0,
      created_at: null,
      updated_at: null,
    }
    activeMessages.value = []
    messagesPagination.value = {
      limit: 25,
      has_more: false,
      next_cursor: null,
    }
    error.value = null
    isLoadingMessages.value = false
  }

  /**
   * Close active conversation.
   */
  function closeConversation(): void {
    activeConversation.value = null
    activeMessages.value = []
    messagesPagination.value = {
      limit: 25,
      has_more: false,
      next_cursor: null,
    }
  }

  /**
   * Set chat view active status (true when ChatPage.vue is mounted and visible).
   */
  function setChatViewActive(active: boolean): void {
    isChatViewActive.value = active
  }

  /**
   * Set browser window / tab visibility status.
   */
  function setWindowVisible(visible: boolean): void {
    isWindowVisible.value = visible
  }

  /**
   * Check whether a conversation is genuinely being actively viewed by the user right now.
   * Requires:
   * 1. User is on /chat view (isChatViewActive === true).
   * 2. Browser tab is visible (not hidden/minimized).
   * 3. activeConversation is non-null and matching conversationId.
   */
  function isConversationActivelyViewed(conversationId: number): boolean {
    if (!isChatViewActive.value) {
      return false
    }
    if (!isWindowVisible.value) {
      return false
    }
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
      return false
    }
    if (!activeConversation.value) {
      return false
    }
    return Number(activeConversation.value.id) === Number(conversationId)
  }

  /**
   * Check if an incoming message can transition an open draft conversation with this recipient.
   */
  function canTransitionDraft(senderId: number): boolean {
    if (!isChatViewActive.value || !isWindowVisible.value) {
      return false
    }
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
      return false
    }
    return (
      activeConversation.value !== null &&
      Number(activeConversation.value.id) === 0 &&
      activeConversation.value.recipient !== null &&
      Number(activeConversation.value.recipient?.id) === Number(senderId)
    )
  }

  /**
   * Load older messages (scroll up).
   */
  async function loadOlderMessages(): Promise<void> {
    if (!activeConversation.value || !messagesPagination.value.has_more || isLoadingMessages.value) {
      return
    }

    const firstMessage = activeMessages.value[0]
    if (!firstMessage) {
      return
    }

    const convId = activeConversation.value.id
    isLoadingMessages.value = true
    try {
      const response = await ChatService.getMessages(convId, {
        limit: messagesPagination.value.limit,
        before_id: firstMessage.id,
      })

      // Guard: if user switched conversations while request was in-flight, discard
      if (!activeConversation.value || activeConversation.value.id !== convId) {
        return
      }

      // Prepend older messages while maintaining chronological order
      activeMessages.value = [...response.data, ...activeMessages.value]
      messagesPagination.value = response.meta
    } catch (err) {
      if (activeConversation.value && activeConversation.value.id === convId) {
        error.value = getErrorMessage(err, 'Không thể tải tin nhắn cũ.')
      }
    } finally {
      if (activeConversation.value && activeConversation.value.id === convId) {
        isLoadingMessages.value = false
      }
    }
  }

  /**
   * Send a new message to active conversation with instant Optimistic UI.
   * Immediately adds the message to activeMessages and updates sidebar preview,
   * then reconciles with server response or marks as failed on network error.
   */
  async function sendMessage(body: string, type = 'text', overrideUserId?: number): Promise<Message | null> {
    const trimmed = body.trim()
    if (!activeConversation.value || !trimmed) {
      return null
    }

    const convId = Number(activeConversation.value.id)
    const clientMessageId = getOrCreateClientMessageId(trimmed)
    const authStore = useAuthStore()
    const currentUserId = Number(overrideUserId ?? authStore.user?.id ?? 0)

    // Handle lazy conversation creation for draft conversations (id === 0)
    if (convId === 0) {
      if (!activeConversation.value.recipient) {
        return null
      }
      const recipientId = activeConversation.value.recipient.id
      isSendingMessage.value = true
      error.value = null

      const tempId = -Date.now()
      const optimisticMsg: Message = {
        id: tempId,
        conversation_id: 0,
        sender_id: currentUserId,
        type,
        body: trimmed,
        client_message_id: clientMessageId,
        created_at: new Date().toISOString(),
        status: 'sending',
      }
      activeMessages.value = [optimisticMsg]

      try {
        const res = await ChatService.sendDirectMessage(recipientId, {
          body: trimmed,
          type,
          client_message_id: clientMessageId,
        })

        clearClientMessageId()

        // Transition from draft to persisted conversation
        activeConversation.value = res.conversation
        activeMessages.value = [{ ...res.data, status: 'sent' }]
        messagesPagination.value = {
          limit: 25,
          has_more: false,
          next_cursor: null,
        }

        // If participants missing, load conversation detail asynchronously to ensure read receipts work
        if (!res.conversation.participants || res.conversation.participants.length === 0) {
          ChatService.getConversation(res.conversation.id).then((fullConv) => {
            if (activeConversation.value && Number(activeConversation.value.id) === Number(fullConv.id)) {
              activeConversation.value = {
                ...activeConversation.value,
                participants: fullConv.participants || [],
              }
            }
          }).catch(() => {})
        }

        // Add to conversations list
        const idx = conversations.value.findIndex((c) => Number(c.id) === Number(res.conversation.id))
        if (idx !== -1) {
          conversations.value[idx] = res.conversation
        } else {
          conversations.value.unshift(res.conversation)
        }
        sortConversations()
        return res.data
      } catch (err) {
        if (activeMessages.value.length > 0 && activeMessages.value[0].id === tempId) {
          activeMessages.value[0].status = 'failed'
          activeMessages.value = [...activeMessages.value]
        }
        error.value = getErrorMessage(err, 'Gửi tin nhắn không thành công.')
        throw err
      } finally {
        isSendingMessage.value = false
      }
    }

    // Standard conversation: immediately render optimistic message
    const tempId = -Date.now()
    const optimisticMsg: Message = {
      id: tempId,
      conversation_id: convId,
      sender_id: currentUserId,
      type,
      body: trimmed,
      client_message_id: clientMessageId,
      created_at: new Date().toISOString(),
      status: 'sending',
    }

    // Instantly append optimistic message to chat pane (0ms latency perception)
    activeMessages.value = [...activeMessages.value, optimisticMsg]

    const optimisticLastMsg = {
      id: tempId,
      sender_id: currentUserId,
      type,
      body: trimmed,
      created_at: optimisticMsg.created_at,
    }
    activeConversation.value.last_message = optimisticLastMsg
    activeConversation.value.last_message_at = optimisticMsg.created_at

    // Update conversation sidebar preview immediately
    const conv = conversations.value.find((c) => Number(c.id) === convId)
    if (conv) {
      conv.last_message = optimisticLastMsg
      conv.last_message_at = optimisticMsg.created_at
      sortConversations()
    }

    isSendingMessage.value = true
    error.value = null

    try {
      const createdMessage = await ChatService.sendMessage(convId, {
        body: trimmed,
        type,
        client_message_id: clientMessageId,
      })

      // On successful send, clear saved client_message_id
      clearClientMessageId()

      const reconciledMsg: Message = {
        ...createdMessage,
        id: Number(createdMessage.id),
        sender_id: Number(createdMessage.sender_id),
        conversation_id: convId,
        status: 'sent',
      }

      // Reconcile optimistic message in activeMessages
      if (activeConversation.value && Number(activeConversation.value.id) === convId) {
        const existingIdx = activeMessages.value.findIndex(
          (m) =>
            m.id === tempId ||
            (m.client_message_id && m.client_message_id === clientMessageId) ||
            Number(m.id) === Number(createdMessage.id)
        )

        if (existingIdx !== -1) {
          activeMessages.value[existingIdx] = reconciledMsg
          activeMessages.value = [...activeMessages.value]
        } else {
          activeMessages.value = [...activeMessages.value, reconciledMsg]
          activeMessages.value.sort((a, b) => Number(a.id) - Number(b.id))
        }

        const lastMsg = {
          id: Number(createdMessage.id),
          sender_id: Number(createdMessage.sender_id),
          type: createdMessage.type,
          body: createdMessage.body,
          created_at: createdMessage.created_at,
        }
        activeConversation.value.last_message = lastMsg
        activeConversation.value.last_message_at = createdMessage.created_at
      }

      // Update conversation in list
      if (conv) {
        conv.last_message = {
          id: Number(createdMessage.id),
          sender_id: Number(createdMessage.sender_id),
          type: createdMessage.type,
          body: createdMessage.body,
          created_at: createdMessage.created_at,
        }
        conv.last_message_at = createdMessage.created_at
        sortConversations()
      }

      return createdMessage
    } catch (err) {
      // Mark optimistic message as failed
      if (activeConversation.value && Number(activeConversation.value.id) === convId) {
        const failedMsg = activeMessages.value.find(
          (m) =>
            m.id === tempId ||
            (m.client_message_id && m.client_message_id === clientMessageId)
        )
        if (failedMsg) {
          failedMsg.status = 'failed'
          activeMessages.value = [...activeMessages.value]
        }
      }
      error.value = getErrorMessage(err, 'Gửi tin nhắn không thành công.')
      throw err
    } finally {
      isSendingMessage.value = false
    }
  }

  /**
   * Mark messages as read in active conversation with monotonic guarantee.
   */
  async function markAsRead(messageId: number, currentUserId?: number): Promise<void> {
    if (!activeConversation.value) {
      return
    }

    const convId = activeConversation.value.id

    // Check if read marker is already at or past this message ID to avoid redundant REST requests
    const existingParticipant = activeConversation.value.participants.find(
      (p) => (currentUserId ? p.user_id === currentUserId : true)
    )
    if (existingParticipant?.last_read_message_id && existingParticipant.last_read_message_id >= messageId) {
      return
    }

    try {
      const response = await ChatService.markAsRead(convId, messageId)
      const data = response.data

      // Update read marker in active conversation participants if still open
      if (activeConversation.value && activeConversation.value.id === convId) {
        if (activeConversation.value.participants) {
          const participant = activeConversation.value.participants.find(
            (p) => (currentUserId ? Number(p.user_id) === Number(currentUserId) : true)
          )
          if (participant) {
            const newLastRead = Number(data.last_read_message_id)
            const currentLastRead = participant.last_read_message_id ? Number(participant.last_read_message_id) : 0
            if (!participant.last_read_message_id || newLastRead > currentLastRead) {
              participant.last_read_message_id = newLastRead
              participant.last_read_at = data.last_read_at
            }
          }
        }
        activeConversation.value.unread_count = 0
        activeConversation.value = {
          ...activeConversation.value,
          participants: activeConversation.value.participants ? [...activeConversation.value.participants] : [],
        }
      }

      // Update in conversation list
      const conv = conversations.value.find((c) => c.id === convId)
      if (conv) {
        if (!conv.last_message || Number(conv.last_message.id) <= Number(data.last_read_message_id)) {
          conv.unread_count = 0
        }
        updateComputedTotalUnread()
      }
    } catch {
      // Background read status sync failure does not block the user
    }
  }

  /**
   * Fetch total unread count from authoritative REST API.
   */
  async function fetchUnreadCount(): Promise<void> {
    if (isSyncingUnread) {
      return
    }
    isSyncingUnread = true

    try {
      const unreadData = await ChatService.getUnreadCount()
      totalUnreadCount.value = unreadData.total_unread

      // Apply unread count per conversation
      const map = new Map<number, number>(
        unreadData.conversations.map((item) => [item.conversation_id, item.unread_count])
      )

      conversations.value.forEach((conv) => {
        if (map.has(conv.id)) {
          conv.unread_count = map.get(conv.id)!
        } else {
          conv.unread_count = 0
        }
      })
    } catch {
      // Preserve existing count on network error
    } finally {
      isSyncingUnread = false
    }
  }

  /**
   * Handle incoming MessageSent broadcast event from WebSocket.
   * Strictly enforces deduplication by ID and client_message_id.
   */
  /**
   * Handle incoming MessageSent broadcast event from WebSocket.
   * Strictly enforces deduplication and reconciliation with optimistic messages.
   */
  function handleMessageSent(payload: MessageSentPayload, _currentUserId?: number): void {
    const authStore = useAuthStore()
    const currentUserId = _currentUserId || authStore.user?.id

    // Normalize payload in case of nested message wrapper
    const rawMessage: Message = payload.message || payload
    const convId = Number(rawMessage.conversation_id)

    const message: Message = {
      ...rawMessage,
      id: Number(rawMessage.id),
      sender_id: Number(rawMessage.sender_id),
      conversation_id: convId,
      status: 'sent',
    }

    // 1. If the message belongs to the active conversation (or transitions an open draft with this recipient)
    let isViewing = isConversationActivelyViewed(convId)
    if (!isViewing && canTransitionDraft(Number(rawMessage.sender_id))) {
      activeConversation.value!.id = convId
      isViewing = true
    }

    if (isViewing) {
      const existingIdx = activeMessages.value.findIndex(
        (m) =>
          Number(m.id) === Number(message.id) ||
          (message.client_message_id && m.client_message_id === message.client_message_id)
      )

      if (existingIdx !== -1) {
        // Reconcile optimistic message or update existing
        activeMessages.value[existingIdx] = message
        activeMessages.value = [...activeMessages.value]
      } else {
        // Append new message and reassign for immediate Vue 3 reactivity
        activeMessages.value = [...activeMessages.value, message]
        activeMessages.value.sort((a, b) => Number(a.id) - Number(b.id))

        // If message is from another user and conversation is actively open, mark as read
        if (currentUserId && Number(message.sender_id) !== Number(currentUserId)) {
          markAsRead(Number(message.id), currentUserId)
        }
      }

      if (activeConversation.value) {
        activeConversation.value.last_message = {
          id: Number(message.id),
          sender_id: Number(message.sender_id),
          type: message.type,
          body: message.body,
          created_at: message.created_at,
        }
        activeConversation.value.last_message_at = message.created_at
      }
    }

    // 2. Update conversation in conversations list
    const conv = conversations.value.find((c) => Number(c.id) === convId)
    if (conv) {
      conv.last_message = {
        id: Number(message.id),
        sender_id: Number(message.sender_id),
        type: message.type,
        body: message.body,
        created_at: message.created_at,
      }
      conv.last_message_at = message.created_at

      // If not currently actively viewing, increment unread count
      if (!isViewing) {
        if (currentUserId && Number(message.sender_id) !== Number(currentUserId)) {
          conv.unread_count = (conv.unread_count || 0) + 1
          updateComputedTotalUnread()
        }
      }

      sortConversations()
    } else {
      // Conversation not yet loaded in list; fetch authoritative list & unread count
      if (!isViewing && currentUserId && Number(message.sender_id) !== Number(currentUserId)) {
        totalUnreadCount.value = Math.max(0, totalUnreadCount.value + 1)
      }
      fetchConversations()
      fetchUnreadCount()
    }
  }

  /**
   * Handle incoming ConversationUpdated broadcast event on user channel.
   * Updates sidebar conversation and unread count without duplicate increments.
   */
  function handleConversationUpdated(payload: ConversationUpdatedPayload, _currentUserId?: number): void {
    const authStore = useAuthStore()
    const currentUserId = _currentUserId || authStore.user?.id

    const convId = Number(payload.conversation_id)
    const conv = conversations.value.find((c) => Number(c.id) === convId)
    let isViewing = isConversationActivelyViewed(convId)
    if (
      !isViewing &&
      canTransitionDraft(Number(payload.last_message.sender_id))
    ) {
      activeConversation.value!.id = convId
      isViewing = true
    }

    // 1. If user is currently actively viewing this conversation, append/reconcile the message into activeMessages!
    if (isViewing) {
      const rawMsg = payload.last_message
      const message: Message = {
        ...rawMsg,
        id: Number(rawMsg.id),
        sender_id: Number(rawMsg.sender_id),
        conversation_id: convId,
        status: 'sent',
      }
      const existingIdx = activeMessages.value.findIndex(
        (m) =>
          Number(m.id) === Number(message.id) ||
          (message.client_message_id && m.client_message_id === message.client_message_id)
      )

      if (existingIdx !== -1) {
        activeMessages.value[existingIdx] = message
        activeMessages.value = [...activeMessages.value]
      } else {
        activeMessages.value = [...activeMessages.value, message]
        activeMessages.value.sort((a, b) => Number(a.id) - Number(b.id))

        if (currentUserId && Number(message.sender_id) !== Number(currentUserId)) {
          markAsRead(Number(message.id), currentUserId)
        }
      }

      if (activeConversation.value) {
        activeConversation.value.last_message = {
          id: Number(message.id),
          sender_id: Number(message.sender_id),
          type: message.type,
          body: message.body,
          created_at: message.created_at,
        }
        activeConversation.value.last_message_at = message.created_at
      }
    }

    if (conv) {
      // Out-of-order snapshot protection: ignore only if incoming message ID is strictly older than existing
      if (conv.last_message && Number(payload.last_message.id) < Number(conv.last_message.id)) {
        debouncedSyncUnread()
        return
      }

      // Event is newer or equal: update last_message
      conv.last_message = {
        id: Number(payload.last_message.id),
        sender_id: Number(payload.last_message.sender_id),
        type: payload.last_message.type,
        body: payload.last_message.body,
        created_at: payload.last_message.created_at,
      }
      conv.last_message_at = payload.last_message.created_at

      // If user is currently looking at this conversation, unread count remains 0
      if (isViewing) {
        conv.unread_count = 0
      } else {
        // Authoritative unread count from backend event
        conv.unread_count = Number(payload.unread_count)
      }

      sortConversations()
      updateComputedTotalUnread()
    } else {
      // Conversation not yet loaded in list (e.g. user on another page such as HomePage)
      // Immediately increment totalUnreadCount so Header unread badge updates without reload!
      if (!isViewing) {
        totalUnreadCount.value = Math.max(1, totalUnreadCount.value + Number(payload.unread_count || 1))
      }
      fetchConversations()
      fetchUnreadCount()
    }
  }

  /**
   * Handle incoming MessagesRead broadcast event on conversation channel.
   * Strictly enforces monotonic progression of read markers.
   */
  function handleMessagesRead(payload: MessagesReadPayload): void {
    if (activeConversation.value && Number(activeConversation.value.id) === Number(payload.conversation_id)) {
      if (activeConversation.value.participants) {
        const participant = activeConversation.value.participants.find(
          (p) => Number(p.user_id) === Number(payload.user_id)
        )

        if (participant) {
          // Strictly monotonic: only advance forward, never move backward
          const newLastRead = Number(payload.last_read_message_id)
          const currentLastRead = participant.last_read_message_id ? Number(participant.last_read_message_id) : 0
          if (!participant.last_read_message_id || newLastRead > currentLastRead) {
            participant.last_read_message_id = newLastRead
            participant.last_read_at = payload.last_read_at
            // Trigger reactive update across components
            activeConversation.value = {
              ...activeConversation.value,
              participants: [...activeConversation.value.participants],
            }
          }
        }
      }
    }
  }

  /**
   * Resync data after WebSocket reconnection to recover any missed events.
   */
  async function handleReconnect(currentUserId?: number): Promise<void> {
    await fetchUnreadCount()
    await fetchConversations()

    if (activeConversation.value && currentUserId) {
      // Re-fetch latest messages for active conversation
      try {
        const messagesData = await ChatService.getMessages(activeConversation.value.id)
        activeMessages.value = messagesData.data
        messagesPagination.value = messagesData.meta
      } catch {
        // Continue with current state if resync fails
      }
    }
  }

  /**
   * Reset store state (e.g. on user logout).
   */
  function $reset(): void {
    conversations.value = []
    activeConversation.value = null
    activeMessages.value = []
    messagesPagination.value = {
      limit: 25,
      has_more: false,
      next_cursor: null,
    }
    totalUnreadCount.value = 0
    isChatViewActive.value = false
    isLoadingConversations.value = false
    isLoadingMessages.value = false
    isSendingMessage.value = false
    error.value = null
  }

  return {
    // State
    conversations,
    activeConversation,
    activeMessages,
    messagesPagination,
    totalUnreadCount,
    isChatViewActive,
    isWindowVisible,
    isLoadingConversations,
    isLoadingMessages,
    isSendingMessage,
    error,

    // Computed
    activeConversationId,
    hasActiveConversation,

    // Actions
    fetchConversations,
    openConversation,
    openDraftConversation,
    closeConversation,
    setChatViewActive,
    setWindowVisible,
    isConversationActivelyViewed,
    loadOlderMessages,
    sendMessage,
    markAsRead,
    fetchUnreadCount,
    handleMessageSent,
    handleConversationUpdated,
    handleMessagesRead,
    handleReconnect,
    getOrCreateClientMessageId,
    clearClientMessageId,
    $reset,
  }
})
