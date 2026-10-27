<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    /**
     * Display a listing of all tags sorted alphabetically.
     */
    public function index(): AnonymousResourceCollection
    {
        $tags = Tag::query()
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return TagResource::collection($tags);
    }
}
