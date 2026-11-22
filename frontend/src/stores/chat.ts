import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { ChatService } from '@/services/chatService'
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
    totalUnreadCount.value = conversations.value.reduce(
      (sum, conv) => sum + (conv.unread_count || 0),
      0
    )
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
        conversations.value[index] = { ...conversations.value[index], ...convData }
      } else {
        conversations.value.push(convData)
        sortConversations()
      }

      // If there are messages and the latest message is from the other user, mark as read
      if (messagesData.data.length > 0 && currentUserId) {
        const latestMessage = messagesData.data[messagesData.data.length - 1]
        if (latestMessage.sender_id !== currentUserId) {
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
   * Send a new message to active conversation.
   */
  async function sendMessage(body: string, type = 'text'): Promise<Message | null> {
    const trimmed = body.trim()
    if (!activeConversation.value || !trimmed || isSendingMessage.value) {
      return null
    }

    const convId = activeConversation.value.id
    // Preserve client_message_id across retries for the same draft text
    const clientMessageId = getOrCreateClientMessageId(trimmed)

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

      // Guard: only append to activeMessages if activeConversation is still the same conversation
      if (activeConversation.value && activeConversation.value.id === convId) {
        // Deduplicate before appending
        const existingIdx = activeMessages.value.findIndex(
          (m) =>
            m.id === createdMessage.id ||
            (createdMessage.client_message_id && m.client_message_id === createdMessage.client_message_id)
        )

        if (existingIdx !== -1) {
          activeMessages.value[existingIdx] = createdMessage
        } else {
          activeMessages.value.push(createdMessage)
          // Ensure chronological ascending sort
          activeMessages.value.sort((a, b) => a.id - b.id)
        }

        const lastMsg = {
          id: createdMessage.id,
          sender_id: createdMessage.sender_id,
          type: createdMessage.type,
          body: createdMessage.body,
          created_at: createdMessage.created_at,
        }

        activeConversation.value.last_message = lastMsg
        activeConversation.value.last_message_at = createdMessage.created_at
      }

      // Update conversation last_message in list
      const conv = conversations.value.find((c) => c.id === convId)
      if (conv) {
        conv.last_message = {
          id: createdMessage.id,
          sender_id: createdMessage.sender_id,
          type: createdMessage.type,
          body: createdMessage.body,
          created_at: createdMessage.created_at,
        }
        conv.last_message_at = createdMessage.created_at
        sortConversations()
      }

      return createdMessage
    } catch (err) {
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
        const participant = activeConversation.value.participants.find(
          (p) => (currentUserId ? p.user_id === currentUserId : true)
        )
        if (participant) {
          if (!participant.last_read_message_id || data.last_read_message_id > participant.last_read_message_id) {
            participant.last_read_message_id = data.last_read_message_id
            participant.last_read_at = data.last_read_at
          }
        }
        activeConversation.value.unread_count = 0
      }

      // Update in conversation list
      const conv = conversations.value.find((c) => c.id === convId)
      if (conv) {
        if (!conv.last_message || conv.last_message.id <= data.last_read_message_id) {
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
  function handleMessageSent(payload: MessageSentPayload, currentUserId?: number): void {
    // Normalize payload in case of nested message wrapper
    const message: Message = payload.message || payload

    // 1. If the message belongs to the active conversation, append with deduplication
    if (activeConversation.value && activeConversation.value.id === message.conversation_id) {
      const isDuplicate = activeMessages.value.some(
        (m) =>
          m.id === message.id ||
          (message.client_message_id && m.client_message_id === message.client_message_id)
      )

      if (!isDuplicate) {
        activeMessages.value.push(message)
        // Strictly preserve chronological ascending order
        activeMessages.value.sort((a, b) => a.id - b.id)

        // If message is from another user and conversation is open, mark as read
        if (currentUserId && message.sender_id !== currentUserId) {
          markAsRead(message.id, currentUserId)
        }
      }

      activeConversation.value.last_message = {
        id: message.id,
        sender_id: message.sender_id,
        type: message.type,
        body: message.body,
        created_at: message.created_at,
      }
      activeConversation.value.last_message_at = message.created_at
    }

    // 2. Update conversation in conversations list
    const conv = conversations.value.find((c) => c.id === message.conversation_id)
    if (conv) {
      conv.last_message = {
        id: message.id,
        sender_id: message.sender_id,
        type: message.type,
        body: message.body,
        created_at: message.created_at,
      }
      conv.last_message_at = message.created_at

      // If not currently viewing, increment unread count
      if (!activeConversation.value || activeConversation.value.id !== message.conversation_id) {
        if (currentUserId && message.sender_id !== currentUserId) {
          conv.unread_count = (conv.unread_count || 0) + 1
          totalUnreadCount.value += 1
        }
      }

      sortConversations()
    } else {
      // Conversation not yet loaded in list; fetch authoritative list from REST
      fetchConversations()
    }
  }

  /**
   * Handle incoming ConversationUpdated broadcast event on user channel.
   * Updates sidebar conversation and unread count without duplicate increments.
   */
  function handleConversationUpdated(payload: ConversationUpdatedPayload, _currentUserId?: number): void {
    const conv = conversations.value.find((c) => c.id === payload.conversation_id)

    if (conv) {
      // Out-of-order snapshot protection:
      // 1. If incoming message ID is older than our existing last_message ID, this event is stale.
      // 2. If incoming message ID is the SAME as existing last_message ID, but payload unread_count is GREATER
      //    than current unread_count (e.g. event captured before a read was committed), it's a stale pre-read snapshot.
      //    Never let a stale snapshot resurrect unread count.
      if (conv.last_message) {
        if (payload.last_message.id < conv.last_message.id) {
          debouncedSyncUnread()
          return
        }
        if (
          payload.last_message.id === conv.last_message.id &&
          conv.unread_count !== undefined &&
          payload.unread_count > conv.unread_count
        ) {
          debouncedSyncUnread()
          return
        }
      }

      // Event is newer or equal: update last_message
      conv.last_message = {
        id: payload.last_message.id,
        sender_id: payload.last_message.sender_id,
        type: payload.last_message.type,
        body: payload.last_message.body,
        created_at: payload.last_message.created_at,
      }
      conv.last_message_at = payload.last_message.created_at

      // If user is currently looking at this conversation, unread count remains 0
      if (activeConversation.value && activeConversation.value.id === payload.conversation_id) {
        conv.unread_count = 0
      } else {
        // Authoritative unread count from backend event
        conv.unread_count = payload.unread_count
      }

      sortConversations()
      updateComputedTotalUnread()
    } else {
      // New conversation created by another user; refresh list from authoritative REST API
      fetchConversations()
    }
  }

  /**
   * Handle incoming MessagesRead broadcast event on conversation channel.
   * Strictly enforces monotonic progression of read markers.
   */
  function handleMessagesRead(payload: MessagesReadPayload): void {
    if (activeConversation.value && activeConversation.value.id === payload.conversation_id) {
      const participant = activeConversation.value.participants.find(
        (p) => p.user_id === payload.user_id
      )

      if (participant) {
        // Strictly monotonic: only advance forward, never move backward
        if (!participant.last_read_message_id || payload.last_read_message_id > participant.last_read_message_id) {
          participant.last_read_message_id = payload.last_read_message_id
          participant.last_read_at = payload.last_read_at
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
    closeConversation,
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
