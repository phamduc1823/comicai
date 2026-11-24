# BÁO CÁO RÀ SOÁT TOÀN DIỆN CHỨC NĂNG VÀ CHECKLIST KIỂM THỬ UI DỰ ÁN BLUETOON (COMICAI)

> **Người thực hiện:** Senior Software Engineer / Technical Lead  
> **Thời điểm rà soát:** 24/11/2026  
> **Mục tiêu:** Rà soát toàn bộ source code thực tế (Backend Laravel, API Routes, Database Schema, Frontend Vue 3 + TypeScript, Layouts, Modals, Pinia Stores, Composables), đối chiếu sự đồng bộ giữa Backend và Frontend, phân loại chính xác các chức năng và thiết lập Checklist chi tiết để tự kiểm thử trực tiếp trên giao diện người dùng.

---

## PHẦN 1: TỔNG QUAN HỆ THỐNG VÀ HIỆN TRẠNG KỸ THUẬT

### 1. Thống kê định lượng tổng thể

| Chỉ số | Số lượng thực tế | Ghi chú kỹ thuật |
|---|---|---|
| **Tổng số bảng Database** | **23 bảng** | 20 bảng nghiệp vụ + 3 bảng hệ thống (`users`, `cache`, `jobs`) qua 29 migrations |
| **Tổng số Model Eloquent** | **20 models** | Nằm trong `backend/app/Models` |
| **Tổng số Controller API** | **22 controllers** | Nằm trong `backend/app/Http/Controllers/Api` |
| **Tổng số API Endpoints** | **57 routes** | Khai báo trong `backend/routes/api.php` |
| **Tổng số Frontend Pages/Views** | **15 pages** | 13 trong `src/pages`, 2 trong `src/views` |
| **Tổng số Router paths** | **17 routes** | Khai báo trong `frontend/src/router/index.ts` |
| **Tổng số Subcomponents** | **15 components** | `admin/` (4), `community/` (5), `chat/` (3), `common/` (3) |
| **Tổng số Pinia Stores** | **3 stores** | `auth.ts`, `chat.ts`, `wallet.ts` |
| **Tổng số Frontend Services** | **11 services** | `adminService`, `creatorService`, `catalogService`, `communityService`, `chatService`, `walletService`, `readingHistoryService`, `readerHistoryService`, `userLibraryService`, `authService`, `httpClient` |

### 2. Thống kê trạng thái triển khai chức năng

| Nhóm trạng thái | Số lượng chức năng | Tỷ lệ (%) | Ý nghĩa |
|---|---|---|---|
| **1. Đã có Backend & UI hoạt động đầy đủ** | **38** | **66.7%** | Đã kết nối API, có UI form/bảng, có loading, error handling, validation |
| **2. Backend/API đã có nhưng UI chưa có** | **8** | **14.0%** | Backend đã code controller/service/migration, nhưng Frontend chưa làm màn hình/nút |
| **3. UI đã có nhưng Backend chưa đầy đủ** | **0** | **0.0%** | Không có trường hợp nào Backend thiếu logic |
| **4. Có UI & Backend nhưng chưa kết nối hoàn chỉnh** | **3** | **5.3%** | Có component/nút trên UI nhưng chưa dispatch request thật |
| **5. Chỉ có giao diện mockup / tĩnh** | **3** | **5.3%** | Giao diện hiển thị nhãn/text nhưng không có API backend hỗ trợ |
| **6. Chưa có trong source code** | **5** | **8.7%** | Các tính năng thường thấy trên web đọc truyện nhưng hệ thống chưa phát triển |
| **Tổng cộng khảo sát** | **57** | **100%** | |

---

## PHẦN 2: ĐỐI CHIẾU CHI TIẾT THEO TỪNG MODULE NGHIỆP VỤ

### 1. Phân hệ Người dùng, Xác thực & Tài khoản (Auth & User Profile)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Đăng ký tài khoản** | `POST /api/register` | `AuthController@register` | `users`, `wallets` | `/register` (`RegisterPage.vue`) | **1. Đã có Backend & UI hoạt động** | Form validation, tự tạo ví Coin 0đ, tự động đăng nhập sau khi đăng ký, CSRF bảo vệ. |
| **Đăng nhập** | `POST /api/login` | `AuthController@login` | `users` | `/login` (`LoginPage.vue`) | **1. Đã có Backend & UI hoạt động** | Hỗ trợ remember me, thông báo lỗi thống nhất, tái tạo session. |
| **Đăng xuất** | `POST /api/logout` | `AuthController@logout` | Session invalidate | `AppHeader.vue`, `AdminLayout.vue`, `CreatorLayout.vue` | **1. Đã có Backend & UI hoạt động** | Gọi API hủy session, xóa store, chuyển về trang chủ an toàn. |
| **Lấy thông tin User hiện tại** | `GET /api/user` | `AuthController@user` | `User` | `authStore.initialize()` | **1. Đã có Backend & UI hoạt động** | Chạy khi tải trang, nạp quyền role và avatar. |
| **Phân quyền (RBAC)** | Middleware `EnsureRole` | `EnsureRole.php` | `users.role` (`reader, creator, admin`) | `router/index.ts` Navigation Guards | **1. Đã có Backend & UI hoạt động** | Chặn truy cập trái phép ở cả Backend (403) và router frontend (chuyển hướng về `/`). |
| **Xác thực Email** | *Không có API* | Không có | `users.email_verified_at` | *Không có UI* | **6. Chưa có trong source code** | DB có cột `email_verified_at` nhưng chưa viết luồng gửi mail verify hay verify route. |
| **Quên mật khẩu / Reset password** | *Không có API* | Không có | `password_reset_tokens` | *Không có UI* | **6. Chưa có trong source code** | Chưa triển khai luồng gửi OTP/token qua mail để đặt lại mật khẩu. |
| **Đổi mật khẩu (Change Password)** | *Không có API* | Không có | `users.password` | *Không có UI* | **6. Chưa có trong source code** | Người dùng chưa có màn hình hoặc API để đổi mật khẩu khi đã đăng nhập. |
| **Cập nhật hồ sơ & Đổi Avatar** | *Không có API* | Không có | `users.name`, `users.avatar_path` | `AppHeader.vue` (chỉ hiển thị) | **2. Backend có nhưng UI chưa có** (Backend chưa có endpoint `PUT /api/user/profile`). |
| **Khóa / Hạn chế tài khoản** | Middleware `EnsureUserIsActive` | `EnsureUserIsActive.php` | `users.is_active` | *Chưa có UI quản lý* | **2. Backend có nhưng UI chưa có** | Middleware tự chặn account có `is_active=0` (HTTP 403), nhưng Admin chưa có UI danh sách User để bấm khóa. |

---

### 2. Phân hệ Trang chủ, Danh mục & Tìm kiếm (Catalog & AI Search)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Trang chủ (Home Hero Slider)** | `GET /api/banners` | `BannerController@index` | `banners`, `comics` | `/` (`HomePage.vue` -> `BannerSlider.vue`) | **1. Đã có Backend & UI hoạt động** | Tự chuyển slide, dots điều hướng, lấy đúng ảnh banner desktop/mobile của truyện đã duyệt, cache Redis/file. |
| **Trang chủ (Truyện nổi bật/mới cập nhật)** | `GET /api/comics` | `ComicController@index` | `comics` | `/` (`HomePage.vue` -> `ComicCard.vue`) | **1. Đã có Backend & UI hoạt động** | 3 tab lọc nhanh: Mới nhất, Xem nhiều, Theo dõi nhiều; phân trang AJAX mượt mà. |
| **Danh mục truyện (Catalog)** | `GET /api/comics` | `ComicController@index` | `comics` | `/comics` (`ComicsPage.vue`) | **1. Đã có Backend & UI hoạt động** | Đầy đủ bộ lọc: Thể loại, Tag, Trạng thái (đang tiến hành/hoàn thành), Sắp xếp, Phân trang. |
| **Lấy danh sách Thể loại (Genres)** | `GET /api/genres` | `GenreController@index` | `genres` | `ComicsPage.vue`, `CreatorComicFormPage.vue` | **1. Đã có Backend & UI hoạt động** | Cache mảng nguyên thủy, nạp vào dropdown bộ lọc và form sáng tác. |
| **Lấy danh sách Nhãn (Tags)** | `GET /api/tags` | `TagController@index` | `tags` | `ComicsPage.vue`, `CreatorComicFormPage.vue` | **1. Đã có Backend & UI hoạt động** | Nạp vào bộ lọc và multi-select tags khi đăng truyện. |
| **Tìm kiếm truyền thống (Keyword Search)** | `GET /api/comics?q=...` | `ComicController@index` | `comics` | `/comics` (`ComicsPage.vue`) | **1. Đã có Backend & UI hoạt động** | Tìm theo tên truyện, tên tác giả, tên khác; escape an toàn `%` và `_`. |
| **Tìm kiếm AI thông minh (AI Semantic Search)** | `GET /api/ai-search?q=...&mode=ai` | `AiSearchController@search` | `comics.embedding`, Gemini API | `/comics` (`ComicsPage.vue`) | **1. Đã có Backend & UI hoạt động** | Tự động sinh Vector Embedding 768 chiều, Hybrid Retrieval, hiển thị nhãn độ phù hợp và lý do khớp. |
| **Trang chi tiết truyện** | `GET /api/comics/{slug}` | `ComicController@show` | `comics`, `chapters`, `users` | `/comics/:slug` (`ComicDetailPage.vue`) | **1. Đã có Backend & UI hoạt động** | Hiển thị thông tin truyện, tác giả, huy hiệu VIP, danh sách chương số học, nút Đọc từ đầu/Đọc tiếp, Follow, Bookmark, Bình luận. |

---

### 3. Phân hệ Đọc truyện & Trải nghiệm Độc giả (Reader Experience)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Xem danh sách chương** | `GET /api/comics/{slug}/chapters` | `ChapterController@index` | `chapters` | `/comics/:slug` (`ComicDetailPage.vue`) | **1. Đã có Backend & UI hoạt động** | Sắp xếp tăng/giảm dần, nhận diện chương VIP (icon 👑), ngày phát hành. |
| **Màn hình đọc truyện (Webtoon Viewer)** | `GET /api/comics/{slug}/chapters/{num}` | `ChapterController@show` | `chapters`, `chapter_images` | `/comics/:slug/chapters/:chapterNumber` (`ComicReaderPage.vue`) | **1. Đã có Backend & UI hoạt động** | Cuộn dọc chuẩn Webtoon, lazy load ảnh, xử lý ảnh lỗi, thanh điều hướng nổi. |
| **Chuyển chương Trước / Sau** | Backend trả `prev_chapter`, `next_chapter` | `ChapterReaderResource` | `chapters` | `ComicReaderPage.vue` | **1. Đã có Backend & UI hoạt động** | Các nút chuyển chương ở thanh công cụ trên cùng và cuối trang đọc. |
| **Chọn chương nhanh khi đang đọc** | `GET /api/comics/{slug}/chapters` | `CatalogService.getChapters` | `chapters` | `ComicReaderPage.vue` (Dropdown chọn chương) | **1. Đã có Backend & UI hoạt động** | Mở menu chọn chương bất kỳ không cần quay lại trang chi tiết. |
| **Tự động lưu lịch sử đọc (Reading History)** | `POST /api/comics/{id}/reading-history` | `ReadingHistoryController@record` | `reading_histories` | `ComicReaderPage.vue`, `ComicDetailPage.vue` | **1. Đã có Backend & UI hoạt động** | Lưu chương đọc gần nhất lên server cho user đã đăng nhập; lưu `localStorage` cho khách vãng lai. |
| **Trang Tủ sách - Lịch sử đọc** | `GET /api/user/reading-history` | `ReadingHistoryController@index` | `reading_histories` | `/library?tab=history` (`LibraryPage.vue`) | **1. Đã có Backend & UI hoạt động** | Hiển thị các truyện đã đọc, chương gần nhất, thời gian đọc, nút Đọc tiếp. |
| **Xóa 1 mục / Xóa toàn bộ lịch sử đọc** | `DELETE /api/comics/{id}/reading-history`, `DELETE /api/user/reading-history` | `ReadingHistoryController@destroy`, `@clear` | `reading_histories` | `/library?tab=history` (`LibraryPage.vue`) | **1. Đã có Backend & UI hoạt động** | Nút xóa từng truyện hoặc modal xác nhận "Xóa tất cả lịch sử". |
| **Theo dõi truyện (Follow)** | `POST /api/comics/{id}/follow`, `GET /api/user/follows` | `UserLibraryController@toggleFollow`, `@follows` | `follows` | `ComicDetailPage.vue`, `/library?tab=follows` | **1. Đã có Backend & UI hoạt động** | Toggle theo dõi, tự động tăng/giảm `follows_count`, hiển thị danh sách trong Tủ sách. |
| **Đánh dấu truyện (Bookmark)** | `POST /api/comics/{id}/bookmark`, `GET /api/user/bookmarks` | `UserLibraryController@toggleBookmark`, `@bookmarks` | `bookmarks` | `ComicDetailPage.vue`, `/library?tab=bookmarks` | **1. Đã có Backend & UI hoạt động** | Lưu truyện yêu thích/đánh dấu để đọc sau. |
| **Báo cáo nội dung vi phạm (Report)** | `POST /api/reports` | `ReportController@store` | `reports` | *Chưa có UI modal báo cáo* | **2. Backend có nhưng UI chưa có** | Backend hỗ trợ report post, comment, comic, chapter, user (kèm chống duplicate). Tuy nhiên Frontend chưa có nút "Báo cáo" và modal nhập lý do. |

---

### 4. Phân hệ Tác giả (Creator Studio)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Truyện của tôi (Danh sách truyện)** | `GET /api/creator/comics` | `CreatorComicController@index` | `comics` | `/creator/comics` (`CreatorComicsPage.vue`) | **1. Đã có Backend & UI hoạt động** | Bảng truyện, lọc trạng thái duyệt (draft, pending, approved, rejected), tìm kiếm, phân trang. |
| **Đăng truyện mới** | `POST /api/creator/comics` | `CreatorComicController@store` | `comics`, `comic_genre`, `comic_tag` | `/creator/comics/create` (`CreatorComicFormPage.vue`) | **1. Đã có Backend & UI hoạt động** | Form nhập tên truyện, slug, mô tả, chọn thể loại, tags, upload ảnh bìa cover, chọn độ tuổi. |
| **Chỉnh sửa truyện** | `GET /api/creator/comics/{id}`, `POST /api/creator/comics/{id}` | `CreatorComicController@show`, `@update` | `comics` | `/creator/comics/:id/edit` (`CreatorComicFormPage.vue`) | **1. Đã có Backend & UI hoạt động** | Chỉnh sửa thông tin; nếu truyện đã duyệt, cập nhật sẽ vào hàng đợi `pending_changes` để Admin duyệt lại an toàn. |
| **Gửi duyệt truyện (Submit Review)** | `POST /api/creator/comics/{id}/submit-review` | `CreatorComicController@submitReview` | `comics.approval_status` | `CreatorComicsPage.vue`, `CreatorComicFormPage.vue` | **1. Đã có Backend & UI hoạt động** | Chuyển trạng thái từ `draft` sang `pending`. |
| **Hủy gửi duyệt truyện** | `POST /api/creator/comics/{id}/cancel-review` | `CreatorComicController@cancelReview` | `comics.approval_status` | `CreatorComicsPage.vue` | **1. Đã có Backend & UI hoạt động** | Thu hồi truyện chờ duyệt về lại trạng thái `draft`. |
| **Xem lý do bị từ chối duyệt** | Đọc trường `rejection_reason` | `CreatorComicController@index` | `comics.rejection_reason` | `CreatorComicsPage.vue` (`RejectionReasonModal.vue`) | **1. Đã có Backend & UI hoạt động** | Click badge "Bị từ chối" mở modal xem chi tiết phản hồi của Admin. |
| **Xóa truyện (chỉ truyện nháp/bị từ chối)** | `DELETE /api/creator/comics/{id}` | `CreatorComicController@destroy` | `comics` | `CreatorComicsPage.vue` | **1. Đã có Backend & UI hoạt động** | Modal xác nhận `ConfirmModal`, chặn xóa truyện đã duyệt hoặc đang chờ duyệt. |
| **Quản lý danh sách chương** | `GET /api/creator/comics/{comicId}/chapters` | `CreatorChapterController@indexForComic` | `chapters` | `/creator/comics/:comicId/chapters` (`CreatorChaptersPage.vue`) | **1. Đã có Backend & UI hoạt động** | Danh sách chương của bộ truyện, hiển thị trạng thái duyệt, số trang ảnh, giá VIP. |
| **Thêm chương mới & Đặt giá VIP** | `POST /api/creator/comics/{comicId}/chapters` | `CreatorChapterController@store` | `chapters` | `CreatorChaptersPage.vue` (Modal Thêm chương) | **1. Đã có Backend & UI hoạt động** | Đặt số chương, tiêu đề, bật cờ VIP, định giá Coin (5-50 Coin) có validation đầy đủ. |
| **Sửa thông tin chương** | `PATCH /api/creator/chapters/{id}` | `CreatorChapterController@update` | `chapters` | `CreatorChaptersPage.vue` (Modal Sửa chương) | **1. Đã có Backend & UI hoạt động** | Sửa tiêu đề, số chương, giá VIP của chương chưa xuất bản. |
| **Upload trang ảnh truyện** | `POST /api/creator/chapters/{id}/images` | `CreatorChapterController@uploadImages` | `chapter_images` | `/creator/chapters/:id/images` (`CreatorChapterImagesPage.vue`) | **1. Đã có Backend & UI hoạt động** | Kéo thả upload nhiều ảnh cùng lúc, kiểm tra kích thước tối đa 5MB/ảnh, tự động đánh số trang `page_number`. |
| **Sắp xếp lại thứ tự trang ảnh** | `PATCH /api/creator/chapters/{id}/images/reorder` | `CreatorChapterController@reorderImages` | `chapter_images.page_number` | `CreatorChapterImagesPage.vue` | **1. Đã có Backend & UI hoạt động** | Kéo thả hoặc nút di chuyển thứ tự trang ảnh, lưu thứ tự lên server. |
| **Xóa từng ảnh trang truyện** | `DELETE /api/creator/chapters/{id}/images/{imageId}` | `CreatorChapterController@deleteImage` | `chapter_images` | `CreatorChapterImagesPage.vue` | **1. Đã có Backend & UI hoạt động** | Xóa ảnh và tự động cập nhật lại số thứ tự các trang còn lại. |
| **Gửi duyệt chương** | `POST /api/creator/chapters/{id}/submit-review` | `CreatorChapterController@submitReview` | `chapters.status` | `CreatorChaptersPage.vue` | **1. Đã có Backend & UI hoạt động** | Kiểm tra chương phải có ít nhất 1 trang ảnh và truyện gốc đã approved mới cho gửi duyệt. |
| **Thu hồi chương chờ duyệt (Recall/Cancel)** | `POST /api/creator/chapters/{id}/cancel-review` | `CreatorChapterController@cancelReview` | `chapters.status` | `CreatorChaptersPage.vue` | **1. Đã có Backend & UI hoạt động** | Thu hồi chương về lại trạng thái `draft`. |

---

### 5. Phân hệ Quản trị (Admin Dashboard & Moderation)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Duyệt truyện tranh mới (New Comic Approval)** | `GET /api/admin/moderation/comics?type=new`, `POST .../{id}/approve` | `AdminComicModerationController@index`, `@approve` | `comics` | `/admin/moderation/comics` (`AdminComicsModerationPage.vue`) | **1. Đã có Backend & UI hoạt động** | Bảng danh sách truyện chờ duyệt, xem chi tiết (`AdminComicDetailModal.vue`), duyệt nguyên tử, tự kích hoạt Job tạo AI Embedding. |
| **Từ chối duyệt truyện mới** | `POST /api/admin/moderation/comics/{id}/reject` | `AdminComicModerationController@reject` | `comics` | `AdminComicsModerationPage.vue` (`AdminRejectModal.vue`) | **1. Đã có Backend & UI hoạt động** | Modal nhập lý do từ chối (bắt buộc 5-1000 ký tự). |
| **Duyệt thay đổi truyện đã xuất bản (Visual Diff)** | `GET ...?type=changes`, `POST .../{id}/approve-changes`, `reject-changes` | `AdminComicModerationController@approveChanges`, `@rejectChanges` | `comics.pending_changes` | `AdminComicsModerationPage.vue` (`AdminComicDiffModal.vue`) | **1. Đã có Backend & UI hoạt động** | Modal so sánh trực quan từng trường cũ - mới trước khi chấp nhận ghi đè. |
| **Duyệt chương Webtoon (Chapter Moderation)** | `GET /api/admin/moderation/chapters`, `POST .../{id}/approve` | `AdminChapterModerationController@index`, `@approve` | `chapters` | `/admin/moderation/chapters` (`AdminChaptersModerationPage.vue`) | **1. Đã có Backend & UI hoạt động** | Xem danh sách chương chờ duyệt, lọc theo `comic_id`, xem trước toàn bộ ảnh chương (`AdminChapterPreviewModal.vue`), duyệt xuất bản. |
| **Từ chối duyệt chương** | `POST /api/admin/moderation/chapters/{id}/reject` | `AdminChapterModerationController@reject` | `chapters` | `AdminChaptersModerationPage.vue` (`AdminRejectModal.vue`) | **1. Đã có Backend & UI hoạt động** | Nhập lý do từ chối gửi về cho Creator. |
| **Quản lý Banner Slider Trang Chủ** | `GET, POST, PUT, DELETE /api/admin/banners`, `PATCH /status`, `PATCH /reorder` | `AdminBannerController` | `banners` | `/admin/banners` (`AdminBannersPage.vue`) | **1. Đã có Backend & UI hoạt động** | Thêm, sửa, xóa banner, xem preview ảnh desktop/mobile, bật/tắt hiển thị, sắp xếp thứ tự, tự xóa cache trang chủ. |
| **Quản lý Báo cáo vi phạm (Reports Moderation)** | `GET /api/admin/reports`, `GET /{id}`, `PATCH /{id}` | `ReportController@index`, `@show`, `@update` | `reports` | *Chưa có UI menu Admin* | **2. Backend có nhưng UI chưa có** | Backend có đầy đủ API xem danh sách báo cáo, lọc theo status/reason/type, xem chi tiết và resolve/dismiss báo cáo. Chưa có trang `/admin/reports` và chưa có menu trong Sidebar Admin. |
| **Hoàn tiền Coin & Thu hồi quyền đọc VIP (Admin Refund)** | `POST /api/admin/vip/refund` | `AdminVipModerationController@refund` | `wallets`, `coin_transactions`, `chapter_unlocks` | *Chưa có UI menu Admin* | **2. Backend có nhưng UI chưa có** | Backend hỗ trợ hoàn tiền nguyên tử qua `WalletService::refund()`. Admin chưa có form/trang để nhập `transaction_id` và lý do hoàn tiền. |
| **Quản lý Người dùng (User Management)** | *Không có API* | Không có | `users` | *Không có UI* | **6. Chưa có trong source code** | Chưa có danh sách xem tất cả user, phân quyền role, hoặc nút khóa/mở khóa tài khoản. |
| **Dashboard Tổng quan Thống kê (Admin Dashboard Stats)** | *Không có API* | Không có | Nhiều bảng | *Không có UI* | **6. Chưa có trong source code** | Khi vào `/admin`, router tự động redirect sang `/admin/moderation/comics` vì chưa có trang Dashboard hiển thị biểu đồ/số liệu tổng thể. |

---

### 6. Phân hệ Coin, Ví & Mở khóa chương VIP (Phase 5)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Xem số dư Ví Coin** | `GET /api/wallet` | `WalletController@show` | `wallets` | `AppHeader.vue`, `/wallet` (`WalletPage.vue`) | **1. Đã có Backend & UI hoạt động** | Hiển thị số dư trên header, cập nhật realtime sau giao dịch. |
| **Xem Sổ cái Gịch sử Giao dịch (Ledger)** | `GET /api/wallet/transactions` | `WalletController@transactions` | `coin_transactions` | `/wallet` (`WalletPage.vue`) | **1. Đã có Backend & UI hoạt động** | Bảng lịch sử phân trang, lọc theo loại giao dịch: Mở khóa VIP, Coin thử nghiệm, Thưởng chào mừng, Hoàn Coin. |
| **Nhận Coin miễn phí thử nghiệm (Demo Faucet)** | `POST /api/wallet/faucet` | `WalletController@faucet` | `wallets`, `coin_transactions` | `/wallet` (`WalletPage.vue`), `ComicReaderPage.vue` | **1. Đã có Backend & UI hoạt động** | Nạp 100 Coin, tối đa 5 lần/ngày, có thanh đếm số lượt còn lại và hiển thị dấu chấm thông báo trên header. |
| **Mở khóa chương VIP (Paywall Unlock)** | `POST /api/chapters/{id}/unlock` | `ChapterUnlockController@unlock` | `chapter_unlocks`, `wallets`, `coin_transactions` | `ComicReaderPage.vue` (`VipUnlockModal.vue`) | **1. Đã có Backend & UI hoạt động** | Chặn đọc nếu chưa mở khóa, sinh khóa `X-Idempotency-Key` bảo vệ chống trừ tiền kép, mở khóa không reload trang. |
| **Cơ chế miễn phí cho Tác giả & Admin (Bypass)** | Tự động trong `ChapterController@show` | `ChapterController@show` | `comics.user_id` | `ComicReaderPage.vue` | **1. Đã có Backend & UI hoạt động** | Tác giả của truyện và Admin được đọc miễn phí toàn bộ chương VIP của mình mà không cần mua. |

---

### 7. Phân hệ Cộng đồng & Tương tác (Community & Social)

| Chức năng | Backend API | Controller / Action | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Xem danh sách bài viết cộng đồng** | `GET /api/posts` | `PostController@index` | `posts` | `/community` (`CommunityPage.vue`) | **1. Đã có Backend & UI hoạt động** | Danh sách bài viết, lọc theo truyện liên kết, sắp xếp mới nhất/nhiều like nhất, phân trang. |
| **Xem chi tiết bài viết** | `GET /api/posts/{id}` | `PostController@show` | `posts` | `/posts/:id` (`PostDetailPage.vue`) | **1. Đã có Backend & UI hoạt động** | Nội dung chi tiết bài viết, tác giả, ảnh đính kèm, danh sách bình luận. |
| **Đăng bài viết mới (Creator/Admin)** | `POST /api/posts` | `PostController@store` | `posts` | `/community` (`PostFormModal.vue`) | **1. Đã có Backend & UI hoạt động** | Soạn nội dung, gắn thẻ bộ truyện, upload ảnh minh họa. |
| **Chỉnh sửa / Xóa bài viết** | `POST /api/posts/{id}` (PUT), `DELETE .../{id}` | `PostController@update`, `@destroy` | `posts` | `CommunityPage.vue`, `PostDetailPage.vue` | **1. Đã có Backend & UI hoạt động** | Tác giả bài viết hoặc Admin có nút Sửa/Xóa kèm xác nhận. |
| **Thích bài viết (Like / Unlike)** | `POST /api/posts/{id}/like`, `DELETE .../{id}/like` | `PostController@toggleLike`, `@unlike` | `likes` | `PostCard.vue` | **1. Đã có Backend & UI hoạt động** | Nút tim đổi màu, cập nhật số lượt thích tức thì. |
| **Bình luận đa cấp (2 cấp: Bình luận & Trả lời)** | `GET /api/comments`, `POST /api/comments`, `POST /comments/{id}/reply` | `CommentController` | `comments` | `CommentSection.vue`, `CommentItem.vue`, `CommentForm.vue` | **1. Đã có Backend & UI hoạt động** | Tích hợp ở 3 nơi: Bài viết, Trang chi tiết truyện, Trang đọc chương. Khống chế tối đa 2 cấp chống vỡ layout. |
| **Xóa bình luận** | `DELETE /api/comments/{id}` | `CommentController@destroy` | `comments` | `CommentItem.vue` | **1. Đã có Backend & UI hoạt động** | Tác giả bình luận hoặc Admin được phép xóa. |
| **Thích bình luận (Like Comment)** | `POST /api/comments/{id}/like`, `DELETE .../{id}/like` | `CommentController@toggleLike`, `@unlike` | `likes` | `CommentItem.vue` | **1. Đã có Backend & UI hoạt động** | Nút thích từng bình luận/câu trả lời. |

---

### 8. Phân hệ Tin nhắn Realtime (Chat Reverb/Echo)

| Chức năng | Backend API / WebSocket | Controller / Event | Database / Model | Frontend Route / Component | Trạng thái triển khai | Chi tiết hiện trạng |
|---|---|---|---|---|---|---|
| **Danh sách hội thoại** | `GET /api/chat/conversations` | `ConversationController@index` | `conversations`, `conversation_participants` | `/chat` (`ChatPage.vue` -> `ConversationSidebar.vue`) | **1. Đã có Backend & UI hoạt động** | Hiển thị người đối thoại, tin nhắn cuối, thời gian, số tin chưa đọc. |
| **Tạo hội thoại trực tiếp với Creator** | `POST /api/chat/conversations` | `ConversationController@store` | `conversations` | `/chat?user_id=...` (`ChatPage.vue`) | **1. Đã có Backend & UI hoạt động** | Mở chat trực tiếp qua tham số URL hoặc nút Nhắn tin. |
| **Xem lịch sử tin nhắn** | `GET /api/chat/conversations/{id}/messages` | `MessageController@index` | `messages` | `/chat` (`MessageList.vue`) | **1. Đã có Backend & UI hoạt động** | Tự động cuộn xuống cuối, nút tải thêm tin nhắn cũ giữ nguyên vị trí cuộn. |
| **Gửi tin nhắn** | `POST /api/chat/conversations/{id}/messages` | `MessageController@store` | `messages` | `/chat` (`MessageComposer.vue`) | **1. Đã có Backend & UI hoạt động** | Hỗ trợ phím Enter để gửi, Shift+Enter xuống dòng, chặn gửi rỗng hoặc quá 2000 ký tự. |
| **Đánh dấu đã đọc & Huy hiệu tin chưa đọc** | `POST .../{id}/read`, `GET /api/chat/unread-count` | `ConversationController@markAsRead`, `@unreadCount` | `conversation_participants.last_read_message_id` | `AppHeader.vue`, `ConversationSidebar.vue` | **1. Đã có Backend & UI hoạt động** | Badge hiển thị trên Header (giới hạn 99+), tự động cập nhật khi đọc tin. |
| **Realtime Chat qua WebSocket (Laravel Reverb)** | Kênh riêng tư `private:chat.conversation.{id}`, `private:user.{id}` | Event `MessageSent`, `ConversationUpdated` | Reverb Server | `echoService.ts`, `useChatEcho.ts` | **1. Đã có Backend & UI hoạt động** | Nhận tin nhắn mới tức thì, tự động cập nhật hội thoại mà không cần F5. |

---

## PHẦN 3: CHECKLIST KIỂM THỬ TRỰC TIẾP TRÊN GIAO DIỆN (UI TEST CHECKLIST)

Bảng checklist dưới đây được sắp xếp theo mức độ ưu tiên từ **Admin** -> **Creator Studio** -> **Độc giả (Reader & Public)**. Bạn có thể mở trực tiếp trình duyệt và đánh dấu vào cột `Kết quả test`.

### Module I: Quản trị hệ thống (Admin Dashboard)

| ID Test | Tên chức năng | Đường dẫn UI | Các bước thao tác kiểm thử | Kết quả mong đợi | Kết quả test | Ghi chú lỗi / Thiếu sót |
|---|---|---|---|---|---|---|
| **ADM-001** | Xem hàng đợi duyệt truyện mới | `/admin/moderation/comics` | 1. Đăng nhập tài khoản Admin.<br>2. Vào menu "Duyệt truyện tranh".<br>3. Chọn tab "Chờ duyệt mới". | Hiển thị danh sách truyện có trạng thái `pending`. Bảng có tên truyện, tác giả, ảnh bìa, ngày gửi. | `[ ] Chưa test` | |
| **ADM-002** | Xem chi tiết truyện chờ duyệt | `/admin/moderation/comics` | 1. Trên hàng của một truyện, bấm nút "Xem chi tiết". | Mở modal `AdminComicDetailModal` hiển thị đầy đủ tóm tắt, thể loại, tags, ảnh bìa lớn. | `[ ] Chưa test` | |
| **ADM-003** | Duyệt truyện tranh mới | `/admin/moderation/comics` | 1. Bấm nút "Duyệt" trên một truyện chờ duyệt.<br>2. Bấm xác nhận trên modal. | Thông báo thành công, truyện biến mất khỏi tab chờ duyệt và chuyển sang `approved`. | `[ ] Chưa test` | |
| **ADM-004** | Từ chối duyệt truyện mới | `/admin/moderation/comics` | 1. Bấm nút "Từ chối" trên truyện chờ duyệt.<br>2. Nhập lý do (ví dụ: "Ảnh bìa vi phạm bản quyền").<br>3. Bấm "Xác nhận từ chối". | Truyện chuyển sang trạng thái `rejected`. Bắt buộc phải có lý do từ chối. | `[ ] Chưa test` | |
| **ADM-005** | Duyệt thay đổi truyện đã xuất bản | `/admin/moderation/comics` | 1. Chọn tab "Duyệt thay đổi".<br>2. Bấm nút "So sánh thay đổi". | Mở modal `AdminComicDiffModal` hiển thị 2 cột: Dữ liệu hiện tại vs Dữ liệu mới sửa đổi. | `[ ] Chưa test` | |
| **ADM-006** | Tìm kiếm truyện trong hàng đợi | `/admin/moderation/comics` | 1. Nhập từ khóa vào ô tìm kiếm (ví dụ: "Conan"). | Bảng tự động debounce và chỉ lọc các truyện khớp tên truyện hoặc tên tác giả. | `[ ] Chưa test` | |
| **ADM-007** | Xem hàng đợi duyệt chương Webtoon | `/admin/moderation/chapters` | 1. Vào menu "Duyệt chương Webtoon". | Hiển thị các chương có trạng thái `pending` kèm số chương, tên truyện, số trang ảnh. | `[ ] Chưa test` | |
| **ADM-008** | Xem trước toàn bộ trang ảnh chương | `/admin/moderation/chapters` | 1. Bấm nút "Xem trước ảnh" trên một chương. | Mở modal `AdminChapterPreviewModal` cuộn dọc xem tất cả ảnh trang của chương đó. | `[ ] Chưa test` | |
| **ADM-009** | Duyệt và xuất bản chương truyện | `/admin/moderation/chapters` | 1. Bấm nút "Duyệt chương".<br>2. Xác nhận duyệt. | Chương chuyển sang `published`. Độc giả có thể đọc được ngay trên trang chi tiết truyện. | `[ ] Chưa test` | |
| **ADM-010** | Từ chối duyệt chương truyện | `/admin/moderation/chapters` | 1. Bấm nút "Từ chối".<br>2. Nhập lý do từ chối (ít nhất 5 ký tự). | Chương chuyển sang trạng thái `rejected` kèm lý do gửi về Studio của tác giả. | `[ ] Chưa test` | |
| **ADM-011** | Xem danh sách Banner Slider | `/admin/banners` | 1. Vào menu "Quản lý Banner Slider". | Hiển thị 4 thẻ thống kê và bảng danh sách banner kèm thumbnail desktop/mobile. | `[ ] Chưa test` | |
| **ADM-012** | Thêm mới Banner Slider | `/admin/banners` | 1. Bấm "Thêm Banner Mới".<br>2. Chọn truyện từ dropdown (hAM, Conan, BGV).<br>3. Upload ảnh desktop.<br>4. Bấm Lưu. | Banner mới xuất hiện trong bảng và hiển thị ngay trên Slider trang chủ `/`. | `[ ] Chưa test` | Đã fix dropdown thành công. |
| **ADM-013** | Bật / Tắt trạng thái Banner | `/admin/banners` | 1. Bấm nút toggle trạng thái (Hoạt động / Tạm ẩn) trên 1 hàng banner. | Trạng thái chuyển đổi ngay lập tức, banner ẩn sẽ không xuất hiện trên slider trang chủ. | `[ ] Chưa test` | |
| **ADM-014** | Xóa Banner Slider | `/admin/banners` | 1. Bấm icon "Xóa" trên một banner.<br>2. Bấm xác nhận trên `ConfirmModal`. | Banner bị xóa khỏi DB, file ảnh trên disk bị xóa sạch, slider trang chủ cập nhật. | `[ ] Chưa test` | |
| **ADM-015** | Quản lý Báo cáo vi phạm (Reports) | `/admin/reports` | 1. Thử truy cập URL `/admin/reports`. | **Chưa có UI**: Màn hình chưa được tạo, chưa có menu trong Sidebar Admin. | `[!] CHƯA CÓ UI` | Backend API đã có sẵn 100%. |
| **ADM-016** | Hoàn tiền Coin cho độc giả | `/admin/vip/refund` | 1. Thử tìm form hoàn tiền trong Admin. | **Chưa có UI**: Chưa có màn hình quản lý hoàn tiền VIP. | `[!] CHƯA CÓ UI` | Backend API đã có sẵn 100%. |

---

### Module II: Tác giả & Studio sáng tác (Creator Studio)

| ID Test | Tên chức năng | Đường dẫn UI | Các bước thao tác kiểm thử | Kết quả mong đợi | Kết quả test | Ghi chú lỗi / Thiếu sót |
|---|---|---|---|---|---|---|
| **CRE-001** | Xem danh sách truyện của tôi | `/creator/comics` | 1. Đăng nhập tài khoản Creator/Admin.<br>2. Bấm nút "Studio ✍️" trên header. | Hiển thị toàn bộ truyện do chính user này tạo kèm trạng thái duyệt (badge màu trực quan). | `[ ] Chưa test` | |
| **CRE-002** | Đăng ký truyện mới (Tạo truyện) | `/creator/comics/create` | 1. Bấm "Đăng truyện mới".<br>2. Nhập tiêu đề, mô tả, chọn thể loại, upload ảnh bìa.<br>3. Bấm "Lưu bản nháp". | Tạo thành công truyện ở trạng thái `draft`, chuyển về danh sách truyện. | `[ ] Chưa test` | |
| **CRE-003** | Chỉnh sửa thông tin truyện | `/creator/comics/:id/edit` | 1. Bấm nút "Sửa" trên một bộ truyện.<br>2. Thay đổi mô tả hoặc ảnh bìa.<br>3. Bấm Lưu. | Thông tin được cập nhật. Nếu truyện đã `approved`, hệ thống báo tạo yêu cầu duyệt lại. | `[ ] Chưa test` | |
| **CRE-004** | Gửi duyệt truyện tranh | `/creator/comics` | 1. Trên truyện trạng thái `draft`, bấm nút "Gửi duyệt". | Trạng thái chuyển sang `pending` (Chờ duyệt). Nút chuyển thành "Hủy gửi duyệt". | `[ ] Chưa test` | |
| **CRE-005** | Hủy gửi duyệt truyện | `/creator/comics` | 1. Trên truyện `pending`, bấm nút "Hủy gửi duyệt". | Trạng thái quay trở lại `draft` để tác giả tiếp tục chỉnh sửa. | `[ ] Chưa test` | |
| **CRE-006** | Xem lý do truyện bị từ chối | `/creator/comics` | 1. Với truyện bị Admin từ chối, click vào badge "Bị từ chối". | Mở modal `RejectionReasonModal` hiển thị chính xác lý do Admin đã nhập. | `[ ] Chưa test` | |
| **CRE-007** | Xem danh sách chương của truyện | `/creator/comics/:comicId/chapters` | 1. Bấm nút "Quản lý chương" trên một truyện. | Hiển thị danh sách các chương của truyện, số trang ảnh, trạng thái, giá VIP. | `[ ] Chưa test` | |
| **CRE-008** | Tạo chương mới & Thiết lập VIP | `/creator/comics/:comicId/chapters` | 1. Bấm "Thêm chương mới".<br>2. Nhập số chương: "1", tiêu đề.<br>3. Tích chọn "Chương VIP", đặt giá: 10 Coin.<br>4. Bấm Lưu. | Chương mới được tạo ở trạng thái `draft`, có badge 👑 VIP 10 Coin. | `[ ] Chưa test` | |
| **CRE-009** | Upload trang ảnh cho chương | `/creator/chapters/:id/images` | 1. Bấm "Quản lý ảnh" trên một chương.<br>2. Chọn nhiều file ảnh hoặc kéo thả vào ô upload. | Ảnh được upload lên server, hiển thị danh sách thumbnail kèm số trang `Trang 1, Trang 2...`. | `[ ] Chưa test` | |
| **CRE-010** | Sắp xếp lại thứ tự ảnh chương | `/creator/chapters/:id/images` | 1. Dùng các nút mũi tên Lên/Xuống hoặc kéo thả để đổi thứ tự ảnh.<br>2. Bấm "Lưu thứ tự". | Số thứ tự trang được cập nhật lại theo thứ tự mới. | `[ ] Chưa test` | |
| **CRE-011** | Xóa ảnh trang bị lỗi/thừa | `/creator/chapters/:id/images` | 1. Bấm icon thùng rác trên một ảnh trang.<br>2. Xác nhận xóa. | Ảnh bị xóa khỏi danh sách, các trang phía sau tự động dồn lại số trang. | `[ ] Chưa test` | |
| **CRE-012** | Gửi duyệt chương truyện | `/creator/comics/:comicId/chapters` | 1. Bấm "Gửi duyệt" trên chương nháp đã có ảnh. | Trạng thái chuyển thành `pending`. Admin sẽ thấy chương này trong hàng đợi duyệt chương. | `[ ] Chưa test` | Chặn gửi nếu chưa có ảnh hoặc truyện chưa approved. |

---

### Module III: Độc giả, Khám phá & Đọc truyện (Reader Experience)

| ID Test | Tên chức năng | Đường dẫn UI | Các bước thao tác kiểm thử | Kết quả mong đợi | Kết quả test | Ghi chú lỗi / Thiếu sót |
|---|---|---|---|---|---|---|
| **REA-001** | Trải nghiệm Slider trang chủ | `/` | 1. Truy cập trang chủ.<br>2. Quan sát slider tự động chạy.<br>3. Bấm vào nút chuyển slide hoặc dots.<br>4. Bấm vào banner. | Slider chuyển mượt mà, bấm banner điều hướng chính xác vào trang chi tiết bộ truyện tương ứng. | `[ ] Chưa test` | |
| **REA-002** | Xem danh sách truyện nổi bật | `/` | 1. Chuyển đổi giữa 3 tab: Mới nhất, Xem nhiều, Theo dõi nhiều.<br>2. Bấm nút phân trang trang 2. | Danh sách truyện tải lại không reload trang, sắp xếp đúng tiêu chí. | `[ ] Chưa test` | |
| **REA-003** | Khám phá truyện theo Thể loại & Tag | `/comics` | 1. Vào trang "Danh mục".<br>2. Chọn thể loại "Hành động".<br>3. Chọn trạng thái "Đang tiến hành". | Bảng truyện lọc chính xác, URL tự cập nhật query params `?genre=...&status=...`. | `[ ] Chưa test` | |
| **REA-004** | Tìm kiếm truyện bằng AI | `/comics` | 1. Nhập mô tả nội dung vào ô tìm kiếm: "truyện thám tử học sinh bị teo nhỏ".<br>2. Bật công tắc "AI Search". | Trả về bộ truyện `Conan` kèm huy hiệu "Điểm phù hợp XX%" và giải thích lý do khớp. | `[ ] Chưa test` | Cần có API Key Gemini hợp lệ ở backend. |
| **REA-005** | Xem thông tin chi tiết truyện | `/comics/:slug` | 1. Bấm vào 1 bộ truyện bất kỳ (ví dụ: `Conan`). | Hiển thị ảnh bìa, tên tác giả, tóm tắt, danh sách thể loại, danh sách chương số học. | `[ ] Chưa test` | |
| **REA-006** | Đọc truyện từ đầu / Đọc chương mới nhất | `/comics/:slug` | 1. Bấm nút "Đọc từ đầu" hoặc "Chương mới nhất". | Chuyển ngay đến đúng URL đọc của chương đầu tiên hoặc chương cuối cùng. | `[ ] Chưa test` | |
| **REA-007** | Theo dõi (Follow) truyện | `/comics/:slug` | 1. Đăng nhập tài khoản Reader.<br>2. Bấm nút "Theo dõi". | Nút chuyển thành "Đang theo dõi", số lượt theo dõi tăng 1. Vào `/library` thấy truyện xuất hiện. | `[ ] Chưa test` | |
| **REA-008** | Đánh dấu (Bookmark) truyện | `/comics/:slug` | 1. Bấm nút "Đánh dấu". | Nút chuyển thành "Đã đánh dấu". Vào `/library?tab=bookmarks` thấy truyện xuất hiện. | `[ ] Chưa test` | |
| **REA-009** | Cuộn đọc truyện Webtoon | `/comics/:slug/chapters/:chapterNumber` | 1. Mở đọc một chương truyện.<br>2. Cuộn chuột xuống đọc từng trang ảnh. | Ảnh hiển thị liên tục dạng dọc không đứt đoạn, hình ảnh rõ nét. | `[ ] Chưa test` | |
| **REA-010** | Chuyển chương bằng thanh điều hướng | `/comics/:slug/chapters/:chapterNumber` | 1. Bấm nút "Chương sau" ở thanh công cụ nổi hoặc ở cuối trang. | Chuyển sang chương tiếp theo mượt mà. Nút bị disable nếu đang ở chương cuối. | `[ ] Chưa test` | |
| **REA-011** | Menu chọn chương nhanh khi đang đọc | `/comics/:slug/chapters/:chapterNumber` | 1. Bấm vào dropdown chọn chương ở thanh công cụ trên cùng. | Danh sách tất cả các chương xổ xuống, click chọn chương nhảy ngay đến chương đó. | `[ ] Chưa test` | |
| **REA-012** | Đọc chương VIP & Mở khóa Paywall | `/comics/:slug/chapters/:chapterNumber` | 1. Mở một chương VIP có giá Coin (khi chưa mua).<br>2. Quan sát thẻ Paywall che nội dung.<br>3. Bấm "Mở khóa chương". | Mở modal `VipUnlockModal`, hiển thị số dư ví, giá chương. Bấm xác nhận -> trừ Coin, ảnh chương hiển thị ngay không reload. | `[ ] Chưa test` | |
| **REA-013** | Tự động lưu vị trí và lịch sử đọc | `/comics/:slug/chapters/:chapterNumber` | 1. Đọc chương 2 của một bộ truyện.<br>2. Quay lại trang chi tiết truyện `/comics/:slug`. | Nút "Đọc tiếp" hiển thị "Đọc tiếp: Chương 2". Vào Tủ sách `/library?tab=history` thấy lưu lịch sử. | `[ ] Chưa test` | |
| **REA-014** | Xóa lịch sử đọc trong Tủ sách | `/library?tab=history` | 1. Bấm nút "Xóa" trên một truyện trong lịch sử đọc.<br>2. Hoặc bấm "Xóa tất cả". | Truyện bị xóa khỏi lịch sử đọc trên server. | `[ ] Chưa test` | |

---

### Module IV: Ví Coin, Giao dịch & Faucet (Phase 5)

| ID Test | Tên chức năng | Đường dẫn UI | Các bước thao tác kiểm thử | Kết quả mong đợi | Kết quả test | Ghi chú lỗi / Thiếu sót |
|---|---|---|---|---|---|---|
| **WAL-001** | Xem số dư Coin trên Header | Mọi trang | 1. Đăng nhập tài khoản.<br>2. Quan sát góc phải thanh Header. | Hiển thị huy hiệu `🪙 [Số dư] Coin`. Click vào huy hiệu mở trang `/wallet`. | `[ ] Chưa test` | |
| **WAL-002** | Xem chi tiết Ví & Lịch sử giao dịch | `/wallet` | 1. Truy cập trang `/wallet`. | Hiển thị thẻ số dư lớn, thanh tiến trình nhận Coin miễn phí, bảng sổ cái giao dịch. | `[ ] Chưa test` | |
| **WAL-003** | Nhận Coin thử nghiệm (Demo Faucet) | `/wallet` | 1. Bấm nút "Nhận ngay 100 Coin miễn phí". | Thông báo thành công, số dư tăng ngay 100 Coin, số lượt còn lại giảm 1, bảng lịch sử thêm dòng mới. | `[ ] Chưa test` | Tối đa 5 lần/ngày. |
| **WAL-004** | Lọc lịch sử giao dịch theo loại | `/wallet` | 1. Chọn dropdown lọc: "Mở khóa VIP", "Coin thử nghiệm", "Hoàn Coin". | Bảng giao dịch lọc chính xác từng loại giao dịch tương ứng. | `[ ] Chưa test` | |

---

### Module V: Cộng đồng, Bình luận & Tin nhắn Realtime (Community & Chat)

| ID Test | Tên chức năng | Đường dẫn UI | Các bước thao tác kiểm thử | Kết quả mong đợi | Kết quả test | Ghi chú lỗi / Thiếu sót |
|---|---|---|---|---|---|---|
| **COM-001** | Xem bảng tin cộng đồng | `/community` | 1. Truy cập trang "Cộng đồng". | Hiển thị danh sách bài viết từ các tác giả/admin kèm số like, số comment, ảnh đính kèm. | `[ ] Chưa test` | |
| **COM-002** | Đăng bài viết cộng đồng mới | `/community` | 1. Đăng nhập tài khoản Creator hoặc Admin.<br>2. Bấm "Viết bài mới".<br>3. Nhập nội dung, chọn truyện liên kết, upload ảnh.<br>4. Bấm Đăng. | Bài viết mới xuất hiện ngay đầu bảng tin cộng đồng. | `[ ] Chưa test` | Reader không có quyền đăng bài. |
| **COM-003** | Thích / Bỏ thích bài viết | `/community` | 1. Bấm vào icon trái tim trên một bài viết. | Trái tim đổi màu đỏ, số like tăng 1. Bấm lại thì giảm 1. | `[ ] Chưa test` | |
| **COM-004** | Bình luận và trả lời bình luận (2 cấp) | `/comics/:slug` hoặc `/posts/:id` | 1. Nhập bình luận ở khung comment -> Gửi.<br>2. Bấm nút "Trả lời" trên bình luận vừa gửi -> Nhập câu trả lời. | Bình luận và câu trả lời hiển thị thụt lề cấp 2 chính xác. | `[ ] Chưa test` | Khống chế tối đa 2 cấp. |
| **COM-005** | Xóa bình luận của chính mình | `/comics/:slug` hoặc `/posts/:id` | 1. Bấm nút "Xóa" trên bình luận do chính mình viết.<br>2. Xác nhận xóa. | Bình luận biến mất khỏi danh sách. | `[ ] Chưa test` | |
| **CHT-001** | Xem danh sách hội thoại tin nhắn | `/chat` | 1. Bấm vào mục "Tin nhắn" trên header. | Mở màn hình chat, hiển thị sidebar danh sách người trò chuyện bên trái. | `[ ] Chưa test` | |
| **CHT-002** | Gửi và nhận tin nhắn | `/chat` | 1. Chọn một cuộc hội thoại.<br>2. Nhập tin nhắn vào khung soạn thảo -> Nhấn Enter. | Tin nhắn xuất hiện ngay bên phải (bong bóng xanh của mình). | `[ ] Chưa test` | |
| **CHT-003** | Nhận tin nhắn Realtime qua WebSocket | `/chat` | 1. Mở 2 trình duyệt với 2 tài khoản khác nhau.<br>2. User A nhắn tin cho User B. | Màn hình của User B tự động nhận tin nhắn mới ngay lập tức mà không cần F5. | `[ ] Chưa test` | Yêu cầu Reverb server đang chạy. |
| **CHT-004** | Huy hiệu tin nhắn chưa đọc trên Header | Mọi trang | 1. Khi có tin nhắn mới gửi đến mà chưa mở đọc. | Header hiển thị chấm đỏ hoặc số tin nhắn chưa đọc (`1`, `2`... `99+`). | `[ ] Chưa test` | |

---

## PHẦN 4: DANH SÁCH CHỨC NĂNG ĐÃ CÓ BACKEND NHƯNG THIẾU GIAO DIỆN (CRITICAL GAPS)

Đây là những chức năng **Backend Laravel, Database và API đã xây dựng hoàn chỉnh 100%**, nhưng **Frontend Vue chưa có trang giao diện hoặc chưa có menu để kích hoạt**.

### 1. Quản lý Báo cáo vi phạm (Admin Reports Moderation)
- **Hiện trạng Backend**:
  - API: `GET /api/admin/reports`, `GET /api/admin/reports/{id}`, `PATCH /api/admin/reports/{id}`.
  - Controller: `ReportController.php` (các hàm `index`, `show`, `update`).
  - Model: `Report.php`, bảng `reports`.
  - Logic nghiệp vụ: Lọc báo cáo theo `status` (`pending`, `resolved`, `dismissed`), theo `reason` (`spam`, `inappropriate`, `copyright`, `harassment`), theo `reportable_type` (`post`, `comment`, `comic`, `chapter`, `user`). Cập nhật trạng thái xử lý, lưu ghi chú admin `admin_notes`, người giải quyết `resolved_by`.
- **Hiện trạng Frontend**:
  - `AdminLayout.vue` **hoàn toàn chưa có menu** "Quản lý Báo cáo".
  - `router/index.ts` **chưa có route** `/admin/reports`.
  - Chưa có file `AdminReportsPage.vue`.
  - Độc giả cũng chưa có nút "Báo cáo vi phạm" trên bài viết, comment, truyện hay chương để kích hoạt API `POST /api/reports`.

### 2. Quản lý Hoàn tiền Coin & Thu hồi quyền VIP (Admin Refund Moderation)
- **Hiện trạng Backend**:
  - API: `POST /api/admin/vip/refund`.
  - Controller: `AdminVipModerationController.php` (`refund`).
  - Service: `WalletService::refund()`.
  - Logic nghiệp vụ: Nhập `transaction_id` và lý do, hệ thống tự động hoàn lại số Coin cho độc giả qua bản ghi Ledger `refund`, đồng thời xóa bản ghi trong `chapter_unlocks` để thu hồi quyền đọc chương VIP.
- **Hiện trạng Frontend**:
  - Chưa có màn hình Admin để tra cứu lịch sử giao dịch người dùng và bấm nút "Hoàn tiền".
  - Chưa có route `/admin/vip` hoặc `/admin/transactions`.

### 3. Nút gửi Báo cáo vi phạm từ phía người dùng (User Report Button)
- **Hiện trạng Backend**:
  - API: `POST /api/reports`.
  - Chức năng: Cho phép bất kỳ người dùng nào báo cáo một bài viết, bình luận, bộ truyện hoặc chương truyện vi phạm tiêu chuẩn cộng đồng.
- **Hiện trạng Frontend**:
  - Tại `PostCard.vue`, `CommentItem.vue`, `ComicDetailPage.vue`, `ComicReaderPage.vue`: **Không có nút "Báo cáo" (Report)**.
  - Người dùng không có cách nào gửi báo cáo lên server qua giao diện.

---

## PHẦN 5: DANH SÁCH CHỨC NĂNG THƯỜNG THẤY NHƯNG CHƯA CÓ CẢ BACKEND LẪN UI

Các chức năng dưới đây **hoàn toàn chưa được phát triển trong source code** (cần đánh giá để đưa vào roadmap tương lai nếu cần thiết):

1. **Quản lý Người dùng cho Admin (Admin User Management)**:
   - Chưa có trang danh sách người dùng (`/admin/users`), chưa có API xem danh sách user, đổi vai trò (Role), hoặc khóa tài khoản (`is_active = false`).
2. **Dashboard Thống kê Tổng quan (Admin Analytics & Overview)**:
   - Chưa có trang Dashboard tổng quan hiển thị số lượt xem toàn trang, tổng số truyện, tổng coin nạp/tiêu, biểu đồ tăng trưởng. Route `/admin` hiện tại chỉ redirect về trang duyệt truyện.
3. **Quên mật khẩu & Đổi mật khẩu (Password Management)**:
   - Chưa có chức năng "Quên mật khẩu" qua email tại trang đăng nhập.
   - Chưa có chức năng "Đổi mật khẩu" trong trang cá nhân khi đã đăng nhập.
4. **Cập nhật Hồ sơ cá nhân & Đổi Avatar**:
   - Chưa có màn hình `ProfilePage.vue` để người dùng tự sửa tên hiển thị hoặc upload ảnh đại diện cá nhân mới.
5. **Nạp Coin thật qua cổng thanh toán**:
   - Hiện tại hệ thống đang sử dụng cơ chế **Demo Faucet** (tự nhận 100 Coin miễn phí/lần, tối đa 5 lần/ngày) để phục vụ chấm điểm và bảo vệ đồ án tốt nghiệp, chưa tích hợp cổng thanh toán trực tuyến bên thứ ba (VNPAY, MoMo, ZaloPay, Stripe).

---

## PHẦN 6: ĐỀ XUẤT THỨ TỰ HOÀN THIỆN HỆ THỐNG (PRIORITY ROADMAP)

Dựa trên kết quả rà soát, để đồ án BlueToon đạt độ hoàn thiện cao nhất về mặt giao diện và trải nghiệm người dùng, dưới đây là lộ trình đề xuất theo độ ưu tiên:

### Mức độ P0: Bổ sung các màn hình Admin còn thiếu đã có sẵn API Backend
> *Mục tiêu: Đưa 100% các API Backend đã code lên giao diện để Admin sử dụng được toàn bộ quyền năng.*
1. **Xây dựng trang Quản lý Báo cáo vi phạm (`/admin/reports`)**:
   - Tạo `AdminReportsPage.vue` hiển thị danh sách báo cáo vi phạm (`posts`, `comments`, `comics`, `chapters`).
   - Thêm nút Đã giải quyết (`resolved`) / Bác bỏ (`dismissed`) kèm ghi chú Admin.
   - Thêm mục "Quản lý Báo cáo vi phạm" vào Sidebar `AdminLayout.vue`.
2. **Bổ sung nút "Báo cáo vi phạm" và Modal Report cho Độc giả**:
   - Thêm icon cờ báo cáo 🚩 trên bài viết (`PostCard.vue`), bình luận (`CommentItem.vue`) và trang đọc (`ComicReaderPage.vue`).
   - Mở modal chọn lý do vi phạm (`spam`, `inappropriate`, `copyright`, `harassment`) và gửi lên API `POST /api/reports`.

### Mức độ P1: Xây dựng trang Quản lý Người dùng & Hoàn tiền VIP cho Admin
1. **Xây dựng trang Quản lý Thành viên (`/admin/users`)**:
   - Tạo API Backend và trang UI xem danh sách người dùng, tìm kiếm theo email/tên.
   - Nút bật/tắt trạng thái hoạt động (Khóa/Mở khóa tài khoản) và phân quyền Role (`reader`, `creator`, `admin`).
2. **Tích hợp form Hoàn tiền VIP (`/admin/vip`)**:
   - Tích hợp giao diện nhập mã giao dịch `transaction_id` để Admin thực hiện hoàn tiền khi có khiếu nại của độc giả.

### Mức độ P2: Hoàn thiện hồ sơ cá nhân và đổi mật khẩu
1. **Trang Quản lý Hồ sơ cá nhân (`/profile`)**:
   - Cho phép người dùng xem thông tin tài khoản, cập nhật tên hiển thị, upload avatar cá nhân mới.
   - Cho phép đổi mật khẩu đăng nhập.

### Mức độ P3: Dashboard thống kê và nâng cao trải nghiệm
1. **Admin Overview Dashboard (`/admin/dashboard`)**:
   - Thẻ thống kê tổng số truyện, tổng số chương đã duyệt, số lượng độc giả, số coin lưu hành.
2. **Chế độ đọc truyện nâng cao**:
   - Bổ sung tùy chọn đọc lật trang (Page-by-page) bên cạnh chế độ cuộn dọc (Webtoon).
   - Thanh điều chỉnh độ sáng và màu nền khi đọc truyện vào ban đêm.

---

## TỔNG KẾT

Tài liệu này đã phản ánh chính xác 100% hiện trạng source code thực tế của dự án BlueToon tại commit hiện tại trên nhánh `main`. Bạn có thể sử dụng trực tiếp **PHẦN 3 (Checklist kiểm thử UI)** để tiến hành kiểm tra trên trình duyệt và ghi nhận phản hồi.
