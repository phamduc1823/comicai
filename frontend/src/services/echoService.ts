import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { httpClient, setSocketIdProvider } from '@/services/httpClient'
import type {
  MessageSentPayload,
  ConversationUpdatedPayload,
  MessagesReadPayload,
} from '@/types/chat'

// Ensure Pusher is attached globally for Echo compatibility
if (typeof window !== 'undefined') {
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
}

export type ConnectionStatus = 'connected' | 'disconnected' | 'connecting' | 'reconnecting' | 'failed' | 'unavailable'

interface ConversationChannelHandlers {
  onMessageSent?: (event: MessageSentPayload) => void
  onMessagesRead?: (event: MessagesReadPayload) => void
}

type ReconnectCallback = () => void

let echoInstance: Echo<'reverb'> | null = null

// Track subscribed channels to prevent duplicate listeners
const subscribedConversationChannels = new Map<number, ConversationChannelHandlers>()
const subscribedUserChannels = new Map<number, (event: ConversationUpdatedPayload) => void>()
const reconnectCallbacks = new Set<ReconnectCallback>()

/**
 * Build Reverb configuration options from Vite environment variables.
 * Never hardcodes credentials or hosts.
 */
function getReverbConfig() {
  const appKey = import.meta.env.VITE_REVERB_APP_KEY || ''
  const host =
    import.meta.env.VITE_REVERB_HOST ||
    (typeof window !== 'undefined' ? window.location.hostname : 'localhost')
  const rawPort = import.meta.env.VITE_REVERB_PORT
  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'
  const forceTLS = scheme === 'https'
  const defaultPort = forceTLS ? 443 : 8080
  const port = rawPort ? Number(rawPort) : defaultPort

  return {
    appKey,
    host,
    port,
    scheme,
    forceTLS,
  }
}

/**
 * Initialize or get singleton Echo instance.
 */
export function getEcho(): Echo<'reverb'> | null {
  if (echoInstance) {
    return echoInstance
  }

  if (typeof window === 'undefined') {
    return null
  }

  const config = getReverbConfig()

  if (!config.appKey) {
    // Reverb app key is not configured; Echo stays inactive without throwing
    return null
  }

  try {
    echoInstance = new Echo({
      broadcaster: 'reverb' as const,
      key: config.appKey,
      wsHost: config.host,
      wsPort: config.port,
      wssPort: config.port,
      forceTLS: config.forceTLS,
      enabledTransports: ['ws', 'wss'],
      Pusher: Pusher,
      authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: (error: Error | null, authData: any) => void) => {
          httpClient
            .post('/broadcasting/auth', {
              socket_id: socketId,
              channel_name: channel.name,
            })
            .then((response) => {
              callback(null, response.data)
            })
            .catch((error: unknown) => {
              const err = error instanceof Error ? error : new Error(String(error))
              callback(err, null)
            })
        },
      }),
    })

    // Bind connection events for reconnection detection
    const pusher = echoInstance.connector?.pusher
    if (pusher?.connection) {
      pusher.connection.bind('state_change', (states: { current: string; previous: string }) => {
        const { current, previous } = states

        // Trigger reconnect callbacks when transitioning back to connected from a disconnected/unavailable state
        if (current === 'connected' && (previous === 'unavailable' || previous === 'disconnected' || previous === 'connecting')) {
          reconnectCallbacks.forEach((cb) => {
            try {
              cb()
            } catch (err) {
              console.error('[Echo] Error in reconnect callback:', err)
            }
          })
        }
      })
    }
  } catch (err) {
    console.error('[Echo] Failed to initialize Laravel Echo:', err)
    echoInstance = null
    return null
  }

  return echoInstance
}

/**
 * Get Socket ID from current Echo connection if connected.
 * Used by httpClient request interceptor to populate X-Socket-ID header.
 */
export function getEchoSocketId(): string | undefined {
  if (!echoInstance) {
    return undefined
  }
  try {
    return echoInstance.socketId()
  } catch {
    return undefined
  }
}

// Register Socket ID provider with httpClient
setSocketIdProvider(getEchoSocketId)

/**
 * Subscribe to private conversation channel: chat.conversation.{conversationId}
 * Listens for .MessageSent and .MessagesRead.
 * Automatically cleans up existing listeners before registering new ones.
 */
export function subscribeConversationChannel(
  conversationId: number,
  handlers: ConversationChannelHandlers
): () => void {
  const echo = getEcho()
  const channelName = `chat.conversation.${conversationId}`

  if (!echo) {
    // If Echo is unavailable, store handler reference and return no-op cleanup
    subscribedConversationChannels.set(conversationId, handlers)
    return () => unsubscribeConversationChannel(conversationId)
  }

  // If already subscribed, unbind previous listeners to prevent duplicate triggers
  const existingHandlers = subscribedConversationChannels.get(conversationId)
  if (existingHandlers) {
    unsubscribeConversationChannel(conversationId)
  }

  subscribedConversationChannels.set(conversationId, handlers)
  const channel = echo.private(channelName)

  if (handlers.onMessageSent) {
    channel.listen('.MessageSent', (payload: MessageSentPayload) => {
      handlers.onMessageSent?.(payload)
    })
  }

  if (handlers.onMessagesRead) {
    channel.listen('.MessagesRead', (payload: MessagesReadPayload) => {
      handlers.onMessagesRead?.(payload)
    })
  }

  return () => unsubscribeConversationChannel(conversationId)
}

/**
 * Unsubscribe from private conversation channel and cleanup listeners.
 */
export function unsubscribeConversationChannel(conversationId: number): void {
  subscribedConversationChannels.delete(conversationId)

  if (echoInstance) {
    try {
      const channelName = `chat.conversation.${conversationId}`
      echoInstance.leave(channelName)
    } catch {
      // Ignore leave errors on inactive channel
    }
  }
}

/**
 * Subscribe to private user channel: chat.user.{userId}
 * Listens for .ConversationUpdated to refresh conversations sidebar and unread status.
 */
export function subscribeUserChannel(
  userId: number,
  handler: (event: ConversationUpdatedPayload) => void
): () => void {
  const echo = getEcho()
  const channelName = `chat.user.${userId}`

  if (!echo) {
    subscribedUserChannels.set(userId, handler)
    return () => unsubscribeUserChannel(userId)
  }

  // Cleanup existing subscription to prevent duplicates
  if (subscribedUserChannels.has(userId)) {
    unsubscribeUserChannel(userId)
  }

  subscribedUserChannels.set(userId, handler)
  const channel = echo.private(channelName)

  channel.listen('.ConversationUpdated', (payload: ConversationUpdatedPayload) => {
    handler(payload)
  })

  return () => unsubscribeUserChannel(userId)
}

/**
 * Unsubscribe from private user channel.
 */
export function unsubscribeUserChannel(userId: number): void {
  subscribedUserChannels.delete(userId)

  if (echoInstance) {
    try {
      const channelName = `chat.user.${userId}`
      echoInstance.leave(channelName)
    } catch {
      // Ignore leave errors
    }
  }
}

/**
 * Register a callback to be triggered when WebSocket reconnects after being disconnected.
 * Returns an unregister function.
 */
export function onEchoReconnect(callback: ReconnectCallback): () => void {
  reconnectCallbacks.add(callback)
  return () => {
    reconnectCallbacks.delete(callback)
  }
}

/**
 * Disconnect Echo completely, leaving all subscribed channels and resetting singleton.
 * Should be called upon user logout.
 */
export function disconnectEcho(): void {
  if (echoInstance) {
    try {
      echoInstance.leaveAllChannels()
      echoInstance.disconnect()
    } catch {
      // Ignore disconnect errors
    }
    echoInstance = null
  }

  subscribedConversationChannels.clear()
  subscribedUserChannels.clear()
  reconnectCallbacks.clear()
}

/**
 * Helper to check current connection status.
 */
export function getEchoConnectionStatus(): ConnectionStatus {
  if (!echoInstance) {
    return 'disconnected'
  }
  try {
    const status = echoInstance.connector?.pusher?.connection?.state as ConnectionStatus
    return status || 'disconnected'
  } catch {
    return 'disconnected'
  }
}
