<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, toRef, watch } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
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

function handleVisibilityChange() {
  const visible = typeof document !== 'undefined' ? document.visibilityState === 'visible' : true
  chatStore.setWindowVisible(visible)
  if (visible && chatStore.activeConversation && authStore.user?.id) {
    // If returning to tab and actively viewing a conversation, mark latest message as read if unread
    if (chatStore.activeMessages.length > 0) {
      const latestMsg = chatStore.activeMessages[chatStore.activeMessages.length - 1]
      if (latestMsg && Number(latestMsg.sender_id) !== Number(authStore.user.id)) {
        chatStore.markAsRead(Number(latestMsg.id), authStore.user.id)
      }
    }
  }
}

onMounted(async () => {
  chatStore.setChatViewActive(true)
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', handleVisibilityChange)
  }

  // Fetch initial conversations list & unread count
  const initialTab = route.query.tab === 'requests' ? 'requests' : 'main'
  await Promise.all([
    chatStore.fetchConversations(1, 20, initialTab),
    chatStore.fetchUnreadCount(),
    chatStore.fetchMessageRequestsCount(),
  ])

  // Handle query params if navigated with ?user_id=... or ?conversation_id=...
  await handleRouteParams()
})

onBeforeRouteLeave(() => {
  chatStore.setChatViewActive(false)
  chatStore.closeConversation()
})

onUnmounted(() => {
  chatStore.setChatViewActive(false)
  chatStore.closeConversation()
  if (typeof document !== 'undefined') {
    document.removeEventListener('visibilitychange', handleVisibilityChange)
  }
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

async function handleSendMessage(payload: any) {
  try {
    const wasDraft = chatStore.activeConversation?.id === 0
    if (typeof payload === 'string') {
      await chatStore.sendMessage(payload)
    } else {
      await chatStore.sendMessage(
        payload.body || '',
        'text',
        undefined,
        {
          images: payload.images,
          comic_id: payload.comic_id,
          comic_preview: payload.comic_preview,
        }
      )
    }
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

async function handleChangeTab(tab: 'main' | 'requests') {
  await chatStore.fetchConversations(1, 20, tab)
  const query = { ...route.query }
  if (tab === 'requests') {
    query.tab = 'requests'
  } else {
    delete query.tab
  }
  router.replace({ path: '/chat', query })
}

async function handleAcceptRequest() {
  await chatStore.acceptActiveConversation()
}

async function handleRejectRequest() {
  await chatStore.rejectActiveConversation()
  router.replace({ path: '/chat', query: route.query.tab ? { tab: route.query.tab } : {} })
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
          :active-tab="chatStore.activeTab"
          :requests-count="chatStore.messageRequestsCount"
          @select="handleSelectConversation"
          @retry="handleRetrySidebar"
          @change-tab="handleChangeTab"
        />
      </div>

      <!-- Right: Message Area (List + Composer) -->
      <main class="chat-main" aria-label="Khu vực tin nhắn">
        <!-- Message Request Action Banner -->
        <div v-if="chatStore.activeConversation?.is_pending_request" class="message-request-banner" role="alert">
          <div class="banner-text-group">
            <span class="banner-icon" aria-hidden="true">📬</span>
            <div class="banner-desc">
              <strong>Tin nhắn chờ:</strong> Người này muốn gửi tin nhắn cho bạn. Chấp nhận để tiếp tục trò chuyện hoặc từ chối để xóa tin nhắn chờ này.
            </div>
          </div>
          <div class="banner-actions">
            <button
              type="button"
              class="btn-request-accept"
              @click="handleAcceptRequest"
            >
              Chấp nhận
            </button>
            <button
              type="button"
              class="btn-request-reject"
              @click="handleRejectRequest"
            >
              Từ chối
            </button>
          </div>
        </div>

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

.message-request-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 18px;
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  color: #166534;
}

.banner-text-group {
  display: flex;
  align-items: center;
  gap: 10px;
}

.banner-icon {
  font-size: 1.25rem;
}

.banner-desc {
  font-size: 0.86rem;
  line-height: 1.4;
}

.banner-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

.btn-request-accept {
  padding: 6px 14px;
  background: #16a34a;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.btn-request-accept:hover {
  background: #15803d;
}

.btn-request-reject {
  padding: 6px 14px;
  background: #ffffff;
  color: #dc2626;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-request-reject:hover {
  background: #fee2e2;
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
