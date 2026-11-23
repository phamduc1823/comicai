# ==============================================================================
# ComicAI Frontend Dockerfile — Multi-Stage Build
# Stage 1: Build Vue 3 + TypeScript SPA bundle
# Stage 2: Serve optimized static assets via Nginx Alpine
# ==============================================================================

# --- Stage 1: Build ---
FROM node:20-alpine AS build-stage

WORKDIR /app

# Cache package dependencies
COPY package.json package-lock.json* ./
RUN npm ci

# Copy application source code
COPY . .

# Build production assets (vue-tsc type-check + vite build)
ARG VITE_API_BASE_URL=""
ARG VITE_REVERB_APP_KEY="comicai-key"
ARG VITE_REVERB_HOST="localhost"
ARG VITE_REVERB_PORT="8080"
ARG VITE_REVERB_SCHEME="http"

ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

RUN npm run build

# --- Stage 2: Production Nginx Runtime ---
FROM nginx:1.27-alpine AS production-stage

# Remove default nginx static files
RUN rm -rf /usr/share/nginx/html/*

# Copy built SPA bundle from build-stage
COPY --from=build-stage /app/dist /usr/share/nginx/html

# Copy custom Nginx configuration
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Security & Healthcheck
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
  CMD wget -q --spider http://localhost/ || exit 1

CMD ["nginx", "-g", "daemon off;"]
