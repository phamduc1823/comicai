<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Like;
use App\Models\Post;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a paginated listing of community posts.
     * Publicly accessible by guests and authenticated users.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Post::query()->with([
            'user:id,name,avatar_path,role',
            'comic:id,title,slug,cover_path',
        ]);

        // Optional filter by comic
        if ($request->filled('comic_id')) {
            $query->where('comic_id', $request->integer('comic_id'));
        }

        // Optional filter by author
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        // Check liked state if user is authenticated
        $currentUser = $request->user('sanctum');
        if ($currentUser) {
            $query->withExists([
                'likes as is_liked' => function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id);
                },
            ]);
        }

        // Sort posts (latest or popular)
        $sort = $request->input('sort', 'latest');
        match ($sort) {
            'popular' => $query->orderBy('likes_count', 'desc')
                               ->orderBy('created_at', 'desc')
                               ->orderBy('id', 'desc'),
            default => $query->orderBy('created_at', 'desc')
                             ->orderBy('id', 'desc'),
        };

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $posts = $query->paginate($perPage);

        return PostResource::collection($posts);
    }

    /**
     * Display the specified community post.
     * Publicly accessible by guests and authenticated users.
     */
    public function show(Request $request, int|string $id): JsonResponse|PostResource
    {
        $query = Post::query()->with([
            'user:id,name,avatar_path,role',
            'comic:id,title,slug,cover_path',
        ]);

        $currentUser = $request->user('sanctum');
        if ($currentUser) {
            $query->withExists([
                'likes as is_liked' => function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id);
                },
            ]);
        }

        $post = $query->find($id);

        if (! $post) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bài đăng không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        return new PostResource($post);
    }

    /**
     * Store a newly created community post with optional image and comic reference.
     * Accessible by all active authenticated users (reader, creator, admin) via PostPolicy.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $this->authorize('create', Post::class);

        $validated = $request->validated();
        $user = $request->user();

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $extension = $file->guessExtension() ?: 'webp';
            $imagePath = $file->storeAs('posts', Str::random(40) . '.' . $extension, 'public');
        }

        try {
            $post = DB::transaction(function () use ($validated, $user, $imagePath) {
                return Post::create([
                    'user_id' => $user->id,
                    'comic_id' => $validated['comic_id'] ?? null,
                    'content' => $validated['content'],
                    'image_path' => $imagePath,
                    'likes_count' => 0,
                    'comments_count' => 0,
                ]);
            });
        } catch (\Throwable $e) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $e;
        }

        $post->load([
            'user:id,name,avatar_path,role',
            'comic:id,title,slug,cover_path',
        ]);

        return (new PostResource($post))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the specified post.
     * Strictly author-only.
     */
    public function update(UpdatePostRequest $request, int|string $id): JsonResponse
    {
        $post = Post::find($id);

        if (! $post) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bài đăng không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $this->authorize('update', $post);

        $validated = $request->validated();
        $oldImagePath = $post->image_path;
        $newImagePath = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $extension = $file->guessExtension() ?: 'webp';
            $newImagePath = $file->storeAs('posts', Str::random(40) . '.' . $extension, 'public');
        }

        try {
            DB::transaction(function () use ($post, $validated, $newImagePath, $request) {
                if (isset($validated['content'])) {
                    $post->content = $validated['content'];
                }
                if (array_key_exists('comic_id', $validated)) {
                    $post->comic_id = $validated['comic_id'];
                }
                if ($newImagePath !== null) {
                    $post->image_path = $newImagePath;
                } elseif ($request->boolean('remove_image')) {
                    $post->image_path = null;
                }

                $post->save();
            });
        } catch (\Throwable $e) {
            if ($newImagePath) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $e;
        }

        // Clean up superseded old image file
        if (
            $oldImagePath &&
            ($newImagePath !== null || $request->boolean('remove_image')) &&
            $oldImagePath !== $post->image_path &&
            str_starts_with($oldImagePath, 'posts/') &&
            Storage::disk('public')->exists($oldImagePath)
        ) {
            Storage::disk('public')->delete($oldImagePath);
        }

        $post->load([
            'user:id,name,avatar_path,role',
            'comic:id,title,slug,cover_path',
        ]);

        return (new PostResource($post))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Delete the specified post.
     * Author or Admin only.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $post = Post::find($id);

        if (! $post) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bài đăng không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $this->authorize('delete', $post);

        $post->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Bài đăng đã được xóa thành công.',
        ], 200);
    }

    /**
     * Toggle like/unlike on a community post.
     * Accessible by any authenticated and active user.
     */
    public function toggleLike(Request $request, int|string $id): JsonResponse
    {
        $post = Post::find($id);

        if (! $post) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bài đăng không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $user = $request->user();
        $this->authorize('like', $post);

        $liked = false;
        $createdNewLike = false;

        try {
            DB::transaction(function () use ($user, $post, &$liked, &$createdNewLike) {
                $existingLike = Like::where('user_id', $user->id)
                    ->where('likeable_type', 'post')
                    ->where('likeable_id', $post->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingLike) {
                    $existingLike->delete();
                    $liked = false;
                    $createdNewLike = false;
                } else {
                    Like::create([
                        'user_id' => $user->id,
                        'likeable_type' => 'post',
                        'likeable_id' => $post->id,
                    ]);
                    $liked = true;
                    $createdNewLike = true;
                }
            });
        } catch (QueryException $e) {
            // Gracefully handle duplicate race conditions
            $createdNewLike = false;
            $liked = Like::where('user_id', $user->id)
                ->where('likeable_type', 'post')
                ->where('likeable_id', $post->id)
                ->exists();
        }

        if ($createdNewLike) {
            try {
                app(NotificationService::class)->notifyPostLiked($post, $user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $post->refresh();

        return response()->json([
            'status' => 'success',
            'message' => $liked ? 'Đã thích bài đăng.' : 'Đã bỏ thích bài đăng.',
            'data' => [
                'id' => $post->id,
                'liked' => $liked,
                'likes_count' => (int) $post->likes_count,
            ],
        ], 200);
    }

    /**
     * Explicitly unlike a community post (idempotent).
     */
    public function unlike(Request $request, int|string $id): JsonResponse
    {
        $post = Post::find($id);

        if (! $post) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bài đăng không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $user = $request->user();
        $this->authorize('like', $post);

        DB::transaction(function () use ($user, $post) {
            $existingLike = Like::where('user_id', $user->id)
                ->where('likeable_type', 'post')
                ->where('likeable_id', $post->id)
                ->lockForUpdate()
                ->first();

            if ($existingLike) {
                $existingLike->delete();
            }
        });

        $post->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Đã bỏ thích bài đăng.',
            'data' => [
                'id' => $post->id,
                'liked' => false,
                'likes_count' => (int) $post->likes_count,
            ],
        ], 200);
    }
}
