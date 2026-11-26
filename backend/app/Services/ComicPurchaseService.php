<?php

namespace App\Services;

use App\Exceptions\ComicNotVipException;
use App\Exceptions\IdempotencyPayloadMismatchException;
use App\Exceptions\InsufficientCoinsException;
use App\Models\Chapter;
use App\Models\ChapterUnlock;
use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\ComicPurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\ComicPurchaseServiceInterface;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ComicPurchaseService implements ComicPurchaseServiceInterface
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Check whether a user (or guest) has read access to the specified chapter.
     * Order of evaluation:
     * 1. If comic is not VIP (or chapter is explicitly not VIP and comic is free) -> true
     * 2. If guest -> false (if comic is VIP)
     * 3. If admin -> true (system-wide bypass)
     * 4. If author/creator of this comic -> true
     * 5. If user has active ComicPurchase for this comic_id -> true (includes all present & future chapters!)
     * 6. Legacy compatibility: If user has active ChapterUnlock for this chapter_id -> true
     */
    public function canAccessChapter(?User $user, Chapter $chapter): bool
    {
        $comic = $chapter->relationLoaded('comic') && $chapter->comic
            ? $chapter->comic
            : Comic::find($chapter->comic_id);

        $isComicVip = $comic ? (bool) $comic->is_vip : false;
        $isChapterVip = (bool) $chapter->is_vip;

        // If neither the comic nor the chapter is marked VIP, chapter is publicly readable
        if (! $isComicVip && ! $isChapterVip) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($comic && $comic->user_id === $user->id) {
            return true;
        }

        // Check active comic purchase (covers all chapters including future published chapters)
        if ($comic && $this->hasPurchasedComic($user, $comic)) {
            return true;
        }

        // Legacy compatibility check: Did user previously unlock this specific chapter?
        if (ChapterUnlock::where('user_id', $user->id)
            ->where('chapter_id', $chapter->id)
            ->where('status', 'active')
            ->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a user has purchased the specified comic.
     */
    public function hasPurchasedComic(?User $user, Comic|int $comic): bool
    {
        if (! $user) {
            return false;
        }

        $comicId = $comic instanceof Comic ? $comic->id : $comic;

        return ComicPurchase::where('user_id', $user->id)
            ->where('comic_id', $comicId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Purchase a VIP comic using coins with atomic transaction and idempotency.
     */
    public function purchaseComic(User $user, Comic $comic, ?string $idempotencyKey = null, array $payload = []): array
    {
        if ($comic->approval_status !== 'approved') {
            abort(404, 'Truyện không tồn tại hoặc chưa được duyệt phát hành.');
        }

        if (! $comic->is_vip) {
            throw new ComicNotVipException();
        }

        $cleanPayload = $payload;
        unset($cleanPayload['idempotency_key'], $cleanPayload['X-Idempotency-Key']);
        ksort($cleanPayload);
        $payloadHash = $idempotencyKey ? hash('sha256', json_encode($cleanPayload)) : null;

        try {
            return DB::transaction(function () use ($user, $comic, $idempotencyKey, $payloadHash) {
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

                        $currentPurchase = ComicPurchase::where('user_id', $user->id)
                            ->where('comic_id', $comic->id)
                            ->first();

                        $alreadyActive = $currentPurchase && $currentPurchase->status === 'active';

                        return [
                            'comic_id' => $comic->id,
                            'title' => $comic->title,
                            'price_paid' => (int) $existingTx->amount,
                            'remaining_balance' => (int) $wallet->balance,
                            'is_purchased' => $alreadyActive,
                            'already_purchased' => true,
                            'is_repurchase' => false,
                        ];
                    }
                }

                // Step 2: Check current purchase status
                $existingPurchase = ComicPurchase::where('user_id', $user->id)
                    ->where('comic_id', $comic->id)
                    ->first();

                // Case A: User already owns active purchase
                if ($existingPurchase && $existingPurchase->status === 'active') {
                    return [
                        'comic_id' => $comic->id,
                        'title' => $comic->title,
                        'price_paid' => 0,
                        'remaining_balance' => (int) $wallet->balance,
                        'is_purchased' => true,
                        'already_purchased' => true,
                        'is_repurchase' => false,
                    ];
                }

                // Step 3: Check coin balance
                $price = (int) $comic->price;
                if ($wallet->balance < $price) {
                    throw new InsufficientCoinsException();
                }

                $balanceBefore = (int) $wallet->balance;
                $balanceAfter = $balanceBefore - $price;

                // Step 4: Deduct coins from wallet
                $wallet->balance = $balanceAfter;
                $wallet->save();

                $isRepurchase = false;

                // Step 5: Save or update ComicPurchase
                if ($existingPurchase) {
                    // Case C: Repurchase after previous revocation/refund
                    $isRepurchase = true;
                    $existingPurchase->status = 'active';
                    $existingPurchase->price_paid = $price;
                    $existingPurchase->purchase_count += 1;
                    $existingPurchase->purchased_at = now();
                    $existingPurchase->revoked_at = null;
                    $existingPurchase->save();
                    $purchase = $existingPurchase;
                } else {
                    // Case B: Brand-new purchase
                    $purchase = ComicPurchase::create([
                        'user_id' => $user->id,
                        'comic_id' => $comic->id,
                        'status' => 'active',
                        'price_paid' => $price,
                        'purchase_count' => 1,
                        'purchased_at' => now(),
                    ]);
                }

                // Step 6: Record immutable ledger transaction
                $tx = CoinTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'type' => 'comic_purchase',
                    'amount' => $price,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => ComicPurchase::class,
                    'reference_id' => $purchase->id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'description' => 'Mua truyện VIP: ' . $comic->title,
                    'created_at' => now(),
                ]);

                $purchase->transaction_id = $tx->id;
                $purchase->save();

                return [
                    'comic_id' => $comic->id,
                    'title' => $comic->title,
                    'price_paid' => $price,
                    'remaining_balance' => $balanceAfter,
                    'is_purchased' => true,
                    'already_purchased' => false,
                    'is_repurchase' => $isRepurchase,
                ];
            });
        } catch (QueryException $e) {
            // Check for duplicate key constraint race condition (e.g. concurrent requests)
            if ($this->isDuplicateKeyViolation($e)) {
                $existing = ComicPurchase::where('user_id', $user->id)
                    ->where('comic_id', $comic->id)
                    ->first();

                if ($existing && $existing->status === 'active') {
                    $wallet = Wallet::where('user_id', $user->id)->first();
                    return [
                        'comic_id' => $comic->id,
                        'title' => $comic->title,
                        'price_paid' => 0,
                        'remaining_balance' => (int) ($wallet?->balance ?? 0),
                        'is_purchased' => true,
                        'already_purchased' => true,
                        'is_repurchase' => false,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * Determine if a QueryException corresponds to a unique constraint violation.
     */
    protected function isDuplicateKeyViolation(QueryException $e): bool
    {
        $errorCode = $e->errorInfo[1] ?? 0;
        // MySQL 1062, SQLite 19 / 2067
        return $errorCode === 1062 || $errorCode === 19 || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
