# Đề Cương Thuyết Trình Bảo Vệ Đồ Án Tốt Nghiệp
## Đề tài: Xây Dựng Website Đọc Truyện Tranh Trực Tuyến Tích Hợp AI Semantic Search Và Nền Kinh Tế Số (ComicAI)

---

### THÔNG TIN CHUNG
- **Sinh viên thực hiện**: `[SINH VIÊN ĐIỀN: Họ và tên]`
- **Mã số sinh viên**: `[SINH VIÊN ĐIỀN: Mã số sinh viên]`
- **Lớp / Khóa**: `[SINH VIÊN ĐIỀN: Lớp / Khóa / Khoa CNTT]`
- **Trường**: Đại học Giao thông Vận tải (UTC)
- **Giảng viên hướng dẫn**: `[SINH VIÊN ĐIỀN: Học hàm, học vị, Họ và tên GVHD]`
- **Thời gian bảo vệ dự kiến**: `[SINH VIÊN ĐIỀN: Ngày / Tháng / Năm]`
- **Thời lượng trình bày**: Dự kiến 15 – 20 phút thuyết trình + 10 phút demo + 15 phút vấn đáp.

---

## 1. Bối Cảnh Và Lý Do Chọn Đề Tài

### 1.1 Bối cảnh thực tiễn
- Ngành công nghiệp truyện tranh kỹ thuật số (webtoons/comics) đang phát triển bùng nổ với hàng triệu độc giả và khối lượng tác phẩm khổng lồ được xuất bản mỗi ngày.
- Tuy nhiên, phần lớn các nền tảng đọc truyện hiện nay vẫn sử dụng các cơ chế tìm kiếm từ khóa truyền thống (Lexical/Keyword matching). Độc giả thường gặp khó khăn lớn khi chỉ nhớ mang máng cốt truyện, tính cách nhân vật hoặc chi tiết kịch bản nhưng không nhớ chính xác tên truyện.
- Sự phát triển vượt bậc của các mô hình ngôn ngữ lớn (LLM) và Vector Embeddings mở ra cơ hội giải quyết bài toán tìm kiếm ngữ nghĩa (Semantic Search) một cách chính xác, đưa trải nghiệm người dùng lên tầm cao mới.

### 1.2 Lý do chọn đề tài
- Nghiên cứu và hiện thực hóa mô hình **Hybrid Search** (kết hợp sức mạnh của Vector Embedding ngữ nghĩa và Fulltext Search từ khóa) vào bài toán thực tế của nền tảng truyện tranh.
- Xây dựng một hệ sinh thái web hoàn chỉnh bao gồm: tương tác cộng đồng, nhắn tin thời gian thực (Realtime WebSocket) và mô hình kinh tế số vi mô (Coin Wallet & VIP Chapter Unlock) với yêu cầu khắt khe về tính toàn vẹn dữ liệu tài chính (Append-Only Ledger, Idempotency).

---

## 2. Mục Tiêu Và Phạm Vi Đề Tài

### 2.1 Mục tiêu
1. Xây dựng một ứng dụng web đọc truyện tranh hiện đại, phản hồi nhanh, giao diện thân thiện (SPA).
2. Tích hợp AI Semantic Search hỗ trợ tìm kiếm bằng văn phong tự nhiên, xử lý ngữ nghĩa tiếng Việt đa dạng.
3. Thiết lập nền kinh tế số nội bộ (Coin Wallet) cho phép nạp tiền thử nghiệm và mở khóa chương truyện trả phí (VIP Paywall) an toàn, chống gian lận và lỗi đồng thời (concurrency race condition).
4. Xây dựng kênh giao tiếp thời gian thực (Realtime Chat 1-1) bằng giải pháp self-hosted WebSocket (Laravel Reverb) nhằm tối ưu chi phí hạ tầng.
5. Thiết lập quy trình phát triển chuyên nghiệp: Kiểm thử tự động đa tầng (Unit, Feature, Regression, Security, Benchmark) và đóng gói sẵn sàng triển khai (Docker Compose).

### 2.2 Phạm vi đề tài
- **Trong phạm vi (In-Scope)**:
  - 4 phân quyền người dùng: Guest, Reader, Creator, Admin.
  - Đọc truyện dạng cuộn dọc, thư viện cá nhân (đánh dấu, theo dõi, lịch sử).
  - Creator Studio quản lý truyện/chương, Admin Moderation Queue kèm Visual Diff.
  - Tương tác cộng đồng (bài viết, bình luận, reaction, báo cáo vi phạm) và Chat 1-1 thời gian thực.
  - Hệ thống Ví Coin, sổ cái giao dịch bất biến (Append-Only), mở khóa chương VIP bằng Idempotency Key.
  - AI Hybrid Search sử dụng Google Gemini Embedding `text-embedding-004` (768 chiều), hàng đợi xử lý ngầm (Queue Worker).
  - Đóng gói container hóa Docker 7 services và cấu hình Gateway Nginx.
- **Ngoài phạm vi (Out-of-Scope / Hướng mở rộng)**:
  - Tích hợp cổng thanh toán ngân hàng thực tế (VNPay, MoMo) — Sử dụng cơ chế nạp thử nghiệm Faucet kiểm soát giới hạn.
  - Hệ thống app di động Native (Android/iOS) — Tập trung tối ưu Responsive Web SPA.
  - Cơ sở dữ liệu Vector chuyên dụng phân tán (Milvus, Pinecone) — Hiện tại lưu trữ vector trong MySQL và tính toán tương đồng Cosine in-memory.

---

## 3. Phân Tích Yêu Cầu Hệ Thống

### 3.1 Yêu cầu chức năng theo tác nhân (Use Cases)
- **Độc giả (Reader)**: Tìm kiếm truyện bằng AI/thường, đọc chương miễn phí, nạp Coin faucet (5 lần/ngày), mở khóa chương VIP, quản lý tủ truyện, bình luận, chat 1-1 với người dùng khác.
- **Tác giả (Creator)**: Đăng tải tác phẩm, tạo chương mới, tải trang ảnh, cấu hình chương VIP và định giá Coin (5–50 Coin), theo dõi trạng thái kiểm duyệt.
- **Quản trị viên (Admin)**: Duyệt tác phẩm/chương mới hoặc bản cập nhật qua Visual Diff, từ chối kèm lý do, hoàn tiền giao dịch mua chương VIP và thu hồi quyền đọc của độc giả, giám sát hệ thống.

### 3.2 Yêu cầu phi chức năng
- **Bảo mật (Security)**: Chống Privilege Escalation, chống IDOR (tác giả không can thiệp tác phẩm người khác), bảo vệ ảnh chương VIP tuyệt đối không rò rỉ URL cho người chưa mua (`images: []`), bảo vệ sổ cái bất biến (không cho sửa/xóa giao dịch đã ghi).
- **Tính toàn vẹn giao dịch (Financial Integrity)**: Giao dịch mở khóa chương có tính nguyên tử (Atomicity), khóa dòng `lockForUpdate()`, cơ chế chống trừ tiền hai lần (Idempotency Key RFC4122).
- **Hiệu năng (Performance)**: Triệt tiêu N+1 queries, query catalog cố định $\le 5$ queries $O(1)$, cache thể loại/tag/banner với TTL 24h và tự động invalidation, cache vector embedding với key SHA-256 TTL 7 ngày.
- **Tính sẵn sàng & Suy giảm mềm (Fault Tolerance)**: Khi Gemini API gặp sự cố hoặc cờ cấu hình AI bị tắt, hệ thống tự động fallback về tìm kiếm từ khóa truyền thống mà không làm gián đoạn người dùng.

---

## 4. Kiến Trúc Hệ Thống (System Architecture)

### 4.1 Mô hình kiến trúc phân tầng (Decoupled Client-Server Architecture)
Hệ thống được thiết kế theo mô hình Monorepo phân tách rõ ràng:
- **Frontend SPA (Single Page Application)**: Xây dựng bằng **Vue 3**, **TypeScript**, **Pinia**, **Vue Router**, và **Vite**. Giao diện áp dụng phong cách tối giản Dark Theme (BlueToon Pastel Tokens) được đóng gói và phục vụ qua Nginx.
- **Backend API Layer**: Xây dựng bằng **PHP 8.3** và **Laravel Framework 13.38.0** (khai báo `laravel/framework: ^13.0` trong `backend/composer.json`, `composer.lock` xác nhận `v13.38.0`). Cung cấp RESTful API phi trạng thái kết hợp bảo vệ phiên qua Laravel Sanctum Cookie/Session (Stateful SPA).
- **Cổng ngõ Nginx Gateway**: Đóng vai trò Reverse Proxy duy nhất tiếp nhận request tại cổng 80/443, tự động phân luồng:
  - Request static assets (`/`, `/assets/`) $\rightarrow$ Frontend container.
  - Request API & Auth (`/api/`, `/sanctum/`) $\rightarrow$ Backend PHP-FPM container qua giao thức FastCGI.
  - Request Media (`/storage/`) $\rightarrow$ Thư mục volume tĩnh dùng chung.
  - Request WebSocket (`/app/`, `/apps/`) $\rightarrow$ Reverb WebSocket container với nâng cấp giao thức HTTP/1.1 Upgrade/Connection.

```
       +-------------------------------------------------------------+
       |                        NGINX GATEWAY                        |
       |                (Reverse Proxy / Port 80/443)                |
       +-------+--------------------+-------------------+------------+
               |                    |                   |
               v                    v                   v
        [Frontend SPA]       [Backend PHP-FPM]    [Reverb WebSocket]
       (Vue 3 + TS + Vite)   (Laravel 13 REST)     (Chat Broadcasting)
               |                    |                   |
               |                    +---------+---------+
               |                              |
               v                              v
        [Trình Duyệt]                 +---------------+---------------+
       (User Experience)              |                               |
                                      v                               v
                              [MySQL 8.4 LTS]                  [Redis 7 Alpine]
                           (Relational & Vectors)            (Cache, Session, Queue)
```

---

## 5. Công Nghệ Sử Dụng Và Lý Do Lựa Chọn

| Thành phần | Công nghệ | Lý do lựa chọn thực tế |
| :--- | :--- | :--- |
| **Backend Framework** | **Laravel Framework 13.38.0 (PHP 8.3)** | Framework hoàn thiện, hệ sinh thái phong phú, ORM Eloquent mạnh mẽ, hỗ trợ sẵn Database Transaction, Job Queue, Policy/Gate và đặc biệt là Laravel Reverb cho WebSocket. |
| **Frontend Framework** | **Vue 3 + TypeScript + Vite** | Composition API hiện đại, phản ứng nhạy bén, kiểm soát kiểu dữ liệu tĩnh nghiêm ngặt qua TypeScript, tốc độ biên dịch cực nhanh với Vite (build hoàn tất trong ~1.2s). |
| **Quản trị trạng thái** | **Pinia 4.x** | Store management nhẹ, type-safe hoàn toàn, dễ dàng module hóa cho Auth, Wallet, Chat, và Catalog. |
| **Cơ sở dữ liệu** | **MySQL 8.4 LTS** | RDBMS tiêu chuẩn công nghiệp, hỗ trợ Transaction ACID nghiêm ngặt, Fulltext Search tiếng Việt, tính toàn vẹn khóa ngoại cao. |
| **Bộ nhớ đệm & Hàng đợi** | **Redis 7 (Alpine)** | Hiệu năng xử lý in-memory cao, chia sẻ dữ liệu phiên (Session), hỗ trợ quản lý hàng đợi (Queue Worker) và tối ưu hóa bộ nhớ đệm (Cache). |
| **Realtime Engine** | **Laravel Reverb** | Máy chủ WebSocket native viết bằng PHP, hiệu năng cao, tích hợp mượt mà với Laravel Echo, hoàn toàn tự chủ (self-hosted), không phát sinh chi phí như Pusher. |
| **Trí tuệ nhân tạo (AI)**| **Google Gemini API** | Cung cấp model `text-embedding-004` cho vector ngữ nghĩa 768 chiều với chi phí tối ưu, hiểu sâu ngữ cảnh tiếng Việt và khả năng Query Understanding vượt trội. |
| **Đóng gói & Triển khai**| **Docker & Docker Compose**| Chuẩn hóa môi trường, dễ dàng triển khai với 1 lệnh, cô lập các dịch vụ qua bridge network nội bộ. |

---

## 6. Thiết Kế Cơ Sở Dữ Liệu (Database Design)

- Tổng cộng **29 migrations** quản lý schema chặt chẽ.
- **18+ bảng nghiệp vụ chính**:
  - `users`: Thông tin người dùng, vai trò (`reader`, `creator`, `admin`), trạng thái kích hoạt `is_active`.
  - `genres`, `tags`, `comics`, `comic_genre`, `comic_tag`: Quản lý danh mục truyện tranh quan hệ nhiều-nhiều. Cột `embedding` lưu vector 768 chiều dưới dạng JSON text, cột `embedding_hash` (SHA-256) tối ưu hóa việc tái sinh embedding.
  - `chapters`, `chapter_images`: Nội dung chương, thứ tự hiển thị trang ảnh, trạng thái VIP (`is_vip`) và giá Coin (`coin_price`).
  - `follows`, `bookmarks`, `reading_histories`: Theo dõi tương tác độc giả và lịch sử đọc.
  - `wallets`: Số dư Coin của người dùng, liên kết 1-1 với `users`.
  - `coin_transactions`: Sổ cái giao dịch bất biến (Append-Only Ledger), lưu vết mọi biến động số dư. Cột `refund_for_transaction_id` kèm ràng buộc Unique ngăn chặn Double-Refund.
  - `chapter_unlocks`: Lưu vết quyền sở hữu vĩnh viễn chương VIP, khóa kết hợp `(user_id, chapter_id)` và trạng thái `active`/`revoked`.
  - `conversations`, `conversation_participants`, `messages`: Hệ thống trò chuyện 1-1 thời gian thực với khóa ngoại `last_message_id` tối ưu truy vấn.
  - `posts`, `comments`, `likes`, `reports`: Tương tác cộng đồng đa chiều.

---

## 7. Các Chức Năng Nổi Bật

1. **Hệ Thống Ví Coin & Sổ Cái Giao Dịch Bất Biến (Append-Only Ledger)**:
   - Nghiêm cấm câu lệnh `UPDATE` hoặc `DELETE` trên bảng giao dịch tài chính thông qua Model Events Hook (`LogicException`).
   - Xử lý race condition khi tạo ví và nạp Welcome Bonus bằng cơ chế duplicate-key recovery trong transaction.
   - Cơ chế bảo vệ mở khóa bằng `X-Idempotency-Key` (UUID v4) đảm bảo không bao giờ trừ tiền hai lần dù người dùng bấm liên tục hoặc mạng chập chờn.
2. **Kiểm Duyệt Nội Dung & So Sánh Khác Biệt Trực Quan (Visual Diff Moderation)**:
   - Khi tác giả gửi yêu cầu cập nhật truyện đã duyệt, dữ liệu mới được lưu ở trạng thái `pending_changes` mà không làm gián đoạn tác phẩm đang công khai.
   - Admin kiểm duyệt trên giao diện Visual Diff: tô màu xanh các trường thêm mới, màu vàng trường sửa đổi và màu đỏ trường bị xóa/thay thế.
3. **Realtime Chat 1-1 Thuần Nội Bộ (Native Self-Hosted WebSocket)**:
   - Sử dụng Laravel Reverb tiết kiệm 100% chi phí dịch vụ bên thứ ba.
   - Cơ chế strict whitelist 7 trường dữ liệu trên broadcast payload, loại bỏ triệt để nguy cơ rò rỉ quan hệ model hoặc trường nhạy cảm.

---

## 8. Tìm Kiếm Ngữ Nghĩa Bằng Trí Tuệ Nhân Tạo (AI Semantic Search)

### 8.1 Luồng xử lý chi tiết (Pipeline)
```
[Truy vấn của Độc giả]
        │
        ▼
[Gemini Query Understanding] ──> Bóc tách Intent, Thể loại, Clues
        │
        ▼
[Gemini Text Embedding-004] ──> Vector biểu diễn ngữ nghĩa 768 chiều
        │
        ▼
[Hybrid Retrieval Engine]
        ├──> Semantic Search: Tính Cosine Similarity với vector truyện trong DB
        └──> Lexical Search: Khớp từ khóa toàn văn MySQL Fulltext
        │
        ▼
[Dung hợp Điểm Trọng số Hybrid Score]
   S_hybrid = 0.7 * S_semantic + 0.3 * S_lexical
        │
        ▼
[Hiển thị Kết Quả & Match Type Indicator]
   (Badge: Semantic, Hybrid, Clue với nhãn trung tính "Điểm phù hợp XX%")
```

### 8.2 Cơ chế sinh Vector tự động & Phòng vệ
- Vector embedding của truyện được tạo ngầm qua **Laravel Background Queue Job** ngay khi Admin duyệt tác phẩm, hoàn toàn không làm chậm request của người dùng.
- Áp dụng kỹ thuật sinh mã băm SHA-256 (`embedding_hash`): Nếu tác giả chỉ sửa đổi nhỏ không ảnh hưởng đến mô tả hay thể loại, hệ thống bỏ qua việc gọi API Gemini, giúp tiết kiệm chi phí ngạch API.
- **Graceful Degradation**: Khi mất kết nối Internet hoặc API Key hết quota, hệ thống tự động trả về Fallback Lexical Search, đảm bảo trải nghiệm người dùng luôn thông suốt.

---

## 9. Kiểm Thử Và Đánh Giá Chất Lượng (Quality Gate)

Hệ thống đã trải qua quy trình kiểm thử tự động toàn diện và nghiêm ngặt:
- **Backend Test Suite (PHPUnit)**: **652 / 652 tests PASS (3,295 assertions)** trên SQLite in-memory, thời gian chạy ~27 giây.
  - *Bộ Security Audit chuyên biệt*: 26 test cases kiểm tra RBAC, IDOR, VIP Paywall Masking, SQL Injection, XSS, và chống Double-Refund.
  - *Bộ Performance Benchmark chuyên biệt*: 9 test cases trên fixture 50 truyện, 500 chương, đo lường chính xác số query DB (triệt tiêu N+1 queries, xác nhận cache hit đạt 0 query).
- **Frontend Test Suite (Node.js Test Runner)**: **62 / 62 tests PASS**.
  - 21 tests Ví Coin & VIP Flow.
  - 9 tests AI Search UX & Match Types.
  - 32 tests Realtime Chat (15 Echo tests + 17 UI Component tests).
- **Phân tích tĩnh & Biên dịch**:
  - `vue-tsc -b --noEmit`: 0 lỗi TypeScript.
  - `vite build`: Biên dịch production bundle thành công trong ~1.20s.

---

## 10. Hướng Dẫn Kịch Bản Demo (Tóm Tắt)

1. **Khởi động & Giới thiệu tổng quan**: Trình chiếu giao diện trang chủ, banner, catalog lọc thể loại/tag.
2. **Đăng ký / Đăng nhập & Nhận Coin**: Tạo tài khoản độc giả mới, kiểm tra số dư ví nhận ngay 100 Coin Welcome Bonus, thao tác nạp thử nghiệm Faucet.
3. **Trải nghiệm Đọc truyện & Paywall VIP**:
   - Mở chương truyện thường: Đọc bình thường dạng cuộn dọc.
   - Mở chương truyện VIP: Hiển thị Paywall che mờ ảnh, hiển thị giá Coin $\rightarrow$ Nhấn Mở khóa $\rightarrow$ Giao dịch trừ Coin thành công, mở khóa ảnh tức thì không cần tải lại trang $\rightarrow$ Kiểm tra sổ cái giao dịch.
4. **Creator Studio & Admin Moderation**:
   - Creator tạo chương mới, cấu hình VIP 15 Coin, tải ảnh $\rightarrow$ Gửi duyệt.
   - Đăng nhập Admin $\rightarrow$ Vào hàng đợi duyệt $\rightarrow$ Xem Visual Diff $\rightarrow$ Phê duyệt $\rightarrow$ Kiểm tra hệ thống kích hoạt Job sinh vector embedding.
5. **Tìm kiếm thông minh AI Search**:
   - Nhập câu miêu tả: *"tìm truyện về main bá đạo xuyên không vào thế giới tu tiên nhưng lười biếng chỉ muốn trồng trọt"* (câu miêu tả không trùng với tên bất kỳ truyện nào).
   - Xem kết quả gợi ý đúng truyện liên quan, hiển thị điểm phù hợp và huy hiệu Semantic Match.
6. **Nhắn tin thời gian thực (Realtime Chat)**:
   - Mở song song 2 trình duyệt (1 Reader, 1 Creator/Admin).
   - Gửi tin nhắn từ tài khoản A $\rightarrow$ Tài khoản B nhận tin nhắn ngay lập tức qua WebSocket, số tin chưa đọc cập nhật tự động.

---

## 11. Hạn Chế Hiện Tại Và Hướng Phát Triển

### 11.1 Hạn chế hiện tại
- Phép tính độ tương đồng Cosine của Vector Embedding hiện đang được tính toán trong bộ nhớ PHP in-memory sau khi nạp danh sách truyện từ MySQL. Khi số lượng tác phẩm lên đến hàng chục nghìn, phương pháp này sẽ gây tiêu tốn RAM và tăng độ trễ CPU.
- Quá trình chạy Docker runtime và kịch bản E2E Auth chưa được xác minh trên server thực tế do giới hạn môi trường sandbox cục bộ.
- Chưa tích hợp cổng thanh toán trực tuyến thực tế (do đây là đồ án học thuật).

### 11.2 Hướng phát triển trong tương lai
- Nâng cấp lưu trữ vector sang giải pháp chuyên biệt: Tích hợp extension **`pgvector`** (PostgreSQL) hoặc các Vector Database phân tán như **Milvus / Qdrant** để tăng tốc tìm kiếm vector ở quy mô hàng triệu tác phẩm.
- Tích hợp cổng thanh toán thực tế (VNPAY Sandbox / MoMo) phục vụ bài toán thương mại hóa.
- Phát triển tính năng gợi ý truyện thông minh cá nhân hóa (Recommendation System) dựa trên lịch sử đọc và vector sở thích người dùng.
- Xây dựng ứng dụng di động đa nền tảng (Flutter hoặc React Native) tái sử dụng trọn vẹn hệ thống RESTful API hiện tại.

---
*Đề cương này được tổng hợp hoàn toàn dựa trên mã nguồn, schema cơ sở dữ liệu và kết quả kiểm thử thực tế của dự án ComicAI.*
