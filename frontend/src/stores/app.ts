import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AppInfo, TechStackItem } from '@/types'

export const useAppStore = defineStore('app', () => {
  const appInfo = ref<AppInfo>({
    name: 'BlueToon',
    tagline: 'Nền tảng đọc truyện tranh trực tuyến tích hợp AI',
    version: '0.1.0',
    environment: import.meta.env.VITE_APP_ENV || 'development',
    apiBaseUrl: import.meta.env.VITE_API_BASE_URL || '/api',
  })

  const techStack = ref<TechStackItem[]>([
    {
      name: 'Vue 3',
      category: 'Frontend Framework',
      version: '3.5.x',
      status: 'Ready',
      notes: 'Composition API với cú pháp <script setup>',
    },
    {
      name: 'TypeScript',
      category: 'Language',
      version: '6.0.x',
      status: 'Ready',
      notes: 'Type safety với vue-tsc',
    },
    {
      name: 'Vite',
      category: 'Build Tool',
      version: '8.3.x',
      status: 'Ready',
      notes: 'Dev server nhanh kèm proxy /api tới backend',
    },
    {
      name: 'Pinia',
      category: 'State Management',
      version: '4.1.x',
      status: 'Ready',
      notes: 'Quản lý trạng thái toàn cục cho SPA',
    },
    {
      name: 'Vue Router',
      category: 'Routing',
      version: '5.4.x',
      status: 'Ready',
      notes: 'SPA routing với HTML5 History mode',
    },
    {
      name: 'Vanilla CSS Tokens',
      category: 'Design System',
      version: 'CSS Custom Properties',
      status: 'Ready',
      notes: 'Hệ thống Dark theme với màu nhấn xanh pastel',
    },
  ])

  return {
    appInfo,
    techStack,
  }
})
