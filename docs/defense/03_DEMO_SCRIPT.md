# Kịch Bản Trình Diễn Demo Hệ Thống (Demo Script)
## Phục Vụ Bảo Vệ Đồ Án Tốt Nghiệp ComicAI

> [!IMPORTANT]
> **Lưu ý nguyên tắc**: Tài liệu này là kịch bản hướng dẫn các bước thao tác thực tế dành cho sinh viên khi trình diễn trước Hội đồng chấm thi. Tài liệu **không tuyên bố** rằng các bước demo này đã được tự động chạy thành công trước đó trên môi trường triển khai thực tế.

---

## TỔNG QUAN KỊCH BẢN DEMO

Kịch bản được thiết kế tuần tự theo luồng nghiệp vụ tự nhiên, chia làm 7 phân đoạn trong thời lượng khoảng 8–10 phút:
1. **Phân đoạn 1**: Đăng ký, đăng nhập và phân quyền người dùng (2 phút).
2. **Phân đoạn 2**: Khám phá danh mục truyện, đọc truyện và quản lý thư viện cá nhân (1.5 phút).
3. **Phân đoạn 3**: Creator Studio và quy trình kiểm duyệt Admin kèm Visual Diff (2 phút).
4. **Phân đoạn 4**: Tương tác cộng đồng và Nhắn tin thời gian thực Realtime WebSocket (1.5 phút).
5. **Phân đoạn 5**: Hệ thống Ví Coin, Sổ cái giao dịch và Mở khóa chương VIP (2 phút).
6. **Phân đoạn 6**: Tìm kiếm thông minh bằng ngôn ngữ tự nhiên AI Semantic Search (1.5 phút).
7. **Phân đoạn 7**: Tổng kết kiến trúc hạ tầng và Deployment Readiness (0.5 phút).

---

## 1. Phân Đoạn 1: Đăng Ký, Đăng Nhập & Phân Quyền Người Dùng

### 1.1 Điều kiện chuẩn bị (Preconditions)
- Backend API server đang chạy (`php artisan serve` hoặc Nginx gateway).
- Frontend dev server đang chạy (`npm run dev` hoặc container frontend).
- Đã có tài khoản Admin sẵn trong hệ thống (ví dụ: `admin@comicai.local` / mật khẩu).
- Đã có tài khoản Creator sẵn (ví dụ: `creator@comicai.local`).

### 1.2 Các bước thao tác (Action Steps)
1. Truy cập trang web tại trang chủ: `http://localhost:5173` (hoặc cổng Nginx cấu hình).
2. Nhấn nút **Đăng ký** trên thanh điều hướng (`AppHeader`).
3. Nhập thông tin tài khoản mới:
   - Tên hiển thị: `Độc Giả Trải Nghiệm`
   - Email: `reader_demo@gmail.com`
   - Mật khẩu: `Password123!`
4. Nhấn nút **Tạo tài khoản**.
5. Sau khi đăng ký thành công, hệ thống tự động đăng nhập và chuyển hướng về trang chủ. Quan sát góc phải thanh điều hướng.

### 1.3 Kết quả mong đợi (Expected Results)
- Tài khoản được khởi tạo thành công với vai trò mặc định là `reader`.
- Phiên đăng nhập được duy trì an toàn qua HttpOnly Cookie của Laravel Sanctum.
- Trên thanh điều hướng xuất hiện ngay số dư ví khởi tạo: **`100 Coin`** (Welcome Bonus được cộng tự động).
- Biểu tượng thông báo chấm đỏ Faucet hiển thị báo hiệu người dùng có thể nhận Coin miễn phí mỗi ngày.

---

## 2. Phân Đoạn 2: Trải Nghiệm Đọc Truyện & Quản Lý Thư Viện

### 2.1 Điều kiện chuẩn bị (Preconditions)
- CSDL đã có dữ liệu mẫu truyện tranh (`DatabaseSeeder`) gồm truyện có bìa, banner, thể loại và danh sách chương.

### 2.2 Các bước thao tác (Action Steps)
1. Tại Trang chủ / Trang Danh sách truyện (`/comics`), trình bày các bộ lọc:
   - Lọc theo Thể loại (Action, Romance, Isekai,...).
   - Lọc theo Nhãn / Trạng thái hoàn thành.
   - Sắp xếp theo Lượt xem cao nhất hoặc Mới cập nhật.
2. Nhấp chọn một bộ truyện bất kỳ để vào **Trang Chi Tiết Truyện** (`/comics/:slug`):
   - Nhấn nút **Theo dõi** (Follow) truyện.
   - Nhấn nút **Đánh dấu** (Bookmark) chương đang đọc.
3. Nhấn vào **Chương 1 (Miễn phí)** để chuyển sang **Trang Đọc Truyện** (`/comics/:slug/chapters/1`):
   - Thao tác cuộn chuột xem các trang ảnh dọc mượt mà.
   - Thử nghiệm thanh điều hướng chương nhanh (Chương trước / Chương sau / Chọn danh sách).
4. Quay trở lại thanh menu, nhấp vào mục **Thư viện** (`/library`):
   - Xem danh sách truyện vừa Theo dõi và Bookmark.
   - Chuyển sang tab **Lịch sử đọc**: Xác nhận Chương 1 vừa đọc đã được tự động ghi nhận vào lịch sử kèm thời gian chính xác.

### 2.3 Kết quả mong đợi (Expected Results)
- Giao diện Dark Theme BlueToon hiển thị trực quan, tải ảnh nhanh, không bị giật lag.
- Các thao tác Follow/Bookmark phản hồi tức thì mà không cần tải lại toàn trang.
- Lịch sử đọc được cập nhật chính xác theo tài khoản độc giả đang đăng nhập.

---

## 3. Phân Đoạn 3: Creator Studio & Kiểm Duyệt Admin Kèm Visual Diff

### 3.1 Điều kiện chuẩn bị (Preconditions)
- Chuẩn bị sẵn 2 tab trình duyệt hoặc 2 cửa sổ ẩn danh:
  - Cửa sổ A: Đăng nhập tài khoản `creator`.
  - Cửa sổ B: Đăng nhập tài khoản `admin`.

### 3.2 Các bước thao tác (Action Steps)
1. **Tại Cửa sổ Creator**:
   - Truy cập **Creator Studio** (`/creator/comics`).
   - Chọn một bộ truyện đang có và nhấn **Quản lý chương**.
   - Nhấn nút **Thêm chương mới**:
     - Số chương: Điền số chương tiếp theo.
     - Tiêu đề chương: *"Chương VIP Demo - Đại Chiến Rồng Lửa"*.
     - Gạt nút bật **Chương VIP**: Nhập giá mở khóa là **`15 Coin`** (trong giới hạn cho phép 5–50 Coin).
     - Tải lên 3 trang ảnh truyện.
   - Nhấn **Gửi phê duyệt**.
2. **Tại Cửa sổ Admin**:
   - Truy cập **Admin Moderation Queue** (`/admin/moderation/chapters`).
   - Tìm chương truyện vừa được Creator gửi lên ở trạng thái `pending`.
   - Nhấn nút **Xem khác biệt (Visual Diff)**:
     - Quan sát màn hình so sánh: Thông tin tiêu đề, số chương, trạng thái VIP và định giá 15 Coin được tô màu nổi bật.
   - Nhấn nút **Phê duyệt (Approve)**.

### 3.3 Kết quả mong đợi (Expected Results)
- Creator không thể can thiệp vào truyện của tác giả khác (chống IDOR).
- Form thêm chương kiểm tra tính hợp lệ của giá VIP (báo lỗi nếu giá < 5 hoặc > 50).
- Admin xem được sự khác biệt trực quan rõ ràng trước khi quyết định duyệt.
- Sau khi Admin nhấn duyệt, chương truyện lập tức chuyển sang trạng thái `approved` và xuất hiện công khai trên trang chi tiết truyện.

---

## 4. Phân Đoạn 4: Tương Tác Cộng Đồng & Nhắn Tin Thời Gian Thực (Realtime Chat)

### 4.1 Điều kiện chuẩn bị (Preconditions)
- Mở song song 2 cửa sổ cạnh nhau trên cùng một màn hình:
  - Cửa sổ trái: Đăng nhập tài khoản Độc giả A (`reader_demo@gmail.com`).
  - Cửa sổ phải: Đăng nhập tài khoản Độc giả B (hoặc Admin).
- Laravel Reverb WebSocket server đang lắng nghe tại cổng `8080`.

### 4.2 Các bước thao tác (Action Steps)
1. **Cộng đồng**:
   - Vào mục **Cộng đồng** (`/community`).
   - Độc giả A đăng bài viết thảo luận về bộ truyện vừa đọc.
   - Độc giả B xem bài viết, nhấn **Thả tim** (Like) và viết bình luận phản hồi.
2. **Nhắn tin Thời Gian Thực (Realtime Chat)**:
   - Cả 2 tài khoản cùng mở mục **Tin nhắn** (`/chat`).
   - Độc giả A chọn hội thoại với Độc giả B.
   - Độc giả A gõ tin nhắn: *"Chào bạn, bạn đã đọc chương mới nhất chưa?"* và nhấn Enter.
   - Quan sát ngay lập tức màn hình của Độc giả B bên phải.
   - Độc giả B mở xem tin nhắn và gõ trả lời: *"Mình vừa đọc xong, gay cấn lắm!"*.

### 4.3 Kết quả mong đợi (Expected Results)
- Tin nhắn xuất hiện trên màn hình Độc giả B **ngay tức thì trong vòng dưới 100ms** mà Độc giả B không cần nhấn F5 tải lại trang.
- Số lượng tin nhắn chưa đọc (Unread Badge) trên thanh tiêu đề `AppHeader` và Sidebar tự động tăng lên khi có tin mới, và tự động xóa khi mở xem hội thoại.
- Khi Độc giả B xem tin nhắn, phía Độc giả A xuất hiện dấu tích xanh đôi (✓✓) báo hiệu đã đọc (Read Receipt).

---

## 5. Phân Đoạn 5: Hệ Thống Ví Coin, Sổ Cái & Mở Khóa VIP Paywall

### 5.1 Điều kiện chuẩn bị (Preconditions)
- Đăng nhập bằng tài khoản `reader_demo@gmail.com`.
- Chương truyện VIP tạo ở Phân đoạn 3 đã được duyệt và có giá 15 Coin.

### 5.2 Các bước thao tác (Action Steps)
1. **Quản lý Ví Coin & Faucet**:
   - Nhấn vào số dư Coin trên `AppHeader` để vào trang **Ví của tôi** (`/wallet`).
   - Xem số dư hiện tại (100 Coin).
   - Xem bảng **Lịch sử giao dịch**: Đã có 1 dòng ghi nhận Welcome Bonus `+100 Coin` loại `welcome_bonus`.
   - Thử nghiệm nhận Coin miễn phí: Nhấn nút **Nhận 100 Coin miễn phí (Faucet)**.
   - Quan sát số dư cập nhật thành `200 Coin` và số lần nhận còn lại giảm từ 5 xuống 4 lần.
2. **Mở khóa Chương VIP**:
   - Truy cập vào bộ truyện có Chương VIP vừa được duyệt ở Phân đoạn 3.
   - Nhấn vào đọc Chương VIP:
     - Giao diện đọc xuất hiện **Paywall**: Toàn bộ nội dung trang truyện bị che mờ, thông báo: *"Chương này là nội dung VIP. Bạn cần mở khóa bằng 15 Coin để tiếp tục đọc"*.
     - Mở tab Network (F12) để chứng minh: API `GET /api/comics/{slug}/chapters/{num}` chỉ trả về `images: []` rỗng, hoàn toàn không để lộ link ảnh gốc (Zero-Leakage).
   - Nhấn nút **Mở khóa chương (15 Coin)**:
     - Hộp thoại xác nhận `VipUnlockModal` hiển thị thông tin số dư hiện tại, số Coin bị trừ và số dư còn lại sau khi mở.
     - Nhấn nút **Xác nhận mở khóa**.
3. **Kiểm tra sau khi mở khóa**:
   - Hộp thoại đóng lại, Paywall biến mất ngay lập tức, toàn bộ trang ảnh của chương truyện hiển thị sắc nét để độc giả đọc mà không cần reload trang.
   - Quay lại trang **Ví của tôi** (`/wallet`):
     - Số dư giảm đúng 15 Coin.
     - Sổ cái xuất hiện dòng giao dịch mới: loại `chapter_unlock`, số tiền `-15 Coin`, mã chương tương ứng.
4. **Kiểm tra tính năng Admin Hoàn tiền (Refund) & Thu hồi (Revocation)**:
   - Admin vào trang quản trị giao dịch VIP, nhấn **Hoàn tiền** giao dịch 15 Coin vừa thực hiện.
   - Quay lại màn hình Độc giả: Số dư được hoàn lại đúng 15 Coin, và khi vào lại chương VIP, màn hình Paywall lập tức xuất hiện trở lại (thu hồi quyền đọc an toàn).

### 5.3 Kết quả mong đợi (Expected Results)
- Mở khóa tức thì, mượt mà (Optimistic UI update kết hợp phản hồi từ backend).
- Cơ chế `X-Idempotency-Key` bảo vệ không bao giờ trừ trùng tiền.
- Bảng sổ cái giao dịch `coin_transactions` lưu vết đầy đủ, không thể chỉnh sửa hay tẩy xóa.

---

## 6. Phân Đoạn 6: Tìm Kiếm Ngôn Ngữ Tự Nhiên AI Semantic Search

### 6.1 Điều kiện chuẩn bị (Preconditions)
- Cờ cấu hình `config('ai.enabled') = true` và `GEMINI_API_KEY` đã được thiết lập hợp lệ trong môi trường chạy demo.
- Các truyện trong database đã được sinh vector embedding qua lệnh `php artisan comicai:generate-embeddings`.

### 6.2 Các bước thao tác (Action Steps)
1. Truy cập trang Tìm kiếm (`/comics`).
2. Gạt công tắc chuyển sang chế độ **Tìm kiếm AI (Semantic Search)**.
3. Nhập một câu miêu tả cốt truyện bằng ngôn ngữ tự nhiên không chứa tên truyện, ví dụ:
   > *"Tìm truyện về nhân vật chính bị phản bội, sau đó trùng sinh quay về quá khứ để trả thù và bảo vệ gia đình"*
   hoặc:
   > *"Truyện thể loại tu tiên hài hước nhân vật chính thích giấu nghề chỉ muốn sống yên bình"*
4. Nhấn Enter hoặc nút **Tìm kiếm**.
5. Quan sát danh sách kết quả hiển thị:
   - Xem độ phù hợp ngữ nghĩa.
   - Xem các huy hiệu chỉ báo: `Semantic Match` hoặc `Hybrid Match`.
   - Xem tỷ lệ phù hợp: *"Điểm phù hợp 88%"*.
6. Nhấp vào truyện được xếp hạng cao nhất để kiểm tra nội dung mô tả của truyện, chứng minh truyện thực sự khớp với nội dung người dùng vừa miêu tả.

### 6.3 Kết quả mong đợi (Expected Results)
- Hệ thống tìm ra đúng tác phẩm phù hợp về mặt ý nghĩa ngữ cảnh dù người dùng không nhập đúng bất kỳ từ khóa tiêu đề nào.
- Nhãn hiển thị trung tính, giao diện trực quan, phản hồi trong khoảng 1–2 giây.

---

## 7. Phân Đoạn 7: Tổng Kết Kiến Trúc & Sẵn Sàng Triển Khai (Deployment Readiness)

### 7.1 Các bước thao tác (Action Steps)
1. Mở mã nguồn dự án trên IDE (VS Code).
2. Mở tệp [docker-compose.yml](file:///d:/Study/UTC/ky4/doan/comicai/docker-compose.yml):
   - Giới thiệu ngắn gọn cụm 7 dịch vụ được liên kết qua network bridge: `mysql`, `redis`, `backend`, `queue-worker`, `reverb`, `frontend`, và `nginx`.
3. Mở tệp [docker/nginx/default.conf](file:///d:/Study/UTC/ky4/doan/comicai/docker/nginx/default.conf):
   - Trình bày kiến trúc cổng ngõ Gateway định tuyến thống nhất SPA, API và WebSocket trên cùng một cổng.
4. Mở tài liệu [docs/DEPLOYMENT_GUIDE.md](file:///d:/Study/UTC/ky4/doan/comicai/docs/DEPLOYMENT_GUIDE.md):
   - Nêu tóm tắt quy trình staging, chiến lược giảm thiểu thời gian gián đoạn (downtime minimization), và phương án sao lưu dự phòng CSDL.

### 7.2 Kết quả mong đợi (Expected Results)
- Hội đồng thấy rõ tính hoàn chỉnh, chuyên nghiệp và sự chuẩn bị kỹ lưỡng từ tầng mã nguồn, cơ sở dữ liệu đến kiến trúc hạ tầng và tài liệu vận hành.

---
*Kịch bản demo này cung cấp đầy đủ từng bước thao tác, dữ liệu đầu vào và kết quả mong đợi giúp sinh viên tự tin bảo vệ đồ án tốt nghiệp.*
