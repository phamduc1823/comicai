<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectComicChangesRequest;
use App\Http\Requests\RejectComicRequest;
use App\Http\Resources\AdminModerationComicResource;
use App\Models\Comic;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminComicModerationController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a paginated listing of comics in the moderation queue.
     * Supports:
     * - type: 'new' (approval_status=pending), 'changes' (approval_status=approved and pending_changes!=null), 'all' (default)
     * - q: search by title or author_name
     * - per_page: 1 to 50 (default 15)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $request->input('type', 'all');
        $query = Comic::query();

        if ($type === 'new') {
            $query->where('approval_status', 'pending');
        } elseif ($type === 'changes') {
            $query->where('approval_status', 'approved')->whereNotNull('pending_changes');
        } else {
            // 'all': pending new comics OR approved comics with pending changes
            $query->where(function ($q) {
                $q->where('approval_status', 'pending')
                  ->orWhere(function ($q2) {
                      $q2->where('approval_status', 'approved')
                         ->whereNotNull('pending_changes');
                  });
            });
        }

        if ($request->filled('q')) {
            $rawTerm = Str::limit($request->input('q'), 100, '');
            $escaped = addcslashes($rawTerm, '%_\\');
            $searchTerm = "%{$escaped}%";
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw("title LIKE ? ESCAPE '\\'", [$searchTerm])
                  ->orWhereRaw("author_name LIKE ? ESCAPE '\\'", [$searchTerm]);
            });
        }

        $query->with([
            'genres:id,name,slug',
            'tags:id,name,slug',
            'user:id,name,avatar_path',
            'approver:id,name',
        ]);

        // Stable sorting: oldest submission/update first, secondary sort by ID
        $query->orderBy('updated_at', 'asc')->orderBy('id', 'asc');

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $comics = $query->paginate($perPage);

        return AdminModerationComicResource::collection($comics);
    }

    /**
     * Approve a pending new comic.
     */
    public function approve(Request $request, int|string $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        if ($comic->approval_status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Chỉ có thể duyệt bộ truyện đang trong trạng thái chờ duyệt.',
            ], 422);
        }

        $this->authorize('approve', $comic);

        $statusError = null;
        $updatedComic = null;

        DB::transaction(function () use ($id, $request, &$statusError, &$updatedComic) {
            $locked = Comic::with(['genres', 'tags'])->where('id', $id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== 'pending') {
                $statusError = [
                    'status' => 422,
                    'message' => 'Chỉ có thể duyệt bộ truyện đang trong trạng thái chờ duyệt.',
                ];
                return;
            }

            $locked->approval_status = 'approved';
            $locked->approved_by = $request->user()->id;
            $locked->approved_at = now();
            $locked->rejection_reason = null;
            $locked->save();

            $updatedComic = $locked;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        $updatedComic->load(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name']);

        return response()->json([
            'status' => 'success',
            'message' => 'Duyệt bộ truyện thành công.',
            'data' => new AdminModerationComicResource($updatedComic),
        ]);
    }

    /**
     * Reject a pending new comic with reason.
     */
    public function reject(RejectComicRequest $request, int|string $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        if ($comic->approval_status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Chỉ có thể từ chối bộ truyện đang trong trạng thái chờ duyệt.',
            ], 422);
        }

        $this->authorize('reject', $comic);

        $validated = $request->validated();
        $statusError = null;
        $updatedComic = null;

        DB::transaction(function () use ($id, $request, $validated, &$statusError, &$updatedComic) {
            $locked = Comic::with(['genres', 'tags'])->where('id', $id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== 'pending') {
                $statusError = [
                    'status' => 422,
                    'message' => 'Chỉ có thể từ chối bộ truyện đang trong trạng thái chờ duyệt.',
                ];
                return;
            }

            $locked->approval_status = 'rejected';
            $locked->approved_by = $request->user()->id;
            $locked->approved_at = now();
            $locked->rejection_reason = $validated['rejection_reason'];
            $locked->save();

            $updatedComic = $locked;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        $updatedComic->load(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name']);

        return response()->json([
            'status' => 'success',
            'message' => 'Từ chối duyệt bộ truyện thành công.',
            'data' => new AdminModerationComicResource($updatedComic),
        ]);
    }

    /**
     * Approve proposed pending changes for an already approved comic.
     */
    public function approveChanges(Request $request, int|string $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        if ($comic->approval_status !== 'approved' || $comic->pending_changes === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bộ truyện không có bản chỉnh sửa nào đang chờ duyệt.',
            ], 422);
        }

        $this->authorize('approve', $comic);

        $statusError = null;
        $updatedComic = null;
        $oldCoverPath = null;
        $newCoverPath = null;

        DB::transaction(function () use ($id, &$statusError, &$updatedComic, &$oldCoverPath, &$newCoverPath) {
            $locked = Comic::with(['genres', 'tags'])->where('id', $id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== 'approved' || $locked->pending_changes === null) {
                $statusError = [
                    'status' => 422,
                    'message' => 'Bộ truyện không có bản chỉnh sửa nào đang chờ duyệt.',
                ];
                return;
            }

            $changes = $locked->pending_changes;
            if (! is_array($changes)) {
                $statusError = [
                    'status' => 422,
                    'message' => 'Dữ liệu bản chỉnh sửa không hợp lệ.',
                ];
                return;
            }

            // Apply allowed fields from pending_changes
            if (isset($changes['title']) && is_string($changes['title']) && trim($changes['title']) !== '') {
                $locked->title = trim($changes['title']);
            }

            if (isset($changes['slug']) && is_string($changes['slug']) && trim($changes['slug']) !== '') {
                $locked->slug = trim($changes['slug']);
            }

            if (isset($changes['author_name']) && is_string($changes['author_name']) && trim($changes['author_name']) !== '') {
                $locked->author_name = trim($changes['author_name']);
            }

            if (array_key_exists('description', $changes)) {
                $locked->description = $changes['description'];
            }

            if (isset($changes['age_rating']) && in_array($changes['age_rating'], ['all', '13+', '16+', '18+'], true)) {
                $locked->age_rating = $changes['age_rating'];
            }

            if (! empty($changes['cover_path']) && $changes['cover_path'] !== $locked->cover_path) {
                $oldCoverPath = $locked->cover_path;
                $newCoverPath = $changes['cover_path'];
                $locked->cover_path = $newCoverPath;
            }

            // Sync tags if present in pending_changes
            if (isset($changes['tags']) && is_array($changes['tags'])) {
                $locked->tags()->sync($changes['tags']);
            }

            // Sync genres if present in pending_changes
            if (isset($changes['genres']) && is_array($changes['genres'])) {
                $locked->genres()->sync($changes['genres']);
            }

            // Reset pending_changes
            $locked->pending_changes = null;
            $locked->rejection_reason = null;
            $locked->save();

            $updatedComic = $locked;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        // Delete superseded old cover ONLY after transaction commit, and only if not still in use
        if ($oldCoverPath && $newCoverPath && str_starts_with($oldCoverPath, 'comics/covers/')) {
            $stillInUse = Comic::where('cover_path', $oldCoverPath)->exists();
            if (! $stillInUse) {
                Storage::disk('public')->delete($oldCoverPath);
            }
        }

        $updatedComic->load(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name']);

        return response()->json([
            'status' => 'success',
            'message' => 'Duyệt bản chỉnh sửa của bộ truyện thành công.',
            'data' => new AdminModerationComicResource($updatedComic),
        ]);
    }

    /**
     * Reject proposed pending changes for an already approved comic.
     */
    public function rejectChanges(RejectComicChangesRequest $request, int|string $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);

        if ($comic->approval_status !== 'approved' || $comic->pending_changes === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bộ truyện không có bản chỉnh sửa nào đang chờ duyệt.',
            ], 422);
        }

        $this->authorize('reject', $comic);

        $validated = $request->validated();
        $statusError = null;
        $updatedComic = null;
        $proposedCoverPath = null;
        $activeCoverPath = null;

        DB::transaction(function () use ($id, $validated, &$statusError, &$updatedComic, &$proposedCoverPath, &$activeCoverPath) {
            $locked = Comic::with(['genres', 'tags'])->where('id', $id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== 'approved' || $locked->pending_changes === null) {
                $statusError = [
                    'status' => 422,
                    'message' => 'Bộ truyện không có bản chỉnh sửa nào đang chờ duyệt.',
                ];
                return;
            }

            $activeCoverPath = $locked->cover_path;
            $changes = $locked->pending_changes;
            if (is_array($changes) && ! empty($changes['cover_path'])) {
                $proposedCoverPath = $changes['cover_path'];
            }

            // Keep all current public fields unchanged
            $locked->rejection_reason = $validated['rejection_reason'];
            $locked->pending_changes = null;
            $locked->save();

            $updatedComic = $locked;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        // Clean up the rejected proposed cover file if it differs from the active public cover
        if ($proposedCoverPath && $proposedCoverPath !== $activeCoverPath && str_starts_with($proposedCoverPath, 'comics/covers/')) {
            $stillInUse = Comic::where('cover_path', $proposedCoverPath)->exists();
            if (! $stillInUse) {
                Storage::disk('public')->delete($proposedCoverPath);
            }
        }

        $updatedComic->load(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name']);

        return response()->json([
            'status' => 'success',
            'message' => 'Từ chối bản chỉnh sửa của bộ truyện thành công.',
            'data' => new AdminModerationComicResource($updatedComic),
        ]);
    }
}
