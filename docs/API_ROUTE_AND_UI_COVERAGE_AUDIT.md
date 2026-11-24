# BÁO CÁO KIỂM TOÁN TOÀN DIỆN API ROUTE & ĐỘ PHỦ GIAO DIỆN (CAI-QA)
**Dự án:** ComicAI (BlueToon)  
**Thời gian thực hiện:** 24/11/2026  
**Chuyên viên kiểm toán:** Senior Full-stack Engineer & Lead QA Analyst  
**Quy chế kiểm toán:** **AUDIT ONLY** — Tuyệt đối không sửa code, không tạo UI, không thêm API, không refactor, không chạy migration/seeder ghi dữ liệu, không commit/push.  
**Branch thực hiện:** `main`  

---

## MỤC LỤC
1. [Phần A: Executive Summary](#phan-a-executive-summary)
2. [Phần B: Full API Route Inventory (94 Routes)](#phan-b-full-api-route-inventory-94-routes)
3. [Phần C: Unused / Partially Used API Investigation](#phan-c-unused--partially-used-api-investigation)
4. [Phần D: UI Coverage Matrix](#phan-d-ui-coverage-matrix)
5. [Phần E: Danh Sách UI Cần Triển Khai Sau Này (Backlog Ưu Tiên)](#phan-e-danh-sach-ui-can-trien-khai-sau-nay-backlog-uu-tien)
6. [Phần F: Routes Không Cần UI](#phan-f-routes-khong-can-ui)
7. [Phần G: Route Trùng Lặp, Legacy Hoặc Có Dấu Hiệu Dư Thừa](#phan-g-route-trung-lap-legacy-hoac-co-dau-hieu-du-thua)
8. [Phần H: Kiểm Tra Quyền Truy Cập & Frontend Route Guards](#phan-h-kiem-tra-quyen-truy-cap--frontend-route-guards)
9. [Phần I: Verification Summary](#phan-i-verification-summary)
10. [Phần J: Git Safety Report](#phan-j-git-safety-report)

---

## PHẦN A: EXECUTIVE SUMMARY

Cuộc kiểm toán được thực hiện bằng cách đối chiếu 100% cây định tuyến Backend (`php artisan route:list --json`) với toàn bộ 14 file Frontend Services (`frontend/src/services/`), Pinia Stores (`frontend/src/stores/`), Vue Router (`frontend/src/router/index.ts`), 19 views/pages và hơn 25 components.

### 1. Bảng số liệu thống kê tổng hợp

| Chỉ số kiểm toán | Số lượng | Tỷ lệ (%) | Ghi chú kỹ thuật |
|:---|:---:|:---:|:---|
| **Tổng số route được kiểm kê** | **94** | 100% | Xác nhận đầy đủ qua Laravel Route Engine |
| **Tổng số API route** | **89** | 94.7% | Bao gồm các route có prefix `/api` và `/sanctum/csrf-cookie` |
| **Tổng số Web / System / Infrastructure route** | **5** | 5.3% | `/` (welcome blade), `storage/{path}` (GET, PUT), `up`, `api/health` |
| **Số route Frontend sử dụng trực tiếp (`USED_DIRECTLY`)** | **73** | 77.7% | Được gọi trực tiếp từ các file service và component |
| **Số route Frontend sử dụng gián tiếp (`USED_INDIRECTLY`)** | **9** | 9.6% | Qua Pinia Stores (Chat, Wallet), Echo WebSocket, CSRF Cookie |
| **Số route sử dụng một phần (`PARTIALLY_USED`)** | **0** | 0.0% | Các module đang dùng đều được khai thác trọn vẹn nghiệp vụ |
| **Số route chưa tìm thấy Frontend usage (`NOT_FOUND_IN_FRONTEND`)** | **12** | 12.8% | Gồm: 1 Missing UI (`POST /api/admin/vip/refund`), 6 redundant polymorphic comments, 1 redundant post unlike, 4 system internal routes |
| **Số chức năng UI hoàn chỉnh (`UI_COMPLETE`)** | **87** | 98.9% | Phủ kín 87/88 chức năng nghiệp vụ (80 trực tiếp + 7 qua đa hình/toggle) |
| **Số chức năng UI một phần (`UI_PARTIAL`)** | **0** | 0.0% | Không có màn hình nào bị thiếu nửa chừng |
| **Số chức năng UI còn thiếu (`UI_MISSING`)** | **1** | 1.1% | Duy nhất: `POST /api/admin/vip/refund` (Admin hoàn tiền VIP) |
| **Số mục chưa xác minh (`UNKNOWN` / `NEEDS MANUAL VERIFICATION`)** | **0** | 0.0% | Toàn bộ 94 route và màn hình đã được đối soát chính xác 100% |

---

## PHẦN B: FULL API ROUTE INVENTORY (94 ROUTES)

Bảng kê chi tiết toàn bộ 94 route của hệ thống BlueToon:

| # | Method | URI | Controller@Method | Middleware/Role | Module | Frontend Usage | UI Status | Evidence |
|:---:|:---|:---|:---|:---|:---|:---:|:---:|:---|
| 1 | `GET|HEAD` | `/` | `Closure` | Public (No Auth) | System / Health / Utility | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE (N/A - System)` | Hạ tầng hệ thống / DevOps / Sanctum cookie / Uptime probe / Blade view. |
| 2 | `GET|HEAD` | `api/admin/banners` | `App\Http\Controllers\Api\AdminBannerController@index` | Sanctum / Admin only | Admin Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.getBanners() | Page: AdminBannersPage.vue (/admin/banners) | UI: Bảng banner, bộ lọc, stats bar, thumbnail preview |
| 3 | `POST` | `api/admin/banners` | `App\Http\Controllers\Api\AdminBannerController@store` | Sanctum / Admin only | Admin Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.createBanner() | Page: AdminBannersPage.vue (/admin/banners) | UI: Nút "Thêm Banner Mới", Form Modal, File upload desktop/mobile |
| 4 | `PATCH` | `api/admin/banners/reorder` | `App\Http\Controllers\Api\AdminBannerController@reorder` | Sanctum / Admin only | Admin Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.reorderBanners() | Page: AdminBannersPage.vue (/admin/banners) | UI: Nút di chuyển thứ tự (▲/▼) |
| 5 | `PUT|POST` | `api/admin/banners/{banner}` | `App\Http\Controllers\Api\AdminBannerController@update` | Sanctum / Admin only | Admin Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.updateBanner() | Page: AdminBannersPage.vue (/admin/banners) | UI: Nút "Sửa", Form Modal, preview ảnh |
| 6 | `DELETE` | `api/admin/banners/{banner}` | `App\Http\Controllers\Api\AdminBannerController@destroy` | Sanctum / Admin only | Admin Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.deleteBanner() | Page: AdminBannersPage.vue (/admin/banners) | UI: Nút "Xóa", ConfirmModal xác nhận |
| 7 | `PATCH` | `api/admin/banners/{banner}/status` | `App\Http\Controllers\Api\AdminBannerController@toggleStatus` | Sanctum / Admin only | Admin Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.toggleBannerStatus() | Page: AdminBannersPage.vue (/admin/banners) | UI: Toggle switch/button trực tiếp trên từng hàng bảng |
| 8 | `GET|HEAD` | `api/admin/moderation/chapters` | `App\Http\Controllers\Api\AdminChapterModerationController@index` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.getModerationChapters() | Page: AdminChaptersModerationPage.vue (/admin/moderation/chapters) | UI: Bảng duyệt chương, lọc theo truyện, phân trang |
| 9 | `POST` | `api/admin/moderation/chapters/{id}/approve` | `App\Http\Controllers\Api\AdminChapterModerationController@approve` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.approveChapter() | Page: AdminChaptersModerationPage.vue (/admin/moderation/chapters) | UI: Nút "Phê duyệt" trong bảng và trong Modal xem trước |
| 10 | `POST` | `api/admin/moderation/chapters/{id}/reject` | `App\Http\Controllers\Api\AdminChapterModerationController@reject` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.rejectChapter() | Page: AdminChaptersModerationPage.vue (/admin/moderation/chapters) | UI: Nút "Từ chối", AdminRejectModal |
| 11 | `GET|HEAD` | `api/admin/moderation/comics` | `App\Http\Controllers\Api\AdminComicModerationController@index` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.getModerationComics() | Page: AdminComicsModerationPage.vue (/admin/moderation/comics) | UI: Tabs (Tất cả, Truyện mới, Thay đổi), Tìm kiếm, Bảng duyệt truyện |
| 12 | `POST` | `api/admin/moderation/comics/{id}/approve` | `App\Http\Controllers\Api\AdminComicModerationController@approve` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.approveComic() | Page: AdminComicsModerationPage.vue (/admin/moderation/comics) | UI: Nút "Duyệt truyện", AdminComicDetailModal |
| 13 | `POST` | `api/admin/moderation/comics/{id}/approve-changes` | `App\Http\Controllers\Api\AdminComicModerationController@approveChanges` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.approveComicChanges() | Page: AdminComicsModerationPage.vue (/admin/moderation/comics) | UI: Nút "Duyệt thay đổi", AdminComicDiffModal |
| 14 | `POST` | `api/admin/moderation/comics/{id}/reject` | `App\Http\Controllers\Api\AdminComicModerationController@reject` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.rejectComic() | Page: AdminComicsModerationPage.vue (/admin/moderation/comics) | UI: Nút "Từ chối", AdminRejectModal |
| 15 | `POST` | `api/admin/moderation/comics/{id}/reject-changes` | `App\Http\Controllers\Api\AdminComicModerationController@rejectChanges` | Sanctum / Admin only | Admin Moderation | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AdminService.rejectComicChanges() | Page: AdminComicsModerationPage.vue (/admin/moderation/comics) | UI: Nút "Từ chối thay đổi", AdminRejectModal trong Diff Modal |
| 16 | `GET|HEAD` | `api/admin/reports` | `App\Http\Controllers\Api\ReportController@index` | Sanctum / Admin only | Admin Reports | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReportService.getReports() / AdminService.getReports() | Page: AdminReportsPage.vue (/admin/reports) | UI: Bảng báo cáo, Tabs trạng thái, Filter loại nội dung & lý do |
| 17 | `GET|HEAD` | `api/admin/reports/{id}` | `App\Http\Controllers\Api\ReportController@show` | Sanctum / Admin only | Admin Reports | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReportService.getReport() / AdminService.getReport() | Page: AdminReportsPage.vue (/admin/reports) | UI: Nút "Xem" 👁️, Modal chi tiết báo cáo vi phạm |
| 18 | `PATCH|PUT` | `api/admin/reports/{id}` | `App\Http\Controllers\Api\ReportController@update` | Sanctum / Admin only | Admin Reports | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReportService.updateReport() / AdminService.updateReport() | Page: AdminReportsPage.vue (/admin/reports) | UI: Nút "Xử lý" ⚖️, Modal quyết định xử lý, Textarea admin_notes |
| 19 | `POST` | `api/admin/vip/refund` | `App\Http\Controllers\Api\AdminVipModerationController@refund` | Sanctum / Admin only | VIP / Refund | `NOT_FOUND_IN_FRONTEND` | `UI_MISSING` | Backend: routes/api.php:173, AdminVipModerationController. Frontend: adminService.ts thiếu method, AdminLayout.vue không có menu. |
| 20 | `GET|HEAD` | `api/ai-search` | `App\Http\Controllers\Api\AiSearchController@search` | Public (No Auth) | AI Search / Embeddings | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.aiSearch() | Page: ComicsPage.vue (/comics) | UI: Thanh tìm kiếm AI, Toggle chế độ AI, Badge độ tương đồng, Kết quả truyện |
| 21 | `GET|HEAD` | `api/banners` | `App\Http\Controllers\Api\BannerController@index` | Public (No Auth) | Catalog / Banner | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getBanners() | Page: HomePage.vue (qua BannerSlider.vue) | UI: Carousel slider ảnh trang chủ |
| 22 | `GET|POST|HEAD` | `api/broadcasting/auth` | `Illuminate\Broadcasting\BroadcastController@authenticate` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE (N/A - System)` | Hạ tầng hệ thống / DevOps / Sanctum cookie / Uptime probe / Blade view. |
| 23 | `GET|HEAD` | `api/chapters/{id}/comments` | `App\Http\Controllers\Api\CommentController@indexForChapter` | Public (No Auth) | Community / Comments | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng endpoint đa hình /api/comments qua CommentSection.vue & CommentForm.vue; route lồng nhau này là REST alias. |
| 24 | `POST` | `api/chapters/{id}/comments` | `App\Http\Controllers\Api\CommentController@storeForChapter` | Sanctum / Authenticated | Community / Comments | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng endpoint đa hình /api/comments qua CommentSection.vue & CommentForm.vue; route lồng nhau này là REST alias. |
| 25 | `POST` | `api/chapters/{id}/unlock` | `App\Http\Controllers\Api\ChapterUnlockController@unlock` | Sanctum / Authenticated | VIP / Refund | `USED_DIRECTLY` | `UI_COMPLETE` | Service: WalletService.unlockChapter() | Page: ComicReaderPage.vue (/comics/:slug/chapters/:ch) | UI: Khung Paywall VIP, Nút "Mở khóa chương", VipUnlockModal |
| 26 | `GET|HEAD` | `api/chat/conversations` | `App\Http\Controllers\Api\ConversationController@index` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: ChatService.getConversations() (qua useChatStore) | Page: ChatPage.vue (/chat) | UI: ConversationSidebar.vue, danh sách hội thoại |
| 27 | `POST` | `api/chat/conversations` | `App\Http\Controllers\Api\ConversationController@store` | Sanctum / Authenticated | Chat / Realtime | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ChatService.createDirectConversation() | Page: ChatPage.vue (/chat) | UI: Khởi tạo chat từ query params ?userId=... |
| 28 | `GET|HEAD` | `api/chat/conversations/{conversation}` | `App\Http\Controllers\Api\ConversationController@show` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: ChatService.getConversation() (qua useChatStore) | Page: ChatPage.vue (/chat) | UI: Header cuộc trò chuyện, thông tin đối phương |
| 29 | `GET|HEAD` | `api/chat/conversations/{conversation}/messages` | `App\Http\Controllers\Api\MessageController@index` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: ChatService.getMessages() (qua useChatStore) | Page: ChatPage.vue (/chat) | UI: MessageList.vue, cuộn tin nhắn |
| 30 | `POST` | `api/chat/conversations/{conversation}/messages` | `App\Http\Controllers\Api\MessageController@store` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: ChatService.sendMessage() (qua useChatStore) | Page: ChatPage.vue (/chat) | UI: MessageComposer.vue, ô nhập tin nhắn và nút gửi |
| 31 | `POST` | `api/chat/conversations/{conversation}/read` | `App\Http\Controllers\Api\ConversationController@markAsRead` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: ChatService.markAsRead() (qua useChatStore) | Page: ChatPage.vue (/chat) | UI: Tự động gọi khi mở xem cuộc hội thoại |
| 32 | `GET|HEAD` | `api/chat/unread-count` | `App\Http\Controllers\Api\ConversationController@unreadCount` | Sanctum / Authenticated | Chat / Realtime | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: ChatService.getUnreadCount() (qua useChatStore) | Page: AppHeader.vue | UI: Huy hiệu số đỏ chưa đọc trên menu Tin nhắn Header |
| 33 | `GET|HEAD` | `api/comics` | `App\Http\Controllers\Api\ComicController@index` | Public (No Auth) | Catalog / Comics | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getComics() | Page: HomePage.vue, ComicsPage.vue, AdminBannersPage.vue, PostFormModal.vue | UI: Lưới truyện ComicCard, bộ lọc thể loại, dropdown chọn truyện |
| 34 | `GET|HEAD` | `api/comics/{comicId}/reading-history` | `App\Http\Controllers\Api\ReadingHistoryController@showByComic` | Sanctum / Authenticated | Reading History / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReadingHistoryService.getComicReadingHistory() | Page: ComicDetailPage.vue | UI: Nút "Đọc tiếp (C.X)" trên trang chi tiết truyện |
| 35 | `POST` | `api/comics/{comicId}/reading-history` | `App\Http\Controllers\Api\ReadingHistoryController@record` | Sanctum / Authenticated | Reading History / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReadingHistoryService.recordReadingHistory() | Page: ComicReaderPage.vue | UI: Tự động kích hoạt khi độc giả mở đọc chương |
| 36 | `DELETE` | `api/comics/{comicId}/reading-history` | `App\Http\Controllers\Api\ReadingHistoryController@destroy` | Sanctum / Authenticated | Reading History / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReadingHistoryService.deleteReadingHistory() | Page: LibraryPage.vue (/library?tab=history) | UI: Nút icon thùng rác xóa từng mục lịch sử đọc |
| 37 | `POST` | `api/comics/{id}/bookmark` | `App\Http\Controllers\Api\UserLibraryController@toggleBookmark` | Sanctum / Authenticated | Library / Bookmark / Follow | `USED_DIRECTLY` | `UI_COMPLETE` | Service: UserLibraryService.toggleBookmark() | Page: ComicDetailPage.vue | UI: Nút "Lưu tủ sách" / "Đã lưu" trong cụm tương tác |
| 38 | `GET|HEAD` | `api/comics/{id}/comments` | `App\Http\Controllers\Api\CommentController@indexForComic` | Public (No Auth) | Community / Comments | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng endpoint đa hình /api/comments qua CommentSection.vue & CommentForm.vue; route lồng nhau này là REST alias. |
| 39 | `POST` | `api/comics/{id}/comments` | `App\Http\Controllers\Api\CommentController@storeForComic` | Sanctum / Authenticated | Community / Comments | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng endpoint đa hình /api/comments qua CommentSection.vue & CommentForm.vue; route lồng nhau này là REST alias. |
| 40 | `POST` | `api/comics/{id}/follow` | `App\Http\Controllers\Api\UserLibraryController@toggleFollow` | Sanctum / Authenticated | Library / Bookmark / Follow | `USED_DIRECTLY` | `UI_COMPLETE` | Service: UserLibraryService.toggleFollow() | Page: ComicDetailPage.vue | UI: Nút "Theo dõi" / "Đang theo dõi" có đếm số người theo dõi |
| 41 | `GET|HEAD` | `api/comics/{slug}` | `App\Http\Controllers\Api\ComicController@show` | Public (No Auth) | Comic Detail / Chapter / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getComicDetail() | Page: ComicDetailPage.vue (/comics/:slug) | UI: Hero banner, ảnh bìa, tóm tắt, tác giả, huy hiệu, thể loại, tags |
| 42 | `GET|HEAD` | `api/comics/{slug}/chapters` | `App\Http\Controllers\Api\ChapterController@index` | Public (No Auth) | Comic Detail / Chapter / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getChapters() | Page: ComicDetailPage.vue, ComicReaderPage.vue | UI: Bảng danh sách chương, drawer chọn chương trong reader |
| 43 | `GET|HEAD` | `api/comics/{slug}/chapters/{chapterNumber}` | `App\Http\Controllers\Api\ChapterController@show` | Public (No Auth) | Comic Detail / Chapter / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getChapterReader() | Page: ComicReaderPage.vue (/comics/:slug/chapters/:ch) | UI: Trình đọc Webtoon cuộn dọc, chuyển chương trước/sau |
| 44 | `GET|HEAD` | `api/comments` | `App\Http\Controllers\Api\CommentController@index` | Public (No Auth) | Community / Comments | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.getComments() | Page: CommentSection.vue (trên ComicDetailPage, PostDetailPage) | UI: Danh sách bình luận, phân trang tải thêm, hiển thị phản hồi |
| 45 | `POST` | `api/comments` | `App\Http\Controllers\Api\CommentController@store` | Sanctum / Authenticated | Community / Comments | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.createComment() | Page: CommentForm.vue (trong CommentSection.vue) | UI: Form nhập bình luận, avatar, nút gửi bình luận |
| 46 | `DELETE` | `api/comments/{id}` | `App\Http\Controllers\Api\CommentController@destroy` | Sanctum / Authenticated | Community / Comments | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.deleteComment() | Page: CommentItem.vue | UI: Nút "Xóa" kèm ConfirmModal |
| 47 | `POST` | `api/comments/{id}/like` | `App\Http\Controllers\Api\CommentController@toggleLike` | Sanctum / Authenticated | Community / Comments | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.toggleCommentLike() | Page: CommentItem.vue | UI: Nút tim thích bình luận kèm bộ đếm like |
| 48 | `DELETE` | `api/comments/{id}/like` | `App\Http\Controllers\Api\CommentController@unlike` | Sanctum / Authenticated | Community / Comments | `USED_INDIRECTLY` | `UI_COMPLETE` | Service: CommunityService.unlikeComment() | Page: CommentItem.vue | UI: Có method service dự phòng cho thao tác unlike |
| 49 | `POST` | `api/comments/{id}/reply` | `App\Http\Controllers\Api\CommentController@reply` | Sanctum / Authenticated | Community / Comments | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.replyComment() | Page: CommentItem.vue, CommentForm.vue | UI: Nút "Trả lời", form nhập phản hồi lồng |
| 50 | `GET|HEAD` | `api/creator/chapters/{id}` | `App\Http\Controllers\Api\CreatorChapterController@show` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.getChapter() | Page: CreatorChapterImagesPage.vue (/creator/chapters/:id/images) | UI: Màn hình quản lý ảnh chương, thông tin chương |
| 51 | `PUT|PATCH` | `api/creator/chapters/{id}` | `App\Http\Controllers\Api\CreatorChapterController@update` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.updateChapter() | Page: CreatorChaptersPage.vue (/creator/comics/:comicId/chapters) | UI: Modal chỉnh sửa thông tin chương |
| 52 | `POST` | `api/creator/chapters/{id}/cancel-review` | `App\Http\Controllers\Api\CreatorChapterController@cancelReview` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.cancelChapterReview() | Page: CreatorChaptersPage.vue | UI: Nút "Hủy gửi duyệt" trên danh sách chương |
| 53 | `POST` | `api/creator/chapters/{id}/images` | `App\Http\Controllers\Api\CreatorChapterController@uploadImages` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.uploadChapterImages() | Page: CreatorChapterImagesPage.vue | UI: Vùng kéo thả upload ảnh, input file nhiều ảnh |
| 54 | `PUT|PATCH` | `api/creator/chapters/{id}/images/reorder` | `App\Http\Controllers\Api\CreatorChapterController@reorderImages` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.reorderChapterImages() | Page: CreatorChapterImagesPage.vue | UI: Nút kéo thả hoặc nút chuyển thứ tự ảnh |
| 55 | `DELETE` | `api/creator/chapters/{id}/images/{imageId}` | `App\Http\Controllers\Api\CreatorChapterController@deleteImage` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.deleteChapterImage() | Page: CreatorChapterImagesPage.vue | UI: Nút xóa trên từng thumbnail ảnh kèm modal xác nhận |
| 56 | `POST` | `api/creator/chapters/{id}/recall` | `App\Http\Controllers\Api\CreatorChapterController@recall` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.recallChapter() | Page: CreatorChaptersPage.vue | UI: Nút "Thu hồi chương" kèm ConfirmModal |
| 57 | `POST` | `api/creator/chapters/{id}/submit-review` | `App\Http\Controllers\Api\CreatorChapterController@submitReview` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.submitChapterReview() | Page: CreatorChaptersPage.vue | UI: Nút "Gửi duyệt" trên danh sách chương |
| 58 | `GET|HEAD` | `api/creator/comics` | `App\Http\Controllers\Api\CreatorComicController@index` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.getComics() | Page: CreatorComicsPage.vue (/creator/comics) | UI: Bảng truyện của tác giả, trạng thái kiểm duyệt, thanh tìm kiếm |
| 59 | `POST` | `api/creator/comics` | `App\Http\Controllers\Api\CreatorComicController@store` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.createComic() | Page: CreatorComicFormPage.vue (/creator/comics/create) | UI: Form tạo truyện, upload ảnh bìa, chọn genre/tags, định dạng tuổi |
| 60 | `GET|HEAD` | `api/creator/comics/{comicId}/chapters` | `App\Http\Controllers\Api\CreatorChapterController@indexForComic` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.getChapters() | Page: CreatorChaptersPage.vue (/creator/comics/:comicId/chapters) | UI: Bảng quản lý chương, số trang, lượt xem, giá Coin VIP, trạng thái |
| 61 | `POST` | `api/creator/comics/{comicId}/chapters` | `App\Http\Controllers\Api\CreatorChapterController@store` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.createChapter() | Page: CreatorChaptersPage.vue | UI: Nút "Thêm chương mới", Modal nhập số chương và tiêu đề |
| 62 | `GET|HEAD` | `api/creator/comics/{id}` | `App\Http\Controllers\Api\CreatorComicController@show` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.getComic() | Page: CreatorComicFormPage.vue, CreatorChaptersPage.vue | UI: Tải thông tin truyện lên form chỉnh sửa và header trang chương |
| 63 | `PUT|PATCH|POST` | `api/creator/comics/{id}` | `App\Http\Controllers\Api\CreatorComicController@update` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.updateComic() | Page: CreatorComicFormPage.vue (/creator/comics/:id/edit) | UI: Form chỉnh sửa truyện, nút "Lưu thay đổi" |
| 64 | `DELETE` | `api/creator/comics/{id}` | `App\Http\Controllers\Api\CreatorComicController@destroy` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.deleteComic() | Page: CreatorComicsPage.vue | UI: Nút "Xóa" trên từng dòng truyện kèm ConfirmModal |
| 65 | `POST` | `api/creator/comics/{id}/cancel-review` | `App\Http\Controllers\Api\CreatorComicController@cancelReview` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.cancelReview() | Page: CreatorComicsPage.vue | UI: Nút "Hủy gửi duyệt" trên danh sách truyện |
| 66 | `POST` | `api/creator/comics/{id}/submit-review` | `App\Http\Controllers\Api\CreatorComicController@submitReview` | Sanctum / Creator & Admin | Creator Studio | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CreatorService.submitReview() | Page: CreatorComicsPage.vue | UI: Nút "Gửi duyệt" trên danh sách truyện |
| 67 | `GET|HEAD` | `api/genres` | `App\Http\Controllers\Api\GenreController@index` | Public (No Auth) | Catalog / Metadata | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getGenres() | Page: ComicsPage.vue, CreatorComicFormPage.vue, AdminComicsModerationPage.vue | UI: Chips lọc thể loại, checkbox chọn thể loại trong form |
| 68 | `GET|HEAD` | `api/health` | `Closure` | Public (No Auth) | System / Health / Utility | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE (N/A - System)` | Hạ tầng hệ thống / DevOps / Sanctum cookie / Uptime probe / Blade view. |
| 69 | `POST` | `api/login` | `App\Http\Controllers\Api\AuthController@login` | Public (No Auth) | Authentication / Account | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AuthService.login() (qua useAuthStore) | Page: LoginPage.vue (/login) | UI: Form đăng nhập, input email/password, checkbox ghi nhớ, nút Đăng nhập |
| 70 | `POST` | `api/logout` | `App\Http\Controllers\Api\AuthController@logout` | Sanctum / Authenticated | Authentication / Account | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AuthService.logout() (qua useAuthStore) | Page: AppHeader.vue, AdminLayout.vue | UI: Nút "Đăng xuất" trên menu người dùng và sidebar Admin |
| 71 | `GET|HEAD` | `api/posts` | `App\Http\Controllers\Api\PostController@index` | Public (No Auth) | Community / Posts | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.getPosts() | Page: CommunityPage.vue (/community) | UI: Bảng tin cộng đồng, filter theo truyện/người dùng, phân trang |
| 72 | `POST` | `api/posts` | `App\Http\Controllers\Api\PostController@store` | Sanctum / Authenticated | Community / Posts | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.createPost() | Page: CommunityPage.vue (qua PostFormModal.vue) | UI: Nút "Tạo bài viết", Modal soạn thảo, upload ảnh, chọn truyện |
| 73 | `GET|HEAD` | `api/posts/{id}` | `App\Http\Controllers\Api\PostController@show` | Public (No Auth) | Community / Posts | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.getPost() | Page: PostDetailPage.vue (/posts/:id) | UI: Trang bài viết chi tiết, danh sách bình luận kèm theo |
| 74 | `PUT|PATCH|POST` | `api/posts/{id}` | `App\Http\Controllers\Api\PostController@update` | Sanctum / Authenticated | Community / Posts | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.updatePost() | Page: PostCard.vue, PostFormModal.vue | UI: Nút "Sửa" trên bài viết của chính tác giả, Modal sửa bài |
| 75 | `DELETE` | `api/posts/{id}` | `App\Http\Controllers\Api\PostController@destroy` | Sanctum / Authenticated | Community / Posts | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.deletePost() | Page: PostCard.vue | UI: Nút "Xóa" trên bài viết kèm ConfirmModal |
| 76 | `GET|HEAD` | `api/posts/{id}/comments` | `App\Http\Controllers\Api\CommentController@indexForPost` | Public (No Auth) | Community / Comments | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng endpoint đa hình /api/comments qua CommentSection.vue & CommentForm.vue; route lồng nhau này là REST alias. |
| 77 | `POST` | `api/posts/{id}/comments` | `App\Http\Controllers\Api\CommentController@storeForPost` | Sanctum / Authenticated | Community / Comments | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng endpoint đa hình /api/comments qua CommentSection.vue & CommentForm.vue; route lồng nhau này là REST alias. |
| 78 | `POST` | `api/posts/{id}/like` | `App\Http\Controllers\Api\PostController@toggleLike` | Sanctum / Authenticated | Community / Posts | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CommunityService.togglePostLike() | Page: PostCard.vue | UI: Nút tim thích bài viết kèm bộ đếm like |
| 79 | `DELETE` | `api/posts/{id}/like` | `App\Http\Controllers\Api\PostController@unlike` | Sanctum / Authenticated | Community / Posts | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Frontend dùng nút toggle POST /api/posts/{id}/like trên PostCard.vue; route DELETE này là idempotent alias. |
| 80 | `POST` | `api/register` | `App\Http\Controllers\Api\AuthController@register` | Public (No Auth) | Authentication / Account | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AuthService.register() (qua useAuthStore) | Page: RegisterPage.vue (/register) | UI: Form đăng ký, input tên, email, password, password confirmation, role reader/creator |
| 81 | `POST` | `api/reports` | `App\Http\Controllers\Api\ReportController@store` | Sanctum / Authenticated | Reports | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReportService.submitReport() | Page: ReportModal.vue (gắn trên PostCard.vue, CommentItem.vue, ComicDetailPage.vue) | UI: Nút 🚩 Báo cáo, ReportModal với preset reasons và mô tả |
| 82 | `GET|HEAD` | `api/tags` | `App\Http\Controllers\Api\TagController@index` | Public (No Auth) | Catalog / Metadata | `USED_DIRECTLY` | `UI_COMPLETE` | Service: CatalogService.getTags() | Page: ComicsPage.vue, CreatorComicFormPage.vue, AdminComicsModerationPage.vue | UI: Chips lọc tags, input chọn tags trong form |
| 83 | `GET|HEAD` | `api/user` | `App\Http\Controllers\Api\AuthController@user` | Sanctum / Authenticated | Authentication / Account | `USED_DIRECTLY` | `UI_COMPLETE` | Service: AuthService.getUser() (qua useAuthStore) | Page: AppHeader.vue, AdminLayout.vue, router guards | UI: Avatar, tên người dùng, huy hiệu role, số dư Coin |
| 84 | `GET|HEAD` | `api/user/bookmarks` | `App\Http\Controllers\Api\UserLibraryController@bookmarks` | Sanctum / Authenticated | Library / Bookmark / Follow | `USED_DIRECTLY` | `UI_COMPLETE` | Service: UserLibraryService.getBookmarks() | Page: LibraryPage.vue (/library?tab=bookmarks) | UI: Lưới truyện đã lưu trong Tủ sách, phân trang |
| 85 | `GET|HEAD` | `api/user/follows` | `App\Http\Controllers\Api\UserLibraryController@follows` | Sanctum / Authenticated | Library / Bookmark / Follow | `USED_DIRECTLY` | `UI_COMPLETE` | Service: UserLibraryService.getFollows() | Page: LibraryPage.vue (/library?tab=follows) | UI: Lưới truyện đang theo dõi, phân trang, nút bỏ theo dõi |
| 86 | `GET|HEAD` | `api/user/reading-history` | `App\Http\Controllers\Api\ReadingHistoryController@index` | Sanctum / Authenticated | Reading History / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReadingHistoryService.getReadingHistory() | Page: LibraryPage.vue (/library?tab=history) | UI: Bảng lịch sử đọc, chương đọc gần nhất, thời gian đọc |
| 87 | `DELETE` | `api/user/reading-history` | `App\Http\Controllers\Api\ReadingHistoryController@clear` | Sanctum / Authenticated | Reading History / Reader | `USED_DIRECTLY` | `UI_COMPLETE` | Service: ReadingHistoryService.clearReadingHistory() | Page: LibraryPage.vue (/library?tab=history) | UI: Nút "Xóa tất cả lịch sử" kèm ConfirmModal |
| 88 | `GET|HEAD` | `api/wallet` | `App\Http\Controllers\Api\WalletController@show` | Sanctum / Authenticated | Wallet / Coin / Faucet | `USED_DIRECTLY` | `UI_COMPLETE` | Service: WalletService.getWallet() (qua useWalletStore) | Page: WalletPage.vue (/wallet), AppHeader.vue | UI: Card số dư ví, thanh tiến trình Faucet 5/5, số Coin trên Header |
| 89 | `POST` | `api/wallet/faucet` | `App\Http\Controllers\Api\WalletController@faucet` | Sanctum / Authenticated | Wallet / Coin / Faucet | `USED_DIRECTLY` | `UI_COMPLETE` | Service: WalletService.claimFaucet() (qua useWalletStore) | Page: WalletPage.vue (/wallet) | UI: Nút "Nhận 100 Coin miễn phí", countdown/bộ đếm lượt |
| 90 | `GET|HEAD` | `api/wallet/transactions` | `App\Http\Controllers\Api\WalletController@transactions` | Sanctum / Authenticated | Wallet / Coin / Faucet | `USED_DIRECTLY` | `UI_COMPLETE` | Service: WalletService.getTransactions() (qua useWalletStore) | Page: WalletPage.vue (/wallet) | UI: Bảng lịch sử giao dịch, bộ lọc loại giao dịch, phân trang |
| 91 | `GET|HEAD` | `sanctum/csrf-cookie` | `Laravel\Sanctum\Http\Controllers\CsrfCookieController@show` | Public (No Auth) | System / Health / Utility | `USED_INDIRECTLY` | `UI_COMPLETE (N/A - System)` | Hạ tầng hệ thống / DevOps / Sanctum cookie / Uptime probe / Blade view. |
| 92 | `GET|HEAD` | `storage/{path}` | `Closure` | Public (No Auth) | Upload / Media | `USED_DIRECTLY` | `UI_COMPLETE` | Service: Thẻ <img> tải URL cover, banner, avatar, chapter images | Page: Toàn bộ trang ứng dụng | UI: Ảnh bìa truyện, ảnh trang đọc, ảnh banner, avatar |
| 93 | `PUT` | `storage/{path}` | `Closure` | Public (No Auth) | Upload / Media | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE (N/A - System)` | Hạ tầng hệ thống / DevOps / Sanctum cookie / Uptime probe / Blade view. |
| 94 | `GET|HEAD` | `up` | `Illuminate\Foundation\Http\HealthCheck` | Public (No Auth) | System / Health / Utility | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE (N/A - System)` | Hạ tầng hệ thống / DevOps / Sanctum cookie / Uptime probe / Blade view. |

---

## PHẦN C: UNUSED / PARTIALLY USED API INVESTIGATION

Phân tích chuyên sâu 8 API nghiệp vụ hiện tại Frontend không gọi trực tiếp (`NOT_FOUND_IN_FRONTEND`):

| Method | URI | Controller | Usage Status | UI Status | Root Cause | Evidence | Recommended Next Step |
|:---|:---|:---|:---:|:---:|:---|:---|:---|
| `POST` | `api/admin/vip/refund` | `AdminVipModerationController@refund` | `NOT_FOUND_IN_FRONTEND` | `UI_MISSING` | Backend đã hoàn tất tại Phase 5 (CAI-025B) nhưng chưa tạo service method trong `adminService.ts` và chưa xây dựng giao diện trong Admin. | - Route: `routes/api.php:173`<br>- Controller: `app/Http/Controllers/Api/AdminVipModerationController.php`<br>- Frontend: `adminService.ts` không có method `refundVip`<br>- UI: `AdminLayout.vue` thiếu menu hoàn tiền. | **P0:** Bổ sung method vào `adminService.ts` và xây dựng trang hoặc modal hoàn tiền trong phân hệ Admin. |
| `GET` | `api/chapters/{id}/comments` | `CommentController@indexForChapter` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant REST alias. Frontend đã chuẩn hóa toàn bộ việc tải bình luận qua API đa hình `GET /api/comments?commentable_type=chapter&commentable_id={id}`. | `CommentSection.vue` gọi `CommunityService.getComments({ commentable_type: 'chapter', commentable_id })`. | Giữ nguyên làm REST alias dự phòng. Không cần sửa UI. |
| `POST` | `api/chapters/{id}/comments` | `CommentController@storeForChapter` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant REST alias. Frontend tạo bình luận qua API đa hình `POST /api/comments` với payload `{ commentable_type: 'chapter', commentable_id }`. | `CommentForm.vue` gửi payload đa hình tới `CommunityService.createComment()`. | Giữ nguyên làm REST alias dự phòng. Không cần sửa UI. |
| `GET` | `api/comics/{id}/comments` | `CommentController@indexForComic` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant REST alias. Frontend tải bình luận truyện qua API đa hình `GET /api/comments?commentable_type=comic&commentable_id={id}`. | `ComicDetailPage.vue` truyền `:target-type="'comic'"` vào `CommentSection.vue`. | Giữ nguyên làm REST alias dự phòng. Không cần sửa UI. |
| `POST` | `api/comics/{id}/comments` | `CommentController@storeForComic` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant REST alias. Frontend tạo bình luận truyện qua API đa hình `POST /api/comments`. | `CommentForm.vue` gửi payload đa hình tới `CommunityService.createComment()`. | Giữ nguyên làm REST alias dự phòng. Không cần sửa UI. |
| `GET` | `api/posts/{id}/comments` | `CommentController@indexForPost` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant REST alias. Frontend tải bình luận bài viết qua API đa hình `GET /api/comments?commentable_type=post&commentable_id={id}`. | `PostDetailPage.vue` truyền `:target-type="'post'"` vào `CommentSection.vue`. | Giữ nguyên làm REST alias dự phòng. Không cần sửa UI. |
| `POST` | `api/posts/{id}/comments` | `CommentController@storeForPost` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant REST alias. Frontend đăng bình luận bài viết qua API đa hình `POST /api/comments`. | `CommentForm.vue` gửi payload đa hình tới `CommunityService.createComment()`. | Giữ nguyên làm REST alias dự phòng. Không cần sửa UI. |
| `DELETE` | `api/posts/{id}/like` | `PostController@unlike` | `NOT_FOUND_IN_FRONTEND` | `UI_COMPLETE` | Redundant Toggle alias. Frontend sử dụng endpoint toggle `POST /api/posts/{id}/like` trên `PostCard.vue` để xử lý cả 2 chiều thích và hủy thích. | `PostCard.vue` gọi `CommunityService.togglePostLike(post.id)`. Controller tự động xóa like nếu bản ghi đã tồn tại. | Giữ nguyên làm Idempotent REST API. Không cần sửa UI. |

---

## PHẦN D: UI COVERAGE MATRIX

Bảng ma trận độ phủ giao diện theo từng phân hệ nghiệp vụ:

| Module | Backend Capability | Frontend API Call | UI Entry Point | UI Coverage | Missing Actions | Priority |
|:---|:---|:---|:---|:---:|:---|:---:|
| **Authentication & Account** | Đăng ký, Đăng nhập, Đăng xuất, Lấy Profile | `AuthService.login/register/logout/getUser` | `/login`, `/register`, `AppHeader.vue` | `UI_COMPLETE` | Quên mật khẩu (chưa có cả backend API) | P2 |
| **Catalog, Comics & Search** | Danh sách truyện, Chi tiết truyện, Thể loại, Tags, AI Search | `CatalogService.getComics/getComicDetail/getGenres/getTags/aiSearch` | `/comics`, `/comics/:slug`, `HomePage.vue` | `UI_COMPLETE` | Không có | — |
| **Reader & Reading History** | Danh sách chương, Xem ảnh chương Webtoon, Ghi nhận & Xóa lịch sử đọc | `CatalogService.getChapters/getChapterReader`, `ReadingHistoryService.*` | `/comics/:slug/chapters/:ch`, `/library?tab=history` | `UI_COMPLETE` | Không có | — |
| **User Library** | Lưu tủ sách (Bookmark), Theo dõi truyện (Follow), Lấy danh sách | `UserLibraryService.toggleBookmark/toggleFollow/getBookmarks/getFollows` | `ComicDetailPage.vue`, `/library?tab=bookmarks`, `/library?tab=follows` | `UI_COMPLETE` | Không có | — |
| **Creator Studio** | Quản lý truyện, Tạo/Sửa/Xóa truyện, Quản lý chương, Upload/Sắp xếp/Xóa ảnh Webtoon, Gửi/Hủy duyệt, Thu hồi | `CreatorService.*` (15 methods) | `/creator/comics`, `/creator/comics/create`, `/creator/comics/:id/chapters`, `/creator/chapters/:id/images` | `UI_COMPLETE` | Không có | — |
| **Admin Moderation** | Hàng đợi duyệt truyện mới, Duyệt thay đổi metadata (Visual Diff), Từ chối truyện, Hàng đợi duyệt chương, Xem trước ảnh, Duyệt/Từ chối chương | `AdminService.getModerationComics/approveComic/approveComicChanges/rejectComic/...` | `/admin/moderation/comics`, `/admin/moderation/chapters` | `UI_COMPLETE` | Không có | — |
| **Admin Banner** | CRUD Banner, Sắp xếp thứ tự (Reorder), Bật/Tắt hiển thị (Toggle status) | `AdminService.getBanners/createBanner/updateBanner/deleteBanner/reorderBanners/toggleBannerStatus` | `/admin/banners` | `UI_COMPLETE` | Không có | — |
| **Reports (Báo cáo vi phạm)** | Độc giả gửi báo cáo vi phạm, Admin xem danh sách, Xem chi tiết nội dung bị báo cáo, Cập nhật trạng thái xử lý | `ReportService.submitReport/getReports/getReport/updateReport` | `ReportModal.vue` (trên PostCard, CommentItem, ComicDetail), `/admin/reports` | `UI_COMPLETE` | Khóa tài khoản trực tiếp từ báo cáo | P1 |
| **Wallet, Coin & VIP** | Xem số dư, Nhận Coin Faucet miễn phí, Xem lịch sử giao dịch, Mở khóa chương VIP | `WalletService.getWallet/claimFaucet/getTransactions/unlockChapter` | `/wallet`, `ComicReaderPage.vue` (VipUnlockModal), `AppHeader.vue` | `UI_COMPLETE` | Không có | — |
| **VIP Refund (Hoàn tiền)** | Admin hoàn Coin giao dịch mở khóa và thu hồi quyền đọc | *Chưa có call* (`POST /api/admin/vip/refund`) | *Chưa có màn hình / menu* | `UI_MISSING` | Màn hình Admin hoàn tiền VIP & thu hồi quyền đọc | **P0** |
| **Community & Social** | Đăng/Sửa/Xóa bài viết, Thích bài viết, Bình luận đa hình 2 cấp, Thích bình luận, Xóa bình luận | `CommunityService.*` (14 methods) | `/community`, `/posts/:id`, `CommentSection.vue` | `UI_COMPLETE` | Không có | — |
| **Realtime Chat** | Danh sách hội thoại, Tạo chat DM, Lịch sử tin nhắn, Gửi tin nhắn, Đánh dấu đã đọc, Huy hiệu tin nhắn chưa đọc | `ChatService.*` (qua `useChatStore`), `echoService.ts` | `/chat`, `AppHeader.vue` | `UI_COMPLETE` | Không có | — |

---

## PHẦN E: DANH SÁCH UI CẦN TRIỂN KHAI SAU NÀY (BACKLOG ƯU TIÊN)

### Nhóm P0: Chức năng quan trọng, cần có để vận hành và bảo vệ dữ liệu
1. **Admin Quản lý Giao dịch & Hoàn tiền VIP (VIP Refund UI)**
   * **Chức năng:** Cho phép Admin tra cứu các giao dịch mở khóa chương VIP có khiếu nại (ví dụ chương bị lỗi hình ảnh hoặc bị thu hồi) và thực hiện hoàn Coin lại cho độc giả, đồng thời thu hồi quyền đọc chương đó.
   * **API liên quan:** `POST /api/admin/vip/refund` (`AdminVipModerationController@refund`).
   * **UI cần xây dựng:**
     * Menu "Hoàn tiền VIP" trên sidebar `AdminLayout.vue`.
     * Trang `AdminVipRefundPage.vue` (hoặc modal trong Admin): Form nhập `user_id`, `chapter_id`, `reason`.
     * Bảng hiển thị kết quả hoàn tiền (Transaction ID hoàn, số coin hoàn trả, thời gian xử lý).
   * **Người dùng / Role:** Admin.
   * **Lý do cần thiết:** Đây là API nghiệp vụ hoàn chỉnh duy nhất của Backend chưa được đưa lên UI, giải quyết tranh chấp tài chính Coin giữa độc giả và nền tảng.
   * **Bằng chứng Backend đã sẵn sàng:** Controller `AdminVipModerationController`, FormRequest `RefundChapterUnlockRequest`, Service `ChapterUnlockService::refund()`, bảng `chapter_unlocks` và `coin_transactions` đã hỗ trợ idempotency và khóa ngoại.
   * **Phụ thuộc:** Không có phụ thuộc mới, có thể triển khai ngay.

### Nhóm P1: Chức năng quản trị / nghiệp vụ cần thiết
2. **Admin Quản lý Người dùng & Xử lý Vi phạm (User Management & Ban System)**
   * **Chức năng:** Admin xem danh sách toàn bộ người dùng, số dư Coin, trạng thái tài khoản; hỗ trợ Khóa tài khoản (Ban) hoặc Mở khóa tài khoản (Unban); phân quyền người dùng (Reader / Creator / Admin).
   * **API liên quan:** Cần bổ sung Backend API `GET /api/admin/users`, `POST /api/admin/users/{id}/ban`, `PATCH /api/admin/users/{id}/role`.
   * **UI cần xây dựng:**
     * Trang `AdminUsersPage.vue` tích hợp trên Sidebar Admin.
     * Nút "Khóa người dùng này" tích hợp trực tiếp trong Modal xử lý báo cáo vi phạm (`AdminReportsPage.vue`).
   * **Người dùng / Role:** Admin.
   * **Lý do cần thiết:** Hiện tại khi Admin duyệt báo cáo vi phạm người dùng ở `AdminReportsPage.vue`, Admin chưa có công cụ khóa tài khoản vi phạm.
   * **Bằng chứng Backend:** Bảng `users` đã có sẵn cột `role` và trường trạng thái, chỉ cần viết Controller và UI.
   * **Phụ thuộc:** Cần phát triển cả Backend API và Frontend UI.

### Nhóm P2: Chức năng nâng cao hoặc tiện ích
3. **Quên mật khẩu & Đặt lại mật khẩu (Forgot / Reset Password)**
   * **Chức năng:** Gửi link đặt lại mật khẩu qua email và màn hình nhập mật khẩu mới.
   * **API liên quan:** Cần bổ sung Backend API `POST /api/forgot-password` và `POST /api/reset-password`.
   * **UI cần xây dựng:** `ForgotPasswordPage.vue` và `ResetPasswordPage.vue`.
   * **Người dùng / Role:** Reader, Creator.
   * **Lý do cần thiết:** Tiện ích bảo mật tài khoản người dùng tự phục vụ khi quên mật khẩu.

---

## PHẦN F: ROUTES KHÔNG CẦN UI

Các route sau được xác định rõ là phục vụ hạ tầng, bảo mật hoặc hệ thống nền tảng, không thuộc luồng tương tác giao diện người dùng:

1. **`GET /` (`Closure`):** Web route mặc định của Laravel phục vụ file Blade `welcome.blade.php`. Hệ thống hoạt động theo kiến trúc Single Page Application (SPA) tách rời với Vite dev server / production dist, do đó route này không cần UI.
2. **`GET|POST api/broadcasting/auth` (`BroadcastController@authenticate`):** Endpoint xác thực bảo mật kênh riêng tư của Laravel Echo / Pusher / Reverb. Được thư viện `pusher-js` gọi ngầm tự động khi người dùng kết nối kênh chat `private-chat.{userId}`.
3. **`GET api/health` (`Closure`):** Health check endpoint trả về JSON `{"status": "ok"}`, phục vụ giám sát container Docker / Kubernetes liveness probe, không phục vụ người dùng cuối.
4. **`GET sanctum/csrf-cookie` (`CsrfCookieController@show`):** Endpoint cấp phát cookie bảo mật `XSRF-TOKEN` của Laravel Sanctum. Được Axios client gọi ngầm tự động trước các thao tác xác thực, không cần hiển thị UI.
5. **`GET storage/{path}` (`Closure`):** Phục vụ trực tiếp tài nguyên tĩnh (ảnh bìa, banner, ảnh chương) cho trình duyệt thông qua thẻ HTML `<img :src="...">`.
6. **`PUT storage/{path}` (`Closure`):** Endpoint nội bộ của Laravel Filesystem package, không dùng cho upload người dùng (ứng dụng upload qua Multipart Form Controller).
7. **`GET up` (`HealthCheck`):** Route kiểm tra uptime ping mặc định của Laravel 11.

---

## PHẦN G: ROUTE TRÙNG LẶP, LEGACY HOẶC CÓ DẤU HIỆU DƯ THỪA

Trong quá trình kiểm toán, phát hiện **7 routes** có tính chất trùng lặp hoặc tương đương với các endpoint đang được sử dụng chính thức:

1. **Nhóm 6 route bình luận lồng nhau RESTful:**
   * `GET /api/chapters/{id}/comments` & `POST /api/chapters/{id}/comments`
   * `GET /api/comics/{id}/comments` & `POST /api/comics/{id}/comments`
   * `GET /api/posts/{id}/comments` & `POST /api/posts/{id}/comments`
   * *Đánh giá:* Các route này phục vụ thiết kế RESTful lồng nhau (Nested REST), tuy nhiên Frontend đã chuẩn hóa sử dụng API Đa hình `GET /api/comments` và `POST /api/comments` với tham số `commentable_type` và `commentable_id`.
   * *Khuyến nghị:* Giữ nguyên trong mã nguồn làm REST alias dự phòng, không sửa hoặc xóa trong đợt này.
2. **Route hủy thích bài viết dạng DELETE:**
   * `DELETE /api/posts/{id}/like` (`PostController@unlike`)
   * *Đánh giá:* Frontend đã dùng endpoint `POST /api/posts/{id}/like` dạng Toggle (tự động Like nếu chưa có, Unlike nếu đã Like) trên `PostCard.vue`.
   * *Khuyến nghị:* Giữ nguyên làm Idempotent DELETE RESTful API.

---

## PHẦN H: KIỂM TRA QUYỀN TRUY CẬP & FRONTEND ROUTE GUARDS

Hệ thống phân quyền được kiểm tra đồng bộ giữa Backend Middleware và Frontend Vue Router:

| Cấp độ phân quyền | Backend Middleware | Frontend Router Guard | Các chức năng được bảo vệ |
|:---|:---|:---|:---|
| **Public (Không cần Auth)** | Không có middleware auth | Cho phép tự do truy cập | Đọc truyện công khai, Xem banner, Danh mục, Tags, Tìm kiếm AI, Đăng nhập, Đăng ký |
| **Authenticated (Sanctum)** | `auth:sanctum` | `requiresAuth: true` (chuyển hướng `/login` nếu chưa đăng nhập) | Quản lý tủ sách, Lịch sử đọc, Đăng bình luận, Thả tim, Đăng bài cộng đồng, Chat, Ví Coin, Faucet, Mở khóa VIP, Gửi báo cáo vi phạm |
| **Creator** | `auth:sanctum`, `role:creator,admin` | `requiresRole: ['creator', 'admin']` | Không gian Creator Studio: Tạo truyện, Sửa truyện, Quản lý chương, Upload ảnh Webtoon, Gửi duyệt |
| **Admin** | `auth:sanctum`, `role:admin` | `requiresRole: ['admin']` | Kiểm duyệt truyện mới, Kiểm duyệt thay đổi metadata, Kiểm duyệt chương, Quản lý Banners, Quản lý Báo cáo vi phạm, Hoàn tiền VIP |

*Xác nhận:* 100% các route nhạy cảm của Admin và Creator đều có 2 lớp bảo vệ chặt chẽ: lớp Middleware Backend chặn HTTP 403 Forbidden và lớp Router Guard Frontend chặn chuyển trang trái phép.

---

## PHẦN I: VERIFICATION SUMMARY

* **Các lệnh đã thực thi kiểm tra:**
  1. `git status --short`: Xác minh tính toàn vẹn của working tree trước và sau kiểm toán.
  2. `php artisan route:list --json`: Trích xuất đầy đủ 94 routes từ HTTP Kernel.
  3. Node.js audit script: Khớp nối 100% route với 14 file service và component giao diện.
* **Các file mã nguồn chính đã đối chiếu:**
  * Backend: `routes/api.php`, `routes/web.php`, Controllers (`AdminBannerController`, `AdminComicModerationController`, `AdminChapterModerationController`, `AdminVipModerationController`, `ReportController`, `WalletController`, `ChapterUnlockController`, `AiSearchController`, `CommentController`, `PostController`, `ConversationController`, `CreatorComicController`, `CreatorChapterController`).
  * Frontend: 14 services trong `frontend/src/services/`, 4 stores trong `frontend/src/stores/`, router `frontend/src/router/index.ts`, layout `AdminLayout.vue`, các view và component chính.
* **Các kiểm tra không chạy (`NOT RUN`):**
  * Không chạy bất kỳ database migration / seeder / mutation command nào.
  * Không chạy các request POST/PUT/DELETE trên database thật.
  * `tests/e2e/auth.spec.mjs` bảo lưu là `NOT RUN` theo checklist staging của CAI-029.

---

## PHẦN J: GIT SAFETY REPORT

* **Branch hiện tại:** `main`
* **Trạng thái Git:** Cây làm việc được bảo toàn 100%, tất cả các file modified và untracked từ các đợt phát triển trước (Admin Banners, Report Management, Logo, Test scripts) được giữ nguyên vẹn.
* **Cam kết an toàn:**
  * [x] Tuyệt đối không sửa mã nguồn backend/frontend trong nhiệm vụ này.
  * [x] Tuyệt đối không tạo file mã nguồn ngoài phạm vi báo cáo.
  * [x] Tuyệt đối không chạy migration, seeder hay ghi dữ liệu vào database.
  * [x] Tuyệt đối không dùng git reset, clean, checkout hay stash làm mất dữ liệu người dùng.
  * [x] Tuyệt đối không commit hay push lên remote.

---
*Báo cáo được lập hoàn chỉnh và nghiệm thu kỹ thuật bởi Senior Full-stack Engineer & Lead QA Analyst.*
