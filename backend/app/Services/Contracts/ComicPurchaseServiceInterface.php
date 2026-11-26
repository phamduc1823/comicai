<?php

namespace App\Services\Contracts;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\User;

interface ComicPurchaseServiceInterface
{
    /**
     * Check whether a user (or guest) has access to read the specified chapter within a comic.
     * Evaluates free status, author/admin bypass, comic ownership, or legacy active chapter unlock.
     */
    public function canAccessChapter(?User $user, Chapter $chapter): bool;

    /**
     * Check whether a user has purchased the specified comic.
     */
    public function hasPurchasedComic(?User $user, Comic|int $comic): bool;

    /**
     * Purchase a VIP comic using coins with atomic transaction and idempotency.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function purchaseComic(User $user, Comic $comic, ?string $idempotencyKey = null, array $payload = []): array;
}
