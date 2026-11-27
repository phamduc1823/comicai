<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class TagController extends Controller
{
    /**
     * Display a listing of all tags sorted alphabetically.
     * Caches serialized primitive array data with safe fallback and Model Event invalidation.
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        try {
            $tags = Cache::remember(Tag::CACHE_KEY, Tag::CACHE_TTL, function () {
                $items = Tag::query()
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                return TagResource::collection($items)->resolve();
            });

            // Guard against stale incomplete object cache from legacy serialization
            if (! is_array($tags)) {
                Cache::forget(Tag::CACHE_KEY);
                $items = Tag::query()
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $tags = TagResource::collection($items)->resolve();
                Cache::put(Tag::CACHE_KEY, $tags, Tag::CACHE_TTL);
            }

            return response()->json(['data' => $tags]);
        } catch (\Throwable) {
            $tags = Tag::query()
                ->orderBy('name', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return TagResource::collection($tags);
        }
    }

    /**
     * Autocomplete suggestions for tags.
     * Searches prefix matches first, then substring matches.
     * Supports excluding already selected tag IDs.
     * Max 10 items.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ]);
        }

        $excludeParam = $request->input('exclude', '');
        $excludeIds = [];
        if (is_array($excludeParam)) {
            $excludeIds = array_map('intval', $excludeParam);
        } elseif (is_string($excludeParam) && $excludeParam !== '') {
            $excludeIds = array_filter(array_map('intval', explode(',', $excludeParam)));
        }

        $tags = TagService::suggestTags($q, null, $excludeIds, 10);

        return response()->json([
            'status' => 'success',
            'data' => TagResource::collection($tags),
        ]);
    }

    /**
     * Store or retrieve a community tag.
     * Available to authenticated creators/admins.
     * Reuses existing tag if already present after normalization.
     */
    public function storeCommunityTag(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ], [
            'name.required' => 'Vui lòng nhập tên tag.',
            'name.min' => 'Tag phải chứa ít nhất 2 ký tự.',
            'name.max' => 'Tag không được vượt quá 100 ký tự.',
        ]);

        try {
            $result = TagService::createOrGetCommunityTag($validated['name']);

            return response()->json([
                'status' => 'success',
                'message' => $result['is_new'] ? 'Tạo tag cộng đồng mới thành công.' : 'Sử dụng tag hiện có.',
                'data' => new TagResource($result['tag']),
                'is_new' => $result['is_new'],
            ], $result['is_new'] ? 201 : 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => [
                    'name' => [$e->getMessage()],
                ],
            ], 422);
        }
    }
}
