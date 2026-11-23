<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class GenreController extends Controller
{
    /**
     * Display a listing of active genres sorted alphabetically.
     * Caches result with safe fallback and Model Event invalidation.
     */
    public function index(): AnonymousResourceCollection
    {
        try {
            $genres = Cache::remember(Genre::CACHE_KEY, Genre::CACHE_TTL, function () {
                return Genre::query()
                    ->where('is_active', true)
                    ->orderBy('name', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();
            });
        } catch (\Throwable) {
            $genres = Genre::query()
                ->where('is_active', true)
                ->orderBy('name', 'asc')
                ->orderBy('id', 'asc')
                ->get();
        }

        return GenreResource::collection($genres);
    }
}
