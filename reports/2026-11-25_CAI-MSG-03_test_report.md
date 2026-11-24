# BÁO CÁO NGHIỆM THU KỸ THUẬT: CAI-MSG-03
## Messaging Real-time, Unread Badge, Send Latency & Comment Notification

- **Mã Task**: `CAI-MSG-03`
- **Thời gian thực hiện**: 2026-11-25
- **Mục tiêu**:
  1. Khắc phục triệt để lỗi Header Unread Badge chỉ cập nhật sau khi reload trang; badge phải nhảy số tức thì khi có tin nhắn mới dù người dùng đang ở bất kỳ trang nào (`/`, `/community`, `/chat`).
  2. Khắc phục lỗi khung chat đang mở không hiển thị tin nhắn mới real-time (ở thanh tin nhắn nhanh bên trái và chuông thông báo đã có, nhưng khung chat chính chưa hiển thị tin nhắn mới).
  3. Khắc phục dấu đã xem `✓✓` không real-time (phải load lại trang mới thấy `✓✓`).
  4. Triển khai Optimistic UI cho tin nhắn gửi đi, xóa bỏ hoàn toàn độ trễ 1–2 giây, tin nhắn hiển thị ngay 0ms trong khung chat kèm cơ chế reconcile an toàn bằng `client_message_id`.
  5. Xây dựng phân hệ thông báo bình luận cộng đồng: khi có bình luận mới hoặc phản hồi bình luận, tạo notification và phát real-time đến chuông thông báo của tác giả.
- **Trạng thái**: `DONE — CHỜ USER ACCEPTANCE`

---

## 1. Phân tích Nguyên nhân gốc rễ (Root Cause Analysis)

### Bug 1: Header unread badge chỉ cập nhật sau khi reload
1. **Trạng thái rỗng khi ở ngoài trang `/chat`**: Khi người dùng duyệt ứng dụng (ở `HomePage` hoặc các trang khác), `chatStore.conversations` là mảng rỗng `[]` vì chưa gọi `fetchConversations()`. Khi event `ConversationUpdated` được dispatch đến kênh cá nhân `chat.user.{userId}`, hàm `handleConversationUpdated` không tìm thấy cuộc trò chuyện trong mảng nên chỉ gọi `fetchConversations()` ngầm mà không lập tức tăng `totalUnreadCount.value`, làm badge bị đóng băng cho đến khi người dùng reload trang.
2. **Khối điều kiện Snapshot chặn nhầm dữ liệu mới**: Trong `handleConversationUpdated`, điều kiện kiểm tra `if (payload.last_message.id === conv.last_message.id && conv.unread_count !== undefined && payload.unread_count > conv.unread_count)` đã ngắt sớm (`return`) và chuyển sang debounce 1.5 giây vì nghi ngờ snapshot cũ. Khi `MessageSent` đến trước gán `conv.last_message.id`, `ConversationUpdated` đến ngay sau đó bị chặn đứng việc cập nhật unread count trong bộ nhớ reactive.
3. **Event Name Binding đơn nhất**: `echoService.ts` chỉ lắng nghe duy nhất `.ConversationUpdated`. Trong một số broadcast driver hoặc cấu hình Reverb/Echo, event có thể mang namespace đầy đủ hoặc không có dấu chấm dẫn đầu (`ConversationUpdated`, `.App\Events\ConversationUpdated`), dẫn tới event bị bỏ lỡ.

### Bug 2: Khung chat đang mở không append tin nhắn real-time
1. **Kênh phát đơn tuyến (Single-surface broadcasting)**: Ban đầu event `MessageSent` chỉ broadcast trên kênh hội thoại `chat.conversation.{convId}`. Nếu người dùng chuyển trang hoặc watcher `conversationIdRef` gặp độ trễ/ngắt kết nối WebSocket channel tạm thời, tin nhắn bị lỡ.
2. **Thiếu cơ chế Append từ User Channel**: Kênh cá nhân `chat.user.{userId}` nhận được `ConversationUpdated` (và `NotificationCreated`), nhưng hàm `handleConversationUpdated` trong store CHỈ cập nhật danh sách hội thoại bên trái mà KHÔNG append `payload.last_message` vào `activeMessages` của khung chat chính nếu người dùng đang mở đúng cuộc trò chuyện đó.
3. **Disconnect WebSocket Channel đột ngột khi re-subscribe**: Hàm `subscribeConversationChannel` trước đó gọi `unsubscribeConversationChannel` (thực hiện `echo.leave()`), làm hủy đăng ký kênh WebSocket ngay trước khi subscribe lại, gây ra hiện tượng mất gói tin broadcast real-time.
4. **Kiểu dữ liệu và Vue 3 Array Reactivity**: Việc so sánh `activeConversation.id === message.conversation_id` chưa đồng bộ ép kiểu `Number()`, đồng thời phép gán `activeMessages.value.push()` đôi khi không kích hoạt re-render đồng bộ trên watcher scroll của `MessageList.vue`.

### Bug 3: Dấu đã xem `✓✓` không real-time (phải reload mới thấy)
1. Event `MessagesRead` trước đó chỉ phát trên kênh `chat.conversation.{id}`. Sender không nhận được nếu kênh hội thoại chưa kịp bind hoặc bị lệch.
2. Phép mutate trực tiếp trên participant không kích hoạt reactivity của Vue 3 trên props `conversation.participants`.

### Bug 4: Gửi tin nhắn bị giật/trễ 1–2 giây
1. **Chặn giao diện đồng bộ (Synchronous Blocking)**: Khi người dùng bấm Gửi hoặc nhấn Enter, `MessageComposer.vue` disable toàn bộ textarea và giữ nguyên nội dung tin nhắn trong ô nhập liệu. Store chờ toàn bộ round-trip HTTP request (`POST /api/chat/conversations/{id}/messages`) gửi đi và chờ server lưu DB, phát sinh độ trễ mạng từ 500ms–1500ms khiến ứng dụng tạo cảm giác bị đơ.
2. **Thiếu cơ chế Optimistic UI & Reconcile**: Hệ thống chưa có cơ chế hiển thị trước tin nhắn tạm với trạng thái `sending` và tự động hoán đổi (reconcile) bằng `client_message_id` khi server trả về mã 201.

### Bug 5: Thiếu thông báo khi có bình luận cộng đồng
1. Backend hoàn toàn chưa có Notification class và Service trigger cho sự kiện bình luận trên bài viết hoặc trả lời bình luận.

---

## 2. Các thay đổi và Giải pháp triển khai

### A. Chuẩn hóa Multi-surface Broadcasting & Multi-binding (`backend` & `echoService.ts`)
1. **Multi-surface Broadcast trên Backend**:
   - `backend/app/Events/MessageSent.php`: Phát đồng thời tới `chat.conversation.{convId}` và `chat.user.{recipientId}` của các bên tham gia cuộc trò chuyện.
   - `backend/app/Events/MessagesRead.php`: Phát đồng thời tới `chat.conversation.{convId}` và `chat.user.{pId}` của tất cả các bên tham gia.
2. **Multi-binding và Kết nối bền vững (`echoService.ts`)**:
   - `chat.user.{userId}` lắng nghe: `.ConversationUpdated`, `.App\Events\ConversationUpdated`, `ConversationUpdated`, `.MessageSent`, `.MessagesRead`.
   - `chat.conversation.{convId}` lắng nghe: `.MessageSent`, `.App\Events\MessageSent`, `MessageSent`, cùng `.MessagesRead`, `.App\Events\MessagesRead`, `MessagesRead`.
   - Thay thế việc `leave()` kênh đột ngột bằng `channel.stopListening()` có chọn lọc, giữ nguyên kết nối kênh WebSocket hoạt động liên tục.
   - Xuất `echoConnectionStatus = ref<ConnectionStatus>('disconnected')` reactive để hiển thị chính xác trạng thái kết nối trên sidebar.

### B. Cập nhật Store Xử lý Real-time & Optimistic UI (`stores/chat.ts`)
1. **Khắc phục Header Badge**:
   - Trong `handleConversationUpdated`: Khi `conv` chưa nạp vào store (ngoài `/chat`), lập tức cộng dồn `totalUnreadCount.value += Number(payload.unread_count || 1)` để badge trên Header nhảy số trong **0ms**, đồng thời kích hoạt đồng bộ nền `fetchUnreadCount()` và `fetchConversations()`.
   - Trong `useNotificationRealtime.ts`: Bổ sung hook lắng nghe `NotificationCreated`, khi nhận event tin nhắn mới, tự động kích hoạt `chatStore.fetchUnreadCount()` và đảm bảo `totalUnreadCount >= 1` ngay tức thì dù ở bất kỳ trang nào.
2. **Khắc phục Khung Chat Real-time & Đồng bộ 2 chiều**:
   - Trong `handleConversationUpdated`: Nếu người dùng đang mở đúng cuộc trò chuyện (`Number(activeConversation.id) === Number(convId)`), lập tức append/reconcile `payload.last_message` vào `activeMessages.value`, kích hoạt Vue 3 reactivity `[...activeMessages.value]`, tự động cuộn xuống qua `scrollToBottom` và gọi `markAsRead` tự động. Gán `conv.unread_count = 0` để không tăng unread count ảo.
   - Trong `handleMessageSent`: Chuẩn hóa so sánh `Number(activeConversation.value.id) === Number(message.conversation_id)`.
3. **Khắc phục Dấu đã xem `✓✓` Real-time**:
   - Bắt event `MessagesRead` trên cả user channel và conversation channel. Cập nhật ngay `participant.last_read_message_id = payload.last_read_message_id` với reference mới để Vue 3 kích hoạt re-render ngay lập tức.
4. **Triển khai Optimistic UI cho `sendMessage`**:
   - Khi người dùng gửi tin, tạo ngay tin nhắn tạm với ID âm `-Date.now()` và trạng thái `status: 'sending'`.
   - Append tức thì vào `activeMessages` và cập nhật preview cuộc trò chuyện ở sidebar.
   - Khi API server phản hồi 201: hàm reconcile thay thế tin nhắn tạm bằng dữ liệu thực từ server (`status: 'sent'`) với ID chính thức.
   - Nếu xảy ra lỗi mạng: đánh dấu `status: 'failed'` để người dùng nhận biết ngay mà không làm mất nội dung tin nhắn.

### C. Xây dựng Phân hệ Thông báo Bình luận (`PostCommentedNotification`)
1. **Tạo Notification Class**: `backend/app/Notifications/PostCommentedNotification.php` với `kind: 'post_commented'`, payload chứa thông tin actor, nội dung tóm tắt, target `type: 'post'`, `url: '/posts/' . $post->id`.
2. **Cập nhật NotificationService**: Thêm `notifyCommentCreated(Comment $comment, User $commenter)`:
   - Tự động phân loại: bình luận bài viết (thông báo cho tác giả bài viết) và trả lời bình luận (thông báo cho tác giả bình luận gốc).
   - Chặn thông báo tự gửi cho chính mình (self-comment/reply) và tự động chống trùng lặp qua `dedupeKey`.
   - Dispatch `NotificationCreated` qua private channel `chat.user.{recipientId}`.
3. **Tích hợp CommentController**: Gọi `notifyCommentCreated` ngay sau khi commit transaction tạo comment.

---

## 3. Kết quả Kiểm thử & Quality Gate

1. **Frontend Unit Test Suite (`frontend/tests/unit-messaging-fixes.mjs`)**:
   - Suite 1: Sender identity & bubble alignment (PASS).
   - Suite 2: Read receipts (`✓✓`) reactivity & participant matching (PASS).
   - Suite 3: Unread message count calculation & real-time sync (PASS).
   - Suite 4: Real-time Header badge update across app (PASS).
   - Suite 5: Active chat real-time append & reactivity (PASS).
   - Suite 6: Optimistic UI, temporary negative ID, `client_message_id` reconciliation & error state (PASS).
   - Kết quả: **6/6 test suites PASS (100%)**.
2. **Các Frontend Test Suite liên quan**:
   - `unit-chat-lazy.mjs`: **4/4 tests PASS**.
   - `unit-notifications.mjs`: **15/15 tests PASS**.
   - `unit-community.mjs`: **6/6 tests PASS**.
3. **Type-check & Production Build**:
   - `npm run type-check`: **0 lỗi (PASS)**.
   - `npm run build`: **Vite build thành công trong 1.20s (PASS)**.
4. **Backend Test Suites**:
   - `NotificationApiTest`: **20/20 tests PASS (198 assertions)** — bao gồm 3 test mới cho Comment & Reply Notification.
   - `ChatBroadcastEventsTest`: **11/11 tests PASS (64 assertions)**.
   - `MessagingBugFixesTest`: **7/7 tests PASS (26 assertions)**.
   - `ConversationApiTest`: **45/45 tests PASS (205 assertions)**.
   - `LazyConversationApiTest`: **9/9 tests PASS (55 assertions)**.
   - **Toàn bộ backend test suite (`php artisan test`)**: **713/713 tests PASS (3720 assertions)**.
