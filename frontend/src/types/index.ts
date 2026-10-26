export interface AppInfo {
  name: string
  tagline: string
  version: string
  environment: string
  apiBaseUrl: string
}

export interface TechStackItem {
  name: string
  category: string
  version: string
  status: 'Ready' | 'Configured' | 'Scaffold'
  notes: string
}

export interface ApiHealthResponse {
  status: string
  service?: string
  message?: string
  timestamp: string
}
