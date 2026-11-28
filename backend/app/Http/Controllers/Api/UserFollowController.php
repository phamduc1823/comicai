<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UserFollowController extends Controller
{
    /**
     * Follow a user (one-way relationship).
     * POST /api/users/{id}/follow
     */
    public function follow(Request $request, int $id): JsonResponse
    {
        $authUser = $request->user();

        if ($authUser->id === $id) {
            return response()->json([
                'message' => 'Bạn không thể tự theo dõi chính mình.',
            ], 422);
        }

        $targetUser = User::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        if (! $targetUser) {
            return response()->json([
                'message' => 'Người dùng không tồn tại hoặc tài khoản đã bị vô hiệu hóa.',
            ], 404);
        }

        UserFollow::firstOrCreate([
            'follower_id' => $authUser->id,
            'following_id' => $targetUser->id,
        ]);

        return response()->json([
            'message' => 'Đã theo dõi ' . $targetUser->name . '.',
            'is_following' => true,
            'following_id' => $targetUser->id,
        ]);
    }

    /**
     * Unfollow a user.
     * DELETE /api/users/{id}/follow
     */
    public function unfollow(Request $request, int $id): JsonResponse
    {
        $authUser = $request->user();

        UserFollow::where('follower_id', $authUser->id)
            ->where('following_id', $id)
            ->delete();

        return response()->json([
            'message' => 'Đã bỏ theo dõi người dùng.',
            'is_following' => false,
            'following_id' => $id,
        ]);
    }

    /**
     * Get list of users the authenticated user is currently following.
     * GET /api/user/following
     */
    public function following(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $limit = min(max($request->integer('limit', 20), 1), 100);

        $followingUsers = $authUser->followingUsers()
            ->select('users.id', 'users.name', 'users.avatar_path', 'users.role')
            ->withCount('posts')
            ->orderByPivot('created_at', 'desc')
            ->limit($limit)
            ->get();

        $data = $followingUsers->map(function (User $user) {
            $avatarUrl = null;
            if ($user->avatar_path) {
                $avatarUrl = Storage::disk('public')->url($user->avatar_path);
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_path' => $user->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => $user->role,
                'posts_count' => (int) ($user->posts_count ?? 0),
                'is_following' => true,
            ];
        });

        $total = UserFollow::where('follower_id', $authUser->id)->count();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Get list of users following the authenticated user.
     * GET /api/user/followers
     */
    public function followers(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $limit = min(max($request->integer('limit', 20), 1), 100);

        $followerUsers = $authUser->followerUsers()
            ->select('users.id', 'users.name', 'users.avatar_path', 'users.role')
            ->withCount('posts')
            ->orderByPivot('created_at', 'desc')
            ->limit($limit)
            ->get();

        $followedIds = UserFollow::where('follower_id', $authUser->id)
            ->pluck('following_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $data = $followerUsers->map(function (User $user) use ($followedIds) {
            $avatarUrl = null;
            if ($user->avatar_path) {
                $avatarUrl = Storage::disk('public')->url($user->avatar_path);
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_path' => $user->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => $user->role,
                'posts_count' => (int) ($user->posts_count ?? 0),
                'is_following' => in_array((int) $user->id, $followedIds, true),
            ];
        });

        $total = UserFollow::where('following_id', $authUser->id)->count();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Get suggested users to follow (max 5 actual active accounts).
     * GET /api/community/suggested-users
     */
    public function suggestedUsers(Request $request): JsonResponse
    {
        $currentUser = $request->user('sanctum');

        $query = User::query()
            ->where('is_active', true)
            ->select('users.id', 'users.name', 'users.avatar_path', 'users.role');

        if ($currentUser) {
            $followedIds = UserFollow::where('follower_id', $currentUser->id)->pluck('following_id')->toArray();
            $query->where('id', '!=', $currentUser->id);
            if (! empty($followedIds)) {
                $query->whereNotIn('id', $followedIds);
            }
        }

        // Prioritize creators and users with recent posts in last 30 days
        $thirtyDaysAgo = now()->subDays(30);

        $suggested = $query->withCount([
                'posts as recent_posts_count' => function ($q) use ($thirtyDaysAgo) {
                    $q->where('created_at', '>=', $thirtyDaysAgo);
                },
                'posts as total_posts_count',
            ])
            ->orderByDesc('recent_posts_count')
            ->orderByRaw("CASE WHEN role = 'creator' THEN 0 ELSE 1 END")
            ->orderByDesc('total_posts_count')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $data = $suggested->map(function (User $user) {
            $avatarUrl = null;
            if ($user->avatar_path) {
                $avatarUrl = Storage::disk('public')->url($user->avatar_path);
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_path' => $user->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => $user->role,
                'posts_count' => (int) ($user->total_posts_count ?? 0),
                'is_following' => false,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Search users by name for community follow connection.
     * GET /api/community/users/search?q=...
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $queryText = trim((string) $request->input('q', ''));
        if ($queryText === '') {
            return response()->json(['data' => []]);
        }

        $currentUser = $request->user('sanctum');

        $usersQuery = User::query()
            ->where('is_active', true)
            ->where('name', 'like', '%' . addcslashes($queryText, '%_\\') . '%')
            ->select('users.id', 'users.name', 'users.avatar_path', 'users.role');

        if ($currentUser) {
            $usersQuery->where('id', '!=', $currentUser->id);
        }

        $followedIds = [];
        if ($currentUser) {
            $followedIds = UserFollow::where('follower_id', $currentUser->id)
                ->pluck('following_id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        $results = $usersQuery
            ->withCount('posts')
            ->orderByRaw("CASE WHEN role = 'creator' THEN 0 ELSE 1 END")
            ->orderByDesc('posts_count')
            ->limit(15)
            ->get();

        $data = $results->map(function (User $user) use ($followedIds) {
            $avatarUrl = null;
            if ($user->avatar_path) {
                $avatarUrl = Storage::disk('public')->url($user->avatar_path);
            }

            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'avatar_path' => $user->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $user->role,
                'posts_count' => (int) ($user->posts_count ?? 0),
                'is_following' => in_array((int) $user->id, $followedIds, true),
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
