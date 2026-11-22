import { watch, onUnmounted, type Ref } from 'vue'
import {
  subscribeConversationChannel,
  unsubscribeConversationChannel,
  subscribeUserChannel,
  unsubscribeUserChannel,
  onEchoReconnect,
} from '@/services/echoService'
import { useChatStore } from '@/stores/chat'
import type { User } from '@/types/auth'

/**
 * Composable to manage Laravel Echo subscription lifecycle with Vue components.
 * Automatically synchronizes subscriptions with active conversation and auth user.
 */
export function useChatEcho(options?: {
  userRef?: Ref<User | null>
  conversationIdRef?: Ref<number | null>
}) {
  const chatStore = useChatStore()

  let currentSubscribedConversationId: number | null = null
  let currentSubscribedUserId: number | null = null

  // Cleanups
  let unsubscribeReconnect: (() => void) | null = null

  /**
   * Subscribe to user channel for personal conversation updates.
   */
  function syncUserSubscription(userId: number | null | undefined): void {
    if (currentSubscribedUserId && currentSubscribedUserId !== userId) {
      unsubscribeUserChannel(currentSubscribedUserId)
      currentSubscribedUserId = null
    }

    if (userId && currentSubscribedUserId !== userId) {
      currentSubscribedUserId = userId
      subscribeUserChannel(userId, (payload) => {
        chatStore.handleConversationUpdated(payload, userId)
      })
    }
  }

  /**
   * Subscribe to conversation channel for real-time messages and read markers.
   */
  function syncConversationSubscription(conversationId: number | null | undefined, userId?: number): void {
    if (currentSubscribedConversationId && currentSubscribedConversationId !== conversationId) {
      unsubscribeConversationChannel(currentSubscribedConversationId)
      currentSubscribedConversationId = null
    }

    if (conversationId && currentSubscribedConversationId !== conversationId) {
      currentSubscribedConversationId = conversationId
      subscribeConversationChannel(conversationId, {
        onMessageSent: (payload) => {
          chatStore.handleMessageSent(payload, userId)
        },
        onMessagesRead: (payload) => {
          chatStore.handleMessagesRead(payload)
        },
      })
    }
  }

  // Set up reconnect listener
  unsubscribeReconnect = onEchoReconnect(() => {
    const userId = options?.userRef?.value?.id
    chatStore.handleReconnect(userId)
  })

  // Watch for changes in authenticated user
  if (options?.userRef) {
    watch(
      () => options.userRef?.value?.id,
      (newUserId) => {
        syncUserSubscription(newUserId)
      },
      { immediate: true }
    )
  }

  // Watch for changes in active conversation ID
  if (options?.conversationIdRef) {
    watch(
      () => options.conversationIdRef?.value,
      (newConvId) => {
        const userId = options?.userRef?.value?.id
        syncConversationSubscription(newConvId, userId)
      },
      { immediate: true }
    )
  }

  // Cleanup on unmount
  onUnmounted(() => {
    if (currentSubscribedConversationId) {
      unsubscribeConversationChannel(currentSubscribedConversationId)
      currentSubscribedConversationId = null
    }
    if (currentSubscribedUserId) {
      unsubscribeUserChannel(currentSubscribedUserId)
      currentSubscribedUserId = null
    }
    if (unsubscribeReconnect) {
      unsubscribeReconnect()
      unsubscribeReconnect = null
    }
  })

  return {
    syncUserSubscription,
    syncConversationSubscription,
  }
}
