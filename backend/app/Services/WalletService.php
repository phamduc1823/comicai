<?php

namespace App\Services;

use App\Exceptions\FaucetDisabledException;
use App\Exceptions\FaucetLimitExceededException;
use App\Exceptions\RefundExceedsOriginalAmountException;
use App\Models\ChapterUnlock;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class WalletService implements WalletServiceInterface
{
    /**
     * Retrieve or lazily create a wallet for the user, granting welcome bonus if eligible.
     */
    public function getOrCreateWallet(User $user): Wallet
    {
        $wallet = Wallet::where('user_id', $user->id)->first();
        if ($wallet) {
            return $wallet;
        }

        try {
            return DB::transaction(function () use ($user) {
                $existing = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                $hasWelcomeTx = CoinTransaction::where('user_id', $user->id)
                    ->where('type', 'welcome_bonus')
                    ->exists();

                $bonus = $hasWelcomeTx ? 0 : (int) config('vip.welcome_bonus', 100);

                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'balance' => $bonus,
                ]);

                if ($bonus > 0) {
                    CoinTransaction::create([
                        'user_id' => $user->id,
                        'wallet_id' => $wallet->id,
                        'type' => 'welcome_bonus',
                        'amount' => $bonus,
                        'balance_before' => 0,
                        'balance_after' => $bonus,
                        'description' => 'Tặng Coin chào mừng thành viên mới',
                        'idempotency_key' => 'welcome_'.$user->id,
                        'created_at' => now(),
                    ]);
                }

                return $wallet;
            });
        } catch (QueryException $e) {
            $msg = $e->getMessage();
            $isDuplicateWallet = str_contains($msg, 'uk_wallets_user_id')
                || (str_contains($msg, 'wallets') && str_contains($msg, 'user_id') && str_contains($msg, 'UNIQUE'))
                || (str_contains($msg, '1062') && str_contains($msg, 'wallets'));

            if ($isDuplicateWallet) {
                // Re-read wallet created by concurrent winning transaction outside the failed transaction
                $existing = Wallet::where('user_id', $user->id)->first();
                if ($existing) {
                    return $existing;
                }
            }

            // Rethrow original exception if not a duplicate key error on uk_wallets_user_id or if wallet not found
            throw $e;
        }
    }

    /**
     * Get the current coin balance of the user.
     */
    public function getBalance(User $user): int
    {
        $wallet = $this->getOrCreateWallet($user);

        return (int) $wallet->balance;
    }

    /**
     * Grant welcome bonus to a newly registered user (strictly once).
     */
    public function grantWelcomeBonus(User $user): ?CoinTransaction
    {
        $this->getOrCreateWallet($user);

        return CoinTransaction::where('user_id', $user->id)
            ->where('type', 'welcome_bonus')
            ->first();
    }

    /**
     * Claim demo coins from faucet (enforces daily limit and feature flag).
     */
    public function claimFaucet(User $user): array
    {
        if (! config('vip.faucet_enabled', true)) {
            throw new FaucetDisabledException;
        }

        $timezone = config('vip.faucet_reset_timezone', config('app.timezone', 'Asia/Ho_Chi_Minh'));
        $startOfDay = now($timezone)->startOfDay()->utc();
        $dailyLimit = (int) config('vip.faucet_daily_limit', 5);
        $faucetAmount = (int) config('vip.faucet_amount', 100);

        return DB::transaction(function () use ($user, $startOfDay, $dailyLimit, $faucetAmount) {
            $claimsToday = CoinTransaction::where('user_id', $user->id)
                ->where('type', 'faucet')
                ->where('created_at', '>=', $startOfDay)
                ->lockForUpdate()
                ->count();

            if ($claimsToday >= $dailyLimit) {
                throw new FaucetLimitExceededException;
            }

            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $this->getOrCreateWallet($user);
                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + $faucetAmount;

            $wallet->balance = $balanceAfter;
            $wallet->save();

            CoinTransaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => 'faucet',
                'amount' => $faucetAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => 'Nhận Coin thử nghiệm từ faucet',
                'created_at' => now(),
            ]);

            $remainingToday = max(0, $dailyLimit - ($claimsToday + 1));

            return [
                'credited_amount' => $faucetAmount,
                'new_balance' => $balanceAfter,
                'faucet_remaining_today' => $remainingToday,
            ];
        });
    }

    /**
     * Get the remaining faucet claims available for today.
     */
    public function getRemainingFaucetCount(User $user): int
    {
        $timezone = config('vip.faucet_reset_timezone', config('app.timezone', 'Asia/Ho_Chi_Minh'));
        $startOfDay = now($timezone)->startOfDay()->utc();
        $dailyLimit = (int) config('vip.faucet_daily_limit', 5);

        $claimsToday = CoinTransaction::where('user_id', $user->id)
            ->where('type', 'faucet')
            ->where('created_at', '>=', $startOfDay)
            ->count();

        return max(0, $dailyLimit - $claimsToday);
    }

    /**
     * Get paginated transaction history ledger for the user.
     */
    public function getTransactions(User $user, int $perPage = 15, ?string $type = null): LengthAwarePaginator
    {
        $query = CoinTransaction::where('user_id', $user->id)->orderBy('id', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        return $query->paginate($perPage);
    }

    /**
     * Admin manual refund for a chapter unlock transaction with VIP revocation.
     */
    public function refund(int $originalTransactionId, string $reason, ?string $idempotencyKey = null, ?User $admin = null): array
    {
        return DB::transaction(function () use ($originalTransactionId, $reason, $idempotencyKey) {
            $originalTx = CoinTransaction::where('id', $originalTransactionId)
                ->lockForUpdate()
                ->first();

            if (! $originalTx) {
                abort(404, 'Không tìm thấy giao dịch gốc.');
            }

            if ($originalTx->type !== 'chapter_unlock' && $originalTx->type !== 'comic_purchase') {
                throw new RefundExceedsOriginalAmountException('Chỉ có thể hoàn tiền cho giao dịch mở khóa VIP.');
            }

            // Check if already refunded
            $alreadyRefunded = CoinTransaction::where('reference_type', CoinTransaction::class)
                ->where('reference_id', $originalTx->id)
                ->where('type', 'refund')
                ->exists();

            if ($alreadyRefunded) {
                throw new RefundExceedsOriginalAmountException;
            }

            $userId = (int) $originalTx->user_id;
            $refundAmount = (int) $originalTx->amount;

            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + $refundAmount;

            $wallet->balance = $balanceAfter;
            $wallet->save();

            $refundTx = CoinTransaction::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'type' => 'refund',
                'amount' => $refundAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => CoinTransaction::class,
                'reference_id' => $originalTx->id,
                'refund_for_transaction_id' => $originalTx->id,
                'description' => 'Hoàn Coin VIP: '.$reason,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
            ]);

            // Handle comic_purchase revocation
            if ($originalTx->type === 'comic_purchase') {
                $comicPurchase = \App\Models\ComicPurchase::where('transaction_id', $originalTx->id)->first();
                if ($comicPurchase) {
                    $comicPurchase->status = 'revoked';
                    $comicPurchase->revoked_at = now();
                    $comicPurchase->refund_transaction_id = $refundTx->id;
                    $comicPurchase->save();
                }
            }

            $unlock = ChapterUnlock::where('transaction_id', $originalTx->id)->first();
            if (! $unlock && $originalTx->reference_type === Chapter::class) {
                $unlock = ChapterUnlock::where('user_id', $userId)
                    ->where('chapter_id', $originalTx->reference_id)
                    ->first();
            }

            if ($unlock) {
                $unlock->status = 'revoked';
                $unlock->revoked_at = now();
                $unlock->refund_transaction_id = $refundTx->id;
                $unlock->save();
            }

            return [
                'refund_transaction_id' => $refundTx->id,
                'original_transaction_id' => $originalTx->id,
                'refunded_amount' => $refundAmount,
                'user_id' => $userId,
                'chapter_id' => $unlock?->chapter_id,
                'unlock_status' => 'revoked',
            ];
        });
    }
}
