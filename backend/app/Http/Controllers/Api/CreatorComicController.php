<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCreatorComicRequest;
use App\Http\Requests\UpdateCreatorComicRequest;
use App\Http\Resources\CreatorComicResource;
use App\Models\Comic;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreatorComicController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of comics in Creator Studio.
     * - Creator: strictly filtered to comics owned by the authenticated user.
     * - Admin: authorized to view all comics across the platform for management.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Comic::class);

        $user = $request->user();
        $query = Comic::query();

        // Scope query to owner for creators; admins can view all comics
        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        }

        $query->with([
            'genres:id,name,slug',
            'tags:id,name,slug',
            'user:id,name,avatar_path',
        ])->withCount([
            'chapters',
            'chapters as published_chapters_count' => function ($q) {
                $q->where('status', 'published');
            },
        ]);

        // Optional filter by approval_status
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->input('approval_status'));
        }

        // Optional filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Optional search query
        if ($request->filled('q')) {
            $escaped = addcslashes($request->input('q'), '%_\\');
            $searchTerm = "%{$escaped}%";
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw("title LIKE ? ESCAPE '\\'", [$searchTerm])
                  ->orWhereRaw("author_name LIKE ? ESCAPE '\\'", [$searchTerm]);
            });
        }

        $query->orderBy('updated_at', 'desc')->orderBy('id', 'desc');

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $comics = $query->paginate($perPage);

        return CreatorComicResource::collection($comics);
    }

    /**
     * Store a newly created comic in draft state with cover upload.
     */
    public function store(StoreCreatorComicRequest $request): JsonResponse
    {
        $this->authorize('create', Comic::class);

        $validated = $request->validated();
        $user = $request->user();

        // 1. Generate a collision-free slug from title
        $baseSlug = Str::slug($validated['title']);
        if ($baseSlug === '') {
            $baseSlug = 'comic-' . Str::lower(Str::random(8));
        }
        $slug = $baseSlug;
        $counter = 1;
        while (Comic::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        // 2. Upload cover with randomized filename
        $file = $request->file('cover');
        $extension = $file->guessExtension() ?: 'webp';
        $filename = Str::random(40) . '.' . $extension;
        $coverPath = $file->storeAs('comics/covers', $filename, 'public');

        try {
            $comic = DB::transaction(function () use ($validated, $user, $slug, $coverPath) {
                $comic = new Comic();
                $comic->title = $validated['title'];
                $comic->slug = $slug;
                $comic->author_name = $validated['author_name'];
                $comic->user_id = $user->id; // Enforce owner from authenticated user
                $comic->cover_path = $coverPath;
                $comic->description = $validated['description'] ?? null;
                $comic->alternative_titles = $validated['alternative_titles'] ?? null;
                $comic->age_rating = $validated['age_rating'] ?? '13+';
                $comic->status = $validated['status'] ?? 'ongoing';
                $comic->approval_status = 'draft'; // Strictly draft on creation
                $comic->approved_by = null;
                $comic->approved_at = null;
                $comic->rejection_reason = null;
                $comic->pending_changes = null;
                $comic->views_count = 0;
                $comic->follows_count = 0;
                $comic->is_featured = false;
                $comic->save();

                if (! empty($validated['genres'])) {
                    $comic->genres()->sync($validated['genres']);
                }
                if (! empty($validated['tags'])) {
                    $comic->tags()->sync($validated['tags']);
                }

                return $comic;
            });
        } catch (\Throwable $e) {
            // Roll back uploaded file if transaction fails
            Storage::disk('public')->delete($coverPath);
            throw $e;
        }

        $comic->load(['genres', 'tags', 'user:id,name,avatar_path']);

        return response()->json([
            'status' => 'success',
            'message' => 'Tạo bộ truyện mới thành công.',
            'data' => new CreatorComicResource($comic),
        ], 201);
    }

    /**
     * Display the specified comic details for Creator Studio.
     */
    public function show(int|string $id): JsonResponse
    {
        $comic = Comic::with([
            'genres',
            'tags',
            'user:id,name,avatar_path',
            'approver:id,name',
        ])->withCount([
            'chapters',
            'chapters as published_chapters_count' => function ($q) {
                $q->where('status', 'published');
            },
        ])->findOrFail($id);

        $this->authorize('view', $comic);

        return response()->json([
            'status' => 'success',
            'data' => new CreatorComicResource($comic),
        ]);
    }

    /**
     * Update the specified comic in Creator Studio.
     * - Draft / Rejected: updates fields directly.
     * - Approved: critical changes are saved into pending_changes for admin review.
     * - Pending: editing is locked until review completes or is cancelled.
     */
    public function update(UpdateCreatorComicRequest $request, int|string $id): JsonResponse
    {
        $comic = Comic::with(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name'])->findOrFail($id);
        $this->authorize('update', $comic);

        $user = $request->user();
        $validated = $request->validated();

        // Preliminary fast-fail checks before processing file uploads
        if ($comic->approval_status === 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Không thể chỉnh sửa bộ truyện khi đang trong quá trình chờ duyệt.',
            ], 403);
        }

        if ($comic->approval_status === 'approved' && $comic->pending_changes !== null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bộ truyện đang có bản chỉnh sửa chờ duyệt. Bạn không thể gửi bản sửa đổi mới cho đến khi bản hiện tại được xử lý hoặc bị hủy.',
            ], 422);
        }

        $newCoverPath = null;
        $oldCoverPath = null;

        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $extension = $file->guessExtension() ?: 'webp';
            $filename = Str::random(40) . '.' . $extension;
            $newCoverPath = $file->storeAs('comics/covers', $filename, 'public');
        }

        $statusError = null;
        $isApprovedBranch = false;

        try {
            DB::transaction(function () use (
                $id,
                $user,
                $validated,
                $newCoverPath,
                &$comic,
                &$oldCoverPath,
                &$statusError,
                &$isApprovedBranch
            ) {
                $locked = Comic::with(['genres', 'tags'])->where('id', $id)->lockForUpdate()->firstOrFail();

                // 1. Re-verify ownership on the locked record (creators can only update own comic)
                if ($user->role !== 'admin' && (int) $locked->user_id !== (int) $user->id) {
                    $statusError = [
                        'status' => 403,
                        'message' => 'Hành động không được phép.',
                    ];
                    return;
                }

                // 2. Re-verify pending status on the locked record
                if ($locked->approval_status === 'pending') {
                    $statusError = [
                        'status' => 403,
                        'message' => 'Không thể chỉnh sửa bộ truyện khi đang trong quá trình chờ duyệt.',
                    ];
                    return;
                }

                // 3. Branch A: Comic is APPROVED
                if ($locked->approval_status === 'approved') {
                    if ($locked->pending_changes !== null) {
                        $statusError = [
                            'status' => 422,
                            'message' => 'Bộ truyện đang có bản chỉnh sửa chờ duyệt. Bạn không thể gửi bản sửa đổi mới cho đến khi bản hiện tại được xử lý hoặc bị hủy.',
                        ];
                        return;
                    }

                    $isApprovedBranch = true;

                    // Non-critical fields apply directly:
                    if (isset($validated['status'])) {
                        $locked->status = $validated['status'];
                    }
                    if (array_key_exists('alternative_titles', $validated)) {
                        $locked->alternative_titles = $validated['alternative_titles'];
                    }

                    // Critical changes are queued into pending_changes:
                    $tagIds = isset($validated['tags'])
                        ? array_values(array_unique($validated['tags']))
                        : $locked->tags->pluck('id')->all();

                    $locked->pending_changes = [
                        'title' => $validated['title'] ?? $locked->title,
                        'author_name' => $validated['author_name'] ?? $locked->author_name,
                        'description' => array_key_exists('description', $validated) ? $validated['description'] : $locked->description,
                        'cover_path' => $newCoverPath ?? $locked->cover_path,
                        'age_rating' => $validated['age_rating'] ?? $locked->age_rating,
                        'tags' => $tagIds,
                        'submitted_at' => now()->toIso8601String(),
                    ];

                    $locked->save();
                    $comic = $locked;
                    return;
                }

                // 4. Branch B: Comic is in 'draft' or 'rejected'
                if (isset($validated['title'])) {
                    $locked->title = $validated['title'];
                }
                if (isset($validated['author_name'])) {
                    $locked->author_name = $validated['author_name'];
                }
                if (array_key_exists('description', $validated)) {
                    $locked->description = $validated['description'];
                }
                if (array_key_exists('alternative_titles', $validated)) {
                    $locked->alternative_titles = $validated['alternative_titles'];
                }
                if (isset($validated['age_rating'])) {
                    $locked->age_rating = $validated['age_rating'];
                }
                if (isset($validated['status'])) {
                    $locked->status = $validated['status'];
                }
                if ($newCoverPath) {
                    $oldCoverPath = $locked->cover_path;
                    $locked->cover_path = $newCoverPath;
                }

                $locked->save();

                if (isset($validated['genres'])) {
                    $locked->genres()->sync($validated['genres']);
                }
                if (isset($validated['tags'])) {
                    $locked->tags()->sync($validated['tags']);
                }

                $comic = $locked;
            });
        } catch (\Throwable $e) {
            if ($newCoverPath) {
                Storage::disk('public')->delete($newCoverPath);
            }
            throw $e;
        }

        if ($statusError !== null) {
            if ($newCoverPath) {
                Storage::disk('public')->delete($newCoverPath);
            }
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        // Delete old uploaded cover if superseded in Branch B (draft/rejected)
        if (! $isApprovedBranch && $oldCoverPath && $newCoverPath && str_starts_with($oldCoverPath, 'comics/covers/')) {
            Storage::disk('public')->delete($oldCoverPath);
        }

        $comic->load(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name']);

        if ($isApprovedBranch) {
            return response()->json([
                'status' => 'success',
                'message' => 'Bản chỉnh sửa quan trọng đã được gửi vào hàng đợi duyệt của Quản trị viên.',
                'data' => new CreatorComicResource($comic),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật bộ truyện thành công.',
            'data' => new CreatorComicResource($comic),
        ]);
    }

    /**
     * Submit a draft or rejected comic for admin review.
     */
    public function submitReview(int|string $id): JsonResponse
    {
        $comic = Comic::with(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name'])->findOrFail($id);
        $this->authorize('submitReview', $comic);

        // Pre-condition: required fields must be populated
        if (empty($comic->title) || empty($comic->author_name) || empty($comic->cover_path) || $comic->genres->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bộ truyện phải có đầy đủ tiêu đề, tên tác giả, ảnh bìa và ít nhất một thể loại trước khi gửi duyệt.',
            ], 422);
        }

        $comic->approval_status = 'pending';
        $comic->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Gửi duyệt bộ truyện thành công.',
            'data' => new CreatorComicResource($comic),
        ]);
    }

    /**
     * Withdraw/cancel review request, moving comic from pending back to draft.
     */
    public function cancelReview(int|string $id): JsonResponse
    {
        $comic = Comic::with(['genres', 'tags', 'user:id,name,avatar_path', 'approver:id,name'])->findOrFail($id);
        $this->authorize('cancelReview', $comic);

        $comic->approval_status = 'draft';
        $comic->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Rút yêu cầu duyệt bộ truyện thành công.',
            'data' => new CreatorComicResource($comic),
        ]);
    }

    /**
     * Delete a draft or rejected comic.
     */
    public function destroy(int|string $id): JsonResponse
    {
        $comic = Comic::findOrFail($id);
        $this->authorize('delete', $comic);

        $coverPath = $comic->cover_path;

        $comic->delete();

        if ($coverPath && str_starts_with($coverPath, 'comics/covers/')) {
            Storage::disk('public')->delete($coverPath);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Xóa bộ truyện thành công.',
        ]);
    }
}
