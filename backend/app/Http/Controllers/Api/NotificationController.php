<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(IndexNotificationsRequest $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $this->notificationService->getNotificationsForUser(
            $user,
            $request->getPerPage(),
            $request->getUnreadOnly()
        );
        $unreadCount = $this->notificationService->getUnreadCount($user);

        return response()->json([
            'data' => NotificationResource::collection($notifications->items()),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Get unread notification count for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $unreadCount = $this->notificationService->getUnreadCount($request->user());

        return response()->json([
            'data' => [
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Mark a single notification as read (strictly scoped to the authenticated owner).
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $result = $this->notificationService->markAsRead($request->user(), $id);

        if ($result['status'] === 'invalid_id') {
            return response()->json([
                'status' => 'error',
                'message' => 'ID thông báo không hợp lệ.',
            ], 422);
        }

        if ($result['status'] === 'not_found') {
            return response()->json([
                'status' => 'error',
                'message' => 'Không tìm thấy thông báo.',
            ], 404);
        }

        if ($result['status'] === 'forbidden') {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn không có quyền thao tác với thông báo này.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => new NotificationResource($result['notification']),
            'meta' => [
                'unread_count' => $result['unread_count'],
            ],
        ]);
    }

    /**
     * Mark all unread notifications for the authenticated user as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $result = $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'status' => 'success',
            'data' => [
                'updated_count' => $result['updated_count'],
                'unread_count' => $result['unread_count'],
            ],
        ]);
    }
}
