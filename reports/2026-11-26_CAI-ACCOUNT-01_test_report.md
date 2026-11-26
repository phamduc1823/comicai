# Báo cáo Kiểm thử Phân hệ Quản lý tài khoản & User Profile Dropdown (CAI-ACCOUNT-01)

## 1. Thông tin tổng quan
- **Mã nhiệm vụ**: `CAI-ACCOUNT-01`
- **Tên nhiệm vụ**: Account Management & User Profile Dropdown on Navbar
- **Thời gian thực hiện**: 2026-11-26
- **Trạng thái kỹ thuật**: **DONE (Chờ duyệt ACCEPTED)**
- **Môi trường thực thi**:
  - Backend: Laravel 12 API, PHP 8.4, SQLite in-memory (testing), Laravel Sanctum
  - Frontend: Vue 3, TypeScript, Vite, Pinia, Vue Router

---

## 2. Phạm vi đã triển khai

### Backend API:
- `GET /api/user/profile`: Xem thông tin tài khoản hiện tại (id, name, email, role, avatar_path, avatar_url, is_active, email_verified_at, created_at, updated_at).
- `PUT /api/user/profile`: Cập nhật họ tên và email (ngăn chặn mass-assignment role, is_active, user id; nếu đổi email, tự động reset trạng thái xác minh).
- `POST /api/user/avatar`: Tải lên ảnh đại diện mới (validate MIME type, định dạng an toàn jpeg/png/webp, dung lượng <= 2MB, lưu trữ tại `storage/app/public/avatars`, tự động xóa avatar cũ của người dùng nếu không phải ảnh mặc định).
- `DELETE /api/user/avatar`: Xóa ảnh đại diện hiện tại (xóa file vật lý khỏi disk public, đặt `avatar_path = null`).
- `PUT /api/user/password`: Đổi mật khẩu tài khoản (xác minh mật khẩu hiện tại bằng `Hash::check`, validate độ dài tối thiểu 8 ký tự, xác nhận mật khẩu khớp, kiểm tra mật khẩu mới không trùng mật khẩu cũ, rate limiting `throttle:10,1`, bảo mật tuyệt đối không log/trả về hash).
- Cập nhật `UserResource.php`: Bổ sung trường `avatar_url` chuẩn hóa từ Laravel Storage public disk.

### Frontend UI & Store:
- **User Profile Dropdown trên Navbar (`AppHeader.vue`)**:
  - Kích hoạt bằng hover hoặc click vào tên/avatar người dùng.
  - Dropdown chevron xoay mượt mà khi mở/đóng.
  - Tự động đóng khi click ra ngoài (`click outside`) hoặc nhấn phím `Escape`.
  - Hỗ trợ mobile (chạm để mở/đóng, không phụ thuộc hover).
  - Khung header dropdown với background banner BlueToon, avatar tròn (kèm fallback chữ cái đầu viết hoa, chống ảnh vỡ), tên người dùng, email, và huy hiệu vai trò (`Quản trị viên 🛡️`, `Tác giả ✍️`, `Độc giả 📖`).
  - Danh sách menu items đầy đủ:
    1. Hồ sơ cá nhân (`/profile`)
    2. Cập nhật thông tin (`/profile#settings`)
    3. Đổi mật khẩu (`/profile#password`)
    4. Trạng thái Creator tương ứng:
       - Reader chưa đăng ký: Nút CTA nổi bật "Đăng ký Creator ✍️" kèm badge "Mới".
       - Reader đang chờ duyệt: Huy hiệu "Hồ sơ Creator: Chờ duyệt ⏳" hiển thị mã `CA-xxxxxx`.
       - Reader bị từ chối: Huy hiệu "Hồ sơ Creator: Từ chối ⚠️" dẫn đến xem lý do và nộp lại.
       - Đã là Creator: Menu "Kênh sáng tác (Studio) ✍️" dẫn đến `/creator/comics`.
       - Admin: Menu "Trang quản trị (Admin) 🛡️" dẫn đến `/admin/moderation/comics`.
    5. Đăng xuất: Nút đăng xuất kèm trạng thái loading spinner.
- **Trang Quản lý tài khoản (`AccountProfilePage.vue` - `/profile`)**:
  - Điều hướng 3 tabs linh hoạt: Thông tin cá nhân, Đổi mật khẩu, Hồ sơ Tác giả (hỗ trợ hash `#profile`, `#settings`, `#password`, `#creator`).
  - Quản lý Avatar: Chọn ảnh từ máy, xem trước (preview) tức thì, kiểm tra dung lượng <= 2MB và định dạng ảnh, nút Lưu ảnh, nút Xóa ảnh.
  - Form Cập nhật Họ tên và Email: Hiển thị badge trạng thái email ("Đã xác minh ✅" / "Chưa xác minh ⚠️"), nút "Gửi lại email xác minh ✉️".
  - Form Đổi mật khẩu: Mật khẩu hiện tại, Mật khẩu mới, Xác nhận mật khẩu, icon toggle ẩn/hiện mật khẩu (👁️ / 🙈), validation lỗi chi tiết.
  - Thẻ Trạng thái Creator: Kết nối trực tiếp API `getMyApplication()`, hiển thị đầy đủ 4 trạng thái (Chưa đăng ký, Đang chờ duyệt, Bị từ chối kèm lý do BQT, Đã là Tác giả).
  - Nút liên kết "Xem hồ sơ công khai ↗" dẫn đến `/users/:id`.
- **Trang Hồ sơ công khai (`UserProfilePage.vue`)**:
  - Bổ sung nút "Quản lý tài khoản ⚙️" khi người dùng xem profile của chính mình.
- **Service & Store**:
  - `AccountService.ts` quản lý toàn bộ HTTP requests cho profile, avatar, password.
  - `useAuthStore().setUser()` cập nhật reactive user state tức thì, Navbar và toàn ứng dụng cập nhật 0ms không cần reload trang.
  - `useNotificationRealtime.ts` tự động gọi `authStore.fetchUser()` khi nhận thông báo duyệt Creator để cập nhật vai trò ngay lập tức.

---

## 3. Bằng chứng Kiểm thử (Evidence)

### Backend Tests
| STT | Lệnh kiểm thử | Kết quả thực tế | Trạng thái |
|:---:|---|---|:---:|
| 1 | `php artisan test --filter=AccountProfileApiTest` | 16 passed (64 assertions, 1.57s) | **PASS** |
| 2 | `php artisan test --filter=UserProfileApiTest` | 6 passed (59 assertions, 2.57s) | **PASS** |
| 3 | `php artisan test --filter=AuthenticationTest` | 25 passed (146 assertions, 4.86s) | **PASS** |
| 4 | `php artisan test --filter=CreatorManagementApiTest` | 20 passed (90 assertions, 3.62s) | **PASS** |
| 5 | `php artisan test --filter=NotificationApiTest` | 25 passed (225 assertions, 3.93s) | **PASS** |

### Frontend Tests
| STT | Lệnh kiểm thử | Kết quả thực tế | Trạng thái |
|:---:|---|---|:---:|
| 1 | `node tests/unit-account-dropdown.mjs` | 6/6 test suites passed | **PASS** |
| 2 | `node tests/unit-profile.mjs` | 6/6 test suites passed | **PASS** |
| 3 | `node tests/unit-creator-application.mjs` | 8/8 test suites passed | **PASS** |
| 4 | `node tests/unit-notifications.mjs` | 16/16 test suites passed | **PASS** |
| 5 | `npm run type-check` (`vue-tsc -b --noEmit`) | 0 errors | **PASS** |
| 6 | `npm run build` (`vite build`) | Thành công trong 2.14s (271 modules) | **PASS** |

---

## 4. Kết luận
Phân hệ Quản lý tài khoản và User Profile Dropdown đáp ứng 100% tiêu chí kỹ thuật và giao diện đặt ra trong yêu cầu, đảm bảo an toàn phân quyền, không rò rỉ dữ liệu nhạy cảm, giao diện mượt mà và đồng bộ.
