<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a paginated listing of root comments for a target entity.
     * Publicly accessible by guests and authenticated users.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $type = $request->input('commentable_type');
        $id = $request->input('commentable_id');

        if (! in_array($type, ['post', 'comic', 'chapter'], true) || ! is_numeric($id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vui lòng cung cấp loại đối tượng (post, comic, chapter) và ID hợp lệ.',
            ], 422);
        }

        $target = $this->resolveCommentableTarget($type, (int) $id);
        if (! $target) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nội dung được bình luận không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        $currentUser = $request->user() ?? $request->user('sanctum');

        $query = Comment::query()
            ->where('commentable_type', $type)
            ->where('commentable_id', (int) $id)
            ->whereNull('parent_id')
            ->with([
                'user:id,name,avatar_path,role',
                'replies' => function ($q) use ($currentUser) {
                    $q->orderBy('created_at', 'asc')
                      ->with(['user:id,name,avatar_path,role']);
                    if ($currentUser) {
                        $q->withExists([
                            'likes as is_liked' => function ($likeQ) use ($currentUser) {
                                $likeQ->where('user_id', $currentUser->id);
                            },
                        ]);
                    }
                },
            ]);

        if ($currentUser) {
            $query->withExists([
                'likes as is_liked' => function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id);
                },
            ]);
        }

        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        $targetAuthorId = match ($type) {
            'comic' => $target->user_id,
            'chapter' => $target->comic?->user_id,
            default => null,
        };

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $comments = $query->paginate($perPage);

        // CAI-COMMENT-01: Attach target comic author ID to root comments and replies for context-aware badge
        $comments->getCollection()->each(function (Comment $comment) use ($targetAuthorId) {
            $comment->targetAuthorId = $targetAuthorId;
            if ($comment->relationLoaded('replies')) {
                $comment->replies->each(function (Comment $reply) use ($targetAuthorId) {
                    $reply->targetAuthorId = $targetAuthorId;
                });
            }
        });

        return CommentResource::collection($comments);
    }

    /**
     * Dedicated listing route for posts.
     */
    public function indexForPost(Request $request, int|string $id): AnonymousResourceCollection|JsonResponse
    {
        $request->merge(['commentable_type' => 'post', 'commentable_id' => $id]);
        return $this->index($request);
    }

    /**
     * Dedicated listing route for comics.
     */
    public function indexForComic(Request $request, int|string $id): AnonymousResourceCollection|JsonResponse
    {
        $request->merge(['commentable_type' => 'comic', 'commentable_id' => $id]);
        return $this->index($request);
    }

    /**
     * Dedicated listing route for chapters.
     */
    public function indexForChapter(Request $request, int|string $id): AnonymousResourceCollection|JsonResponse
    {
        $request->merge(['commentable_type' => 'chapter', 'commentable_id' => $id]);
        return $this->index($request);
    }

    /**
     * Store a root comment or a reply to a root comment.
     * Accessible by Reader, Creator, Admin.
     */
    public function store(StoreCommentRequest $request): JsonResponse
    {
        $this->authorize('create', Comment::class);

        $validated = $request->validated();
        $user = $request->user();

        // 1. Determine parent & target entity
        $parentId = null;
        if (! empty($validated['parent_id'])) {
            $parent = Comment::find($validated['parent_id']);
            if (! $parent) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bình luận cha không tồn tại hoặc đã bị xóa.',
                ], 404);
            }

            if ($parent->parent_id !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Không thể trả lời một bình luận cấp 2. Chỉ hỗ trợ tối đa 2 cấp bình luận.',
                ], 422);
            }

            $parentId = $parent->id;
            $targetType = $parent->commentable_type;
            $targetId = $parent->commentable_id;
        } else {
            $targetType = $validated['commentable_type'];
            $targetId = (int) $validated['commentable_id'];
        }

        // 2. Verify target entity exists and is accessible
        $target = $this->resolveCommentableTarget($targetType, $targetId);
        if (! $target) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nội dung được bình luận không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        // 3. Create comment within transaction
        $comment = DB::transaction(function () use ($user, $parentId, $targetType, $targetId, $validated) {
            return Comment::create([
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'commentable_type' => $targetType,
                'commentable_id' => $targetId,
                'content' => $validated['content'],
            ]);
        });

        // 4. Trigger notification after commit
        try {
            app(\App\Services\NotificationService::class)->notifyCommentCreated($comment, $user);
        } catch (\Throwable $e) {
            report($e);
        }

        $comment->load(['user:id,name,avatar_path,role']);

        $targetAuthorId = match ($targetType) {
            'comic' => $target->user_id,
            'chapter' => $target->comic?->user_id,
            default => null,
        };
        $comment->targetAuthorId = $targetAuthorId;

        return (new CommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Dedicated store route for posts.
     */
    public function storeForPost(StoreCommentRequest $request, int|string $id): JsonResponse
    {
        $request->merge(['commentable_type' => 'post', 'commentable_id' => $id]);
        return $this->store($request);
    }

    /**
     * Dedicated store route for comics.
     */
    public function storeForComic(StoreCommentRequest $request, int|string $id): JsonResponse
    {
        $request->merge(['commentable_type' => 'comic', 'commentable_id' => $id]);
        return $this->store($request);
    }

    /**
     * Dedicated store route for chapters.
     */
    public function storeForChapter(StoreCommentRequest $request, int|string $id): JsonResponse
    {
        $request->merge(['commentable_type' => 'chapter', 'commentable_id' => $id]);
        return $this->store($request);
    }

    /**
     * Dedicated reply route to a root comment.
     */
    public function reply(StoreCommentRequest $request, int|string $id): JsonResponse
    {
        $request->merge(['parent_id' => $id]);
        return $this->store($request);
    }

    /**
     * Delete a comment or reply.
     * Author or Admin only.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (! $comment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bình luận không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Bình luận đã được xóa thành công.',
        ], 200);
    }

    /**
     * Toggle like/unlike on a comment.
     */
    public function toggleLike(Request $request, int|string $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (! $comment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bình luận không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $user = $request->user();
        $this->authorize('like', $comment);

        $liked = false;

        try {
            DB::transaction(function () use ($user, $comment, &$liked) {
                $existingLike = Like::where('user_id', $user->id)
                    ->where('likeable_type', 'comment')
                    ->where('likeable_id', $comment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingLike) {
                    $existingLike->delete();
                    $liked = false;
                } else {
                    Like::create([
                        'user_id' => $user->id,
                        'likeable_type' => 'comment',
                        'likeable_id' => $comment->id,
                    ]);
                    $liked = true;
                }
            });
        } catch (QueryException $e) {
            $liked = Like::where('user_id', $user->id)
                ->where('likeable_type', 'comment')
                ->where('likeable_id', $comment->id)
                ->exists();
        }

        $comment->refresh();

        return response()->json([
            'status' => 'success',
            'message' => $liked ? 'Đã thích bình luận.' : 'Đã bỏ thích bình luận.',
            'data' => [
                'id' => $comment->id,
                'liked' => $liked,
                'likes_count' => (int) $comment->likes_count,
            ],
        ], 200);
    }

    /**
     * Explicitly unlike a comment (idempotent).
     */
    public function unlike(Request $request, int|string $id): JsonResponse
    {
        $comment = Comment::find($id);

        if (! $comment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bình luận không tồn tại hoặc đã bị xóa.',
            ], 404);
        }

        $user = $request->user();
        $this->authorize('like', $comment);

        DB::transaction(function () use ($user, $comment) {
            $existingLike = Like::where('user_id', $user->id)
                ->where('likeable_type', 'comment')
                ->where('likeable_id', $comment->id)
                ->lockForUpdate()
                ->first();

            if ($existingLike) {
                $existingLike->delete();
            }
        });

        $comment->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Đã bỏ thích bình luận.',
            'data' => [
                'id' => $comment->id,
                'liked' => false,
                'likes_count' => (int) $comment->likes_count,
            ],
        ], 200);
    }

    /**
     * Validate and retrieve the commentable target entity.
     * Ensures target exists and is publicly accessible.
     */
    private function resolveCommentableTarget(string $type, int|string $id): ?Model
    {
        return match ($type) {
            'post' => Post::find($id),
            'comic' => Comic::where('id', $id)->where('approval_status', 'approved')->first(),
            'chapter' => Chapter::where('id', $id)
                ->where('status', 'published')
                ->with('comic:id,user_id')
                ->whereHas('comic', fn ($q) => $q->where('approval_status', 'approved'))
                ->first(),
            default => null,
        };
    }
}
