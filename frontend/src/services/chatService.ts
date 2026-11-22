import { httpClient } from '@/services/httpClient'
import type {
  Conversation,
  Message,
  MessagesListResponse,
  UnreadCountData,
} from '@/types/chat'

export class ChatService {
  /**
   * Get paginated conversations for authenticated user.
   */
  static async getConversations(page = 1, perPage = 15): Promise<{ data: Conversation[]; meta?: unknown }> {
    const response = await httpClient.get<{ data: Conversation[]; meta?: unknown }>('/chat/conversations', {
      params: { page, per_page: perPage },
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
   * Send a new message to a conversation with optional client_message_id for idempotency.
   */
  static async sendMessage(
    conversationId: number,
    payload: { body: string; type?: string; client_message_id?: string | null }
  ): Promise<Message> {
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
}
