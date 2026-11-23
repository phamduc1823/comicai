<?php

namespace App\Services\Contracts;

use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WalletServiceInterface
{
    /**
     * Retrieve or lazily create a wallet for the user, granting welcome bonus if eligible.
     */
    public function getOrCreateWallet(User $user): Wallet;

    /**
     * Get the current coin balance of the user.
     */
    public function getBalance(User $user): int;

    /**
     * Grant welcome bonus to a newly registered user (strictly once).
     */
    public function grantWelcomeBonus(User $user): ?CoinTransaction;

    /**
     * Claim demo coins from faucet (enforces daily limit and feature flag).
     *
     * @return array{credited_amount: int, new_balance: int, faucet_remaining_today: int}
     */
    public function claimFaucet(User $user): array;

    /**
     * Get the remaining faucet claims available for today.
     */
    public function getRemainingFaucetCount(User $user): int;

    /**
     * Get paginated transaction history ledger for the user.
     */
    public function getTransactions(User $user, int $perPage = 15, ?string $type = null): LengthAwarePaginator;

    /**
     * Admin manual refund for a chapter unlock transaction with VIP revocation.
     *
     * @return array<string, mixed>
     */
    public function refund(int $originalTransactionId, string $reason, ?string $idempotencyKey = null, ?User $admin = null): array;
}
