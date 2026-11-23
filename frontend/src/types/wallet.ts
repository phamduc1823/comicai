/**
 * Types for Coin Wallet & VIP Chapter Unlock (Phase 5).
 */

export type TransactionType = 'welcome_bonus' | 'faucet' | 'chapter_unlock' | 'refund'

export interface WalletData {
  user_id: number
  balance: number
  currency: string
  faucet_available_today: number
  updated_at: string | null
}

export interface CoinTransaction {
  id: number
  wallet_id: number
  user_id: number
  type: TransactionType
  amount: number
  balance_before: number
  balance_after: number
  reference_type: string | null
  reference_id: number | null
  description: string
  created_at: string
}

export interface TransactionsResponseData {
  data: CoinTransaction[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface FaucetClaimResult {
  user_id: number
  faucet_amount: number
  balance_before: number
  balance_after: number
  faucet_claims_today: number
  faucet_remaining_today: number
}

export interface UnlockChapterResult {
  chapter_id: number
  comic_id: number
  price_paid: number
  remaining_balance: number
  is_unlocked: boolean
  already_unlocked: boolean
  is_repurchase: boolean
}
