<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResubmitCreatorApplicationRequest;
use App\Http\Requests\SubmitCreatorApplicationRequest;
use App\Http\Resources\CreatorApplicationResource;
use App\Http\Resources\CreatorProfileResource;
use App\Models\CreatorApplication;
use App\Models\CreatorProfile;
use App\Services\CreatorManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatorApplicationController extends Controller
{
    public function __construct(
        protected CreatorManagementService $service
    ) {}

    /**
     * Get the authenticated user's current application and creator profile (if any).
     */
    public function myApplication(Request $request): JsonResponse
    {
        $user = $request->user();

        $application = CreatorApplication::query()
            ->where('user_id', $user->id)
            ->with([
                'histories' => fn ($q) => $q->orderBy('version', 'desc')->orderBy('id', 'desc'),
                'reviewer',
                'user',
            ])
            ->orderBy('id', 'desc')
            ->first();

        $profile = CreatorProfile::query()
            ->where('user_id', $user->id)
            ->with('user')
            ->first();

        return response()->json([
            'application' => $application ? new CreatorApplicationResource($application) : null,
            'profile' => $profile ? new CreatorProfileResource($profile) : null,
        ]);
    }

    /**
     * Submit a new creator application (first-time or re-application).
     */
    public function store(SubmitCreatorApplicationRequest $request): JsonResponse
    {
        $application = $this->service->submitApplication($request->user(), $request->validated());
        $application->load(['histories', 'user']);

        return response()->json([
            'message' => 'Hồ sơ đăng ký Creator đã được gửi thành công. Vui lòng chờ Admin xét duyệt.',
            'data' => new CreatorApplicationResource($application),
        ], 201);
    }

    /**
     * Cancel an active pending application.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $application = $this->service->cancelApplication($request->user(), $id);
        $application->load(['histories', 'user']);

        return response()->json([
            'message' => 'Đã hủy hồ sơ đăng ký Creator thành công.',
            'data' => new CreatorApplicationResource($application),
        ]);
    }

    /**
     * Resubmit a rejected application with corrections.
     */
    public function resubmit(ResubmitCreatorApplicationRequest $request, int $id): JsonResponse
    {
        $application = $this->service->resubmitApplication($request->user(), $id, $request->validated());
        $application->load(['histories', 'user']);

        return response()->json([
            'message' => 'Đã gửi lại hồ sơ đăng ký Creator thành công. Vui lòng chờ Admin xét duyệt.',
            'data' => new CreatorApplicationResource($application),
        ]);
    }
}
