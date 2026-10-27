<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BannerController extends Controller
{
    /**
     * Display a listing of active banners for approved comics.
     * Prevents N+1 query and leaks of unapproved comics or sensitive fields.
     */
    public function index(): AnonymousResourceCollection
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->whereHas('comic', function ($query) {
                $query->where('approval_status', 'approved');
            })
            ->with(['comic' => function ($query) {
                $query->select(['id', 'title', 'slug', 'cover_path', 'approval_status']);
            }])
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return BannerResource::collection($banners);
    }
}
