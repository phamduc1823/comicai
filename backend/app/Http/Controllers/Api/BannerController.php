<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class BannerController extends Controller
{
    /**
     * Display a listing of active banners for approved comics.
     * Prevents N+1 query and leaks of unapproved comics or sensitive fields.
     * Caches serialized primitive array data with safe fallback and Model Event invalidation.
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        try {
            $banners = Cache::remember(Banner::CACHE_KEY, Banner::CACHE_TTL, function () {
                $items = Banner::query()
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

                return BannerResource::collection($items)->resolve();
            });

            // Guard against stale incomplete object cache from legacy serialization
            if (! is_array($banners)) {
                Cache::forget(Banner::CACHE_KEY);
                $items = Banner::query()
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

                $banners = BannerResource::collection($items)->resolve();
                Cache::put(Banner::CACHE_KEY, $banners, Banner::CACHE_TTL);
            }

            return response()->json(['data' => $banners]);
        } catch (\Throwable) {
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
}
