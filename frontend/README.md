# BlueToon Frontend (SPA)

Nền tảng giao diện người dùng cho hệ thống đọc truyện tranh trực tuyến BlueToon, được xây dựng dưới dạng Single Page Application (SPA).

## 1. Công nghệ sử dụng

- **Framework**: [Vue 3](https://vuejs.org/) (Composition API, `<script setup>`)
- **Ngôn ngữ**: [TypeScript](https://www.typescriptlang.org/)
- **Build tool**: [Vite](https://vite.dev/)
- **Quản lý state**: [Pinia](https://pinia.vuejs.org/)
- **SPA Routing**: [Vue Router](https://router.vuejs.org/)
- **Styling**: Vanilla CSS với CSS Custom Properties (Design Tokens, Dark Theme)

## 2. Cấu trúc thư mục

```text
frontend/
├── public/                 # Static assets công khai
├── src/
│   ├── assets/             # Hình ảnh, icons nội bộ
│   ├── components/         # Reusable UI components (AppHeader, AppFooter, v.v.)
│   ├── layouts/            # Layout components (DefaultLayout, v.v.)
│   ├── pages/              # Route view pages (HomePage, v.v.)
│   ├── router/             # Cấu hình Vue Router SPA
│   ├── services/           # API service client kết nối backend
│   ├── stores/             # Pinia state stores
│   ├── styles/             # Vanilla CSS design tokens & base styles
│   ├── types/              # TypeScript types & interfaces
│   ├── App.vue             # Root component
│   └── main.ts             # Điểm khởi tạo ứng dụng
├── .env.example            # Mẫu biến môi trường
├── index.html              # HTML entry point
├── package.json            # Dependencies và scripts
├── tsconfig.json           # Cấu hình TypeScript
└── vite.config.ts          # Cấu hình Vite & API dev proxy
```

## 3. Lệnh thực thi

```bash
# Cài đặt dependencies
npm install

# Khởi chạy dev server (mặc định http://localhost:5173)
npm run dev

# Kiểm tra kiểu TypeScript
npm run type-check

# Đóng gói bản production (build)
npm run build

# Xem thử bản build production
npm run preview
```

## 4. Cấu hình biến môi trường và API Proxy

Copy file `.env.example` thành `.env` hoặc `.env.local` để điều chỉnh cấu hình cục bộ:

```env
VITE_API_BASE_URL=/api
VITE_API_TARGET_URL=http://127.0.0.1:8000
```

Trong môi trường development, Vite dev server tự động chuyển tiếp (proxy) các request bắt đầu bằng `/api` và `/sanctum` sang máy chủ backend Laravel được chỉ định bởi `VITE_API_TARGET_URL`.
