<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SystemBroadcastNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class AdminBroadcastNotificationController extends Controller
{
    /**
     * Dispatch a system-wide broadcast notification to target users.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
            'broadcast_type' => ['nullable', 'string', Rule::in(['announcement', 'maintenance', 'promotion', 'update'])],
            'action_url' => ['nullable', 'string', 'max:255'],
            'target_audience' => ['required', 'string', Rule::in(['all', 'readers', 'creators'])],
        ]);

        $admin = $request->user();
        $broadcastType = $validated['broadcast_type'] ?? 'announcement';
        $actionUrl = $validated['action_url'] ?? '/';

        $query = User::where('is_active', true);
        if ($validated['target_audience'] === 'readers') {
            $query->where('role', 'reader');
        } elseif ($validated['target_audience'] === 'creators') {
            $query->where('role', 'creator');
        }

        $notification = new SystemBroadcastNotification(
            $validated['title'],
            $validated['message'],
            $broadcastType,
            $actionUrl,
            $admin
        );

        $recipientsCount = 0;
        $query->chunk(100, function ($users) use ($notification, &$recipientsCount) {
            Notification::send($users, $notification);
            $recipientsCount += $users->count();
        });

        return response()->json([
            'status' => 'success',
            'message' => "Đã gửi thông báo hệ thống thành công đến {$recipientsCount} người dùng.",
            'data' => [
                'title' => $validated['title'],
                'target_audience' => $validated['target_audience'],
                'recipients_count' => $recipientsCount,
                'sent_at' => now()->toISOString(),
            ],
        ], 200);
    }

    /**
     * List recent broadcast notifications history.
     */
    public function history(Request $request): JsonResponse
    {
        $query = DatabaseNotification::query()
            ->where('type', SystemBroadcastNotification::class)
            ->latest('created_at');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }
}
