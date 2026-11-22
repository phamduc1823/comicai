<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMessagesRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\MessageService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected MessageService $messageService
    ) {}

    /**
     * Get paginated messages for a conversation.
     * Strictly IDOR-protected via ConversationPolicy@view.
     * Implemented in Package 9.7.3C.
     */
    public function index(IndexMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $result = $this->messageService->getMessages(
            $request->user(),
            $conversation,
            [
                'limit' => $request->getLimit(),
                'before_id' => $request->validated('before_id'),
                'after_id' => $request->validated('after_id'),
            ]
        );

        return response()->json([
            'data' => MessageResource::collection($result['messages']),
            'meta' => [
                'limit' => $result['limit'],
                'has_more' => $result['has_more'],
                'next_cursor' => $result['next_cursor'],
            ],
        ]);
    }

    /**
     * Send a new message to a conversation.
     * Strictly IDOR-protected via ConversationPolicy@sendMessage.
     * Implemented in Package 9.7.3C.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $result = $this->messageService->sendMessage(
            $request->user(),
            $conversation,
            $request->validated()
        );

        return (new MessageResource($result['message']))
            ->response()
            ->setStatusCode($result['status']);
    }
}
