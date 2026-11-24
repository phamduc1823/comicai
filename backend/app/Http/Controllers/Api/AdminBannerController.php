<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderAdminBannersRequest;
use App\Http\Requests\StoreAdminBannerRequest;
use App\Http\Requests\UpdateAdminBannerRequest;
use App\Http\Resources\AdminBannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminBannerController extends Controller
{
    /**
     * Display a listing of all banners for Admin management.
     */
    public function index(): AnonymousResourceCollection
    {
        $banners = Banner::query()
            ->with(['comic' => function ($query) {
                $query->select(['id', 'title', 'slug', 'cover_path', 'approval_status']);
            }])
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return AdminBannerResource::collection($banners);
    }

    /**
     * Store a newly created banner with image uploads.
     */
    public function store(StoreAdminBannerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $uploadedFiles = [];

        try {
            // 1. Upload Desktop Banner (required)
            $desktopFile = $request->file('desktop_banner');
            $desktopExt = $desktopFile->guessExtension() ?: 'webp';
            $desktopFilename = Str::random(40) . '.' . $desktopExt;
            $desktopPath = $desktopFile->storeAs('banners/desktop', $desktopFilename, 'public');
            $uploadedFiles[] = $desktopPath;

            // 2. Upload Mobile Banner (optional)
            $mobilePath = null;
            if ($request->hasFile('mobile_banner')) {
                $mobileFile = $request->file('mobile_banner');
                $mobileExt = $mobileFile->guessExtension() ?: 'webp';
                $mobileFilename = Str::random(40) . '.' . $mobileExt;
                $mobilePath = $mobileFile->storeAs('banners/mobile', $mobileFilename, 'public');
                $uploadedFiles[] = $mobilePath;
            }

            // 3. Database Persistence inside transaction
            $banner = DB::transaction(function () use ($validated, $desktopPath, $mobilePath) {
                $banner = new Banner();
                $banner->comic_id = $validated['comic_id'];
                $banner->title_override = $validated['title_override'] ?? null;
                $banner->desktop_banner_path = $desktopPath;
                $banner->mobile_banner_path = $mobilePath;
                $banner->sort_order = isset($validated['sort_order']) ? (int) $validated['sort_order'] : 0;
                $banner->is_active = isset($validated['is_active']) ? (bool) $validated['is_active'] : true;
                $banner->save();

                return $banner;
            });

            Banner::flushCache();

            $banner->load(['comic' => function ($query) {
                $query->select(['id', 'title', 'slug', 'cover_path', 'approval_status']);
            }]);

            return response()->json([
                'status' => 'success',
                'message' => 'Tạo banner mới thành công.',
                'data' => new AdminBannerResource($banner),
            ], 201);
        } catch (\Throwable $e) {
            // Clean up any newly uploaded files on failure
            foreach ($uploadedFiles as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            throw $e;
        }
    }

    /**
     * Update an existing banner and manage optional new image uploads.
     */
    public function update(UpdateAdminBannerRequest $request, Banner $banner): JsonResponse
    {
        $validated = $request->validated();

        $newUploadedFiles = [];
        $oldDesktopPath = $banner->desktop_banner_path;
        $oldMobilePath = $banner->mobile_banner_path;

        try {
            $newDesktopPath = null;
            if ($request->hasFile('desktop_banner')) {
                $desktopFile = $request->file('desktop_banner');
                $desktopExt = $desktopFile->guessExtension() ?: 'webp';
                $desktopFilename = Str::random(40) . '.' . $desktopExt;
                $newDesktopPath = $desktopFile->storeAs('banners/desktop', $desktopFilename, 'public');
                $newUploadedFiles[] = $newDesktopPath;
            }

            $newMobilePath = null;
            if ($request->hasFile('mobile_banner')) {
                $mobileFile = $request->file('mobile_banner');
                $mobileExt = $mobileFile->guessExtension() ?: 'webp';
                $mobileFilename = Str::random(40) . '.' . $mobileExt;
                $newMobilePath = $mobileFile->storeAs('banners/mobile', $mobileFilename, 'public');
                $newUploadedFiles[] = $newMobilePath;
            }

            DB::transaction(function () use ($banner, $validated, $newDesktopPath, $newMobilePath) {
                if (isset($validated['comic_id'])) {
                    $banner->comic_id = $validated['comic_id'];
                }
                if (array_key_exists('title_override', $validated)) {
                    $banner->title_override = $validated['title_override'];
                }
                if ($newDesktopPath !== null) {
                    $banner->desktop_banner_path = $newDesktopPath;
                }
                if ($newMobilePath !== null) {
                    $banner->mobile_banner_path = $newMobilePath;
                }
                if (isset($validated['sort_order'])) {
                    $banner->sort_order = (int) $validated['sort_order'];
                }
                if (isset($validated['is_active'])) {
                    $banner->is_active = (bool) $validated['is_active'];
                }

                $banner->save();
            });

            // Clean up old files only if replaced and safe
            if ($newDesktopPath && $oldDesktopPath && $oldDesktopPath !== $newDesktopPath) {
                $this->safeDeleteFile($oldDesktopPath, $banner->id);
            }
            if ($newMobilePath && $oldMobilePath && $oldMobilePath !== $newMobilePath) {
                $this->safeDeleteFile($oldMobilePath, $banner->id);
            }

            Banner::flushCache();

            $banner->load(['comic' => function ($query) {
                $query->select(['id', 'title', 'slug', 'cover_path', 'approval_status']);
            }]);

            return response()->json([
                'status' => 'success',
                'message' => 'Cập nhật banner thành công.',
                'data' => new AdminBannerResource($banner),
            ]);
        } catch (\Throwable $e) {
            // Clean up newly uploaded files on transaction error
            foreach ($newUploadedFiles as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            throw $e;
        }
    }

    /**
     * Delete an existing banner and safely clean up associated images.
     */
    public function destroy(Banner $banner): JsonResponse
    {
        $desktopPath = $banner->desktop_banner_path;
        $mobilePath = $banner->mobile_banner_path;
        $bannerId = $banner->id;

        DB::transaction(function () use ($banner) {
            $banner->delete();
        });

        if ($desktopPath) {
            $this->safeDeleteFile($desktopPath, $bannerId);
        }
        if ($mobilePath) {
            $this->safeDeleteFile($mobilePath, $bannerId);
        }

        Banner::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Xóa banner thành công.',
        ]);
    }

    /**
     * Toggle or explicitly update active status of a banner.
     */
    public function toggleStatus(Request $request, Banner $banner): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('is_active', $validated)) {
            $banner->is_active = (bool) $validated['is_active'];
        } else {
            $banner->is_active = ! $banner->is_active;
        }

        $banner->save();
        Banner::flushCache();

        $banner->load(['comic' => function ($query) {
            $query->select(['id', 'title', 'slug', 'cover_path', 'approval_status']);
        }]);

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật trạng thái banner thành công.',
            'data' => new AdminBannerResource($banner),
        ]);
    }

    /**
     * Batch update sort orders of banners.
     */
    public function reorder(ReorderAdminBannersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated) {
            foreach ($validated['orders'] as $item) {
                Banner::where('id', $item['id'])->update([
                    'sort_order' => (int) $item['sort_order'],
                ]);
            }
        });

        Banner::flushCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Cập nhật thứ tự banner thành công.',
        ]);
    }

    /**
     * Safely delete an image file from storage:
     * - Protects against deleting placeholder files.
     * - Ensures no other banner record still references the same file path.
     */
    private function safeDeleteFile(string $path, int $excludeBannerId): void
    {
        if (str_starts_with($path, 'placeholders/')) {
            return;
        }

        $isStillUsed = Banner::query()
            ->where('id', '!=', $excludeBannerId)
            ->where(function ($query) use ($path) {
                $query->where('desktop_banner_path', $path)
                    ->orWhere('mobile_banner_path', $path);
            })
            ->exists();

        if (! $isStillUsed && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
