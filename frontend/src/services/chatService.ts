import { httpClient } from '@/services/httpClient'
import type {
  Conversation,
  Message,
  MessagesListResponse,
  UnreadCountData,
} from '@/types/chat'

export class ChatService {
  /**
   * Get paginated conversations for authenticated user with optional tab ('main' | 'requests').
   */
  static async getConversations(page = 1, perPage = 15, tab: 'main' | 'requests' = 'main'): Promise<{ data: Conversation[]; meta?: unknown }> {
    const response = await httpClient.get<{ data: Conversation[]; meta?: unknown }>('/chat/conversations', {
      params: { page, per_page: perPage, tab },
    })
    return response.data
  }

  /**
   * Create or fetch existing direct conversation with another user.
   */
  static async createDirectConversation(userId: number): Promise<Conversation> {
    const response = await httpClient.post<{ data: Conversation }>('/chat/conversations', {
      user_id: userId,
    })
    return response.data.data
  }

  /**
   * Find existing direct conversation without creating a new record.
   * Returns conversation if exists, or data: null with recipient info if not.
   */
  static async findDirectConversation(userId: number): Promise<{ data: Conversation | null; recipient?: import('@/types/chat').ChatUserSummary }> {
    const response = await httpClient.get<{ data: Conversation | null; recipient?: import('@/types/chat').ChatUserSummary }>(`/chat/direct/${userId}`)
    return response.data
  }

  /**
   * Send a direct message to a user.
   * Atomically creates the conversation if this is the first message (lazy conversation flow).
   */
  static async sendDirectMessage(
    userId: number,
    payload: { body: string; type?: string; client_message_id?: string | null }
  ): Promise<{ data: Message; conversation: Conversation }> {
    const response = await httpClient.post<{ data: Message; conversation: Conversation }>(
      `/chat/direct/${userId}/messages`,
      payload
    )
    return response.data
  }

  /**
   * Get single conversation detail by ID.
   */
  static async getConversation(conversationId: number): Promise<Conversation> {
    const response = await httpClient.get<{ data: Conversation }>(`/chat/conversations/${conversationId}`)
    return response.data.data
  }

  /**
   * Get paginated messages for a conversation (chronological ascending order).
   */
  static async getMessages(
    conversationId: number,
    params?: { limit?: number; before_id?: number | null; after_id?: number | null }
  ): Promise<MessagesListResponse> {
    const response = await httpClient.get<MessagesListResponse>(
      `/chat/conversations/${conversationId}/messages`,
      { params }
    )
    return response.data
  }

  /**
   * Send a new message to a conversation with optional client_message_id and attachments.
   */
  static async sendMessage(
    conversationId: number,
    payload: {
      body?: string
      type?: string
      client_message_id?: string | null
      comic_id?: number | null
      post_id?: number | null
      images?: File[]
    }
  ): Promise<Message> {
    if (payload.images && payload.images.length > 0) {
      const formData = new FormData()
      if (payload.body) formData.append('body', payload.body)
      if (payload.type) formData.append('type', payload.type)
      if (payload.client_message_id) formData.append('client_message_id', payload.client_message_id)
      if (payload.comic_id) formData.append('comic_id', String(payload.comic_id))
      if (payload.post_id) formData.append('post_id', String(payload.post_id))
      payload.images.forEach((img) => formData.append('images[]', img))

      const response = await httpClient.post<{ data: Message }>(
        `/chat/conversations/${conversationId}/messages`,
        formData,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      )
      return response.data.data
    }

    const response = await httpClient.post<{ data: Message }>(
      `/chat/conversations/${conversationId}/messages`,
      payload
    )
    return response.data.data
  }

  /**
   * Mark messages as read up to a specific message ID with monotonic guarantee.
   */
  static async markAsRead(
    conversationId: number,
    lastReadMessageId: number
  ): Promise<{ status: string; data: { conversation_id: number; last_read_message_id: number; last_read_at: string | null } }> {
    const response = await httpClient.post<{
      status: string
      data: { conversation_id: number; last_read_message_id: number; last_read_at: string | null }
    }>(`/chat/conversations/${conversationId}/read`, {
      last_read_message_id: lastReadMessageId,
    })
    return response.data
  }

  /**
   * Get total unread count and per-conversation breakdown.
   */
  static async getUnreadCount(): Promise<UnreadCountData> {
    const response = await httpClient.get<{ data: UnreadCountData }>('/chat/unread-count')
    return response.data.data
  }

  /**
   * Get count of pending message requests for current user.
   * GET /api/chat/conversations/requests-count
   */
  static async getMessageRequestsCount(): Promise<number> {
    const response = await httpClient.get<{ data: { count: number } }>('/chat/conversations/requests-count')
    return response.data.data?.count ?? 0
  }

  /**
   * Accept a pending message request.
   * POST /api/chat/conversations/{id}/accept
   */
  static async acceptConversation(conversationId: number): Promise<Conversation> {
    const response = await httpClient.post<{ message: string; data: Conversation }>(`/chat/conversations/${conversationId}/accept`)
    return response.data.data
  }

  /**
   * Reject a pending message request.
   * POST /api/chat/conversations/{id}/reject
   */
  static async rejectConversation(conversationId: number): Promise<void> {
    await httpClient.post(`/chat/conversations/${conversationId}/reject`)
  }

  /**
   * Get eligible recipients for sharing (users with accepted direct conversations).
   */
  static async getShareRecipients(): Promise<import('@/types/chat').ShareRecipient[]> {
    const response = await httpClient.get<{ data: import('@/types/chat').ShareRecipient[] }>('/chat/share/recipients')
    return response.data.data
  }

  /**
   * Share comic or post to multiple recipients via direct messages.
   */
  static async shareToRecipients(payload: {
    recipient_ids: number[]
    comic_id?: number
    post_id?: number
    body?: string
  }): Promise<{ message: string; sent_count: number }> {
    const response = await httpClient.post<{ message: string; sent_count: number }>(
      '/chat/share',
      payload
    )
    return response.data
  }
}
