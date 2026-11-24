import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { ref } from 'vue'
import { httpClient, setSocketIdProvider } from '@/services/httpClient'
import type {
  MessageSentPayload,
  ConversationUpdatedPayload,
  MessagesReadPayload,
} from '@/types/chat'
import type { NotificationCreatedPayload } from '@/types/notification'

// Ensure Pusher is attached globally for Echo compatibility
if (typeof window !== 'undefined') {
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
}

export type ConnectionStatus = 'connected' | 'disconnected' | 'connecting' | 'reconnecting' | 'failed' | 'unavailable'

// Reactive connection status for UI components
export const echoConnectionStatus = ref<ConnectionStatus>('disconnected')

interface ConversationChannelHandlers {
  onMessageSent?: (event: MessageSentPayload) => void
  onMessagesRead?: (event: MessagesReadPayload) => void
}

export interface UserChannelOptions {
  onConversationUpdated?: (event: ConversationUpdatedPayload) => void
  onMessageSent?: (event: MessageSentPayload) => void
  onMessagesRead?: (event: MessagesReadPayload) => void
}

interface UserChannelHandlers {
  conversationUpdatedHandlers: Set<(event: ConversationUpdatedPayload) => void>
  notificationCreatedHandlers: Set<(event: NotificationCreatedPayload) => void>
  messageSentHandlers: Set<(event: MessageSentPayload) => void>
  messagesReadHandlers: Set<(event: MessagesReadPayload) => void>
}

type ReconnectCallback = () => void

let echoInstance: Echo<'reverb'> | null = null

// Track subscribed channels to prevent duplicate listeners
const subscribedConversationChannels = new Map<number, ConversationChannelHandlers>()
const subscribedUserChannels = new Map<number, UserChannelHandlers>()
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

    // Bind connection events for reconnection detection and dev logging
    const pusher = echoInstance.connector?.pusher
    if (pusher?.connection) {
      echoConnectionStatus.value = (pusher.connection.state as ConnectionStatus) || 'connecting'

      pusher.connection.bind('state_change', (states: { current: string; previous: string }) => {
        const { current, previous } = states
        echoConnectionStatus.value = current as ConnectionStatus

        if (import.meta.env.DEV) {
          console.log(`[Echo WebSocket] State changed: ${previous} -> ${current}`)
        }

        // Trigger reconnect callbacks when transitioning back to connected from a disconnected/unavailable state
        if (current === 'connected' && (previous === 'unavailable' || previous === 'disconnected' || previous === 'connecting')) {
          if (import.meta.env.DEV) {
            console.log('[Echo WebSocket] Reconnected to server. Resynchronizing channel listeners and unread counts...')
          }

          // Re-bind listeners for all currently active user channels
          subscribedUserChannels.forEach((state, userId) => {
            const channel = echoInstance?.private(`chat.user.${userId}`)
            if (channel) {
              if (state.conversationUpdatedHandlers.size > 0) {
                channel.stopListening('.ConversationUpdated')
                channel.stopListening('.App\\Events\\ConversationUpdated')
                channel.stopListening('ConversationUpdated')
                const dispatchConversation = (payload: ConversationUpdatedPayload) => {
                  state.conversationUpdatedHandlers.forEach((h) => {
                    try {
                      h(payload)
                    } catch (err) {
                      console.error('[Echo] Error in conversationUpdated handler:', err)
                    }
                  })
                }
                channel.listen('.ConversationUpdated', dispatchConversation)
                channel.listen('.App\\Events\\ConversationUpdated', dispatchConversation)
                channel.listen('ConversationUpdated', dispatchConversation)
              }
              if (state.messageSentHandlers.size > 0) {
                channel.stopListening('.MessageSent')
                channel.stopListening('.App\\Events\\MessageSent')
                channel.stopListening('MessageSent')
                const dispatchSent = (payload: MessageSentPayload) => {
                  state.messageSentHandlers.forEach((h) => {
                    try {
                      h(payload)
                    } catch (err) {
                      console.error('[Echo] Error in messageSent user handler:', err)
                    }
                  })
                }
                channel.listen('.MessageSent', dispatchSent)
                channel.listen('.App\\Events\\MessageSent', dispatchSent)
                channel.listen('MessageSent', dispatchSent)
              }
              if (state.messagesReadHandlers.size > 0) {
                channel.stopListening('.MessagesRead')
                channel.stopListening('.App\\Events\\MessagesRead')
                channel.stopListening('MessagesRead')
                const dispatchRead = (payload: MessagesReadPayload) => {
                  state.messagesReadHandlers.forEach((h) => {
                    try {
                      h(payload)
                    } catch (err) {
                      console.error('[Echo] Error in messagesRead user handler:', err)
                    }
                  })
                }
                channel.listen('.MessagesRead', dispatchRead)
                channel.listen('.App\\Events\\MessagesRead', dispatchRead)
                channel.listen('MessagesRead', dispatchRead)
              }
              if (state.notificationCreatedHandlers.size > 0) {
                channel.stopListening('.NotificationCreated')
                channel.stopListening('.App\\Events\\NotificationCreated')
                channel.stopListening('NotificationCreated')
                const dispatch = (payload: NotificationCreatedPayload) => {
                  if (import.meta.env.DEV) {
                    console.log('[Echo Realtime] Received NotificationCreated event on reconnect:', payload)
                  }
                  state.notificationCreatedHandlers.forEach((h) => {
                    try {
                      h(payload)
                    } catch (err) {
                      console.error('[Echo] Error in notificationCreated handler:', err)
                    }
                  })
                }
                channel.listen('.NotificationCreated', dispatch)
                channel.listen('.App\\Events\\NotificationCreated', dispatch)
                channel.listen('NotificationCreated', dispatch)
              }
            }
          })

          // Re-bind listeners for active conversation channels
          subscribedConversationChannels.forEach((handlers, conversationId) => {
            const channel = echoInstance?.private(`chat.conversation.${conversationId}`)
            if (channel) {
              if (handlers.onMessageSent) {
                channel.stopListening('.MessageSent')
                channel.stopListening('.App\\Events\\MessageSent')
                channel.stopListening('MessageSent')
                const dispatchSent = (payload: MessageSentPayload) => handlers.onMessageSent?.(payload)
                channel.listen('.MessageSent', dispatchSent)
                channel.listen('.App\\Events\\MessageSent', dispatchSent)
                channel.listen('MessageSent', dispatchSent)
              }
              if (handlers.onMessagesRead) {
                channel.stopListening('.MessagesRead')
                channel.stopListening('.App\\Events\\MessagesRead')
                channel.stopListening('MessagesRead')
                const dispatchRead = (payload: MessagesReadPayload) => handlers.onMessagesRead?.(payload)
                channel.listen('.MessagesRead', dispatchRead)
                channel.listen('.App\\Events\\MessagesRead', dispatchRead)
                channel.listen('MessagesRead', dispatchRead)
              }
            }
          })

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
 * Uses multi-binding (.Event, .App\Events\Event, Event) to guarantee bulletproof delivery.
 */
export function subscribeConversationChannel(
  conversationId: number,
  handlers: ConversationChannelHandlers
): () => void {
  const echo = getEcho()
  const channelName = `chat.conversation.${conversationId}`

  if (!echo) {
    subscribedConversationChannels.set(conversationId, handlers)
    return () => unsubscribeConversationChannel(conversationId)
  }

  subscribedConversationChannels.set(conversationId, handlers)
  const channel = echo.private(channelName)

  // Remove existing listeners without dropping WebSocket connection
  channel.stopListening('.MessageSent')
  channel.stopListening('.App\\Events\\MessageSent')
  channel.stopListening('MessageSent')
  channel.stopListening('.MessagesRead')
  channel.stopListening('.App\\Events\\MessagesRead')
  channel.stopListening('MessagesRead')

  if (handlers.onMessageSent) {
    const dispatchSent = (payload: MessageSentPayload) => {
      handlers.onMessageSent?.(payload)
    }
    channel.listen('.MessageSent', dispatchSent)
    channel.listen('.App\\Events\\MessageSent', dispatchSent)
    channel.listen('MessageSent', dispatchSent)
  }

  if (handlers.onMessagesRead) {
    const dispatchRead = (payload: MessagesReadPayload) => {
      handlers.onMessagesRead?.(payload)
    }
    channel.listen('.MessagesRead', dispatchRead)
    channel.listen('.App\\Events\\MessagesRead', dispatchRead)
    channel.listen('MessagesRead', dispatchRead)
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
      const channel = echoInstance.private(channelName)
      channel.stopListening('.MessageSent')
      channel.stopListening('.App\\Events\\MessageSent')
      channel.stopListening('MessageSent')
      channel.stopListening('.MessagesRead')
      channel.stopListening('.App\\Events\\MessagesRead')
      channel.stopListening('MessagesRead')
      echoInstance.leave(channelName)
    } catch {
      // Ignore leave errors on inactive channel
    }
  }
}

/**
 * Subscribe to private user channel: chat.user.{userId}
 * Listens for .ConversationUpdated, .MessageSent, and .MessagesRead to refresh state.
 * Shares the underlying private channel safely with notification listeners via Set-based handlers.
 */
export function subscribeUserChannel(
  userId: number,
  handlerOrOptions: ((event: ConversationUpdatedPayload) => void) | UserChannelOptions
): () => void {
  const echo = getEcho()
  const channelName = `chat.user.${userId}`

  let channelState = subscribedUserChannels.get(userId)
  if (!channelState) {
    channelState = {
      conversationUpdatedHandlers: new Set(),
      notificationCreatedHandlers: new Set(),
      messageSentHandlers: new Set(),
      messagesReadHandlers: new Set(),
    }
    subscribedUserChannels.set(userId, channelState)
  }

  if (typeof handlerOrOptions === 'function') {
    channelState.conversationUpdatedHandlers.add(handlerOrOptions)
  } else {
    if (handlerOrOptions.onConversationUpdated) {
      channelState.conversationUpdatedHandlers.add(handlerOrOptions.onConversationUpdated)
    }
    if (handlerOrOptions.onMessageSent) {
      channelState.messageSentHandlers.add(handlerOrOptions.onMessageSent)
    }
    if (handlerOrOptions.onMessagesRead) {
      channelState.messagesReadHandlers.add(handlerOrOptions.onMessagesRead)
    }
  }

  if (!echo) {
    return () => unsubscribeUserChannel(userId, handlerOrOptions)
  }

  const channel = echo.private(channelName)

  // 1. ConversationUpdated listeners
  channel.stopListening('.ConversationUpdated')
  channel.stopListening('.App\\Events\\ConversationUpdated')
  channel.stopListening('ConversationUpdated')

  const dispatchUpdate = (payload: ConversationUpdatedPayload) => {
    const state = subscribedUserChannels.get(userId)
    state?.conversationUpdatedHandlers.forEach((h) => {
      try {
        h(payload)
      } catch (err) {
        console.error('[Echo] Error in conversationUpdated handler:', err)
      }
    })
  }

  channel.listen('.ConversationUpdated', dispatchUpdate)
  channel.listen('.App\\Events\\ConversationUpdated', dispatchUpdate)
  channel.listen('ConversationUpdated', dispatchUpdate)

  // 2. MessageSent listeners on user channel
  channel.stopListening('.MessageSent')
  channel.stopListening('.App\\Events\\MessageSent')
  channel.stopListening('MessageSent')

  const dispatchSent = (payload: MessageSentPayload) => {
    const state = subscribedUserChannels.get(userId)
    state?.messageSentHandlers.forEach((h) => {
      try {
        h(payload)
      } catch (err) {
        console.error('[Echo] Error in messageSent user handler:', err)
      }
    })
  }

  channel.listen('.MessageSent', dispatchSent)
  channel.listen('.App\\Events\\MessageSent', dispatchSent)
  channel.listen('MessageSent', dispatchSent)

  // 3. MessagesRead listeners on user channel
  channel.stopListening('.MessagesRead')
  channel.stopListening('.App\\Events\\MessagesRead')
  channel.stopListening('MessagesRead')

  const dispatchRead = (payload: MessagesReadPayload) => {
    const state = subscribedUserChannels.get(userId)
    state?.messagesReadHandlers.forEach((h) => {
      try {
        h(payload)
      } catch (err) {
        console.error('[Echo] Error in messagesRead user handler:', err)
      }
    })
  }

  channel.listen('.MessagesRead', dispatchRead)
  channel.listen('.App\\Events\\MessagesRead', dispatchRead)
  channel.listen('MessagesRead', dispatchRead)

  return () => unsubscribeUserChannel(userId, handlerOrOptions)
}

/**
 * Unsubscribe chat conversation update listener from private user channel.
 * Leaves the channel only when no other user-channel listeners remain active.
 */
export function unsubscribeUserChannel(
  userId: number,
  handlerOrOptions?: ((event: ConversationUpdatedPayload) => void) | UserChannelOptions
): void {
  const state = subscribedUserChannels.get(userId)
  if (!state) {
    return
  }

  if (typeof handlerOrOptions === 'function') {
    state.conversationUpdatedHandlers.delete(handlerOrOptions)
  } else if (handlerOrOptions) {
    if (handlerOrOptions.onConversationUpdated) {
      state.conversationUpdatedHandlers.delete(handlerOrOptions.onConversationUpdated)
    }
    if (handlerOrOptions.onMessageSent) {
      state.messageSentHandlers.delete(handlerOrOptions.onMessageSent)
    }
    if (handlerOrOptions.onMessagesRead) {
      state.messagesReadHandlers.delete(handlerOrOptions.onMessagesRead)
    }
  } else {
    state.conversationUpdatedHandlers.clear()
    state.messageSentHandlers.clear()
    state.messagesReadHandlers.clear()
  }

  if (
    state.conversationUpdatedHandlers.size === 0 &&
    state.messageSentHandlers.size === 0 &&
    state.messagesReadHandlers.size === 0 &&
    echoInstance
  ) {
    try {
      const channelName = `chat.user.${userId}`
      const ch = echoInstance.private(channelName)
      ch.stopListening('.ConversationUpdated')
      ch.stopListening('.App\\Events\\ConversationUpdated')
      ch.stopListening('ConversationUpdated')
      ch.stopListening('.MessageSent')
      ch.stopListening('.App\\Events\\MessageSent')
      ch.stopListening('MessageSent')
      ch.stopListening('.MessagesRead')
      ch.stopListening('.App\\Events\\MessagesRead')
      ch.stopListening('MessagesRead')
    } catch {
      // Ignore leave errors
    }
  }

  // If no handlers left on this channel at all, leave channel completely
  if (
    state.conversationUpdatedHandlers.size === 0 &&
    state.messageSentHandlers.size === 0 &&
    state.messagesReadHandlers.size === 0 &&
    state.notificationCreatedHandlers.size === 0
  ) {
    subscribedUserChannels.delete(userId)
    if (echoInstance) {
      try {
        echoInstance.leave(`chat.user.${userId}`)
      } catch {
        // Ignore leave errors
      }
    }
  }
}

/**
 * Subscribe to realtime notifications on the existing private user channel: chat.user.{userId}
 * Listens for .NotificationCreated without creating a duplicate channel or interfering with chat.
 * Uses Set-based handlers and dual event name bindings (.NotificationCreated & .App\Events\NotificationCreated)
 * to ensure bulletproof delivery regardless of broadcaster namespace formatting.
 */
export function subscribeUserNotifications(
  userId: number,
  handler: (event: NotificationCreatedPayload) => void
): () => void {
  const echo = getEcho()
  const channelName = `chat.user.${userId}`

  let channelState = subscribedUserChannels.get(userId)
  if (!channelState) {
    channelState = {
      conversationUpdatedHandlers: new Set(),
      notificationCreatedHandlers: new Set(),
      messageSentHandlers: new Set(),
      messagesReadHandlers: new Set(),
    }
    subscribedUserChannels.set(userId, channelState)
  }

  channelState.notificationCreatedHandlers.add(handler)

  if (!echo) {
    return () => unsubscribeUserNotifications(userId, handler)
  }

  const channel = echo.private(channelName)
  const dispatchNotification = (payload: NotificationCreatedPayload) => {
    if (import.meta.env.DEV) {
      console.log('[Echo Realtime] Received NotificationCreated event:', payload)
    }
    const state = subscribedUserChannels.get(userId)
    state?.notificationCreatedHandlers.forEach((h) => {
      try {
        h(payload)
      } catch (err) {
        console.error('[Echo] Error in notificationCreated handler:', err)
      }
    })
  }

  // Deduplicate listener bindings
  channel.stopListening('.NotificationCreated')
  channel.stopListening('.App\\Events\\NotificationCreated')
  channel.stopListening('NotificationCreated')
  channel.listen('.NotificationCreated', dispatchNotification)
  channel.listen('.App\\Events\\NotificationCreated', dispatchNotification)
  channel.listen('NotificationCreated', dispatchNotification)

  return () => unsubscribeUserNotifications(userId, handler)
}

/**
 * Unsubscribe realtime notification listener from private user channel.
 * Leaves the channel only when no other user-channel listeners remain active.
 */
export function unsubscribeUserNotifications(
  userId: number,
  handler?: (event: NotificationCreatedPayload) => void
): void {
  const state = subscribedUserChannels.get(userId)
  if (!state) {
    return
  }

  if (handler) {
    state.notificationCreatedHandlers.delete(handler)
  } else {
    state.notificationCreatedHandlers.clear()
  }

  if (state.notificationCreatedHandlers.size === 0 && echoInstance) {
    try {
      const channelName = `chat.user.${userId}`
      echoInstance.private(channelName).stopListening('.NotificationCreated')
      echoInstance.private(channelName).stopListening('.App\\Events\\NotificationCreated')
      echoInstance.private(channelName).stopListening('NotificationCreated')
    } catch {
      // Ignore leave errors
    }
  }

  // If no handlers left on this channel at all, leave channel completely
  if (state.conversationUpdatedHandlers.size === 0 && state.notificationCreatedHandlers.size === 0) {
    subscribedUserChannels.delete(userId)
    if (echoInstance) {
      try {
        echoInstance.leave(`chat.user.${userId}`)
      } catch {
        // Ignore leave errors
      }
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
