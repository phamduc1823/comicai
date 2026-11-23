<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class TagController extends Controller
{
    /**
     * Display a listing of all tags sorted alphabetically.
     * Caches result with safe fallback and Model Event invalidation.
     */
    public function index(): AnonymousResourceCollection
    {
        try {
            $tags = Cache::remember(Tag::CACHE_KEY, Tag::CACHE_TTL, function () {
                return Tag::query()
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();
            });
        } catch (\Throwable) {
            $tags = Tag::query()
                ->orderBy('name', 'asc')
                ->orderBy('id', 'asc')
                ->get();
        }

        return TagResource::collection($tags);
    }
}
