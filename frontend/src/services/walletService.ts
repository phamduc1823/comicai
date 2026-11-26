import { httpClient } from './httpClient'
import type {
  WalletData,
  TransactionsResponseData,
  FaucetClaimResult,
  UnlockChapterResult,
  PurchaseComicResult,
  TransactionType,
} from '@/types/wallet'

export interface ApiResponse<T> {
  status: 'success' | 'error'
  message?: string
  data: T
  error_code?: string
}

export class WalletService {
  /**
   * Fetch authenticated user's coin wallet details.
   */
  static async getWallet(): Promise<ApiResponse<WalletData>> {
    const response = await httpClient.get<ApiResponse<WalletData>>('/wallet')
    return response.data
  }

  /**
   * Fetch paginated transaction history ledger.
   */
  static async getTransactions(
    page = 1,
    limit = 15,
    type?: TransactionType | string
  ): Promise<ApiResponse<TransactionsResponseData>> {
    const params: Record<string, string | number> = {
      page,
      per_page: limit,
    }
    if (type) {
      params.type = type
    }
    const response = await httpClient.get<ApiResponse<TransactionsResponseData>>('/wallet/transactions', {
      params,
    })
    return response.data
  }

  /**
   * Claim free demo faucet coins.
   */
  static async claimFaucet(): Promise<ApiResponse<FaucetClaimResult>> {
    const response = await httpClient.post<ApiResponse<FaucetClaimResult>>('/wallet/faucet')
    return response.data
  }

  /**
   * Purchase a VIP comic using coins with idempotency protection.
   */
  static async purchaseComic(
    comicIdOrSlug: number | string,
    idempotencyKey?: string
  ): Promise<ApiResponse<PurchaseComicResult>> {
    const headers: Record<string, string> = {}
    if (idempotencyKey) {
      headers['X-Idempotency-Key'] = idempotencyKey
    }
    const response = await httpClient.post<ApiResponse<PurchaseComicResult>>(
      `/comics/${comicIdOrSlug}/purchase`,
      {},
      { headers }
    )
    return response.data
  }

  /**
   * Check comic VIP purchase status.
   */
  static async getComicPurchaseStatus(
    comicIdOrSlug: number | string
  ): Promise<ApiResponse<{ comic_id: number; is_vip: boolean; price: number; is_purchased: boolean }>> {
    const response = await httpClient.get<ApiResponse<{ comic_id: number; is_vip: boolean; price: number; is_purchased: boolean }>>(
      `/comics/${comicIdOrSlug}/purchase-status`
    )
    return response.data
  }

  /**
   * Unlock a VIP chapter using coins with idempotency protection.
   * @deprecated In COMIC_PURCHASE_ONLY, use purchaseComic instead.
   */
  static async unlockChapter(
    chapterId: number | string,
    idempotencyKey?: string
  ): Promise<ApiResponse<UnlockChapterResult>> {
    const headers: Record<string, string> = {}
    if (idempotencyKey) {
      headers['X-Idempotency-Key'] = idempotencyKey
    }
    const response = await httpClient.post<ApiResponse<UnlockChapterResult>>(
      `/chapters/${chapterId}/unlock`,
      {},
      { headers }
    )
    return response.data
  }
}
