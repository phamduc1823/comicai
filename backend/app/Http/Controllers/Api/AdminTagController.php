<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTagController extends Controller
{
    /**
     * List all tags with comics count and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query()->withCount('comics');

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $sortBy = $request->input('sort_by', 'name');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['id', 'name', 'slug', 'comics_count', 'created_at'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('name', 'asc');
        }

        if ($request->input('per_page') === 'all') {
            $tags = $query->get();
            return response()->json([
                'status' => 'success',
                'data' => $tags,
                'meta' => [
                    'total' => $tags->count(),
                ],
            ]);
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
     * Store a newly created tag.
     */
    public function store(StoreTagRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tag = Tag::create($validated);
        $tag->loadCount('comics');
        Tag::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Tạo thẻ truyện mới thành công.',
            'data' => $tag,
        ], 201);
    }

    /**
     * Display the specified tag.
     */
    public function show(int $id): JsonResponse
    {
        $tag = Tag::withCount('comics')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $tag,
        ]);
    }

    /**
     * Update the specified tag.
     */
    public function update(UpdateTagRequest $request, int $id): JsonResponse
    {
        $tag = Tag::findOrFail($id);
        $validated = $request->validated();

        $tag->update($validated);
        $tag->loadCount('comics');
        Tag::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật thẻ truyện thành công.',
            'data' => $tag,
        ]);
    }

    /**
     * Remove the specified tag safely.
     */
    public function destroy(int $id): JsonResponse
    {
        $tag = Tag::withCount('comics')->findOrFail($id);

        if ($tag->comics_count > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Không thể xóa thẻ '{$tag->name}' vì đang có {$tag->comics_count} bộ truyện liên kết.",
            ], 422);
        }

        $tag->delete();
        Tag::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => "Đã xóa thẻ '{$tag->name}' thành công.",
        ]);
    }
}
