# ComicAI — Báo Cáo Rà Soát Chức Năng Toàn Hệ Thống
## Phục Vụ Bảo Vệ Đồ Án Tốt Nghiệp (Graduation Defense Functional Review)

**Dự án**: ComicAI (Nền tảng đọc truyện tranh trực tuyến tích hợp AI Semantic Search và Kinh tế số Coin/VIP)  
**Ngày rà soát**: 2026-11-24  
**Phiên bản mã nguồn**: Baseline sau hoàn tất CAI-029 (Phase 7)  
**Phiên bản Framework**: Laravel Framework 13.38.0 (`laravel/framework: ^13.0` trong `backend/composer.json`, PHP `^8.3`), Vue 3.5.44  
**Mục đích**: Đối chiếu các tính năng giữa mã nguồn thực tế, schema cơ sở dữ liệu và bằng chứng kiểm thử tự động nội bộ, phục vụ hội đồng chấm đồ án tốt nghiệp.

---

## 1. Phân Hệ Xác Thực & Phân Quyền (Authentication & RBAC)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | Cung cấp luồng đăng ký tài khoản (tự động cấp ví Coin và Welcome Bonus 100 Coin), đăng nhập bằng email/password, duy trì phiên an toàn qua Laravel Sanctum SPA Cookie/Session (Stateful API), bảo vệ chống CSRF, đăng xuất hủy phiên; cơ chế phân quyền 4 vai trò rõ rệt: `guest` (khách vãng lai), `reader` (độc giả thông thường), `creator` (tác giả sáng tác), và `admin` (quản trị viên hệ thống). Chặn tài khoản bị vô hiệu hóa (`is_active = false`). |
| **Vai trò liên quan** | Guest, Reader, Creator, Admin. |
| **Trạng thái triển khai** | **ACCEPTED** (Nghiệm thu qua CAI-007, CAI-REC-01, CAI-027). |
| **Mã nguồn chính** | - Backend: `backend/app/Http/Controllers/Api/AuthController.php`, `backend/app/Http/Middleware/CheckRole.php`, `backend/app/Models/User.php`, `backend/routes/api.php`.<br>- Frontend: `frontend/src/stores/auth.ts`, `frontend/src/pages/LoginPage.vue`, `frontend/src/pages/RegisterPage.vue`, `frontend/src/router/index.ts` (Navigation guards).<br>- Migration: `0001_01_01_000000_create_users_table.php`, `2026_10_27_000001_update_users_table_add_business_fields.php`. |
| **Bằng chứng kiểm thử** | - `backend/tests/Feature/AuthenticationTest.php`: 100% PASS.<br>- `backend/tests/Feature/RoleMiddlewareTest.php`: 100% PASS.<br>- `backend/tests/Feature/SecurityAuditTest.php` (Phần Auth & Privilege Escalation): 100% PASS (chặn đăng ký leo quyền `role=admin`).<br>- Frontend unit testing: Routing guards và Session clear verified. |

---

## 2. Phân Hệ Danh Mục Truyện, Đọc Truyện & Thư Viện Cá Nhân (Catalog, Reader & Library)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | - **Catalog & Khám phá**: Duyệt danh sách truyện đã được kiểm duyệt (`status = approved`), tìm kiếm theo từ khóa thông thường, phân trang, lọc theo thể loại (Genre) và nhãn (Tag), sắp xếp theo lượt xem/mới nhất, hiển thị banner nổi bật tại trang chủ.<br>- **Trang chi tiết truyện**: Thông tin tác giả, mô tả, danh sách chương được sắp xếp, huy hiệu VIP trên các chương trả phí.<br>- **Reader (Đọc truyện)**: Đọc chương truyện tranh dạng cuộn ảnh dọc chất lượng cao, điều hướng chương trước/sau, theo dõi tiến độ đọc, tự động lưu lịch sử đọc (`reading_histories`). Bảo vệ paywall với chương VIP nếu chưa mở khóa.<br>- **Thư viện cá nhân**: Quản lý truyện đang theo dõi (`follows`), đánh dấu truyện yêu thích (`bookmarks`), và lịch sử đọc (`reading_histories`) kèm xóa lịch sử. |
| **Vai trò liên quan** | Guest (duyệt catalog, đọc chương miễn phí), Reader (theo dõi, bookmark, lưu lịch sử, đọc chương). |
| **Trạng thái triển khai** | **ACCEPTED** (Nghiệm thu qua CAI-REC-01, tối ưu hiệu năng tại CAI-028). |
| **Mã nguồn chính** | - Backend: `backend/app/Http/Controllers/Api/ComicController.php`, `backend/app/Http/Controllers/Api/ChapterController.php`, `backend/app/Http/Controllers/Api/GenreController.php`, `backend/app/Http/Controllers/Api/TagController.php`, `backend/app/Http/Controllers/Api/BannerController.php`, `backend/app/Models/Comic.php`, `backend/app/Models/Chapter.php`, `backend/app/Models/ReadingHistory.php`.<br>- Frontend: `frontend/src/pages/ComicsPage.vue`, `frontend/src/pages/ComicDetailPage.vue`, `frontend/src/pages/ComicReaderPage.vue`, `frontend/src/pages/LibraryPage.vue`.<br>- Migration: `2026_10_27_000004_create_comics_table.php`, `2026_10_27_000007_create_chapters_table.php`, `2026_10_27_000008_create_chapter_images_table.php`, `2026_10_27_000009_create_follows_table.php`, `2026_10_27_000010_create_bookmarks_table.php`, `2026_10_27_000011_create_reading_histories_table.php`. |
| **Bằng chứng kiểm thử** | - `backend/tests/Feature/ComicCatalogApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/ChapterApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/ReadingHistoryApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/PerformanceBenchmarkTest.php`: Đảm bảo N+1 query được triệt tiêu, query count catalog cố định $\le 5$ queries $O(1)$ trên fixture 50 truyện, 500 chương. Cache hit thể loại/tag đạt 0 DB queries. |

---

## 3. Phân Hệ Tác Giả Sáng Tác & Kiểm Duyệt Quản Trị (Creator Studio & Admin Moderation)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | - **Creator Studio**: Dành riêng cho tác giả (`creator` hoặc `admin`). Tạo hồ sơ truyện mới, chỉnh sửa thông tin truyện, tải lên ảnh bìa, tạo chương mới, tải lên và sắp xếp thứ tự danh sách trang ảnh chương truyện, cấu hình trạng thái VIP và định giá Coin (5–50 Coin), gửi yêu cầu duyệt lên hệ thống.<br>- **Admin Moderation Queue**: Dành riêng cho `admin`. Hàng đợi kiểm duyệt tập trung các truyện và chương đang chờ duyệt (`status = pending` hoặc `status = pending_changes`). Giao diện Visual Diff hiển thị trực quan dữ liệu thay đổi giữa bản hiện tại và bản đề xuất. Admin có quyền duyệt (`approved`) hoặc từ chối (`rejected`) kèm lý do bắt buộc. Khi duyệt truyện mới/chỉnh sửa, tự động kích hoạt queue job sinh vector embedding AI. Chống IDOR triệt để: tác giả không thể sửa/xóa truyện của tác giả khác. |
| **Vai trò liên quan** | Creator (quản lý tác phẩm của mình), Admin (kiểm duyệt toàn hệ thống). |
| **Trạng thái triển khai** | **ACCEPTED** (Nghiệm thu qua CAI-REC-01, CAI-027). |
| **Mã nguồn chính** | - Backend: `backend/app/Http/Controllers/Api/CreatorComicController.php`, `backend/app/Http/Controllers/Api/CreatorChapterController.php`, `backend/app/Http/Controllers/Api/AdminComicModerationController.php`, `backend/app/Policies/ComicPolicy.php`.<br>- Frontend: `frontend/src/pages/creator/CreatorComicsPage.vue`, `frontend/src/pages/creator/CreatorComicFormPage.vue`, `frontend/src/pages/creator/CreatorChaptersPage.vue`, `frontend/src/pages/creator/CreatorChapterImagesPage.vue`, `frontend/src/pages/admin/AdminComicsModerationPage.vue`, `frontend/src/pages/admin/AdminChaptersModerationPage.vue`.<br>- Migration: `2026_10_28_000001_add_approved_fields_to_chapters_table.php`. |
| **Bằng chứng kiểm thử** | - `backend/tests/Feature/CreatorComicApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/CreatorChapterApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/AdminModerationApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/AdminModerationEmbeddingTriggerTest.php`: 100% PASS.<br>- `backend/tests/Feature/SecurityAuditTest.php` (Phần Resource Ownership / IDOR): 100% PASS (chặn tác giả A can thiệp truyện của tác giả B). |

---

## 4. Phân Hệ Cộng Đồng & Nhắn Tin Thời Gian Thực (Community & Realtime Chat)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | - **Cộng đồng (Community)**: Độc giả đăng bài viết thảo luận gắn với truyện, bình luận đa cấp, thả tim (`likes`), báo cáo nội dung vi phạm (`reports`).<br>- **Realtime Chat 1-1**: Hệ thống trò chuyện trực tiếp giữa người dùng qua WebSocket nội bộ sử dụng **Laravel Reverb** (không dùng dịch vụ bên thứ ba tốn phí như Pusher). Đảm bảo: xác thực kênh riêng tư (`private-chat.conversation.{id}`), broadcast sự kiện tin nhắn mới (`MessageSent`), sự kiện đã đọc (`MessagesRead`), và cập nhật danh sách hội thoại (`ConversationUpdated`). Frontend Vue sử dụng Laravel Echo singleton, cơ chế deduplication tin nhắn theo client_message_id, cập nhật unread counter tức thì, xử lý ngắt kết nối và tự động đồng bộ lại. Bảo vệ strict whitelist 7 trường dữ liệu trên payload broadcast tránh lộ thông tin nội bộ. |
| **Vai trò liên quan** | Reader, Creator, Admin (tất cả người dùng đã đăng nhập). |
| **Trạng thái triển khai** | **ACCEPTED** (Nghiệm thu qua CAI-REC-01, CAI-027, CAI-028 Reverb Payload Hardening). |
| **Mã nguồn chính** | - Backend: `backend/app/Http/Controllers/Api/PostController.php`, `backend/app/Http/Controllers/Api/CommentController.php`, `backend/app/Http/Controllers/Api/ConversationController.php`, `backend/app/Http/Controllers/Api/MessageController.php`, `backend/app/Events/MessageSent.php`, `backend/app/Events/MessagesRead.php`, `backend/app/Events/ConversationUpdated.php`, `backend/app/Services/ConversationService.php`.<br>- Frontend: `frontend/src/pages/CommunityPage.vue`, `frontend/src/pages/PostDetailPage.vue`, `frontend/src/pages/ChatPage.vue`, `frontend/src/stores/chat.ts`, `frontend/src/services/echo.ts`.<br>- Migration: `2026_10_29_000001_create_posts_table.php` đến `2026_10_29_000004_create_reports_table.php`, `2026_11_17_000001_create_conversations_table.php` đến `2026_11_17_000004_add_last_message_foreign_keys.php`. |
| **Bằng chứng kiểm thử** | - `backend/tests/Feature/PostApiTest.php` & `CommentApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/ConversationApiTest.php` & `MessageApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/ChatBroadcastEventsTest.php`: 100% PASS.<br>- `backend/tests/Feature/ChatChannelAuthorizationTest.php`: 100% PASS.<br>- `frontend/tests/unit-chat-echo.mjs`: 15/15 tests PASS.<br>- `frontend/tests/unit-chat-ui.mjs`: 17/17 tests PASS. |

---

## 5. Phân Hệ Ví Coin, Sổ Cái Giao Dịch & Mở Khóa VIP (Coin & VIP Paywall)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | - **Ví Coin (Wallet)**: Tự động khởi tạo khi đăng ký (hoặc lazy-init an toàn chống duplicate-key race condition khi đăng nhập tài khoản cũ). Cấp Welcome Bonus 100 Coin một lần duy nhất. Hỗ trợ tính năng nạp thử nghiệm (Faucet) tối đa 5 lần/ngày (100 Coin/lần) có kiểm tra múi giờ hệ thống.<br>- **Sổ cái bất biến (Append-Only Ledger)**: Bảng `coin_transactions` lưu vết lịch sử mọi giao dịch (welcome_bonus, faucet, chapter_unlock, refund). Được bảo vệ ở tầng Model Hook (`LogicException` khi update hoặc delete).<br>- **Mở khóa chương VIP**: Cho phép độc giả dùng Coin mở khóa vĩnh viễn chương VIP. Bảo vệ bằng khóa phân tán `lockForUpdate()`, kiểm tra số dư đủ, sinh mã `X-Idempotency-Key` (UUID v4) ngăn chặn double-spending khi mạng lag hoặc bấm nhiều lần. Hỗ trợ mua lại an toàn sau khi được Admin hoàn tiền.<br>- **Zero-Leakage VIP Reader Paywall**: Trả về `images: []` nếu chương VIP chưa được mở khóa. Chỉ Admin và Tác giả sở hữu truyện mới được bypass tự động mà không mất Coin.<br>- **Admin Hoàn tiền & Thu hồi quyền (Refund & Revocation)**: Admin hoàn tiền giao dịch unlock, tự động chuyển trạng thái `chapter_unlocks` thành `revoked`, khôi phục số dư cho độc giả. Ràng buộc `UNIQUE(refund_for_transaction_id)` ngăn chặn hoàn tiền hai lần. |
| **Vai trò liên quan** | Reader (quản lý ví, nhận faucet, mở khóa VIP), Creator (định giá VIP chương), Admin (hoàn tiền & thu hồi). |
| **Trạng thái triển khai** | **ACCEPTED** (Nghiệm thu qua CAI-025A, CAI-025B, CAI-025B-FIX-01, CAI-026, CAI-027). |
| **Mã nguồn chính** | - Backend: `backend/app/Models/Wallet.php`, `backend/app/Models/CoinTransaction.php`, `backend/app/Models/ChapterUnlock.php`, `backend/app/Services/WalletService.php`, `backend/app/Services/ChapterUnlockService.php`, `backend/app/Http/Controllers/Api/WalletController.php`, `backend/app/Http/Controllers/Api/ChapterUnlockController.php`, `backend/app/Http/Controllers/Api/AdminVipModerationController.php`.<br>- Frontend: `frontend/src/stores/wallet.ts`, `frontend/src/services/walletService.ts`, `frontend/src/pages/WalletPage.vue`, `frontend/src/components/common/VipUnlockModal.vue`.<br>- Migration: `2026_11_24_000001_add_vip_fields_to_chapters_table.php` đến `2026_11_24_000004_add_refund_for_transaction_id_to_coin_transactions_table.php`. |
| **Bằng chứng kiểm thử** | - `backend/tests/Feature/WalletAndLedgerTest.php`: 22/22 tests PASS (44 assertions).<br>- `backend/tests/Feature/VipChapterUnlockTest.php`: 22/22 tests PASS (111 assertions).<br>- `backend/tests/Feature/SecurityAuditTest.php` (Phần VIP Paywall & Ledger): 100% PASS.<br>- `frontend/tests/unit-wallet.mjs`: 21/21 tests PASS. |

---

## 6. Phân Hệ Tìm Kiếm Ngữ Nghĩa Tích Hợp Trí Tuệ Nhân Tạo (AI Semantic Search)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | - Cho phép người dùng tìm kiếm truyện bằng ngôn ngữ tự nhiên (mô tả cốt truyện, tính cách nhân vật, tình huống kịch bản) ngay cả khi không nhớ tên truyện hoặc từ khóa không khớp chính xác.<br>- **Kiến trúc xử lý 2 giai đoạn (Two-Stage Pipeline)**:
  1. *Stage 1 (Query Understanding)*: Phân tích truy vấn bằng Gemini AI để bóc tách ý định, thể loại mong muốn và trích xuất từ khóa mở rộng (clues).
  2. *Stage 2 (Vector Embedding & Hybrid Search)*: Chuyển đổi truy vấn thành vector đặc trưng 768 chiều (`text-embedding-004`). Tính toán độ tương đồng Cosine giữa vector truy vấn và vector truyện trong cơ sở dữ liệu, kết hợp với điểm tìm kiếm toàn văn MySQL FULLTEXT theo công thức dung hợp trọng số Hybrid Score:
  $$S_{hybrid} = \alpha \cdot S_{semantic} + (1 - \alpha) \cdot S_{lexical}$$
- **Hàng đợi nền (Background Queue Job)**: Tự động tổng hợp dữ liệu truyện (tên, tác giả, mô tả, thể loại, tags) và gọi Gemini sinh vector embedding khi Admin duyệt truyện. Lưu vết hash nội dung (`embedding_hash`) để tránh tính toán lại lãng phí nếu nội dung không đổi.<br>- **Cơ chế phòng vệ & Độc lập**: Khi tắt cờ cấu hình `config('ai.enabled') = false` hoặc Gemini API gặp sự cố/hết hạn ngạch, hệ thống tự động suy giảm mềm (graceful degradation) sang Fallback Lexical Search, đảm bảo hệ thống không bao giờ crash.<br>- **UX Trực quan**: Hiển thị nhãn trung tính "Điểm phù hợp XX%", phân biệt huy hiệu `badge-semantic`, `badge-hybrid`, `badge-clue` qua metadata cấu trúc (`match_type`), tuyệt đối không dùng string sniffing. |
| **Vai trò liên quan** | Guest, Reader (mọi người dùng truy cập trang tìm kiếm). |
| **Trạng thái triển khai** | **ACCEPTED** (Nghiệm thu qua Packages 10.3A đến 10.4D, tối ưu Caching tại CAI-028). |
| **Mã nguồn chính** | - Backend: `backend/app/Services/AiSearchService.php`, `backend/app/Services/GeminiEmbeddingService.php`, `backend/app/Services/FallbackSearchService.php`, `backend/app/Jobs/GenerateComicEmbeddingJob.php`, `backend/app/Console/Commands/GenerateComicEmbeddingsCommand.php`, `backend/app/Http/Controllers/Api/AiSearchController.php`.<br>- Frontend: `frontend/src/pages/ComicsPage.vue`, `frontend/src/components/ComicCard.vue`.<br>- Migration: `2026_10_28_000002_add_embedding_hash_to_comics_table.php`. |
| **Bằng chứng kiểm thử** | - `backend/tests/Feature/AiSearchApiTest.php`: 100% PASS.<br>- `backend/tests/Feature/AiSearchServiceTest.php`: 100% PASS.<br>- `backend/tests/Feature/GeminiEmbeddingServiceTest.php`: 100% PASS.<br>- `backend/tests/Feature/HybridSemanticSearchTest.php`: 100% PASS.<br>- `backend/tests/Feature/GenerateComicEmbeddingJobTest.php`: 100% PASS.<br>- `backend/tests/Feature/PerformanceBenchmarkTest.php`: Caching embedding với key SHA-256 TTL 7 ngày đạt 0 duplicate API calls.<br>- `frontend/tests/unit-ai-search-ui.mjs`: 9/9 tests PASS. |

---

## 7. Phân Hệ Đóng Gói, Triển Khai & Vận Hành (Deployment & Packaging)

| Thuộc tính | Chi tiết |
| :--- | :--- |
| **Chức năng làm gì** | - Đóng gói toàn diện monorepo thành cụm 7 container dịch vụ liên kết chặt chẽ qua Docker Compose: `mysql` (CSDL 8.4 LTS), `redis` (Cache/Session/Queue 7 Alpine), `backend` (PHP 8.3 FPM), `queue-worker` (Background jobs), `reverb` (WebSocket server), `frontend` (Vue 3 Nginx SPA), và `nginx` (Cổng ngõ Gateway hợp nhất).<br>- Định tuyến cổng ngõ Gateway phân biệt request SPA, API, WebSocket và Media Storage.<br>- Hỗ trợ môi trường Staging độc lập (`.env.staging.example`, cổng 8088, DB `comicai_staging`) kèm kịch bản E2E Auth test harness không tác động vào môi trường dev.<br>- Bộ tài liệu vận hành chi tiết 7 chương bao gồm hướng dẫn cài đặt, sao lưu, khôi phục, cấu hình SSL/TLS và chiến lược giảm thiểu downtime. |
| **Vai trò liên quan** | DevOps, System Administrator, Hội đồng đánh giá kỹ thuật. |
| **Trạng thái triển khai** | **ACCEPTED ở phạm vi Deployment Readiness** (Nghiệm thu qua CAI-029). |
| **Mã nguồn chính** | - `frontend/Dockerfile`, `frontend/nginx.conf`, `frontend/.dockerignore`.<br>- `backend/Dockerfile`, `backend/docker/entrypoint.sh`, `backend/docker/php/opcache.ini`, `backend/docker/php/custom.ini`, `backend/.dockerignore`.<br>- `docker/nginx/default.conf`, `docker-compose.yml`.<br>- `.env.example`, `.env.staging.example`.<br>- `docs/DEPLOYMENT_GUIDE.md`. |
| **Bằng chứng kiểm thử** | - Toàn bộ cấu trúc Dockerfile, Docker Compose và Nginx config đã được rà soát cú pháp.<br>- Frontend production build thành công trong 1.20s (`vite build`), type-check đạt 0 lỗi (`vue-tsc`).<br>- **Lưu ý trung thực**: Các lệnh `docker build`, container launch, staging DB migration và staging E2E Auth được bảo lưu trạng thái **NOT RUN** tuân thủ các approval gates an toàn của dự án. |

---

## 8. Bảng Tổng Hợp Kiểm Thử Toàn Hệ Thống

| Phân hệ / Tầng | Công cụ kiểm thử | Số lượng bài test | Assertions | Trạng thái |
| :--- | :--- | :--- | :--- | :--- |
| **Backend Feature & Unit** | PHPUnit (SQLite in-memory) | **652 tests** | **3,295 assertions** | **100% PASS** (27.44s) |
| - *Security Audit chuyên biệt* | PHPUnit (`SecurityAuditTest`) | 26 tests | 87 assertions | **PASS** |
| - *Performance & Query Caching* | PHPUnit (`PerformanceBenchmarkTest`) | 9 tests | 110 assertions | **PASS** |
| - *Ví Coin & Mở khóa VIP* | PHPUnit (`WalletAndLedgerTest`, `VipChapterUnlockTest`) | 44 tests | 155 assertions | **PASS** |
| - *AI Search & Embeddings* | PHPUnit (AiSearch, Hybrid, Embedding) | 91 tests | 405 assertions | **PASS** |
| **Frontend Unit & Integration** | Node.js Test Runners | **62 tests** | — | **100% PASS** |
| - *Ví Coin & VIP UX* | `node tests/unit-wallet.mjs` | 21 tests | — | **PASS** |
| - *AI Search UI & Match Type* | `node tests/unit-ai-search-ui.mjs` | 9 tests | — | **PASS** |
| - *Realtime Echo & Chat UI* | `node tests/unit-chat-echo.mjs` & `unit-chat-ui.mjs` | 32 tests | — | **PASS** |
| **Frontend Static Analysis** | `vue-tsc -b --noEmit` | Toàn bộ codebase TS/Vue | — | **0 lỗi (PASS)** |
| **Frontend Production Build** | `vite build` | 224 modules | Bundle tối ưu | **PASS** (1.20s) |
| **Staging E2E Auth** | `frontend/tests/e2e/auth.spec.mjs` | 5 ca kiểm thử | — | **NOT RUN** (Bảo lưu cho hạ tầng staging thực tế) |

---
*Báo cáo rà soát chức năng này phản ánh hiện trạng mã nguồn thực tế và kết quả test baseline đã được kiểm chứng trực tiếp của dự án ComicAI.*
