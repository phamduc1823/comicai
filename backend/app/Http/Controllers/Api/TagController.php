<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
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
}
