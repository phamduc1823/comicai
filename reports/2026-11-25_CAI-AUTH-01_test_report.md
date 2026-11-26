# Báo cáo kiểm thử CAI-AUTH-01 — Điều tra & Khắc phục lỗi Đăng ký (Thiếu bảng Wallets)

- **Task ID**: `CAI-AUTH-01`
- **Ngày thực hiện**: 25/11/2026
- **Trạng thái**: `DONE (chờ duyệt ACCEPTED)`
- **Chi nhánh Git**: `main`

---

## 1. Bối cảnh & Mục tiêu

Khi người dùng thực hiện đăng ký tài khoản mới trên giao diện ComicAI, hệ thống ném ngoại lệ:
```text
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'comicai.wallets' doesn't exist
SQL: select * from `wallets` where `user_id` = 47 limit 1
```
Hậu quả:
1. User mới đã được ghi vào bảng `users` (ID: 47), nhưng giao diện báo lỗi HTTP 500.
2. Khi người dùng bấm đăng ký lại, hệ thống báo lỗi email trùng lặp ("Email đã tồn tại"), để lại bản ghi người dùng mồ côi không có trạng thái hoàn tất.

Mục tiêu task:
- Đảm bảo đăng ký người dùng mới diễn ra thành công, nguyên tử (atomic transaction rollback).
- Không để lộ lỗi SQL 500 khi cơ sở dữ liệu chưa chạy migration ví.
- Đảm bảo tính tương thích ngược và tự động khởi tạo ví lazy khi migration được thực thi.

---

## 2. Phân tích nguyên nhân gốc (Root Cause Analysis)

1. **Migration ví chưa chạy trên MySQL local**:
   - File migration `2026_11_24_000002_create_wallets_and_coin_transactions_tables.php` đã tồn tại trong repository (`CAI-025B`) nhưng đang ở trạng thái `Pending` trên database MySQL `comicai`.
2. **Thiếu Transaction trong AuthController::register**:
   - Trước đây `User::create` được thực thi mà không có `DB::transaction`. Khi gọi tiếp `WalletService::getOrCreateWallet`, việc truy vấn bảng `wallets` thất bại ném `QueryException (42S02)` khiến request bị hủy ngang trong khi bản ghi User đã commit thành công.
3. **WalletController::show thiếu fallback**:
   - Khi frontend Header tải số dư ví (`/api/wallet`), nếu bảng chưa tồn tại, API ném HTTP 500 làm ảnh hưởng trải nghiệm người dùng.

---

## 3. Các thay đổi kỹ thuật

### Backend
1. `backend/app/Http/Controllers/Api/AuthController.php`:
   - Bọc toàn bộ quy trình tạo User và khởi tạo Token trong `DB::transaction`. Nếu có lỗi xảy ra ở bất kỳ bước nào, toàn bộ thao tác được rollback hoàn toàn, không tạo User mồ côi.
   - Bổ sung phòng vệ trong bước khởi tạo ví: Bắt ngoại lệ `QueryException` mã `42S02` (bảng không tồn tại), ghi log cảnh báo bằng `report($e)` và tiếp tục hoàn tất đăng ký tài khoản thành công cho người dùng.
2. `backend/app/Http/Controllers/Api/WalletController.php`:
   - Bổ sung phòng vệ trong `show()`: Nếu bảng `wallets` chưa tồn tại trong môi trường DB hiện tại, trả về phản hồi mặc định an toàn `{ balance: 0, faucet_available_today: 0 }` (HTTP 200) thay vì ném lỗi HTTP 500.
3. `backend/tests/Feature/AuthenticationTest.php`:
   - Bổ sung Test 20: `test_registration_is_atomic_and_rolls_back_user_creation_on_unhandled_failure`.
   - Bổ sung Test 21: `test_registration_succeeds_gracefully_when_wallets_table_is_missing`.

---

## 4. Kết quả kiểm thử

| Lệnh kiểm thử | Kết quả | Ghi chú |
|---|---|---|
| `php artisan test --filter=AuthenticationTest` | **21 passed (135 assertions)** | Bao gồm 2 tests kiểm tra rollback & fallback |
| `php artisan test --filter=WalletAndLedgerTest` | **22 passed (86 assertions)** | 100% PASS |
| `php artisan test --filter=ConversationApiTest` | **45 passed (205 assertions)** | 100% PASS |
| `php artisan test --filter=NotificationApiTest` | **21 passed (194 assertions)** | 100% PASS |
| `php artisan test` | **716 passed (3722 assertions)** | Toàn bộ backend test suite PASS (36.58s) |
| `npm run type-check` (frontend) | **0 errors (PASS)** | Type an toàn |
