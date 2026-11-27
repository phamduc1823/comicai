<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListComicsRequest;
use App\Http\Resources\ComicDetailResource;
use App\Http\Resources\ComicListResource;
use App\Models\Comic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ComicController extends Controller
{
    /**
     * Display a paginated listing of approved comics with filters and sorting.
     */
    public function index(ListComicsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = Comic::query()
            ->select([
                'id',
                'title',
                'slug',
                'alternative_titles',
                'author_name',
                'user_id',
                'cover_path',
                'age_rating',
                'status',
                'views_count',
                'follows_count',
                'is_featured',
                'approval_status',
                'created_at',
                'updated_at',
            ])
            ->where('approval_status', 'approved')
            ->withCount([
                'chapters as published_chapters_count' => function ($q) {
                    $q->where('status', 'published');
                },
            ])
            ->with([
                'genres:id,name,slug,description',
                'tags:id,name,slug,description',
                'user:id,name,avatar_path',
                'latestPublishedChapter:id,comic_id,chapter_number,title,slug,created_at,updated_at',
            ]);

        // Filter by genre slug
        if (! empty($validated['genre'])) {
            $query->whereHas('genres', function ($q) use ($validated) {
                $q->where('slug', $validated['genre']);
            });
        }

        // Filter by tag slug
        if (! empty($validated['tag'])) {
            $query->whereHas('tags', function ($q) use ($validated) {
                $q->where('slug', $validated['tag']);
            });
        }

        // Filter by comic status (ongoing, completed, hiatus)
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Filter by featured status
        if (isset($validated['is_featured'])) {
            $query->where('is_featured', (bool) $validated['is_featured']);
        }

        // Keyword search with safe % and _ wildcard escaping
        if (! empty($validated['q'])) {
            $escaped = addcslashes($validated['q'], '%_\\');
            $searchTerm = "%{$escaped}%";

            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('title LIKE ? ESCAPE ?', [$searchTerm, '\\'])
                  ->orWhereRaw('author_name LIKE ? ESCAPE ?', [$searchTerm, '\\'])
                  ->orWhereRaw('alternative_titles LIKE ? ESCAPE ?', [$searchTerm, '\\']);
            });
        }

        // Sorting with deterministic id tie-break
        $sort = $validated['sort'] ?? 'latest';
        match ($sort) {
            'views', 'weekly_views' => $query->orderBy('views_count', 'desc')->orderBy('id', 'desc'),
            'follows' => $query->orderBy('follows_count', 'desc')->orderBy('id', 'desc'),
            'updated' => $query->orderByDesc(
                \App\Models\Chapter::select('created_at')
                    ->whereColumn('chapters.comic_id', 'comics.id')
                    ->where('status', 'published')
                    ->latest()
                    ->limit(1)
            )->orderBy('updated_at', 'desc')->orderBy('id', 'desc'),
            default => $query->orderBy('created_at', 'desc')->orderBy('id', 'desc'),
        };

        $comics = $query->paginate($perPage);

        return ComicListResource::collection($comics);
    }

    /**
     * Display the specified approved comic by slug.
     */
    public function show(Request $request, string $slug): JsonResponse|ComicDetailResource
    {
        $currentUser = $request->user() ?? $request->user('sanctum');

        $query = Comic::query()
            ->where('slug', $slug)
            ->where('approval_status', 'approved')
            ->withCount([
                'chapters as published_chapters_count' => function ($q) {
                    $q->where('status', 'published');
                },
            ])
            ->with([
                'genres:id,name,slug,description',
                'tags:id,name,slug,description',
                'user:id,name,avatar_path',
                'chapters' => function ($q) {
                    $q->where('status', 'published')
                      ->orderBy('chapter_number', 'asc')
                      ->orderBy('id', 'asc');
                },
            ]);

        if ($currentUser) {
            $query->withExists([
                'follows as is_followed' => function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id);
                },
                'bookmarks as is_bookmarked' => function ($q) use ($currentUser) {
                    $q->where('user_id', $currentUser->id);
                },
            ]);
        }

        $comic = $query->first();

        if (! $comic) {
            return response()->json([
                'status' => 'error',
                'message' => 'Truyện không tồn tại hoặc chưa được phát hành công khai.',
            ], 404);
        }

        return new ComicDetailResource($comic);
    }
}
