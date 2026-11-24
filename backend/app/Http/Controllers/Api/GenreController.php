<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class GenreController extends Controller
{
    /**
     * Display a listing of active genres sorted alphabetically.
     * Caches serialized primitive array data with safe fallback and Model Event invalidation.
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        try {
            $genres = Cache::remember(Genre::CACHE_KEY, Genre::CACHE_TTL, function () {
                $items = Genre::query()
                    ->where('is_active', true)
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                return GenreResource::collection($items)->resolve();
            });

            // Guard against stale incomplete object cache from legacy serialization
            if (! is_array($genres)) {
                Cache::forget(Genre::CACHE_KEY);
                $items = Genre::query()
                    ->where('is_active', true)
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $genres = GenreResource::collection($items)->resolve();
                Cache::put(Genre::CACHE_KEY, $genres, Genre::CACHE_TTL);
            }

            return response()->json(['data' => $genres]);
        } catch (\Throwable) {
            $genres = Genre::query()
                ->where('is_active', true)
                ->orderBy('name', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return GenreResource::collection($genres);
        }
    }
}
