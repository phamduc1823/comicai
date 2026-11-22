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
            $request->getPerPage()
        );

        return ConversationResource::collection($conversations);
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
}
