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
     * Note: In COMIC_PURCHASE_ONLY model, individual chapter purchase is deprecated.
     * Direct users to purchase the whole comic instead.
     */
    public function unlock(UnlockChapterRequest $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::findOrFail($id);
        $comic = $chapter->comic ?? \App\Models\Comic::find($chapter->comic_id);

        return response()->json([
            'status' => 'error',
            'message' => 'Hệ thống đã chuyển sang mô hình mua trọn bộ truyện VIP. Vui lòng mua toàn bộ truyện để đọc tất cả các chương!',
            'error_code' => 'CHAPTER_UNLOCK_DEPRECATED',
            'comic_id' => $comic?->id,
            'comic_slug' => $comic?->slug,
            'comic_price' => $comic?->price ?? 0,
        ], 422);
    }
}
