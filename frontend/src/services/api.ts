import type { ApiHealthResponse } from '@/types'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'

export class ApiService {
  private static baseUrl = API_BASE_URL

  static getBaseUrl(): string {
    return this.baseUrl
  }

  static async getHealth(): Promise<ApiHealthResponse> {
    const response = await fetch(`${this.baseUrl}/health`, {
      headers: {
        Accept: 'application/json',
      },
    })

    if (!response.ok) {
      throw new Error(`Health check failed with status: ${response.status}`)
    }

    return response.json()
  }
}
