<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexConversationsRequest;
use App\Http\Requests\MarkConversationReadRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ConversationService $conversationService
    ) {}

    /**
     * Get list of conversations for the authenticated user.
     * Implemented in Package 9.7.3B.
     */
    public function index(IndexConversationsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Conversation::class);

        $conversations = $this->conversationService->getConversationsForUser(
            $request->user(),
            $request->getPerPage(),
            (string) $request->input('tab', 'main')
        );

        return ConversationResource::collection($conversations);
    }

    /**
     * Find existing direct conversation with recipient, or return recipient details if no conversation exists yet.
     * Does NOT create any conversation in the database (lazy conversation flow).
     */
    public function findDirect(Request $request, int $userId): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $authUser = $request->user();
        if ($authUser->id === $userId) {
            return response()->json([
                'message' => 'Không thể trò chuyện với chính mình.',
            ], 422);
        }

        $recipient = \App\Models\User::find($userId);
        if (! $recipient || ! $recipient->is_active) {
            return response()->json([
                'message' => 'Người dùng không tồn tại hoặc tài khoản đã bị vô hiệu hóa.',
            ], 404);
        }

        $existing = $this->conversationService->findDirectConversation($authUser, $userId);

        if ($existing) {
            return (new ConversationResource($existing))
                ->response()
                ->setStatusCode(200);
        }

        return response()->json([
            'data' => null,
            'recipient' => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'avatar_path' => $recipient->avatar_path,
                'avatar_url' => $recipient->avatar_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($recipient->avatar_path)
                    : null,
                'role' => $recipient->role,
            ],
        ], 200);
    }

    /**
     * Create or retrieve direct conversation.
     * Implemented in Package 9.7.3B.
     */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $this->authorize('create', Conversation::class);

        $result = $this->conversationService->findOrCreateDirectConversation(
            $request->user(),
            (int) $request->validated('user_id')
        );

        return (new ConversationResource($result['conversation']))
            ->response()
            ->setStatusCode($result['is_new'] ? 201 : 200);
    }

    /**
     * View conversation detail.
     * Strictly IDOR-protected via ConversationPolicy@view.
     * Implemented in Package 9.7.3B.
     */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        $detailed = $this->conversationService->getConversationDetail($request->user(), $conversation);

        return new ConversationResource($detailed);
    }

    /**
     * Mark conversation messages as read.
     * Strictly IDOR-protected via ConversationPolicy@markAsRead.
     * Implemented in Package 9.7.3D.
     */
    public function markAsRead(MarkConversationReadRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('markAsRead', $conversation);

        $result = $this->conversationService->markAsRead(
            $request->user(),
            $conversation,
            (int) $request->validated('last_read_message_id')
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Get total unread message count for authenticated user across all conversations.
     * Implemented in Package 9.7.3D.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $result = $this->conversationService->getUnreadCountForUser($request->user());

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Get count of pending message requests for authenticated user.
     */
    public function requestsCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $count = $this->conversationService->getMessageRequestsCount($request->user());

        return response()->json([
            'data' => [
                'count' => $count,
            ],
        ]);
    }

    /**
     * Accept a pending message request.
     */
    public function accept(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $updated = $this->conversationService->acceptConversation($request->user(), $conversation);

        return response()->json([
            'message' => 'Đã chấp nhận cuộc trò chuyện.',
            'data' => new ConversationResource($updated),
        ]);
    }

    /**
     * Reject a pending message request.
     */
    public function reject(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $this->conversationService->rejectConversation($request->user(), $conversation);

        return response()->json([
            'message' => 'Đã từ chối tin nhắn chờ.',
        ]);
    }
}
