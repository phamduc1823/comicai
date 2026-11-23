# Hướng Dẫn Vận Hành & Triển Khai Hệ Thống ComicAI (BlueToon)

Tài liệu này cung cấp hướng dẫn toàn diện từ cấu hình môi trường phát triển cục bộ (Bare-metal), triển khai bằng Docker Compose (7 dịch vụ), thiết lập bộ nhớ đệm phân tán Redis, quy trình bảo mật SSL, sao lưu phục hồi dữ liệu, đến checklist kiểm thử tiền triển khai (Staging E2E Auth).

---

## 1. Yêu Cầu Hệ Thống & Kiến Trúc Tổng Thể

### 1.1 Yêu Cầu Phần Cứng (Hardware Requirements)
- **CPU**: Tối thiểu 2 Cores (Khuyến nghị 4 Cores cho production).
- **RAM**: Tối thiểu 4 GB (Khuyến nghị 8 GB khi chạy đồng thời MySQL, Redis, Reverb và Queue Worker).
- **Ổ cứng**: Tối thiểu 20 GB SSD khả dụng (khuyến nghị 50 GB+ phục vụ lưu trữ ảnh truyện và MySQL persistent volume).

### 1.2 Yêu Cầu Phần Mềm (Software Prerequisites)
- **Phương án Container (Khuyến nghị)**:
  - Docker Engine: $\ge 24.0.0$
  - Docker Compose: $\ge \text{v}2.20.0$
- **Phương án Cài đặt Trực tiếp (Bare-Metal Local Development)**:
  - PHP: $\ge 8.3$ (kèm các extensions: `pdo_mysql`, `bcmath`, `gd`, `intl`, `zip`, `exif`, `opcache`, `pcntl`, `redis`).
  - Composer: $\ge 2.7.0$
  - Node.js: $\ge \text{v}20.0.0$ LTS kèm npm $\ge 10.0.0$
  - MySQL: $\ge 8.0$ hoặc $8.4\text{ LTS}$
  - Redis: $\ge 7.0$

### 1.3 Kiến Trúc 7 Dịch Vụ Docker Compose
```text
[Client Web Browser / Mobile]
       │
       ▼ (HTTP / HTTPS / WebSocket)
[nginx] (Port 80 / 443 — Gateway Reverse Proxy)
       ├──> [frontend] (Vue 3 SPA tĩnh phục vụ qua Nginx Alpine)
       ├──> [backend]  (Laravel 11 REST API qua PHP-FPM 8.3)
       ├──> [reverb]   (WebSocket Server qua cổng nội bộ 8080)
       └──> [storage_data] (Volume chia sẻ ảnh truyện /storage/)
              │
              ▼
       [queue-worker] (Xử lý nền Gemini Embeddings & Background Jobs)
              │
              ├──> [mysql] (MySQL 8.4 LTS Persistent Database)
              └──> [redis] (Redis 7 Shared Cache, Sessions, Queue Broker)
```

---

## 2. Triển Khai Bằng Docker Compose (Khuyến Nghị Cho Staging & Production)

### Bước 1: Sao chép file cấu hình môi trường
Từ thư mục gốc dự án:
```bash
cp .env.example .env
```
Mở file `.env` và thiết lập các giá trị an toàn:
- `APP_KEY`: Sinh khóa bằng `php artisan key:generate --show`.
- `DB_ROOT_PASSWORD` và `DB_PASSWORD`: Đặt mật khẩu mạnh (ít nhất 16 ký tự).
- `REDIS_PASSWORD`: Đặt mật khẩu bảo vệ cho Redis.
- `REVERB_APP_SECRET`: Đặt khóa bí mật cho WebSocket Reverb.
- `GEMINI_API_KEY`: Điền API key Google Gemini nếu kích hoạt AI Search (`AI_SEARCH_ENABLED=true`).

### Bước 2: Xây dựng và khởi động 7 container
```bash
docker compose up -d --build
```
Kiểm tra trạng thái khởi động của toàn bộ dịch vụ:
```bash
docker compose ps
```
*Tất cả 7 dịch vụ (`mysql`, `redis`, `backend`, `queue-worker`, `reverb`, `frontend`, `nginx`) phải ở trạng thái `Up` (hoặc `healthy`).*

### Bước 3: Thực thi Migration và Symlink Storage
Chạy migration CSDL lần đầu bên trong container backend:
```bash
docker compose exec backend php artisan migrate --force
```
Tạo liên kết symbolic link cho thư mục media công khai:
```bash
docker compose exec backend php artisan storage:link
```
*(Tùy chọn cho Staging/Demo) Nạp dữ liệu mẫu ban đầu:*
```bash
docker compose exec backend php artisan db:seed --force
```

### Bước 4: Kiểm tra truy cập
- **Giao diện Web**: Mở trình duyệt truy cập `http://localhost` (hoặc domain cấu hình).
- **Kiểm tra REST API**: `curl -I http://localhost/api/comics` (Kỳ vọng: `HTTP/1.1 200 OK`).
- **Kiểm tra Gateway Health**: `curl http://localhost/gateway-healthz` (Kỳ vọng: `gateway-ok`).

---

## 3. Cấu Hình Shared Cache & Multi-Server Readiness (Redis Integration)

Hệ thống ComicAI được thiết kế sẵn sàng cho môi trường mở rộng ngang (Multi-Server / Multiple Node).

### 3.1 Cấu hình Cache Store sang Redis
Trong `.env`:
```ini
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=mat_khau_redis_an_toan
```
### 3.2 Lợi ích của Bộ Nhớ Đệm Chia Sẻ (Shared Cache)
1. **Đồng bộ Cache Invalidation**: Các khóa danh mục tĩnh (`catalog:genres:active`, `catalog:tags:all`, `catalog:banners:active`) khi bị xóa bởi sự kiện Model Event trên bất kỳ backend instance nào sẽ lập tức có hiệu lực trên toàn bộ cụm máy chủ.
2. **AI Query Embedding Reuse**: Khóa `ai:query_embedding:{model}:{dims}:{hash}` được lưu trữ tập trung tại Redis. Khi người dùng ở bất kỳ server node nào tìm kiếm cùng nội dung, vector embedding đều được tái sử dụng mà không gọi lại Gemini API.
3. **Session Persistence**: Phiên đăng nhập SPA Sanctum được lưu trữ tại Redis, cho phép người dùng gửi request qua nhiều instance backend mà không bị ngắt quãng hoặc mất phiên.

---

## 4. Quy Trình Kiểm Thử Môi Trường Staging & E2E Auth Checklist

Để đảm bảo an toàn tuyệt đối cho CSDL chính thức, kiểm thử E2E Browser phải được thực hiện trên môi trường Staging biệt lập:

### 4.1 Chuẩn bị Môi trường Staging Riêng Biệt
1. Tạo file `.env.staging` từ mẫu:
   ```bash
   cp .env.staging.example .env.staging
   ```
2. Khởi tạo CSDL Staging riêng:
   - Đảm bảo `DB_DATABASE=comicai_staging` trong `.env.staging`.
   - Tuyệt đối không trỏ vào database chính thức `comicai`.
3. Chạy migration trên staging database:
   ```bash
   php artisan migrate --env=staging --force
   ```

### 4.2 Thực thi Kịch bản E2E Auth (`tests/e2e/auth.spec.mjs`)
Chạy lệnh kiểm thử E2E với biến môi trường chỉ định target Staging:
```bash
E2E_BASE_URL=http://localhost:8088 E2E_SKIP_SPAWN=true node frontend/tests/e2e/auth.spec.mjs
```
### 4.3 Danh mục kiểm tra bắt buộc (Pre-Deployment Checklist)
- [ ] Trang đăng ký `/register` hiển thị đúng giao diện và validation phía client.
- [ ] Đăng ký tài khoản thành công nhận ngay Welcome Bonus 100 Coin vào ví.
- [ ] Đăng nhập tài khoản mới nhận đúng Sanctum Cookie và CSRF token.
- [ ] Tài khoản bị vô hiệu hóa (`is_active: false`) bị chặn đăng nhập và nhận thông báo lỗi thích hợp.
- [ ] Đăng xuất xóa sạch session và token, điều hướng an toàn về trang chủ.

---

## 5. Chiến Lược Cập Nhật Giảm Thiểu Gián Đoạn (Downtime Minimization Strategy)

> [!NOTE]
> Quy trình dưới đây là chiến lược giảm thiểu tối đa thời gian gián đoạn dịch vụ (downtime minimization). Trong môi trường Docker Compose trên một máy chủ đơn lẻ (single-node), việc tái tạo container hoặc chạy migration vẫn có thể xuất hiện độ trễ kết nối ngắn (vài giây) hoặc ngắt kết nối WebSocket tạm thời. Do đó, cần tiến hành kiểm thử thực tế trên hạ tầng staging/production cụ thể trước khi xác nhận đạt chuẩn không gián đoạn hoàn toàn (zero-downtime).

Khi có bản phát hành mã nguồn mới (release update):

1. **Kéo mã nguồn mới nhất**:
   ```bash
   git pull origin main
   ```
2. **Xây dựng lại images không tắt dịch vụ cũ**:
   ```bash
   docker compose build --no-cache frontend backend queue-worker reverb
   ```
3. **Thực thi Migration CSDL an toàn**:
   ```bash
   docker compose exec backend php artisan migrate --force
   ```
4. **Khởi động lại các container theo cơ chế rolling**:
   ```bash
   docker compose up -d --no-deps backend queue-worker reverb frontend nginx
   ```
5. **Xóa cache ứng dụng và nạp lại cấu hình**:
   ```bash
   docker compose exec backend php artisan optimize
   ```

---

## 6. Quy Trình Sao Lưu & Phục Hồi Dữ Liệu (Backup & Restore)

### 6.1 Sao lưu CSDL MySQL
Tạo bản snapshot CSDL nén gzip:
```bash
docker compose exec mysql mysqldump -u root -p"$DB_ROOT_PASSWORD" --single-transaction --quick --databases comicai | gzip > backup_comicai_$(date +%Y%m%d_%H%M%S).sql.gz
```

### 6.2 Sao lưu Thư mục Upload Media
Sao lưu toàn bộ ảnh bìa, banner và trang truyện người dùng tải lên:
```bash
docker run --rm -v comicai_storage_data:/data -v $(pwd):/backup alpine tar czf /backup/storage_media_$(date +%Y%m%d_%H%M%S).tar.gz -C /data .
```

### 6.3 Phục hồi Dữ Liệu (Restore)
1. **Phục hồi MySQL**:
   ```bash
   gunzip < backup_file.sql.gz | docker compose exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" comicai
   ```
2. **Phục hồi Media Storage**:
   ```bash
   docker run --rm -v comicai_storage_data:/data -v $(pwd):/backup alpine sh -c "rm -rf /data/* && tar xzf /backup/storage_media_file.tar.gz -C /data"
   ```

---

## 7. Cấu Hình Bảo Mật Nâng Cao (SSL/TLS & Production Hardening)

### 7.1 Cấu hình HTTPS với Certbot / Let's Encrypt
Trên server production, cấu hình Nginx Gateway bổ sung block SSL:
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # Cấu hình proxy tương tự port 80
}

# Tự động chuyển hướng HTTP sang HTTPS
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}
```

### 7.2 Các Nguyên Tắc An Toàn Bắt Buộc
- **Không bao giờ bật `APP_DEBUG=true`** trên môi trường Production.
- **Không lưu trữ secrets trong mã nguồn Git**: Toàn bộ khóa bảo mật phải được truyền qua biến môi trường hoặc Docker secrets.
- **Giới hạn dung lượng tải lên (`client_max_body_size 25M`)** để ngăn chặn tấn công từ chối dịch vụ (DoS) qua file upload.
- **Phân quyền người dùng container**: Các file mã nguồn và storage thuộc quyền sở hữu của user `www-data` với phân quyền `775`.

---

*Tài liệu triển khai này là nguồn chuẩn mực phục vụ vận hành dự án ComicAI (BlueToon) và nghiệm thu tốt nghiệp.*
