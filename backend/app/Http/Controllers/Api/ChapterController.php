<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChapterReaderResource;
use App\Http\Resources\ChapterSummaryResource;
use App\Models\Chapter;
use App\Models\Comic;
use App\Services\Contracts\ChapterUnlockServiceInterface;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChapterController extends Controller
{
    /**
     * Display a listing of published chapters for the specified approved comic.
     */
    public function index(string $slug): JsonResponse|AnonymousResourceCollection
    {
        $comic = Comic::query()
            ->select(['id', 'title', 'slug', 'user_id', 'approval_status'])
            ->where('slug', $slug)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $chapters = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->orderBy('chapter_number', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return ChapterSummaryResource::collection($chapters);
    }

    /**
     * Display the specified published chapter with reading pages and navigation.
     */
    public function show(Request $request, string $slug, string $chapterNumber): JsonResponse|ChapterReaderResource
    {
        // Validate chapter number against DECIMAL(6,2) range and format
        if (! preg_match('/^[0-9]{1,4}(\.[0-9]{1,2})?$/', $chapterNumber)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương không tồn tại hoặc chưa được phát hành.',
            ], 404);
        }

        $canonicalNumber = sprintf('%.2f', (float) $chapterNumber);

        $comic = Comic::query()
            ->select(['id', 'title', 'slug', 'user_id', 'approval_status'])
            ->where('slug', $slug)
            ->where('approval_status', 'approved')
            ->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $chapter = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->where('chapter_number', $canonicalNumber)
            ->with([
                'images' => function ($q) {
                    $q->orderBy('page_number', 'asc')->orderBy('id', 'asc');
                },
            ])
            ->first();

        if (! $chapter) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương không tồn tại hoặc chưa được phát hành.',
            ], 404);
        }

        // Avoid extra query for comic in resource
        $chapter->setRelation('comic', $comic);

        // Fetch adjacent published chapters for reader navigation
        $prevChapter = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->where('chapter_number', '<', $canonicalNumber)
            ->orderBy('chapter_number', 'desc')
            ->orderBy('id', 'desc')
            ->first(['id', 'chapter_number', 'title', 'slug']);

        $nextChapter = Chapter::query()
            ->where('comic_id', $comic->id)
            ->where('status', 'published')
            ->where('chapter_number', '>', $canonicalNumber)
            ->orderBy('chapter_number', 'asc')
            ->orderBy('id', 'asc')
            ->first(['id', 'chapter_number', 'title', 'slug']);

        $navigation = [
            'prev_chapter' => $prevChapter ? [
                'id' => $prevChapter->id,
                'chapter_number' => (string) $prevChapter->chapter_number,
                'title' => $prevChapter->title,
                'slug' => $prevChapter->slug,
            ] : null,
            'next_chapter' => $nextChapter ? [
                'id' => $nextChapter->id,
                'chapter_number' => (string) $nextChapter->chapter_number,
                'title' => $nextChapter->title,
                'slug' => $nextChapter->slug,
            ] : null,
        ];

        // VIP access evaluation
        $vipContext = [
            'is_vip' => (bool) $chapter->is_vip,
            'is_unlocked' => true,
        ];

        if ($chapter->is_vip) {
            $user = $request->user('sanctum') ?? auth('sanctum')->user() ?? $request->user();
            $unlockService = app(ChapterUnlockServiceInterface::class);
            $canAccess = $unlockService->canAccessChapter($user, $chapter);

            if ($canAccess) {
                $vipContext['is_unlocked'] = true;
                if ($user && ($user->role === 'admin' || $comic->user_id === $user->id)) {
                    $vipContext['bypass_reason'] = 'creator_or_admin';
                }
            } else {
                $vipContext['is_unlocked'] = false;
                $chapter->setRelation('images', collect([]));
                if ($user) {
                    $walletService = app(WalletServiceInterface::class);
                    $vipContext['user_balance'] = $walletService->getBalance($user);
                }
            }
        }

        // Increment view count when readable chapter is opened (with 30-min debounce per user/IP)
        if ($vipContext['is_unlocked']) {
            $userId = $request->user('sanctum')?->id ?? $request->user()?->id;
            $ip = $request->ip() ?: '127.0.0.1';
            $identifier = $userId ? "u_{$userId}" : 'ip_'.md5($ip);
            $viewKey = "viewed_chapter_{$chapter->id}_{$identifier}";

            if (\Illuminate\Support\Facades\Cache::add($viewKey, true, now()->addMinutes(30))) {
                Chapter::where('id', $chapter->id)->increment('views_count');
                Comic::where('id', $comic->id)->increment('views_count');
                $chapter->views_count++;
            }
        }

        return new ChapterReaderResource($chapter, $navigation, $vipContext);
    }
}
