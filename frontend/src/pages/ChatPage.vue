<script setup lang="ts">
import { ref, computed, onMounted, toRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import { ChatService } from '@/services/chatService'
import { useChatEcho } from '@/composables/useChatEcho'
import ConversationSidebar from '@/components/chat/ConversationSidebar.vue'
import MessageList from '@/components/chat/MessageList.vue'
import MessageComposer from '@/components/chat/MessageComposer.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const chatStore = useChatStore()

const composerRef = ref<InstanceType<typeof MessageComposer> | null>(null)
const isOpeningFromRoute = ref(false)

// Mobile view state: show message pane when conversation is active
const isMobileActive = computed(() => chatStore.activeConversation !== null)

// Initialize useChatEcho composable with reactive refs for user and active conversation
useChatEcho({
  userRef: toRef(authStore, 'user'),
  conversationIdRef: computed(() => (chatStore.activeConversation ? Number(chatStore.activeConversation.id) : null)),
})

onMounted(async () => {
  // Fetch initial conversations list & unread count
  await Promise.all([
    chatStore.fetchConversations(),
    chatStore.fetchUnreadCount(),
  ])

  // Handle query params if navigated with ?user_id=... or ?conversation_id=...
  await handleRouteParams()
})

// Watch query params in case user navigates to /chat with different query
watch(
  () => route.query,
  async () => {
    await handleRouteParams()
  }
)

async function handleRouteParams() {
  const userIdQuery = route.query.user_id
  const convIdQuery = route.query.conversation_id

  if (userIdQuery) {
    const targetUserId = parseInt(String(userIdQuery), 10)
    if (!isNaN(targetUserId) && targetUserId !== authStore.user?.id) {
      isOpeningFromRoute.value = true
      try {
        const res = await ChatService.findDirectConversation(targetUserId)
        if (res.data) {
          // Conversation already exists and has messages, open it directly
          await chatStore.openConversation(res.data.id, authStore.user?.id)
          router.replace({ path: '/chat', query: { conversation_id: res.data.id } })
        } else if (res.recipient) {
          // No conversation exists yet: open draft conversation state without DB record
          chatStore.openDraftConversation(res.recipient)
        }
      } catch {
        chatStore.error = 'Không thể tìm thấy thông tin người dùng.'
      } finally {
        isOpeningFromRoute.value = false
      }
      return
    }
  }

  if (convIdQuery) {
    const targetConvId = parseInt(String(convIdQuery), 10)
    if (!isNaN(targetConvId) && targetConvId !== chatStore.activeConversation?.id) {
      await chatStore.openConversation(targetConvId, authStore.user?.id)
    }
  }
}

async function handleSelectConversation(conversationId: number) {
  if (chatStore.activeConversation?.id === conversationId) return
  await chatStore.openConversation(conversationId, authStore.user?.id)
  router.replace({ path: '/chat', query: { conversation_id: conversationId } })
}

function handleBackToSidebar() {
  chatStore.closeConversation()
  router.replace({ path: '/chat' })
}

async function handleSendMessage(body: string) {
  try {
    const wasDraft = chatStore.activeConversation?.id === 0
    await chatStore.sendMessage(body)
    composerRef.value?.clear()
    if (wasDraft && chatStore.activeConversation?.id) {
      router.replace({ path: '/chat', query: { conversation_id: chatStore.activeConversation.id } })
    }
  } catch {
    // MessageComposer preserves user's text on error
  }
}

function handleLoadOlderMessages() {
  chatStore.loadOlderMessages()
}

function handleRetrySidebar() {
  chatStore.fetchConversations()
}
</script>

<template>
  <div class="chat-page-container">
    <div class="chat-card" :class="{ 'mobile-viewing-message': isMobileActive }">
      <!-- Left: Conversation Sidebar -->
      <div class="sidebar-wrapper">
        <ConversationSidebar
          :conversations="chatStore.conversations"
          :active-conversation-id="chatStore.activeConversationId"
          :is-loading="chatStore.isLoadingConversations || isOpeningFromRoute"
          :error="chatStore.error"
          @select="handleSelectConversation"
          @retry="handleRetrySidebar"
        />
      </div>

      <!-- Right: Message Area (List + Composer) -->
      <main class="chat-main" aria-label="Khu vực tin nhắn">
        <MessageList
          :conversation="chatStore.activeConversation"
          :messages="chatStore.activeMessages"
          :current-user-id="authStore.user?.id"
          :is-loading="chatStore.isLoadingMessages"
          :has-more="chatStore.messagesPagination.has_more"
          @load-older="handleLoadOlderMessages"
          @back="handleBackToSidebar"
        />

        <MessageComposer
          v-if="chatStore.activeConversation"
          ref="composerRef"
          :is-sending="chatStore.isSendingMessage"
          :error="chatStore.error"
          @send="handleSendMessage"
        />
      </main>
    </div>
  </div>
</template>

<style scoped>
.chat-page-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: calc(100vh - 64px);
  padding: var(--spacing-sm);
  background-color: var(--color-bg);
}

.chat-card {
  display: flex;
  width: 100%;
  max-width: 1200px;
  height: 100%;
  background-color: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-lg);
}

.sidebar-wrapper {
  display: flex;
  height: 100%;
}

.chat-main {
  display: flex;
  flex-direction: column;
  flex: 1;
  height: 100%;
  min-width: 0;
  background-color: var(--color-bg);
}

@media (max-width: 768px) {
  .chat-page-container {
    padding: 0;
    height: calc(100vh - 64px);
  }

  .chat-card {
    border-radius: 0;
    border: none;
  }

  /* On mobile: toggle between sidebar and message pane */
  .sidebar-wrapper {
    width: 100%;
  }

  .chat-main {
    display: none;
  }

  .chat-card.mobile-viewing-message .sidebar-wrapper {
    display: none;
  }

  .chat-card.mobile-viewing-message .chat-main {
    display: flex;
  }
}
</style>
