<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComicListResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserProfileResource;
use App\Models\Comic;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserProfileController extends Controller
{
    /**
     * Display public profile details and statistics for a given user.
     * Publicly accessible by guests and authenticated users.
     */
    public function show(Request $request, int $id): JsonResponse|UserProfileResource
    {
        $user = User::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Người dùng không tồn tại hoặc tài khoản đã bị vô hiệu hóa.',
            ], 404);
        }

        $postsCount = Post::query()
            ->where('user_id', $user->id)
            ->count();

        $comicsCount = 0;
        if (in_array($user->role, ['creator', 'admin'], true)) {
            $comicsCount = Comic::query()
                ->where('user_id', $user->id)
                ->where('approval_status', 'approved')
                ->count();
        }

        return new UserProfileResource($user, [
            'posts_count' => $postsCount,
            'comics_count' => $comicsCount,
        ]);
    }

    /**
     * Display paginated public approved comics published by a given creator.
     * Strictly filters for approved comics only to protect drafts and pending items.
     */
    public function comics(Request $request, int $id): JsonResponse|AnonymousResourceCollection
    {
        $user = User::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Người dùng không tồn tại hoặc tài khoản đã bị vô hiệu hóa.',
            ], 404);
        }

        // Readers do not author comics
        if (! in_array($user->role, ['creator', 'admin'], true)) {
            return ComicListResource::collection(
                Comic::query()->whereRaw('1 = 0')->paginate((int) $request->input('per_page', 12))
            );
        }

        $perPage = min(max((int) $request->input('per_page', 12), 1), 50);

        $comics = Comic::query()
            ->where('user_id', $user->id)
            ->where('approval_status', 'approved')
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
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return ComicListResource::collection($comics);
    }

    /**
     * Display paginated public community posts authored by a given user.
     */
    public function posts(Request $request, int $id): JsonResponse|AnonymousResourceCollection
    {
        $user = User::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Người dùng không tồn tại hoặc tài khoản đã bị vô hiệu hóa.',
            ], 404);
        }

        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);

        $query = Post::query()
            ->where('user_id', $user->id)
            ->with([
                'user:id,name,avatar_path,role',
                'comic:id,title,slug,cover_path',
            ])
            ->orderBy('created_at', 'desc');

        $currentUser = $request->user('sanctum');
        if ($currentUser) {
            $query->withExists([
                'likes as is_liked' => function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id);
                },
            ]);
        }

        $posts = $query->paginate($perPage);

        return PostResource::collection($posts);
    }
}
