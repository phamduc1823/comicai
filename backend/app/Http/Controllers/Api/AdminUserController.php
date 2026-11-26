<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    /**
     * List all users with filtering, searching, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->with(['wallet:id,user_id,balance', 'creatorProfile:id,user_id,pen_name,status'])
            ->withCount(['comics', 'comicPurchases']);

        // Search by query (name, email, or id)
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
                if (is_numeric($q)) {
                    $sub->orWhere('id', (int) $q);
                }
            });
        }

        // Filter by role
        if ($request->filled('role') && in_array($request->input('role'), ['reader', 'creator', 'admin'], true)) {
            $query->where('role', $request->input('role'));
        }

        // Filter by active status
        if ($request->has('is_active') && $request->input('is_active') !== 'all' && $request->input('is_active') !== null) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        // Filter by email verification
        if ($request->input('email_verified') === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($request->input('email_verified') === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'name', 'email', 'role', 'is_active', 'created_at', 'comics_count', 'comic_purchases_count'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Display detailed user information.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::query()
            ->with(['wallet', 'creatorProfile'])
            ->withCount(['comics', 'comicPurchases', 'bookmarks', 'follows', 'readingHistories'])
            ->findOrFail($id);

        $recentTransactions = $user->coinTransactions()
            ->latest('id')
            ->limit(10)
            ->get();

        $recentPurchases = $user->comicPurchases()
            ->with('comic:id,title,slug,cover_path,price')
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'recent_transactions' => $recentTransactions,
                'recent_purchases' => $recentPurchases,
            ],
        ]);
    }

    /**
     * Toggle user's active/locked status with safety guards.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $admin = $request->user();

        // Guard 1: Cannot lock own account
        if ($admin->id === $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn không thể tự khóa tài khoản của chính mình.',
            ], 422);
        }

        // Guard 2: Cannot lock the last active administrator
        if ($user->role === 'admin' && $user->is_active) {
            $activeAdminCount = User::where('role', 'admin')
                ->where('is_active', true)
                ->count();

            if ($activeAdminCount <= 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Không thể khóa tài khoản Administrator đang hoạt động cuối cùng của hệ thống.',
                ], 422);
            }
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        // If deactivated, revoke all tokens immediately
        if (! $user->is_active) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens')) {
                    $user->tokens()->delete();
                }
            } catch (\Throwable) {
                // Safe ignore if personal_access_tokens table is not available
            }
        }

        $statusText = $user->is_active ? 'Mở khóa' : 'Khóa';

        return response()->json([
            'status' => 'success',
            'message' => "{$statusText} tài khoản '{$user->email}' thành công.",
            'data' => $user->load(['wallet:id,user_id,balance', 'creatorProfile:id,user_id,pen_name,status']),
        ]);
    }

    /**
     * Update user role with safety guards.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $admin = $request->user();

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['reader', 'creator', 'admin'])],
        ]);

        $newRole = $validated['role'];

        if ($user->role === $newRole) {
            return response()->json([
                'status' => 'success',
                'message' => 'Vai trò không thay đổi.',
                'data' => $user,
            ]);
        }

        // Guard 1: Cannot demote self if admin
        if ($admin->id === $user->id && $newRole !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn không thể tự hạ quyền Administrator của chính mình.',
            ], 422);
        }

        // Guard 2: Cannot demote last active admin
        if ($user->role === 'admin' && $newRole !== 'admin') {
            $otherAdminsCount = User::where('role', 'admin')
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherAdminsCount < 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Không thể hạ quyền Administrator cuối cùng của hệ thống.',
                ], 422);
            }
        }

        // Creator lifecycle synchronization
        if ($newRole === 'creator') {
            $profile = $user->creatorProfile;
            if (! $profile) {
                $code = 'CR-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
                $canonical = \Illuminate\Support\Str::slug($user->name) ?: 'creator-'.$user->id;
                CreatorProfile::create([
                    'user_id' => $user->id,
                    'creator_code' => $code,
                    'pen_name' => $user->name,
                    'pen_name_canonical' => $canonical,
                    'status' => 'active',
                    'bio' => null,
                ]);
            } elseif ($profile->status !== 'active') {
                $profile->update(['status' => 'active']);
            }
        } elseif ($user->role === 'creator' && $newRole === 'reader') {
            $profile = $user->creatorProfile;
            if ($profile && $profile->status === 'active') {
                $profile->update(['status' => 'suspended']);
            }
        }

        $user->role = $newRole;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => "Cập nhật vai trò cho người dùng '{$user->email}' thành '{$newRole}' thành công.",
            'data' => $user->load(['wallet:id,user_id,balance', 'creatorProfile:id,user_id,pen_name,status']),
        ]);
    }
}
