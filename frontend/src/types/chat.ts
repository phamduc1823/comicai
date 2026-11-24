/**
 * Types for Chat Module matching Laravel Backend API Resources
 * Implemented in Package 9.7.4.4
 */

export interface ChatUserSummary {
  id: number
  name: string
  avatar_path: string | null
  role: string
}

export interface ConversationParticipant {
  id: number
  conversation_id: number
  user_id: number
  last_read_message_id: number | null
  last_read_at: string | null
  user?: ChatUserSummary
}

export interface Message {
  id: number
  conversation_id: number
  sender_id: number
  type: string
  body: string
  client_message_id: string | null
  created_at: string | null
  status?: 'sending' | 'sent' | 'failed'
}

export interface ConversationLastMessage {
  id: number
  sender_id: number
  type: string
  body: string
  created_at: string | null
}

export interface Conversation {
  id: number
  type: 'direct' | 'group'
  recipient: ChatUserSummary | null
  last_message: ConversationLastMessage | null
  last_message_at: string | null
  participants: ConversationParticipant[]
  unread_count: number
  created_at: string | null
  updated_at: string | null
}

export interface MessagesPaginationMeta {
  limit: number
  has_more: boolean
  next_cursor: number | null
}

export interface MessagesListResponse {
  data: Message[]
  meta: MessagesPaginationMeta
}

export interface UnreadCountConversationItem {
  conversation_id: number
  unread_count: number
}

export interface UnreadCountData {
  total_unread: number
  conversations: UnreadCountConversationItem[]
}

/**
 * WebSocket Broadcast Event Payloads
 */

export interface MessageSentPayload extends Message {
  message?: Message
}

export interface ConversationUpdatedPayload {
  conversation_id: number
  last_message: Message
  unread_count: number
}

export interface MessagesReadPayload {
  conversation_id: number
  user_id: number
  last_read_message_id: number
  last_read_at: string | null
}
