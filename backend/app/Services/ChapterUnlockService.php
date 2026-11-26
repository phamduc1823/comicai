<?php

namespace App\Services;

use App\Exceptions\ChapterNotVipException;
use App\Exceptions\IdempotencyPayloadMismatchException;
use App\Exceptions\InsufficientCoinsException;
use App\Models\Chapter;
use App\Models\ChapterUnlock;
use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\ChapterUnlockServiceInterface;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ChapterUnlockService implements ChapterUnlockServiceInterface
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Check whether a user (or guest) has read access to the specified chapter.
     */
    public function canAccessChapter(?User $user, Chapter $chapter): bool
    {
        // Delegate access evaluation to ComicPurchaseService
        return app(\App\Services\Contracts\ComicPurchaseServiceInterface::class)->canAccessChapter($user, $chapter);
    }

    /**
     * Unlock a VIP chapter using coins.
     */
    public function unlockChapter(User $user, Chapter $chapter, ?string $idempotencyKey = null, array $payload = []): array
    {
        if ($chapter->status !== 'published') {
            abort(404, 'Chương không tồn tại hoặc chưa được phát hành.');
        }

        if (! $chapter->is_vip) {
            throw new ChapterNotVipException();
        }

        $cleanPayload = $payload;
        unset($cleanPayload['idempotency_key'], $cleanPayload['X-Idempotency-Key']);
        ksort($cleanPayload);
        $payloadHash = $idempotencyKey ? hash('sha256', json_encode($cleanPayload)) : null;

        try {
            return DB::transaction(function () use ($user, $chapter, $idempotencyKey, $payloadHash) {
                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
                if (! $wallet) {
                    $this->walletService->getOrCreateWallet($user);
                    $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                }

                // Step 1: Idempotency Key check
                if ($idempotencyKey) {
                    $existingTx = CoinTransaction::where('user_id', $user->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existingTx) {
                        $recordedHash = $existingTx->payload_hash;
                        if ($recordedHash && $payloadHash && $recordedHash !== $payloadHash) {
                            throw new IdempotencyPayloadMismatchException();
                        }

                        // Replay original purchase receipt without reactivating if refunded
                        $currentUnlock = ChapterUnlock::where('user_id', $user->id)
                            ->where('chapter_id', $chapter->id)
                            ->first();

                        $isUnlocked = $currentUnlock ? ($currentUnlock->status === 'active') : true;

                        return [
                            'status' => 'success',
                            'message' => 'Mở khóa chương thành công.',
                            'data' => [
                                'chapter_id' => $chapter->id,
                                'comic_id' => $chapter->comic_id,
                                'price_paid' => (int) $existingTx->amount,
                                'remaining_balance' => (int) $wallet->balance,
                                'is_unlocked' => $isUnlocked,
                                'already_unlocked' => false,
                                'is_repurchase' => false,
                            ],
                        ];
                    }
                }

                // Step 2: Check active unlock state
                $existingUnlock = ChapterUnlock::where('user_id', $user->id)
                    ->where('chapter_id', $chapter->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingUnlock && $existingUnlock->status === 'active') {
                    return [
                        'status' => 'success',
                        'message' => 'Chương này đã được mở khóa trước đó.',
                        'data' => [
                            'chapter_id' => $chapter->id,
                            'comic_id' => $chapter->comic_id,
                            'price_paid' => 0,
                            'remaining_balance' => (int) $wallet->balance,
                            'is_unlocked' => true,
                            'already_unlocked' => true,
                            'is_repurchase' => false,
                        ],
                    ];
                }

                // Step 3: Check coin balance
                $price = (int) $chapter->price;
                if ((int) $wallet->balance < $price) {
                    throw new InsufficientCoinsException();
                }

                // Step 4: Deduct balance
                $balanceBefore = (int) $wallet->balance;
                $balanceAfter = $balanceBefore - $price;
                $wallet->balance = $balanceAfter;
                $wallet->save();

                // Step 5: Record ledger transaction
                $tx = CoinTransaction::create([
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'type' => 'chapter_unlock',
                    'amount' => $price,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'description' => 'Mở khóa chương VIP ' . $chapter->chapter_number,
                    'reference_type' => Chapter::class,
                    'reference_id' => $chapter->id,
                    'created_at' => now(),
                ]);

                // Step 6: Create or reactivate unlock record
                $isRepurchase = false;
                if ($existingUnlock && $existingUnlock->status === 'revoked') {
                    $existingUnlock->status = 'active';
                    $existingUnlock->revoked_at = null;
                    $existingUnlock->price_paid = $price;
                    $existingUnlock->transaction_id = $tx->id;
                    $existingUnlock->unlock_count = (int) ($existingUnlock->unlock_count ?? 1) + 1;
                    $existingUnlock->save();
                    $isRepurchase = true;
                } else {
                    ChapterUnlock::create([
                        'user_id' => $user->id,
                        'chapter_id' => $chapter->id,
                        'comic_id' => $chapter->comic_id,
                        'transaction_id' => $tx->id,
                        'price_paid' => $price,
                        'status' => 'active',
                        'unlock_count' => 1,
                        'unlocked_at' => now(),
                    ]);
                }

                $message = $isRepurchase
                    ? 'Mua lại chương VIP thành công sau khi hoàn tiền.'
                    : 'Mở khóa chương thành công.';

                return [
                    'status' => 'success',
                    'message' => $message,
                    'data' => [
                        'chapter_id' => $chapter->id,
                        'comic_id' => $chapter->comic_id,
                        'price_paid' => $price,
                        'remaining_balance' => $balanceAfter,
                        'is_unlocked' => true,
                        'already_unlocked' => false,
                        'is_repurchase' => $isRepurchase,
                    ],
                ];
            });
        } catch (QueryException $e) {
            $msg = $e->getMessage();

            // Check if violation is on uk_user_chapter
            if (str_contains($msg, 'uk_user_chapter') ||
                (str_contains($msg, 'chapter_unlocks.user_id') && str_contains($msg, 'chapter_unlocks.chapter_id'))) {
                $existingUnlock = ChapterUnlock::where('user_id', $user->id)
                    ->where('chapter_id', $chapter->id)
                    ->first();

                if ($existingUnlock && $existingUnlock->status === 'active') {
                    $wallet = Wallet::where('user_id', $user->id)->first();
                    return [
                        'status' => 'success',
                        'message' => 'Chương này đã được mở khóa trước đó.',
                        'data' => [
                            'chapter_id' => $chapter->id,
                            'comic_id' => $chapter->comic_id,
                            'price_paid' => 0,
                            'remaining_balance' => $wallet ? (int) $wallet->balance : 0,
                            'is_unlocked' => true,
                            'already_unlocked' => true,
                            'is_repurchase' => false,
                        ],
                    ];
                }
            }

            // Check if violation is on uk_user_idempotency
            if (str_contains($msg, 'uk_user_idempotency') ||
                (str_contains($msg, 'coin_transactions.user_id') && str_contains($msg, 'coin_transactions.idempotency_key'))) {
                $existingTx = CoinTransaction::where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingTx) {
                    $recordedHash = $existingTx->payload_hash;
                    if ($recordedHash && $payloadHash && $recordedHash !== $payloadHash) {
                        throw new IdempotencyPayloadMismatchException();
                    }

                    $wallet = Wallet::where('user_id', $user->id)->first();
                    return [
                        'status' => 'success',
                        'message' => 'Mở khóa chương thành công.',
                        'data' => [
                            'chapter_id' => $chapter->id,
                            'comic_id' => $chapter->comic_id,
                            'price_paid' => (int) $existingTx->amount,
                            'remaining_balance' => $wallet ? (int) $wallet->balance : 0,
                            'is_unlocked' => true,
                            'already_unlocked' => false,
                            'is_repurchase' => false,
                        ],
                    ];
                }
            }

            // Unrecognized duplicate key or query error: rethrow transparently
            throw $e;
        }
    }
}
