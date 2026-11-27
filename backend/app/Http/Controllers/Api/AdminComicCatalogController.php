<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Comic;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminComicCatalogController extends Controller
{
    /**
     * List all comics in the catalog regardless of approval status.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Comic::query()
            ->with(['user:id,name,email', 'genres:id,name,slug'])
            ->withCount('chapters');

        // Search q
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('author_name', 'like', "%{$q}%");
            });
        }

        // Filter approval_status
        if ($request->filled('approval_status') && $request->input('approval_status') !== 'all') {
            $query->where('approval_status', $request->input('approval_status'));
        }

        // Filter publication status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Filter is_vip
        if ($request->has('is_vip') && $request->input('is_vip') !== 'all' && $request->input('is_vip') !== null) {
            $isVip = filter_var($request->input('is_vip'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isVip !== null) {
                $query->where('is_vip', $isVip);
            }
        }

        // Filter by genre
        if ($request->filled('genre_id')) {
            $genreId = (int) $request->input('genre_id');
            $query->whereHas('genres', function ($g) use ($genreId) {
                $g->where('genres.id', $genreId);
            });
        }

        // Filter by creator (user_id or name/email search)
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        } elseif ($request->filled('creator')) {
            $creator = trim((string) $request->input('creator'));
            $query->whereHas('user', function ($u) use ($creator) {
                $u->where('name', 'like', "%{$creator}%")
                    ->orWhere('email', 'like', "%{$creator}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'title', 'views_count', 'follows_count', 'price', 'created_at', 'updated_at', 'chapters_count'], true)) {
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
     * Display detailed comic data (without heavy chapters payload).
     */
    public function show(int $id): JsonResponse
    {
        $comic = Comic::with([
            'user:id,name,email,role,is_active,created_at',
            'genres:id,name,slug',
            'tags:id,name,slug',
            'approver:id,name,email',
        ])
            ->withCount(['chapters', 'purchases'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $comic,
        ]);
    }

    /**
     * Update publication status or featured flag.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['ongoing', 'completed', 'hiatus'])],
            'is_featured' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['status'])) {
            $comic->status = $validated['status'];
        }
        if (isset($validated['is_featured'])) {
            $comic->is_featured = (bool) $validated['is_featured'];
        }

        $comic->save();

        return response()->json([
            'status' => 'success',
            'message' => "Cập nhật trạng thái truyện '{$comic->title}' thành công.",
            'data' => $comic->load(['genres', 'user:id,name,email']),
        ]);
    }

    /**
     * Update featured status for a comic.
     */
    public function updateFeatured(Request $request, int $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        $validated = $request->validate([
            'is_featured' => ['required', 'boolean'],
        ]);

        $comic->is_featured = (bool) $validated['is_featured'];
        $comic->save();

        return response()->json([
            'status' => 'success',
            'message' => $comic->is_featured
                ? "Đã đánh dấu truyện '{$comic->title}' là nổi bật."
                : "Đã bỏ đánh dấu nổi bật cho truyện '{$comic->title}'.",
            'data' => [
                'id' => $comic->id,
                'title' => $comic->title,
                'is_featured' => $comic->is_featured,
            ],
        ]);
    }

    /**
     * Override VIP status and price for a comic.
     */
    public function updateVip(Request $request, int $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        $validated = $request->validate([
            'is_vip' => ['required', 'boolean'],
            'price' => ['required_if:is_vip,true', 'integer', 'min:0', 'max:1000000'],
        ]);

        $comic->is_vip = (bool) $validated['is_vip'];
        $comic->price = $comic->is_vip ? (int) ($validated['price'] ?? 0) : 0;
        $comic->save();

        return response()->json([
            'status' => 'success',
            'message' => "Cập nhật thiết lập VIP cho truyện '{$comic->title}' thành công.",
            'data' => [
                'id' => $comic->id,
                'title' => $comic->title,
                'is_vip' => $comic->is_vip,
                'price' => $comic->price,
            ],
        ]);
    }

    /**
     * Unpublish or change approval status directly with reason.
     * CAI-ADMIN-03: Supports optionally approving selected chapters alongside the comic.
     */
    public function updateApprovalStatus(Request $request, int $id, NotificationService $notificationService): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        $validated = $request->validate([
            'approval_status' => ['required', 'string', Rule::in(['draft', 'pending', 'approved', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            'chapter_ids' => ['nullable', 'array'],
            'chapter_ids.*' => ['integer', 'distinct'],
        ]);

        $selectedChapterIds = [];
        if (! empty($validated['chapter_ids']) && $validated['approval_status'] === 'approved') {
            $selectedChapterIds = array_values(array_unique(array_map('intval', $validated['chapter_ids'])));
            $chapters = Chapter::whereIn('id', $selectedChapterIds)->get();

            if ($chapters->count() !== count($selectedChapterIds)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Một hoặc nhiều chương được chọn không tồn tại.',
                ], 422);
            }

            foreach ($chapters as $chap) {
                if ((int) $chap->comic_id !== (int) $comic->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Một hoặc nhiều chương không thuộc bộ truyện này.',
                    ], 422);
                }

                if ($chap->status !== 'pending') {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Chỉ có thể duyệt các chương đang chờ duyệt (Chương #{$chap->chapter_number} đang ở trạng thái '{$chap->status}').",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($comic, $validated, $request, $selectedChapterIds) {
            $lockedComic = Comic::where('id', $comic->id)->lockForUpdate()->firstOrFail();
            $lockedComic->approval_status = $validated['approval_status'];

            if ($validated['approval_status'] === 'approved') {
                $lockedComic->approved_by = $request->user()->id;
                $lockedComic->approved_at = now();
                $lockedComic->rejection_reason = null;
            } elseif ($validated['approval_status'] === 'rejected') {
                $lockedComic->rejection_reason = $validated['rejection_reason'] ?? 'Bị từ chối hoặc thu hồi bởi Quản trị viên';
            }
            $lockedComic->save();

            if (! empty($selectedChapterIds) && $validated['approval_status'] === 'approved') {
                Chapter::whereIn('id', $selectedChapterIds)
                    ->lockForUpdate()
                    ->update([
                        'status' => 'published',
                        'rejection_reason' => null,
                        'approved_by' => $request->user()->id,
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        $comic->refresh();

        // Notify creator
        $admin = $request->user();
        if ($admin) {
            $notificationService->notifyComicReviewResult(
                comic: $comic,
                admin: $admin,
                action: $validated['approval_status'],
                approvedChaptersCount: count($selectedChapterIds),
                reason: $validated['rejection_reason'] ?? null
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => "Cập nhật trạng thái duyệt của truyện '{$comic->title}' thành '{$comic->approval_status}'.",
            'data' => $comic,
            'meta' => [
                'approved_chapters_count' => count($selectedChapterIds),
            ],
        ]);
    }
}
