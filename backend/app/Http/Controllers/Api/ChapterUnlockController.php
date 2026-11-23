<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnlockChapterRequest;
use App\Models\Chapter;
use App\Services\Contracts\ChapterUnlockServiceInterface;
use Illuminate\Http\JsonResponse;

class ChapterUnlockController extends Controller
{
    public function __construct(
        protected ChapterUnlockServiceInterface $chapterUnlockService
    ) {}

    /**
     * Unlock a VIP chapter using coins.
     */
    public function unlock(UnlockChapterRequest $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::findOrFail($id);
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $result = $this->chapterUnlockService->unlockChapter(
            $request->user(),
            $chapter,
            $idempotencyKey,
            $request->except(['idempotency_key'])
        );

        return response()->json($result, 200);
    }
}
