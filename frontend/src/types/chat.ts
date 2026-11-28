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

export interface MessageImageAttachment {
  path: string
  url: string
  name: string
  size: number
}

export interface MessageComicAttachment {
  id: number
  title: string
  slug: string
  cover_url?: string | null
  author_name?: string
  is_unavailable: boolean
}

export interface MessagePostAttachment {
  id: number
  content: string
  created_at: string | null
  user?: {
    id: number
    name: string
    avatar_url?: string | null
  } | null
  comic?: {
    id: number
    title: string
    slug: string
  } | null
  is_unavailable: boolean
}

export interface MessageAttachments {
  images?: MessageImageAttachment[]
  comic?: MessageComicAttachment | null
  post?: MessagePostAttachment | null
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
  attachments?: MessageAttachments
}

export interface ShareRecipient {
  id: number
  name: string
  avatar_url: string | null
  role: string
  conversation_id: number
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
  status?: 'pending' | 'accepted' | 'rejected'
  initiator_id?: number | null
  is_pending_request?: boolean
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
