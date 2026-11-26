import { httpClient } from './httpClient'
import type {
  MyApplicationResponse,
  SubmitApplicationPayload,
  ApplicationActionResponse,
} from '@/types/creatorApplication'

export class CreatorApplicationService {
  /**
   * Fetch authenticated user's current creator application and profile.
   * GET /api/creator-applications/my
   */
  static async getMyApplication(): Promise<MyApplicationResponse> {
    const response = await httpClient.get<MyApplicationResponse>('/creator-applications/my')
    return response.data
  }

  /**
   * Submit a new creator application (first-time or re-application).
   * POST /api/creator-applications
   */
  static async submitApplication(
    payload: SubmitApplicationPayload
  ): Promise<ApplicationActionResponse> {
    const response = await httpClient.post<ApplicationActionResponse>(
      '/creator-applications',
      payload
    )
    return response.data
  }

  /**
   * Cancel an active pending application.
   * POST /api/creator-applications/{id}/cancel
   */
  static async cancelApplication(id: number | string): Promise<ApplicationActionResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.post<ApplicationActionResponse>(
      `/creator-applications/${encodedId}/cancel`
    )
    return response.data
  }

  /**
   * Resubmit a rejected application with corrections.
   * PUT /api/creator-applications/{id}/resubmit
   */
  static async resubmitApplication(
    id: number | string,
    payload: SubmitApplicationPayload
  ): Promise<ApplicationActionResponse> {
    const encodedId = encodeURIComponent(String(id))
    const response = await httpClient.put<ApplicationActionResponse>(
      `/creator-applications/${encodedId}/resubmit`,
      payload
    )
    return response.data
  }
}
