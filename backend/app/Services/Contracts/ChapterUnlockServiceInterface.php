<?php

namespace App\Services\Contracts;

use App\Models\Chapter;
use App\Models\User;

interface ChapterUnlockServiceInterface
{
    /**
     * Check whether a user (or guest) has read access to the specified chapter.
     */
    public function canAccessChapter(?User $user, Chapter $chapter): bool;

    /**
     * Unlock a VIP chapter using coins, strictly validating balance, idempotency, and re-purchase.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function unlockChapter(User $user, Chapter $chapter, ?string $idempotencyKey = null, array $payload = []): array;
}
