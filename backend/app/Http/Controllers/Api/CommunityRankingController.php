<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CommunityRankingController extends Controller
{
    /**
     * Top 5 most liked community posts in the last 7 days.
     * GET /api/community/rankings/top-liked
     */
    public function topLiked(Request $request): JsonResponse
    {
        $sevenDaysAgo = now()->subDays(7);

        // Subquery for likes count in the last 7 days
        $weeklyLikesSubquery = DB::table('likes')
            ->selectRaw('count(*)')
            ->whereColumn('likes.likeable_id', 'posts.id')
            ->whereIn('likes.likeable_type', [Post::class, 'post'])
            ->where('likes.created_at', '>=', $sevenDaysAgo);

        // Subquery for most recent like timestamp (secondary priority tie-break)
        $latestLikeSubquery = DB::table('likes')
            ->selectRaw('max(created_at)')
            ->whereColumn('likes.likeable_id', 'posts.id')
            ->whereIn('likes.likeable_type', [Post::class, 'post'])
            ->where('likes.created_at', '>=', $sevenDaysAgo);

        $posts = Post::query()
            ->select('posts.*')
            ->selectSub($weeklyLikesSubquery, 'weekly_likes_count')
            ->selectSub($latestLikeSubquery, 'latest_like_at')
            ->whereExists(function ($q) use ($sevenDaysAgo) {
                $q->select(DB::raw(1))
                    ->from('likes')
                    ->whereColumn('likes.likeable_id', 'posts.id')
                    ->whereIn('likes.likeable_type', [Post::class, 'post'])
                    ->where('likes.created_at', '>=', $sevenDaysAgo);
            })
            ->with(['user:id,name,avatar_path,role'])
            ->orderByDesc('weekly_likes_count')
            ->orderByDesc('latest_like_at')
            ->orderByDesc('posts.id')
            ->limit(5)
            ->get();

        $data = $posts->map(function (Post $post, int $index) {
            $avatarUrl = null;
            if ($post->user?->avatar_path) {
                $avatarUrl = Storage::disk('public')->url($post->user->avatar_path);
            }

            return [
                'rank' => $index + 1,
                'id' => $post->id,
                'content' => $post->content,
                'snippet' => mb_substr(strip_tags($post->content), 0, 90) . (mb_strlen($post->content) > 90 ? '...' : ''),
                'weekly_count' => (int) ($post->weekly_likes_count ?? 0),
                'weekly_likes_count' => (int) ($post->weekly_likes_count ?? 0),
                'created_at' => $post->created_at?->toISOString(),
                'user' => [
                    'id' => $post->user?->id,
                    'name' => $post->user?->name ?? 'Người dùng',
                    'avatar_path' => $post->user?->avatar_path,
                    'avatar_url' => $avatarUrl,
                    'role' => $post->user?->role ?? 'reader',
                ],
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Top 5 most commented community posts in the last 7 days.
     * GET /api/community/rankings/top-commented
     */
    public function topCommented(Request $request): JsonResponse
    {
        $sevenDaysAgo = now()->subDays(7);

        // Subquery for comments count in the last 7 days
        $weeklyCommentsSubquery = DB::table('comments')
            ->selectRaw('count(*)')
            ->whereColumn('comments.commentable_id', 'posts.id')
            ->whereIn('comments.commentable_type', [Post::class, 'post'])
            ->where('comments.created_at', '>=', $sevenDaysAgo);

        // Subquery for most recent comment timestamp (secondary priority tie-break)
        $latestCommentSubquery = DB::table('comments')
            ->selectRaw('max(created_at)')
            ->whereColumn('comments.commentable_id', 'posts.id')
            ->whereIn('comments.commentable_type', [Post::class, 'post'])
            ->where('comments.created_at', '>=', $sevenDaysAgo);

        $posts = Post::query()
            ->select('posts.*')
            ->selectSub($weeklyCommentsSubquery, 'weekly_comments_count')
            ->selectSub($latestCommentSubquery, 'latest_comment_at')
            ->whereExists(function ($q) use ($sevenDaysAgo) {
                $q->select(DB::raw(1))
                    ->from('comments')
                    ->whereColumn('comments.commentable_id', 'posts.id')
                    ->whereIn('comments.commentable_type', [Post::class, 'post'])
                    ->where('comments.created_at', '>=', $sevenDaysAgo);
            })
            ->with(['user:id,name,avatar_path,role'])
            ->orderByDesc('weekly_comments_count')
            ->orderByDesc('latest_comment_at')
            ->orderByDesc('posts.id')
            ->limit(5)
            ->get();

        $data = $posts->map(function (Post $post, int $index) {
            $avatarUrl = null;
            if ($post->user?->avatar_path) {
                $avatarUrl = Storage::disk('public')->url($post->user->avatar_path);
            }

            return [
                'rank' => $index + 1,
                'id' => $post->id,
                'content' => $post->content,
                'snippet' => mb_substr(strip_tags($post->content), 0, 90) . (mb_strlen($post->content) > 90 ? '...' : ''),
                'weekly_count' => (int) ($post->weekly_comments_count ?? 0),
                'weekly_comments_count' => (int) ($post->weekly_comments_count ?? 0),
                'created_at' => $post->created_at?->toISOString(),
                'user' => [
                    'id' => $post->user?->id,
                    'name' => $post->user?->name ?? 'Người dùng',
                    'avatar_path' => $post->user?->avatar_path,
                    'avatar_url' => $avatarUrl,
                    'role' => $post->user?->role ?? 'reader',
                ],
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
