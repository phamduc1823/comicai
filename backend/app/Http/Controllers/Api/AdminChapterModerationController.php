<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectChapterRequest;
use App\Http\Resources\AdminModerationChapterResource;
use App\Models\Chapter;
use App\Models\Comic;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AdminChapterModerationController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a paginated listing of chapters in the moderation queue (status=pending).
     * Supports:
     * - comic_id: filter chapters for a specific comic
     * - per_page: 1 to 50 (default 15)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Chapter::where('status', 'pending');

        if ($request->filled('comic_id')) {
            $query->where('comic_id', $request->integer('comic_id'));
        }

        $query->with([
            'comic:id,title,slug,user_id,approval_status,status',
            'comic.user:id,name,avatar_path',
            'images' => function ($q) {
                $q->orderBy('page_number', 'asc')->orderBy('id', 'asc');
            },
            'approver:id,name',
        ]);

        // Stable sorting: oldest submission first, secondary sort by ID
        $query->orderBy('created_at', 'asc')->orderBy('id', 'asc');

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $chapters = $query->paginate($perPage);

        return AdminModerationChapterResource::collection($chapters);
    }

    /**
     * Approve and publish a pending chapter.
     * Prerequisite: Parent comic must be approved.
     */
    public function approve(Request $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::with('comic')->findOrFail($id);

        if ($chapter->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Chỉ có thể duyệt chương truyện đang trong trạng thái chờ duyệt.',
            ], 422);
        }

        $this->authorize('approve', $chapter);

        $statusError = null;
        $updatedChapter = null;

        DB::transaction(function () use ($chapter, $id, $request, &$statusError, &$updatedChapter) {
            // Lock parent comic first to enforce consistent hierarchical lock order and check status
            $lockedComic = Comic::where('id', $chapter->comic_id)->lockForUpdate()->firstOrFail();

            if ($lockedComic->approval_status !== 'approved') {
                $statusError = [
                    'status' => 422,
                    'message' => 'Không thể duyệt xuất bản chương khi bộ truyện chưa được duyệt (trạng thái hiện tại: ' . $lockedComic->approval_status . ').',
                ];
                return;
            }

            // Lock chapter second
            $lockedChapter = Chapter::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($lockedChapter->status !== 'pending') {
                $statusError = [
                    'status' => 422,
                    'message' => 'Chỉ có thể duyệt chương truyện đang trong trạng thái chờ duyệt.',
                ];
                return;
            }

            $lockedChapter->status = 'published';
            $lockedChapter->rejection_reason = null;
            $lockedChapter->approved_by = $request->user()->id;
            $lockedChapter->approved_at = now();
            $lockedChapter->save();

            $updatedChapter = $lockedChapter;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        $updatedChapter->load([
            'comic:id,title,slug,user_id,approval_status,status',
            'comic.user:id,name,avatar_path',
            'images',
            'approver:id,name',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Duyệt xuất bản chương truyện thành công.',
            'data' => new AdminModerationChapterResource($updatedChapter),
        ]);
    }

    /**
     * Reject a pending chapter with reason.
     */
    public function reject(RejectChapterRequest $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::with('comic')->findOrFail($id);

        if ($chapter->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Chỉ có thể từ chối chương truyện đang trong trạng thái chờ duyệt.',
            ], 422);
        }

        $this->authorize('reject', $chapter);

        $validated = $request->validated();
        $statusError = null;
        $updatedChapter = null;

        DB::transaction(function () use ($id, $request, $validated, &$statusError, &$updatedChapter) {
            $lockedChapter = Chapter::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($lockedChapter->status !== 'pending') {
                $statusError = [
                    'status' => 422,
                    'message' => 'Chỉ có thể từ chối chương truyện đang trong trạng thái chờ duyệt.',
                ];
                return;
            }

            $lockedChapter->status = 'rejected';
            $lockedChapter->rejection_reason = $validated['rejection_reason'];
            $lockedChapter->approved_by = $request->user()->id;
            $lockedChapter->approved_at = now();
            $lockedChapter->save();

            $updatedChapter = $lockedChapter;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        $updatedChapter->load([
            'comic:id,title,slug,user_id,approval_status,status',
            'comic.user:id,name,avatar_path',
            'images',
            'approver:id,name',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Từ chối duyệt chương truyện thành công.',
            'data' => new AdminModerationChapterResource($updatedChapter),
        ]);
    }
}
