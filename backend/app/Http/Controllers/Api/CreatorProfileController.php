<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCreatorProfileRequest;
use App\Http\Resources\CreatorProfileResource;
use App\Models\CreatorProfile;
use App\Services\CreatorManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorProfileController extends Controller
{
    public function __construct(
        protected CreatorManagementService $service
    ) {}

    /**
     * Display the authenticated Creator's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = CreatorProfile::query()
            ->where('user_id', $request->user()->id)
            ->with('user')
            ->firstOrFail();

        return response()->json([
            'data' => new CreatorProfileResource($profile),
        ]);
    }

    /**
     * Update the Creator's pen name and/or bio.
     */
    public function update(UpdateCreatorProfileRequest $request): JsonResponse
    {
        $profile = $this->service->updateCreatorProfile($request->user(), $request->validated());
        $profile->load('user');

        return response()->json([
            'message' => 'Cập nhật thông tin Creator thành công.',
            'data' => new CreatorProfileResource($profile),
        ]);
    }
}
