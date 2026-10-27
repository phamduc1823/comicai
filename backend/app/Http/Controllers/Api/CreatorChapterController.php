<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderChapterImagesRequest;
use App\Http\Requests\StoreCreatorChapterRequest;
use App\Http\Requests\UpdateCreatorChapterRequest;
use App\Http\Requests\UploadChapterImagesRequest;
use App\Http\Resources\ChapterImageResource;
use App\Http\Resources\CreatorChapterResource;
use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\Comic;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreatorChapterController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of chapters for a comic in Creator Studio.
     * - Creator: strictly allowed only for comics owned by the creator.
     * - Admin: authorized to view chapters across any comic.
     */
    public function indexForComic(Request $request, int|string $comicId): AnonymousResourceCollection
    {
        $comic = Comic::findOrFail($comicId);
        $this->authorize('viewAny', [Chapter::class, $comic]);

        $query = Chapter::query()
            ->where('comic_id', $comic->id)
            ->withCount('images')
            ->orderBy('chapter_number', 'asc')
            ->orderBy('id', 'asc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $chapters = $query->paginate($perPage);

        return CreatorChapterResource::collection($chapters);
    }

    /**
     * Store a newly created chapter in draft state for a comic.
     */
    public function store(StoreCreatorChapterRequest $request, int|string $comicId): JsonResponse
    {
        $comic = Comic::findOrFail($comicId);
        $this->authorize('create', [Chapter::class, $comic]);

        $validated = $request->validated();
        $canonicalNumber = sprintf('%.2f', (float) $validated['chapter_number']);
        $numClean = rtrim(rtrim($canonicalNumber, '0'), '.');
        $title = $validated['title'] ?? null;
        $titlePart = ! empty($title) ? '-' . Str::slug($title) : '';
        $slug = Str::limit('chuong-' . $numClean . $titlePart, 175, '');

        $chapter = new Chapter();
        $chapter->comic_id = $comic->id;
        $chapter->chapter_number = $canonicalNumber;
        $chapter->title = $title;
        $chapter->slug = $slug;
        $chapter->status = 'draft';
        $chapter->rejection_reason = null;
        $chapter->views_count = 0;
        $chapter->save();

        $chapter->load(['comic:id,title,slug,user_id', 'images']);

        return response()->json([
            'status' => 'success',
            'message' => 'Tạo chương mới thành công.',
            'data' => new CreatorChapterResource($chapter),
        ], 201);
    }

    /**
     * Display the specified chapter details with images ordered by page_number.
     */
    public function show(int|string $id): JsonResponse
    {
        $chapter = Chapter::with([
            'comic:id,title,slug,user_id',
            'images' => function ($q) {
                $q->orderBy('page_number', 'asc')->orderBy('id', 'asc');
            },
        ])->findOrFail($id);

        $this->authorize('view', $chapter);

        return response()->json([
            'status' => 'success',
            'data' => new CreatorChapterResource($chapter),
        ]);
    }

    /**
     * Update chapter details (chapter_number, title).
     * Prohibited when chapter is pending review or already published.
     */
    public function update(UpdateCreatorChapterRequest $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::with('comic:id,title,slug,user_id')->findOrFail($id);
        $this->authorize('update', $chapter);

        $user = $request->user();

        if ($chapter->status === 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Không thể chỉnh sửa chương khi đang trong quá trình chờ duyệt.',
            ], 403);
        }

        if ($user->role !== 'admin' && $chapter->status === 'published') {
            return response()->json([
                'status' => 'error',
                'message' => 'Không thể chỉnh sửa chương đã xuất bản. Vui lòng thu hồi chương về bản nháp trước khi chỉnh sửa.',
            ], 403);
        }

        $validated = $request->validated();
        $statusError = null;

        DB::transaction(function () use ($id, $validated, $user, &$chapter, &$statusError) {
            $locked = Chapter::where('id', $id)->lockForUpdate()->firstOrFail();

            // Re-verify status on the locked model to prevent race conditions
            if ($locked->status === 'pending') {
                $statusError = [
                    'status' => 403,
                    'message' => 'Không thể chỉnh sửa chương khi đang trong quá trình chờ duyệt.',
                ];
                return;
            }

            if ($user->role !== 'admin' && $locked->status === 'published') {
                $statusError = [
                    'status' => 403,
                    'message' => 'Không thể chỉnh sửa chương đã xuất bản. Vui lòng thu hồi chương về bản nháp trước khi chỉnh sửa.',
                ];
                return;
            }

            if (isset($validated['chapter_number'])) {
                $canonicalNumber = sprintf('%.2f', (float) $validated['chapter_number']);
                $locked->chapter_number = $canonicalNumber;
            } else {
                $canonicalNumber = sprintf('%.2f', (float) $locked->chapter_number);
            }

            if (array_key_exists('title', $validated)) {
                $locked->title = $validated['title'];
            }

            $numClean = rtrim(rtrim($canonicalNumber, '0'), '.');
            $titlePart = ! empty($locked->title) ? '-' . Str::slug($locked->title) : '';
            $locked->slug = Str::limit('chuong-' . $numClean . $titlePart, 175, '');

            $locked->save();
            $chapter = $locked;
        });

        if ($statusError !== null) {
            return response()->json([
                'status' => 'error',
                'message' => $statusError['message'],
            ], $statusError['status']);
        }

        $chapter->load(['comic:id,title,slug,user_id', 'images']);

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật chương thành công.',
            'data' => new CreatorChapterResource($chapter),
        ]);
    }

    /**
     * Upload one or multiple page images for the chapter.
     */
    public function uploadImages(UploadChapterImagesRequest $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::with('comic:id,title,slug,user_id')->findOrFail($id);
        $this->authorize('manageImages', $chapter);

        $files = [];
        if ($request->hasFile('images')) {
            $files = $request->file('images');
            if (! is_array($files)) {
                $files = [$files];
            }
        } elseif ($request->hasFile('image')) {
            $files = [$request->file('image')];
        }

        if (empty($files)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Không có tệp ảnh nào được tải lên.',
            ], 422);
        }

        $newPaths = [];
        $createdImages = [];

        try {
            DB::transaction(function () use ($chapter, $files, &$newPaths, &$createdImages) {
                $currentMax = ChapterImage::where('chapter_id', $chapter->id)
                    ->lockForUpdate()
                    ->max('page_number') ?? 0;

                foreach ($files as $file) {
                    $extension = $file->guessExtension() ?: 'webp';
                    $filename = Str::random(40) . '.' . $extension;
                    $path = $file->storeAs('chapters/' . $chapter->id, $filename, 'public');
                    $newPaths[] = $path;

                    $width = null;
                    $height = null;
                    $realPath = $file->getRealPath();
                    if ($realPath && file_exists($realPath)) {
                        $imageSize = @getimagesize($realPath);
                        if ($imageSize) {
                            $width = $imageSize[0] ?? null;
                            $height = $imageSize[1] ?? null;
                        }
                    }

                    $currentMax++;
                    $image = new ChapterImage();
                    $image->chapter_id = $chapter->id;
                    $image->page_number = $currentMax;
                    $image->image_path = $path;
                    $image->width = $width;
                    $image->height = $height;
                    $image->save();

                    $createdImages[] = $image;
                }
            });
        } catch (\Throwable $e) {
            foreach ($newPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Tải lên ảnh trang truyện thành công.',
            'data' => ChapterImageResource::collection($createdImages),
        ], 201);
    }

    /**
     * Reorder page images of the chapter.
     */
    public function reorderImages(ReorderChapterImagesRequest $request, int|string $id): JsonResponse
    {
        $chapter = Chapter::with('comic:id,title,slug,user_id')->findOrFail($id);
        $this->authorize('manageImages', $chapter);

        $orderedIds = [];
        if ($request->has('image_ids')) {
            $orderedIds = array_values($request->input('image_ids'));
        } elseif ($request->has('orders')) {
            $orders = $request->input('orders');
            usort($orders, fn ($a, $b) => ($a['page_number'] <=> $b['page_number']));
            $orderedIds = array_column($orders, 'id');
        }

        $existingImages = ChapterImage::where('chapter_id', $chapter->id)->get()->keyBy('id');

        if ($existingImages->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương chưa có ảnh nào để sắp xếp.',
            ], 422);
        }

        // Validate that all submitted IDs belong to this chapter
        foreach ($orderedIds as $imgId) {
            if (! $existingImages->has($imgId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Danh sách chứa ảnh không thuộc về chương này.',
                ], 422);
            }
        }

        if (count(array_unique($orderedIds)) !== $existingImages->count()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Danh sách sắp xếp phải chứa đúng và đủ tất cả các ảnh hiện có của chương.',
            ], 422);
        }

        // Atomically reorder using temporary negative numbers to prevent unique constraint collisions
        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $imgId) {
                ChapterImage::where('id', $imgId)->update(['page_number' => -($index + 1)]);
            }

            foreach ($orderedIds as $index => $imgId) {
                ChapterImage::where('id', $imgId)->update(['page_number' => $index + 1]);
            }
        });

        $images = ChapterImage::where('chapter_id', $chapter->id)->orderBy('page_number', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Sắp xếp lại thứ tự ảnh thành công.',
            'data' => ChapterImageResource::collection($images),
        ]);
    }

    /**
     * Delete a single page image from the chapter.
     */
    public function deleteImage(int|string $id, int|string $imageId): JsonResponse
    {
        $chapter = Chapter::with('comic:id,title,slug,user_id')->findOrFail($id);
        $this->authorize('manageImages', $chapter);

        $image = ChapterImage::where('chapter_id', $chapter->id)->where('id', $imageId)->first();
        if (! $image) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ảnh không tồn tại hoặc không thuộc chương này.',
            ], 404);
        }

        $pathToDelete = $image->image_path;

        DB::transaction(function () use ($chapter, $image) {
            $image->delete();

            // Re-index remaining images sequentially to avoid gaps and avoid unique collisions
            $remaining = ChapterImage::where('chapter_id', $chapter->id)->orderBy('page_number', 'asc')->get();
            foreach ($remaining as $idx => $img) {
                $img->page_number = -($idx + 1);
                $img->save();
            }
            foreach ($remaining as $idx => $img) {
                $img->page_number = $idx + 1;
                $img->save();
            }
        });

        if ($pathToDelete && str_starts_with($pathToDelete, 'chapters/')) {
            Storage::disk('public')->delete($pathToDelete);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Xóa ảnh trang truyện thành công.',
        ]);
    }

    /**
     * Submit chapter for admin review.
     * Allowed only when in draft or rejected status, and must contain at least 1 image.
     */
    public function submitReview(int|string $id): JsonResponse
    {
        $chapter = Chapter::with(['comic:id,title,slug,user_id', 'images'])->findOrFail($id);
        $this->authorize('submitReview', $chapter);

        $imagesCount = ChapterImage::where('chapter_id', $chapter->id)->count();
        if ($imagesCount === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chương truyện phải có ít nhất một ảnh trước khi gửi duyệt.',
            ], 422);
        }

        DB::transaction(function () use ($id, &$chapter) {
            $locked = Chapter::where('id', $id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'rejected'], true)) {
                throw new \DomainException('Chỉ có thể gửi duyệt chương ở trạng thái bản nháp hoặc bị từ chối.');
            }

            $locked->status = 'pending';
            $locked->rejection_reason = null;
            $locked->save();
            $chapter = $locked;
        });

        $chapter->load(['comic:id,title,slug,user_id', 'images']);

        return response()->json([
            'status' => 'success',
            'message' => 'Gửi duyệt chương thành công.',
            'data' => new CreatorChapterResource($chapter),
        ]);
    }

    /**
     * Cancel/withdraw review request, moving chapter from pending back to draft.
     */
    public function cancelReview(int|string $id): JsonResponse
    {
        $chapter = Chapter::with(['comic:id,title,slug,user_id', 'images'])->findOrFail($id);
        $this->authorize('cancelReview', $chapter);

        DB::transaction(function () use ($id, &$chapter) {
            $locked = Chapter::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new \DomainException('Chỉ có thể rút yêu cầu duyệt khi chương đang chờ duyệt.');
            }

            $locked->status = 'draft';
            $locked->save();
            $chapter = $locked;
        });

        $chapter->load(['comic:id,title,slug,user_id', 'images']);

        return response()->json([
            'status' => 'success',
            'message' => 'Rút yêu cầu duyệt chương thành công.',
            'data' => new CreatorChapterResource($chapter),
        ]);
    }

    /**
     * Recall a published chapter back to draft.
     */
    public function recall(int|string $id): JsonResponse
    {
        $chapter = Chapter::with(['comic:id,title,slug,user_id', 'images'])->findOrFail($id);
        $this->authorize('recall', $chapter);

        DB::transaction(function () use ($id, &$chapter) {
            $locked = Chapter::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'published') {
                throw new \DomainException('Chỉ có thể thu hồi chương đã phát hành.');
            }

            $locked->status = 'draft';
            $locked->save();
            $chapter = $locked;
        });

        $chapter->load(['comic:id,title,slug,user_id', 'images']);

        return response()->json([
            'status' => 'success',
            'message' => 'Thu hồi chương về bản nháp thành công.',
            'data' => new CreatorChapterResource($chapter),
        ]);
    }
}
