<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComicListResource;
use App\Models\Bookmark;
use App\Models\Comic;
use App\Models\Follow;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserLibraryController extends Controller
{
    /**
     * Toggle follow/unfollow for an approved comic.
     * Atomic concurrency safe with DB transactions and unique constraints.
     */
    public function toggleFollow(Request $request, int|string $id): JsonResponse
    {
        $comic = Comic::where('id', $id)->where('approval_status', 'approved')->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $user = $request->user();

        $result = DB::transaction(function () use ($user, $comic) {
            $existing = Follow::where('user_id', $user->id)
                ->where('comic_id', $comic->id)
                ->first();

            if ($existing) {
                $deleted = Follow::where('id', $existing->id)->delete();
                if ($deleted > 0) {
                    Comic::where('id', $comic->id)
                        ->where('follows_count', '>', 0)
                        ->decrement('follows_count', 1);
                }
                $isFollowed = false;
            } else {
                try {
                    $inserted = DB::table('follows')->insertOrIgnore([
                        'user_id' => $user->id,
                        'comic_id' => $comic->id,
                        'created_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    $inserted = 0;
                }

                if ($inserted > 0) {
                    Comic::where('id', $comic->id)->increment('follows_count', 1);
                    $isFollowed = true;
                } else {
                    $isFollowed = true;
                }
            }

            $currentCount = (int) Comic::where('id', $comic->id)->value('follows_count');

            return [
                'is_followed' => $isFollowed,
                'follows_count' => max(0, $currentCount),
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => $result['is_followed'] ? 'Đã theo dõi truyện thành công.' : 'Đã bỏ theo dõi truyện thành công.',
            'data' => [
                'comic_id' => (int) $comic->id,
                'is_followed' => $result['is_followed'],
                'follows_count' => $result['follows_count'],
            ],
        ], 200);
    }

    /**
     * Get paginated list of comics followed by the authenticated user.
     * Strictly scoped to the authenticated user and approved comics.
     */
    public function follows(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = min(max($request->integer('per_page', 15), 1), 50);

        $comics = $user->followedComics()
            ->where('comics.approval_status', 'approved')
            ->orderByPivot('created_at', 'desc')
            ->withCount([
                'chapters as published_chapters_count' => function ($q) {
                    $q->where('status', 'published');
                },
            ])
            ->with([
                'genres:id,name,slug,description',
                'tags:id,name,slug,description',
                'user:id,name,avatar_path',
            ])
            ->paginate($perPage);

        return ComicListResource::collection($comics);
    }

    /**
     * Toggle bookmark/unbookmark for an approved comic.
     * Atomic concurrency safe with DB transactions and unique constraints.
     */
    public function toggleBookmark(Request $request, int|string $id): JsonResponse
    {
        $comic = Comic::where('id', $id)->where('approval_status', 'approved')->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $user = $request->user();

        $result = DB::transaction(function () use ($user, $comic) {
            $existing = Bookmark::where('user_id', $user->id)
                ->where('comic_id', $comic->id)
                ->first();

            if ($existing) {
                Bookmark::where('id', $existing->id)->delete();
                $isBookmarked = false;
            } else {
                try {
                    DB::table('bookmarks')->insertOrIgnore([
                        'user_id' => $user->id,
                        'comic_id' => $comic->id,
                        'created_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    // Ignore duplicate
                }
                $isBookmarked = true;
            }

            return [
                'is_bookmarked' => $isBookmarked,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => $result['is_bookmarked'] ? 'Đã lưu truyện vào tủ sách.' : 'Đã gỡ truyện khỏi tủ sách.',
            'data' => [
                'comic_id' => (int) $comic->id,
                'is_bookmarked' => $result['is_bookmarked'],
            ],
        ], 200);
    }

    /**
     * Get paginated list of comics bookmarked by the authenticated user.
     * Strictly scoped to the authenticated user and approved comics.
     */
    public function bookmarks(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = min(max($request->integer('per_page', 15), 1), 50);

        $comics = $user->bookmarkedComics()
            ->where('comics.approval_status', 'approved')
            ->orderByPivot('created_at', 'desc')
            ->withCount([
                'chapters as published_chapters_count' => function ($q) {
                    $q->where('status', 'published');
                },
            ])
            ->with([
                'genres:id,name,slug,description',
                'tags:id,name,slug,description',
                'user:id,name,avatar_path',
            ])
            ->paginate($perPage);

        return ComicListResource::collection($comics);
    }
}
