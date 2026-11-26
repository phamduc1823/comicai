<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Comic;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminChapterCatalogController extends Controller
{
    /**
     * List chapters across all comics or filtered by comic.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Chapter::query()
            ->with(['comic:id,title,slug,cover_path,user_id', 'comic.user:id,name,email'])
            ->withCount('images');

        // Filter by comic_id
        if ($request->filled('comic_id')) {
            $query->where('comic_id', (int) $request->input('comic_id'));
        }

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Search by title or chapter number
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('chapter_number', 'like', "%{$q}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'chapter_number', 'views_count', 'created_at', 'status'], true)) {
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
     * Display detailed chapter data.
     */
    public function show(int $id): JsonResponse
    {
        $chapter = Chapter::with([
            'comic:id,title,slug,cover_path,user_id,is_vip,price',
            'comic.user:id,name,email',
            'images' => fn ($q) => $q->orderBy('page_number', 'asc'),
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $chapter,
        ]);
    }

    /**
     * Update chapter status or unpublish on dispute.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $chapter = Chapter::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['draft', 'pending', 'published', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $chapter->status = $validated['status'];
        if ($validated['status'] === 'rejected') {
            $chapter->rejection_reason = $validated['rejection_reason'] ?? 'Bị từ chối hoặc gỡ bởi Quản trị viên';
        } elseif ($validated['status'] === 'published') {
            $chapter->rejection_reason = null;
        }

        $chapter->save();

        return response()->json([
            'status' => 'success',
            'message' => "Cập nhật trạng thái chương {$chapter->chapter_number} thành '{$chapter->status}'.",
            'data' => $chapter->load(['comic:id,title,slug']),
        ]);
    }

    /**
     * List chapters strictly within the context of a specific Comic with pagination.
     */
    public function indexForComic(Request $request, int $comicId): JsonResponse
    {
        $comic = Comic::findOrFail($comicId);

        $query = Chapter::where('comic_id', $comic->id)
            ->withCount('images');

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Search by title or chapter number
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('chapter_number', 'like', "%{$q}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'chapter_number');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['id', 'chapter_number', 'views_count', 'created_at', 'updated_at', 'status'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('chapter_number', 'asc');
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'comic' => [
                'id' => $comic->id,
                'title' => $comic->title,
                'slug' => $comic->slug,
            ],
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
     * Display detailed chapter data strictly within the context of a Comic.
     */
    public function showForComic(int $comicId, int $chapterId): JsonResponse
    {
        $comic = Comic::findOrFail($comicId);

        $chapter = Chapter::where('comic_id', $comic->id)
            ->with([
                'approver:id,name,email',
                'images' => fn ($q) => $q->orderBy('page_number', 'asc'),
            ])
            ->withCount('images')
            ->findOrFail($chapterId);

        return response()->json([
            'status' => 'success',
            'comic' => [
                'id' => $comic->id,
                'title' => $comic->title,
                'slug' => $comic->slug,
                'user_id' => $comic->user_id,
            ],
            'data' => $chapter,
        ]);
    }

    /**
     * Update chapter status strictly within the context of a Comic.
     */
    public function updateStatusForComic(Request $request, int $comicId, int $chapterId, NotificationService $notificationService): JsonResponse
    {
        $comic = Comic::findOrFail($comicId);
        $chapter = Chapter::where('comic_id', $comic->id)->findOrFail($chapterId);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['draft', 'pending', 'published', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $chapter->status = $validated['status'];
        if ($validated['status'] === 'rejected') {
            $chapter->rejection_reason = $validated['rejection_reason'] ?? 'Bị từ chối hoặc gỡ bởi Quản trị viên';
        } elseif ($validated['status'] === 'published') {
            $chapter->rejection_reason = null;
            if (!$chapter->approved_by) {
                $chapter->approved_by = $request->user()->id;
                $chapter->approved_at = now();
            }
        }

        $chapter->save();

        // Notify creator if status changed to published or rejected
        $admin = $request->user();
        if ($admin) {
            if ($validated['status'] === 'published') {
                $notificationService->notifyChaptersBatchReviewed(
                    comic: $comic,
                    admin: $admin,
                    approvedCount: 1,
                    rejectedCount: 0
                );
            } elseif ($validated['status'] === 'rejected') {
                $notificationService->notifyChaptersBatchReviewed(
                    comic: $comic,
                    admin: $admin,
                    approvedCount: 0,
                    rejectedCount: 1,
                    reason: $chapter->rejection_reason
                );
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Cập nhật trạng thái chương {$chapter->chapter_number} thành '{$chapter->status}'.",
            'data' => $chapter,
        ]);
    }
}
