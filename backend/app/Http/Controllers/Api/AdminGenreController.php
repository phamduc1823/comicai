<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGenreRequest;
use App\Http\Requests\UpdateGenreRequest;
use App\Models\Genre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminGenreController extends Controller
{
    /**
     * List all genres with comics count and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Genre::query()->withCount('comics');

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== 'all' && $request->input('is_active') !== null) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        $sortBy = $request->input('sort_by', 'name');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['id', 'name', 'slug', 'comics_count', 'created_at', 'is_active'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('name', 'asc');
        }

        if ($request->input('per_page') === 'all') {
            $genres = $query->get();
            return response()->json([
                'status' => 'success',
                'data' => $genres,
                'meta' => [
                    'total' => $genres->count(),
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
     * Store a newly created genre.
     */
    public function store(StoreGenreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        $genre = Genre::create($validated);
        $genre->loadCount('comics');
        Genre::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Tạo thể loại mới thành công.',
            'data' => $genre,
        ], 201);
    }

    /**
     * Display the specified genre.
     */
    public function show(int $id): JsonResponse
    {
        $genre = Genre::withCount('comics')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $genre,
        ]);
    }

    /**
     * Update the specified genre.
     */
    public function update(UpdateGenreRequest $request, int $id): JsonResponse
    {
        $genre = Genre::findOrFail($id);
        $validated = $request->validated();

        $genre->update($validated);
        $genre->loadCount('comics');
        Genre::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật thể loại thành công.',
            'data' => $genre,
        ]);
    }

    /**
     * Toggle active status of a genre.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $genre = Genre::findOrFail($id);
        $genre->is_active = ! $genre->is_active;
        $genre->save();
        $genre->loadCount('comics');
        Genre::flushCache();

        $statusText = $genre->is_active ? 'Kích hoạt' : 'Tạm ngưng';

        return response()->json([
            'status' => 'success',
            'message' => "{$statusText} thể loại '{$genre->name}' thành công.",
            'data' => $genre,
        ]);
    }

    /**
     * Remove the specified genre safely.
     */
    public function destroy(int $id): JsonResponse
    {
        $genre = Genre::withCount('comics')->findOrFail($id);

        if ($genre->comics_count > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Không thể xóa thể loại '{$genre->name}' vì đang có {$genre->comics_count} bộ truyện liên kết. Bạn có thể chuyển sang trạng thái Tạm ngưng thay vì xóa.",
            ], 422);
        }

        $genre->delete();
        Genre::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => "Đã xóa thể loại '{$genre->name}' thành công.",
        ]);
    }
}
