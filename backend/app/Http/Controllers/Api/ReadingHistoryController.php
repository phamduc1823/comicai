<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReadingHistoryResource;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\ReadingHistory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReadingHistoryController extends Controller
{
    /**
     * Record or update reading history when user opens a published chapter.
     * Guaranteed single history entry per comic for each user (UPSERT).
     * POST /api/comics/{comicId}/reading-history
     */
    public function record(Request $request, int|string $comicId): JsonResponse
    {
        $request->validate([
            'chapter_id' => 'required|integer',
        ], [
            'chapter_id.required' => 'Mã chương đọc là bắt buộc.',
            'chapter_id.integer' => 'Mã chương đọc không hợp lệ.',
        ]);

        $comic = Comic::where('id', $comicId)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được duyệt phát hành.',
            ], 404);
        }

        $chapter = Chapter::where('id', $request->input('chapter_id'))
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->first();

        if (! $chapter) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương không tồn tại, chưa xuất bản hoặc không thuộc bộ truyện này.',
            ], 404);
        }

        $user = $request->user();
        $now = now();

        try {
            $history = ReadingHistory::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'comic_id' => $comic->id,
                ],
                [
                    'chapter_id' => $chapter->id,
                    'updated_at' => $now,
                ]
            );

            // If chapter hasn't changed, ensure updated_at is touched to record the new read time
            if (! $history->wasRecentlyCreated && ! $history->wasChanged('chapter_id')) {
                $history->touch();
            }
        } catch (QueryException $e) {
            // Handle duplicate-key race condition across concurrent requests
            if (str_contains($e->getMessage(), 'uk_reading_user_comic') || str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $history = ReadingHistory::where('user_id', $user->id)
                    ->where('comic_id', $comic->id)
                    ->firstOrFail();
                $history->update([
                    'chapter_id' => $chapter->id,
                    'updated_at' => $now,
                ]);
            } else {
                throw $e;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Đã lưu lịch sử đọc thành công.',
            'data' => [
                'comic_id' => $comic->id,
                'chapter_id' => $chapter->id,
                'chapter_number' => (string) $chapter->chapter_number,
                'updated_at' => $history->updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Retrieve reading history for a specific comic for the authenticated user.
     * GET /api/comics/{comicId}/reading-history
     */
    public function showByComic(Request $request, int|string $comicId): JsonResponse
    {
        $comic = Comic::where('id', $comicId)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được duyệt phát hành.',
            ], 404);
        }

        $user = $request->user();

        $history = ReadingHistory::where('user_id', $user->id)
            ->where('comic_id', $comic->id)
            ->with('chapter')
            ->first();

        if (! $history || ! $history->chapter || $history->chapter->status !== 'published') {
            return response()->json([
                'status' => 'success',
                'data' => null,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'comic_id' => $comic->id,
                'chapter_id' => $history->chapter->id,
                'chapter_number' => (string) $history->chapter->chapter_number,
                'title' => $history->chapter->title,
                'slug' => $history->chapter->slug,
                'updated_at' => $history->updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Retrieve paginated reading history for the authenticated user.
     * GET /api/user/reading-history
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);

        $histories = ReadingHistory::query()
            ->where('user_id', $user->id)
            ->whereHas('comic', function ($q) {
                $q->where('approval_status', 'approved');
            })
            ->whereHas('chapter', function ($q) {
                $q->where('status', 'published');
            })
            ->with([
                'comic' => function ($q) {
                    $q->with(['genres', 'tags', 'user'])
                      ->withCount([
                          'chapters as published_chapters_count' => function ($sq) {
                              $sq->where('status', 'published');
                          },
                      ]);
                },
                'chapter',
            ])
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return ReadingHistoryResource::collection($histories);
    }

    /**
     * Remove reading history entry for a specific comic.
     * DELETE /api/comics/{comicId}/reading-history
     */
    public function destroy(Request $request, int|string $comicId): JsonResponse
    {
        $user = $request->user();

        ReadingHistory::where('user_id', $user->id)
            ->where('comic_id', $comicId)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Đã xóa lịch sử đọc của bộ truyện.',
        ]);
    }

    /**
     * Clear all reading history entries for the authenticated user.
     * DELETE /api/user/reading-history
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->readingHistories()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Đã xóa toàn bộ lịch sử đọc.',
        ]);
    }
}
