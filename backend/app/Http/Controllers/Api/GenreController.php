<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GenreController extends Controller
{
    /**
     * Display a listing of active genres sorted alphabetically.
     */
    public function index(): AnonymousResourceCollection
    {
        $genres = Genre::query()
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return GenreResource::collection($genres);
    }
}
