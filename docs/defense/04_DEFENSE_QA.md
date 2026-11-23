# Bộ Câu Hỏi Phản Biện Và Hướng Dẫn Trả Lời Mẫu
## Phục Vụ Bảo Vệ Đồ Án Tốt Nghiệp ComicAI

Tài liệu này tổng hợp các câu hỏi chất vấn điển hình mà Hội đồng chấm đồ án tốt nghiệp thường đặt ra, kèm theo câu trả lời mẫu ngắn gọn, chính xác, bám sát mã nguồn và kiến trúc thực tế của dự án ComicAI.

---

## 1. Về Lựa Chọn Công Nghệ (Laravel & Vue 3)

### Câu hỏi 1.1: Tại sao em lại lựa chọn Laravel kết hợp Vue 3 mà không chọn các stack phổ biến khác như MERN (Node.js/Express + React) hay kiến trúc Monolithic truyền thống (Laravel Blade)?
**Trả lời mẫu**:
- **So với MERN**: Laravel (phiên bản Framework 13.38.0 với PHP 8.3) cung cấp cấu trúc kiến trúc vững chắc (Batteries-Included Framework) với hệ thống ORM Eloquent, Database Transactions ACID, hàng đợi nền (Queue Worker), sự kiện thời gian thực (Laravel Reverb) và hệ thống kiểm soát quyền (Policy/Gate) tích hợp sẵn, giúp tiết kiệm thời gian phát triển và giảm thiểu rủi ro bảo mật so với việc tự lắp ráp nhiều thư viện rời rạc trong Node.js.
- **So với Monolithic Blade**: Việc tách biệt Backend API RESTful và Frontend Vue 3 SPA giúp mang lại trải nghiệm người dùng mượt mà, chuyển trang không giật lag (Zero-Page-Reload), đặc biệt phù hợp với tính năng đọc truyện cuộn dọc liên tục và chat thời gian thực. Ngoài ra, kiến trúc này giúp API sẵn sàng phục vụ cho ứng dụng di động trong tương lai mà không cần viết lại backend.
- **Vue 3 + TypeScript**: Vue 3 có cú pháp Composition API rõ ràng, dung lượng runtime nhẹ, và khi kết hợp với TypeScript giúp bắt lỗi kiểu dữ liệu ngay từ giai đoạn viết code, giảm thiểu tối đa runtime bugs.

---

## 2. Về Kiến Trúc Frontend / Backend & Xác Thực

### Câu hỏi 2.1: Hệ thống xác thực giữa Vue 3 và Laravel API hoạt động như thế nào? Tại sao lại dùng Laravel Sanctum SPA Cookie thay vì JWT Token lưu trong LocalStorage?
**Trả lời mẫu**:
- Hệ thống sử dụng **Laravel Sanctum Stateful SPA Authentication**:
  - Khi người dùng đăng nhập, Laravel sinh một session cookie an toàn có cờ **`HttpOnly`**, **`SameSite=Lax`** (hoặc `Strict`) và **`Secure`**.
  - Trình duyệt sẽ tự động đính kèm cookie này trong mọi request API tiếp theo cùng với token chống tấn công giả mạo yêu cầu chéo trang **`X-XSRF-TOKEN`**.
- **Lý do không dùng JWT trong LocalStorage**: Lưu JWT trong `LocalStorage` khiến token rất dễ bị đánh cắp nếu trang web dính lỗ hổng XSS (Cross-Site Scripting). Sử dụng HttpOnly Cookie ngăn chặn hoàn toàn mã JavaScript độc hại tiếp cận session token, mang lại độ an toàn cao hơn rất nhiều cho tài khoản người dùng.

---

## 3. Về Thiết Kế Cơ Sở Dữ Liệu & Quan Hệ Dữ Liệu

### Câu hỏi 3.1: Cơ sở dữ liệu của em có bao nhiêu bảng? Các quan hệ chính được thiết kế như thế nào? Vector embedding của AI được lưu trữ ở đâu?
**Trả lời mẫu**:
- Hệ thống có **29 migration files** quản lý **18+ bảng nghiệp vụ chính**.
- **Các quan hệ chính**:
  - Quan hệ $N-N$ giữa Truyện (`comics`) và Thể loại/Nhãn (`genres`, `tags`) thông qua các bảng trung gian `comic_genre` và `comic_tag`.
  - Quan hệ phân cấp $1-N$ giữa Truyện $\rightarrow$ Chương (`chapters`) $\rightarrow$ Trang ảnh (`chapter_images`).
  - Quan hệ $1-1$ giữa Người dùng (`users`) và Ví Coin (`wallets`), kèm quan hệ $1-N$ với Sổ cái giao dịch (`coin_transactions`).
  - Quan hệ trò chuyện thời gian thực giữa `conversations`, `conversation_participants` và `messages`.
- **Lưu trữ Vector Embedding**:
  - Vector 768 chiều được lưu trữ tại cột `embedding` (kiểu dữ liệu `LONGTEXT` hoặc `JSON`) trong bảng `comics`.
  - Cột `embedding_hash` (chuỗi băm SHA-256 từ nội dung tiêu đề, mô tả, thể loại) được lưu kèm để kiểm tra thay đổi: nếu nội dung truyện không đổi, hệ thống sẽ không gọi lại Gemini API để tái sinh vector, giúp tiết kiệm chi phí ngạch API.

---

## 4. Về Bảo Mật, Phân Quyền & Bảo Vệ VIP Paywall

### Câu hỏi 4.1: Làm thế nào để em đảm bảo người dùng thông thường không xem trộm được ảnh của chương VIP khi chưa mở khóa? Em có che giấu link ảnh không?
**Trả lời mẫu**:
- Hệ thống áp dụng nguyên tắc **Zero-Leakage ở tầng Backend API**:
  - Trong `ChapterController` và `ChapterReaderResource`, backend kiểm tra quyền truy cập của người dùng đối với chương truyện (đã mua trong `chapter_unlocks`, hoặc là Admin, hoặc là chính Tác giả sở hữu truyện).
  - Nếu là khách hoặc độc giả chưa mở khóa, mảng ảnh trả về luôn là mảng rỗng: **`images: []`**.
  - Hệ thống **tuyệt đối không trả về URL ảnh bị che mờ hay ẩn bằng CSS** phía frontend, do đó dù người dùng có mở tab Network trong DevTools (F12) cũng không thể lấy được bất kỳ đường dẫn ảnh gốc nào.

### Câu hỏi 4.2: Em phòng chống tấn công IDOR (Insecure Direct Object Reference) trong Creator Studio như thế nào?
**Trả lời mẫu**:
- Hệ thống sử dụng **Laravel Authorization Policy (`ComicPolicy`, `ChapterPolicy`)**:
  - Mọi thao tác cập nhật truyện, thêm chương, tải ảnh đều đi qua hàm kiểm tra quyền: `$this->authorize('update', $comic)`.
  - Policy sẽ so sánh: `$user->id === $comic->author_id || $user->isAdmin()`. Nếu Tác giả A cố tình gửi request với ID truyện của Tác giả B, server lập tức trả về mã lỗi **`403 Forbidden`**. Hành vi này đã được kiểm chứng tự động và vượt qua 100% trong bộ test `SecurityAuditTest.php`.

---

## 5. Về Tính Năng Realtime Chat (Laravel Reverb)

### Câu hỏi 5.1: Cơ chế Realtime Chat hoạt động như thế nào? Tại sao em chọn Laravel Reverb mà không dùng Pusher hay Firebase?
**Trả lời mẫu**:
- **Cơ chế hoạt động**:
  - Khi người dùng gửi tin nhắn, backend ghi nhận vào CSDL trong một Transaction, sau đó phát tán sự kiện `MessageSent` lên kênh riêng tư `private-chat.conversation.{id}` qua giao thức WebSocket.
  - Phía Vue 3, client sử dụng thư viện **Laravel Echo** kết nối tới máy chủ WebSocket, nhận sự kiện và cập nhật tin nhắn vào giao diện người dùng ngay lập tức mà không cần polling (kéo dữ liệu định kỳ).
- **Lý do chọn Laravel Reverb**:
  - **Reverb** là máy chủ WebSocket chính thức của Laravel chạy trực tiếp trên hạ tầng của dự án (Self-Hosted), tương thích 100% giao thức của Pusher.
  - Sử dụng Reverb giúp dự án hoàn toàn tự chủ, **không bị giới hạn số lượng kết nối đồng thời** và **tiết kiệm 100% chi phí duy trì** so với các dịch vụ tính phí như Pusher hay Firebase.

---

## 6. Về AI Semantic Search & Mô Hình Hybrid

### Câu hỏi 6.1: Cơ chế Tìm kiếm Ngữ nghĩa (Semantic Search) của em hoạt động như thế nào? Làm sao hệ thống hiểu được câu miêu tả tự nhiên của người dùng?
**Trả lời mẫu**:
- Tìm kiếm AI trải qua **2 giai đoạn (Two-Stage Pipeline)**:
  1. **Hiểu truy vấn (Query Understanding)**: Khi người dùng nhập câu miêu tả tự nhiên (ví dụ: *"truyện về main trùng sinh trả thù gia tộc"*), hệ thống gửi câu hỏi tới mô hình Gemini để trích xuất các ý định cốt lõi, gợi ý thể loại (genres) và các từ khóa manh mối (clues).
  2. **Vector Embedding & Hybrid Retrieval**: Câu truy vấn được chuyển đổi thành một vector toán học 768 chiều thông qua model `text-embedding-004`.
  - Hệ thống tính toán độ tương đồng góc Cosine giữa vector truy vấn và vector của từng bộ truyện trong cơ sở dữ liệu:
    $$\text{Cosine Similarity} = \frac{\mathbf{A} \cdot \mathbf{B}}{\|\mathbf{A}\| \|\mathbf{B}\|}$$
  - Kết hợp điểm tương đồng ngữ nghĩa ($S_{semantic}$) với điểm tìm kiếm toàn văn MySQL Fulltext ($S_{lexical}$) theo trọng số Hybrid:
    $$S_{hybrid} = 0.7 \cdot S_{semantic} + 0.3 \cdot S_{lexical}$$
- Nhờ vậy, ngay cả khi tên truyện không chứa từ nào trong câu miêu tả, truyện vẫn được tìm thấy chính xác dựa trên sự tương đồng về mặt ý nghĩa ngữ cảnh.

### Câu hỏi 6.2: Nếu Gemini API bị lỗi, mất mạng hoặc hết hạn ngạch (quota) thì trang tìm kiếm có bị sập không?
**Trả lời mẫu**:
- **Tuyệt đối không bị sập**. Hệ thống được thiết kế theo nguyên tắc **Suy giảm mềm (Graceful Degradation)**:
  - Mọi lời gọi API Gemini đều được bọc trong khối `try - catch` có kiểm soát thời gian chờ (timeout).
  - Nếu API lỗi, trả về mã lỗi hoặc cờ cấu hình `config('ai.enabled') = false`, `AiSearchService` sẽ tự động chuyển hướng truy vấn sang `FallbackSearchService` (sử dụng MySQL Fulltext thông thường).
  - Kết quả trả về kèm metadata `mode: "fallback"`, frontend sẽ hiển thị thông báo nhẹ nhàng cho người dùng và không làm gián đoạn trải nghiệm đọc truyện.

---

## 7. Về Ví Coin, Giao Dịch Tài Chính & Tính Toàn Vẹn Sổ Cái

### Câu hỏi 7.1: Làm thế nào em đảm bảo người dùng không thể gian lận hoặc bấm liên tục để mở khóa một chương VIP nhiều lần (Double-Spending)?
**Trả lời mẫu**:
- Hệ thống áp dụng cơ chế bảo vệ 3 lớp:
  1. **Khóa bản ghi (Pessimistic Locking)**: Trong phương thức trừ tiền, dòng ví của người dùng được khóa bằng `lockForUpdate()` bên trong một Database Transaction, ngăn chặn hai request đồng thời cùng đọc và sửa số dư ví.
  2. **Khóa Idempotency (`X-Idempotency-Key`)**: Client Vue 3 sinh một mã UUID v4 duy nhất cho mỗi lần nhấn mở khóa. Backend kiểm tra mã khóa này: nếu request bị gửi lại do mạng chậm hoặc người dùng click đúp, backend phát hiện request trùng và trả về kết quả thành công trước đó mà không trừ thêm tiền.
  3. **Ràng buộc duy nhất tại CSDL**: Bảng `chapter_unlocks` có ràng buộc duy nhất trên cặp `(user_id, chapter_id)`, ngăn chặn hoàn toàn việc tạo 2 bản ghi mở khóa cho cùng một chương truyện.

### Câu hỏi 7.2: Tại sao em lại gọi bảng giao dịch là Sổ cái bất biến (Append-Only Ledger)?
**Trả lời mẫu**:
- Bảng `coin_transactions` được thiết kế theo nguyên lý kế toán: **Chỉ ghi thêm (Append-Only), không bao giờ sửa hoặc xóa**.
- Ở tầng Eloquent Model (`CoinTransaction.php`), em đã cài đặt các Model Hooks chặn sự kiện `updating` và `deleting`: nếu có bất kỳ đoạn code nào cố tình cập nhật hoặc xóa một dòng giao dịch đã ghi, hệ thống lập tức ném ra lỗi `LogicException: Coin transactions are append-only ledger records`.
- Mọi biến động hoàn tiền (Refund) đều được ghi nhận bằng một dòng giao dịch mới mang giá trị dương, có tham chiếu `refund_for_transaction_id` kèm ràng buộc Unique để chống hoàn tiền 2 lần.

---

## 8. Về Hiệu Năng & Bộ Nhớ Đệm (Performance & Caching)

### Câu hỏi 8.1: Em đã tối ưu hóa hiệu năng hệ thống như thế nào? Làm sao em biết hệ thống không bị lỗi N+1 Query?
**Trả lời mẫu**:
- **Triệt tiêu lỗi N+1 Query**:
  - Em đã xây dựng bộ test tự động chuyên biệt `backend/tests/Feature/PerformanceBenchmarkTest.php` sử dụng `DB::listen` để đếm chính xác số lượng câu truy vấn SQL thực tế trên fixture 50 truyện và 500 chương.
  - Endpoint `GET /api/comics` luôn duy trì số lượng truy vấn cố định $\le 5$ queries $O(1)$ bất kể kích thước dữ liệu tăng lên, nhờ sử dụng kỹ thuật Eager Loading (`with(['genres', 'tags', 'author'])`) và chỉ chọn lọc các cột cần thiết (`select(...)`), loại bỏ việc nạp các vector embedding lớn (768 số thực) vào RAM khi chỉ xem danh sách.
- **Bộ nhớ đệm (Caching)**:
  - Các dữ liệu ít thay đổi như Danh sách Thể loại (Genres), Nhãn (Tags), Banners được đệm vào Redis/Cache với thời gian sống TTL 24 giờ. Khi có dữ liệu mới, hệ thống tự động xóa cache (Invalidation) thông qua Model Events (`saved`, `deleted`).
  - Các vector embedding của câu truy vấn AI được đệm với TTL 7 ngày dựa trên mã băm SHA-256, giúp giảm độ trễ từ ~800ms xuống còn < 5ms đối với các câu hỏi trùng lặp.

---

## 9. Về Kiểm Thử & Đảm Bảo Chất Lượng (Quality Gate)

### Câu hỏi 9.1: Em đã kiểm thử hệ thống như thế nào? Tỷ lệ bao phủ kiểm thử ra sao?
**Trả lời mẫu**:
- Hệ thống áp dụng quy trình kiểm thử tự động toàn diện:
  - **Backend**: **652 tests PASS 100% (3,295 assertions)** trên SQLite in-memory, bao gồm các bài Feature test cho từng Controller, Unit test cho Service logic, bộ Security Audit 26 ca kiểm thử và Performance Benchmark 9 ca kiểm thử.
  - **Frontend**: **62 unit/integration tests PASS 100%** kiểm tra store Pinia, logic tính toán ví coin, quy tắc paywall, deduplication tin nhắn realtime và nhãn tìm kiếm AI.
  - **Kiểm tra kiểu dữ liệu**: Sử dụng `vue-tsc -b --noEmit` đạt 0 lỗi TypeScript.
  - **Đóng gói production**: Bản build `vite build` thành công trong 1.20 giây.

---

## 10. Về Đóng Gói Triển Khai & Hướng Phát Triển

### Câu hỏi 10.1: Em đóng gói hệ thống như thế nào? Nếu triển khai lên server thật cần làm những gì?
**Trả lời mẫu**:
- Hệ thống đã được đóng gói hoàn chỉnh bằng **Docker Compose gồm 7 dịch vụ**: `mysql`, `redis`, `backend` (PHP-FPM), `queue-worker`, `reverb`, `frontend` (Vue SPA), và `nginx` (Gateway).
- Toàn bộ quy trình triển khai, biến môi trường mẫu (`.env.example`, `.env.staging.example`), kịch bản sao lưu CSDL và cấu hình SSL/TLS đã được biên soạn chi tiết trong tài liệu `docs/DEPLOYMENT_GUIDE.md`.
- Để triển khai lên server thật, chỉ cần cài Docker & Docker Compose, cấu hình file `.env` với mật khẩu production, sau đó chạy lệnh `docker compose up -d` và cấu hình tên miền qua Nginx.

### Câu hỏi 10.2: Hạn chế lớn nhất của đồ án hiện tại là gì và em sẽ khắc phục như thế nào?
**Trả lời mẫu**:
- **Hạn chế lớn nhất**: Việc tính toán độ tương đồng Cosine của Vector Embedding hiện đang thực hiện trong bộ nhớ PHP (in-memory) sau khi nạp danh sách truyện từ CSDL. Khi số lượng truyện lên đến hàng chục nghìn bộ, điều này sẽ làm tăng thời gian xử lý và tiêu tốn bộ nhớ RAM của server.
- **Giải pháp khắc phục**: Em dự kiến sẽ nâng cấp lưu trữ vector sang **PostgreSQL với extension `pgvector`** hoặc tích hợp các hệ quản trị Vector DB chuyên dụng mã nguồn mở như **Milvus / Qdrant**. Khi đó, các phép toán vector sẽ được thực hiện trực tiếp dưới tầng cơ sở dữ liệu với các chỉ mục chuyên dụng như HNSW (Hierarchical Navigable Small World), giúp tốc độ tìm kiếm đạt hàng triệu bản ghi trong vài mili-giây.

---
*Bộ câu hỏi và trả lời mẫu này bám sát vào code và dữ liệu thực tế, giúp sinh viên tự tin trả lời lưu loát trước các câu hỏi của Hội đồng.*
