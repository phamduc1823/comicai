import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { WalletService } from '@/services/walletService'
import { getErrorMessage } from '@/services/httpClient'
import type {
  WalletData,
  CoinTransaction,
  FaucetClaimResult,
  TransactionsResponseData,
  TransactionType,
} from '@/types/wallet'
import { useAuthStore } from './auth'

export const useWalletStore = defineStore('wallet', () => {
  const authStore = useAuthStore()

  // State
  const wallet = ref<WalletData | null>(null)
  const isLoading = ref(false)
  const isClaimingFaucet = ref(false)
  const walletError = ref<string | null>(null)

  // Transactions State
  const transactions = ref<CoinTransaction[]>([])
  const pagination = ref<Omit<TransactionsResponseData, 'data'> | null>(null)
  const isLoadingTransactions = ref(false)
  const transactionsError = ref<string | null>(null)

  // Getters
  const balance = computed<number>(() => wallet.value?.balance ?? 0)
  const faucetAvailableToday = computed<number>(() => wallet.value?.faucet_available_today ?? 0)
  const canClaimFaucet = computed<boolean>(() => faucetAvailableToday.value > 0)
  const formattedBalance = computed<string>(() => balance.value.toLocaleString('vi-VN'))

  /**
   * Fetch wallet data for current user.
   */
  async function fetchWallet(force = false): Promise<WalletData | null> {
    if (!authStore.isAuthenticated) {
      wallet.value = null
      return null
    }

    if (!force && wallet.value && !walletError.value) {
      return wallet.value
    }

    isLoading.value = true
    walletError.value = null

    try {
      const res = await WalletService.getWallet()
      wallet.value = res.data
      return res.data
    } catch (err: unknown) {
      walletError.value = getErrorMessage(err, 'Không thể tải thông tin ví Coin.')
      return null
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Claim demo faucet coins.
   */
  async function claimFaucet(): Promise<FaucetClaimResult> {
    isClaimingFaucet.value = true
    walletError.value = null

    try {
      const res = await WalletService.claimFaucet()
      if (wallet.value) {
        wallet.value.balance = res.data.balance_after
        wallet.value.faucet_available_today = res.data.faucet_remaining_today
      } else {
        await fetchWallet(true)
      }
      return res.data
    } catch (err: unknown) {
      const message = getErrorMessage(err, 'Không thể nhận Coin thử nghiệm.')
      walletError.value = message
      throw err
    } finally {
      isClaimingFaucet.value = false
    }
  }

  /**
   * Fetch transaction ledger with optional pagination and filtering.
   */
  async function fetchTransactions(
    page = 1,
    limit = 15,
    type?: TransactionType | string
  ): Promise<TransactionsResponseData | null> {
    if (!authStore.isAuthenticated) {
      transactions.value = []
      pagination.value = null
      return null
    }

    isLoadingTransactions.value = true
    transactionsError.value = null

    try {
      const res = await WalletService.getTransactions(page, limit, type)
      transactions.value = res.data.data
      pagination.value = {
        current_page: res.data.current_page,
        last_page: res.data.last_page,
        per_page: res.data.per_page,
        total: res.data.total,
      }
      return res.data
    } catch (err: unknown) {
      transactionsError.value = getErrorMessage(err, 'Không thể tải lịch sử giao dịch.')
      return null
    } finally {
      isLoadingTransactions.value = false
    }
  }

  /**
   * Directly update balance after a successful unlock or operation.
   */
  function updateBalance(newBalance: number, faucetRemaining?: number) {
    if (wallet.value) {
      wallet.value.balance = newBalance
      if (faucetRemaining !== undefined) {
        wallet.value.faucet_available_today = faucetRemaining
      }
    }
  }

  /**
   * Deduct coins locally after an operation.
   */
  function deductCoins(amount: number) {
    if (wallet.value) {
      wallet.value.balance = Math.max(0, wallet.value.balance - amount)
    }
  }

  /**
   * Reset store upon logout.
   */
  function reset() {
    wallet.value = null
    transactions.value = []
    pagination.value = null
    walletError.value = null
    transactionsError.value = null
    isLoading.value = false
    isClaimingFaucet.value = false
    isLoadingTransactions.value = false
  }

  return {
    wallet,
    isLoading,
    isClaimingFaucet,
    walletError,
    transactions,
    pagination,
    isLoadingTransactions,
    transactionsError,
    balance,
    faucetAvailableToday,
    canClaimFaucet,
    formattedBalance,
    fetchWallet,
    claimFaucet,
    fetchTransactions,
    updateBalance,
    deductCoins,
    reset,
  }
})
