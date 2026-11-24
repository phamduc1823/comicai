# BÁO CÁO KIỂM THỬ: CAI-MSG-05
## Khắc phục trạng thái Đã đọc khi rời khỏi khung Chat (Read State Lifecycle When Leaving Chat View)

- **Mã Task**: `CAI-MSG-05`
- **Ngày thực hiện**: 2026-11-25
- **Môi trường**: MySQL Local `127.0.0.1:3306`, Database `comicai`, Laravel Reverb WebSocket `:8080`, Vite Frontend `:5173`.
- **Trạng thái**: **DONE — CHỜ USER ACCEPTANCE**.

---

### 1. Bối cảnh & Hiện tượng lỗi (Ảnh chụp người dùng cung cấp)
- **Ảnh 1 (`media_1795555495988_e0546177.png`)**:
  - Người dùng "Độc Giả Thân Thiết" mở chat với "Họa Sĩ Minh Trí".
  - Thanh bên trái và thông báo hiển thị tin nhắn mới `oce (1)`, nhưng khung chat chính bị lệch cập nhật.
- **Ảnh 2 (`media_1795555692587_207986e1.png`)**:
  - Người dùng điều hướng ra ngoài khung chat (Trang chủ / Tủ sách / Cộng đồng).
  - Đối phương gửi tin nhắn mới `bbbbbbb`.
  - Chuông thông báo có số, nhưng trên Header mục `Tin nhắn` **hoàn toàn không có badge số tin nhắn chưa đọc** (vẫn là 0).
  - Đồng thời đối phương nhìn thấy dấu tích đã xem `✓✓` ngay lập tức dù người nhận chưa hề mở xem tin nhắn.

---

### 2. Nguyên nhân gốc (Root Cause Analysis)
1. **Singleton Pinia Store không reset khi chuyển route**:
   - `stores/chat.ts` lưu `activeConversation` trong memory Pinia store (singleton cấp toàn ứng dụng).
   - Khi người dùng điều hướng từ `/chat` sang `/` (Trang chủ), `/community` hoặc `/library`, `ChatPage.vue` bị unmount nhưng `activeConversation.value` trong store **vẫn giữ nguyên object cuộc trò chuyện trước đó**.
2. **Kiểm tra `isViewing` thiếu điều kiện Route và Visibility**:
   - Khi đối phương gửi tin mới, `AppHeader.vue` bắt event `ConversationUpdated` và `MessageSent` trên kênh cá nhân `chat.user.{userId}`.
   - Cả hai hàm `handleMessageSent` và `handleConversationUpdated` kiểm tra:
     ```ts
     let isViewing = activeConversation.value && Number(activeConversation.value.id) === convId
     ```
   - Do `activeConversation.value.id` vẫn là ID cuộc trò chuyện cũ, `isViewing` được tính là `true` ngay cả khi người dùng đang ở Trang chủ!
   - Hệ quả:
     - Client tự động gọi `markAsRead(message.id, currentUserId)`.
     - `conv.unread_count` bị ép về `0`.
     - `totalUnreadCount` không tăng, Header menu `Tin nhắn` không hiện badge (như trong Ảnh 2).
     - Gửi event `MessagesRead` sang đối phương, khiến đối phương thấy `✓✓` giả.
3. **Trường hợp Tab trình duyệt bị ẩn (Background/Minimized)**:
   - Nếu người dùng mở tab chat nhưng chuyển sang tab khác làm việc (`document.visibilityState === 'hidden'`), tin nhắn đến vẫn bị tự động mark as read.

---

### 3. Giải pháp triển khai
1. **Quản lý vòng đời `isChatViewActive` & `isWindowVisible` trong `stores/chat.ts`**:
   - Bổ sung state `isChatViewActive = ref<boolean>(false)` và `isWindowVisible = ref<boolean>(...)`.
   - Cung cấp các action `setChatViewActive(boolean)` và `setWindowVisible(boolean)`.
   - Hàm `isConversationActivelyViewed(conversationId)` kiểm tra nghiêm ngặt 4 điều kiện đồng thời:
     1. `isChatViewActive === true` (người dùng thực sự đang ở route `/chat`).
     2. `isWindowVisible === true` và `document.visibilityState !== 'hidden'` (tab đang hiển thị).
     3. `activeConversation.value !== null`.
     4. `Number(activeConversation.value.id) === Number(conversationId)`.
2. **Cập nhật `handleMessageSent` & `handleConversationUpdated`**:
   - Thay thế cách tính `isViewing` cũ bằng `isConversationActivelyViewed(convId)`.
   - Khi `isViewing === false`:
     - **Tuyệt đối không gọi `markAsRead`**.
     - Cập nhật số chưa đọc: `conv.unread_count = Number(payload.unread_count || ((conv.unread_count || 0) + 1))`.
     - Cập nhật `totalUnreadCount`, Header badge trên mục `Tin nhắn` lập tức hiển thị số chưa đọc chính xác.
     - Không phát sinh event `MessagesRead`, đối phương giữ nguyên dấu tích `✓`.
   - Khi `isViewing === true`:
     - Append tin nhắn vào `activeMessages` tức thời.
     - Tự động gọi `markAsRead` và gán `conv.unread_count = 0`.
3. **Đồng bộ vòng đời component trong `ChatPage.vue`**:
   - `onMounted`: Kích hoạt `chatStore.setChatViewActive(true)` và đăng ký sự kiện `visibilitychange`.
   - `onBeforeRouteLeave` & `onUnmounted`: Kích hoạt `chatStore.setChatViewActive(false)` và `chatStore.closeConversation()`, dọn sạch listener `visibilitychange`.
   - Khi người dùng quay lại tab chat (`visibilitychange` sang `visible`), tự động kiểm tra và gọi `markAsRead` cho các tin nhắn chưa đọc của cuộc trò chuyện đang mở.

---

### 4. Kết quả kiểm thử tự động (Quality Gates)

| Phân hệ / Bộ test | Lệnh thực thi | Kết quả | Ghi chú |
|---|---|---|---|
| Frontend Unit Tests | `node frontend/tests/unit-messaging-fixes.mjs` | **8/8 test suites PASS** | Bao gồm Test Suite 8 kiểm thử toàn diện CAI-MSG-05 |
| Frontend Notification Tests | `node frontend/tests/unit-notifications.mjs` | **16/16 tests PASS** | 100% PASS |
| Frontend Chat Lazy Tests | `node frontend/tests/unit-chat-lazy.mjs` | **4/4 tests PASS** | 100% PASS |
| Frontend Community Tests | `node frontend/tests/unit-community.mjs` | **6/6 tests PASS** | 100% PASS |
| Frontend Profile Tests | `node frontend/tests/unit-profile.mjs` | **6/6 tests PASS** | 100% PASS |
| Frontend Type Check | `vue-tsc -b --noEmit` | **0 errors (PASS)** | Type an toàn 100% |
| Frontend Production Build | `vite build` | **PASS (1.55s)** | 247 modules transformed, bundles clean |
| Backend Conversation Tests | `php artisan test --filter=ConversationApiTest` | **45/45 tests PASS (205 assertions)** | 100% PASS |
| Backend Notification Tests | `php artisan test --filter=NotificationApiTest` | **21/21 tests PASS (194 assertions)** | 100% PASS |
| Backend Full Test Suite | `php artisan test` | **714/714 tests PASS** | 100% PASS |

---

### 5. Kết luận
Lỗi read state khi rời khỏi khung chat đã được khắc phục triệt để và an toàn, bảo đảm:
- Khi ở ngoài `/chat` hoặc tab bị ẩn: Tin nhắn mới không bị đánh dấu đã đọc; badge trên Header mục `Tin nhắn` tăng chính xác theo số tin chưa đọc; đối phương không nhận dấu `✓✓`.
- Khi ở trong `/chat` và xem đúng hội thoại: Tin nhắn mới hiển thị tức thì trong khung chat, đánh dấu đã đọc và gửi `✓✓` cho đối phương.
- Không phát sinh thay đổi cấu trúc CSDL hay phá vỡ các tính năng đã nghiệm thu trước đó.
