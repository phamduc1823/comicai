# Báo cáo kiểm thử CAI-MSG-04 — Tách riêng Notification Bell, Khắc phục Active Chat Real-time, Dấu tích ✓✓ và Thông báo Bình luận

- **Task ID**: `CAI-MSG-04`
- **Ngày thực hiện**: 25/11/2026
- **Trạng thái**: `DONE (chờ duyệt ACCEPTED)`
- **Chi nhánh Git**: `main`

---

## 1. Mục tiêu & Bối cảnh

Khắc phục triệt để các phản hồi kiểm thử thực tế từ người dùng kèm ảnh chụp màn hình:
1. **Khung chat chính không hiển thị message mới real-time**: Sidebar bên trái đã cập nhật preview và hiển thị badge, nhưng tin nhắn mới chưa được append vào khung chat chính đang mở mà phải reload mới thấy.
2. **Dấu tích đã xem `✓✓` (Read Receipts) không real-time**: Người gửi chỉ nhìn thấy `✓✓` sau khi tải lại trang, không xuất hiện tức thời khi người nhận vừa đọc tin nhắn.
3. **Badge số lượng tin nhắn trên Header không real-time**: Badge tin nhắn cần hoạt động độc lập và cập nhật tức thì, không bị nhầm lẫn hay đẩy sang chuông thông báo.
4. **Chuông thông báo (Bell Icon) bị lẫn tin nhắn mới**: Chuông thông báo chỉ được phép hiển thị Like và Comment, tuyệt đối không đếm hoặc hiển thị tin nhắn mới.
5. **Không có thông báo khi có bình luận mới**: Người dùng phản ánh khi có bình luận thì không hiển thị thông báo.

---

## 2. Phân tích nguyên nhân gốc (Root Cause Analysis)

1. **Active Chat không append tin nhắn mới real-time**:
   - Trước đây khi component chat nhận sự kiện `ConversationUpdated` qua kênh cá nhân `chat.user.{id}`, store `chat.ts` chỉ cập nhật preview `conv.last_message` trong sidebar mà không kiểm tra xem người dùng có đang mở đúng cuộc trò chuyện đó không để append vào `activeMessages`.
   - Ngoài ra, nếu người dùng đang ở trạng thái hội thoại nháp (draft `id === 0`) chờ tin nhắn từ đối phương, việc so sánh `activeConversation.id === convId` trả về `false` làm mất kết nối cập nhật real-time.
2. **Dấu tích `✓✓` không real-time (phải reload mới thấy)**:
   - Trong `MessageList.vue`, điều kiện hiển thị kiểm tra `!msg.status`.
   - Khi tin nhắn được gửi đi trong phiên hiện tại và server phản hồi thành công, `sendMessage` gán `msg.status = 'sent'`.
   - Vì `'sent'` là chuỗi truthy, biểu thức `!msg.status` đánh giá thành `false`, khiến thẻ hiển thị `✓✓` bị ẩn hoàn toàn cho toàn bộ tin nhắn gửi trong phiên cho đến khi người dùng reload trang (khi `getMessages` trả về dữ liệu thuần không có thuộc tính `status`).
3. **Badge số lượng tin nhắn trên Header không real-time**:
   - Khi người dùng ở trang khác (chẳng hạn Trang chủ), danh sách `conversations` trong store chưa được tải về (`length === 0`). Khi sự kiện `ConversationUpdated` đến, hàm `updateComputedTotalUnread()` tính `reduce` trên mảng rỗng làm reset `totalUnreadCount` về 0.
4. **Chuông thông báo bị lẫn tin nhắn**:
   - Bảng `notifications` trong MySQL local còn lưu trữ 15 bản ghi `NewMessageNotification` cũ được tạo ra trước đó.
   - Hàm `sendMessage()` trước đây gọi `notifyNewMessage()`, sinh bản ghi thông báo tin nhắn vào cơ sở dữ liệu.
5. **Không có thông báo khi bình luận**:
   - Trong `NotificationService::notifyCommentCreated()`, logic trước đó chỉ xử lý bình luận trên `post`, hoàn toàn bỏ qua bình luận trên truyện tranh (`comic`) và chương truyện (`chapter`).
   - Trong `echoService.ts`, hàm `subscribeUserNotifications()` chỉ đăng ký lắng nghe `.NotificationCreated` và `.App\Events\NotificationCreated`, thiếu listener `NotificationCreated` (không có dấu chấm), khiến một số phiên bản Pusher/Echo không kích hoạt callback khi nhận sự kiện từ Reverb.

---

## 3. Các thay đổi đã triển khai

### Backend (Laravel 12 / PHP 8.5)
1. `backend/app/Services/MessageService.php`:
   - Gỡ bỏ hoàn toàn việc gọi `notifyNewMessage()` trong luồng `sendMessage()`. Tin nhắn mới không còn tạo bản ghi thông báo trong bảng `notifications`.
2. `backend/app/Services/NotificationService.php`:
   - Định nghĩa `BELL_NOTIFICATION_TYPES = [PostLikedNotification::class, PostCommentedNotification::class]`.
   - Áp dụng bộ lọc `whereIn('type', self::BELL_NOTIFICATION_TYPES)` cho các hàm `getNotificationsForUser()`, `getUnreadCount()`, `markAllAsRead()`.
   - Mở rộng hàm `notifyCommentCreated()`:
     - Xử lý bình luận bài viết (`post`): Thông báo tác giả bài viết (`$post->user_id`).
     - Xử lý bình luận truyện tranh (`comic`): Thông báo tác giả truyện (`$comic->user_id`).
     - Xử lý bình luận chương truyện (`chapter`): Thông báo tác giả truyện (`$chapter->comic->user_id`).
     - Xử lý trả lời bình luận (reply): Thông báo tác giả của bình luận cha (`$parent->user_id`).
3. `backend/app/Notifications/PostCommentedNotification.php`:
   - Hỗ trợ thêm tham số `?Comic $comic = null` và `?Chapter $chapter = null`.
   - Sinh tiêu đề phù hợp (`... đã bình luận về truyện của bạn` / `... đã bình luận về chương của bạn`).
   - Thiết lập target URL điều hướng chính xác (`/comics/{id}` hoặc `/comics/{comic_id}/read/{id}`).
4. Dọn dẹp dữ liệu MySQL local:
   - Đã xóa sạch 15 bản ghi cũ `NewMessageNotification` khỏi bảng `notifications`. Bảng chỉ còn lưu trữ thông báo Like và Comment.
5. `backend/tests/Feature/NotificationApiTest.php`:
   - Bổ sung Test 21 kiểm tra thông báo khi bình luận truyện và chương. Toàn bộ 21/21 tests PASS.

### Frontend (Vue 3 / TypeScript / Pinia / Laravel Echo)
1. `frontend/src/components/chat/MessageList.vue`:
   - Sửa điều kiện hiển thị dấu `✓✓` thành:
     `v-if="(!msg.status || msg.status === 'sent') && Number(msg.sender_id) === Number(currentUserId) && Number(otherParticipantLastReadId) >= Number(msg.id)"`.
2. `frontend/src/stores/chat.ts`:
   - Bổ sung logic append tin nhắn mới vào `activeMessages` trong cả `handleMessageSent` và `handleConversationUpdated` khi `isViewing === true`.
   - Hỗ trợ tự động chuyển đổi từ trạng thái hội thoại nháp (`id === 0`) sang hội thoại chính thức khi nhận tin nhắn đầu tiên từ đối phương.
   - Sửa `handleMessagesRead`: Sử dụng ép kiểu số `Number(activeConversation.value.id) === Number(payload.conversation_id)`, kích hoạt reactivity cập nhật `participants`.
   - Tối ưu `updateComputedTotalUnread()`: Không ghi đè `totalUnreadCount` khi danh sách `conversations` chưa tải.
3. `frontend/src/services/echoService.ts`:
   - Bổ sung `channel.listen('NotificationCreated', dispatchNotification)` trong `subscribeUserNotifications()` và reconnect handler.
4. `frontend/src/composables/useNotificationRealtime.ts` & `frontend/src/stores/notification.ts`:
   - Tiếp nhận danh sách loại thông báo chuông: `['post_liked', 'post_commented', 'comic_commented', 'chapter_commented']`.
5. `frontend/src/components/NotificationBell.vue`:
   - Bổ sung điều hướng an toàn cho target `comic` (`/comics/{id}`) và `chapter` (`item.target.url`).

---

## 4. Kết quả kiểm thử & Quality Gate

| Thành phần | Lệnh kiểm thử | Kết quả | Ghi chú |
|---|---|---|---|
| Backend Test Suite | `php artisan test` | **714 passed (3716 assertions)** | 100% tests PASS (46.12s) |
| Backend Notification Tests | `php artisan test --filter=NotificationApiTest` | **21 passed (194 assertions)** | Bao gồm Test 21 (Comment notification) |
| Frontend Type Check | `vue-tsc -b --noEmit` | **0 errors (PASS)** | Type an toàn 100% |
| Frontend Build | `vite build` | **PASS (1.39s)** | 247 modules transformed |
| Unit Tests Notification | `node frontend/tests/unit-notifications.mjs` | **16/16 passed** | 100% PASS |
| Unit Tests Messaging Fixes | `node frontend/tests/unit-messaging-fixes.mjs` | **7/7 passed** | 100% PASS |
| Unit Tests Chat Lazy | `node frontend/tests/unit-chat-lazy.mjs` | **4/4 passed** | 100% PASS |
| Unit Tests Community | `node frontend/tests/unit-community.mjs` | **6/6 passed** | 100% PASS |
| Unit Tests Profile | `node frontend/tests/unit-profile.mjs` | **6/6 passed** | 100% PASS |
