<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareMessageRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatShareController extends Controller
{
    public function __construct(
        protected ConversationService $conversationService,
        protected MessageService $messageService
    ) {}

    /**
     * Get list of eligible recipients for sharing.
     * Only returns users who have an accepted direct conversation with the authenticated user.
     */
    public function getRecipients(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->where('type', 'direct')
            ->where(function ($q) {
                $q->where('status', 'accepted')
                  ->orWhereNull('status');
            })
            ->whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with(['participants.user:id,name,avatar_path,role'])
            ->get();

        $recipients = $conversations->map(function ($conv) use ($user) {
            $partner = $conv->participants->firstWhere('user_id', '!=', $user->id)?->user;
            if (!$partner) {
                return null;
            }

            return [
                'id' => (int) $partner->id,
                'name' => (string) $partner->name,
                'avatar_url' => $partner->avatar_path ? Storage::disk('public')->url($partner->avatar_path) : null,
                'role' => (string) $partner->role,
                'conversation_id' => (int) $conv->id,
            ];
        })->filter()->unique('id')->values();

        return response()->json([
            'data' => $recipients,
        ]);
    }

    /**
     * Share a comic or community post to one or multiple accepted contacts via separate direct messages.
     */
    public function share(ShareMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $recipientIds = array_map('intval', (array) $request->input('recipient_ids', []));
        $comicId = $request->input('comic_id') ? (int) $request->input('comic_id') : null;
        $postId = $request->input('post_id') ? (int) $request->input('post_id') : null;
        $body = (string) $request->input('body', '');

        // Fetch accepted direct conversations with these recipients
        $conversations = Conversation::query()
            ->where('type', 'direct')
            ->where(function ($q) {
                $q->where('status', 'accepted')
                  ->orWhereNull('status');
            })
            ->whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with(['participants'])
            ->get();

        $validRecipientMap = [];
        foreach ($conversations as $conv) {
            $otherParticipant = $conv->participants->firstWhere('user_id', '!=', $user->id);
            if ($otherParticipant) {
                $validRecipientMap[$otherParticipant->user_id] = $conv;
            }
        }

        // Verify every chosen recipient has an accepted conversation
        $unauthorizedRecipients = array_diff($recipientIds, array_keys($validRecipientMap));
        if (!empty($unauthorizedRecipients)) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'Chỉ có thể chia sẻ cho người dùng đã có cuộc trò chuyện được chấp nhận.',
            ]);
        }

        $sentCount = 0;
        $messageType = $comicId ? 'comic' : 'post';

        foreach ($recipientIds as $recipientId) {
            $conv = $validRecipientMap[$recipientId];

            $this->messageService->sendMessage($user, $conv, [
                'body' => $body,
                'type' => $messageType,
                'comic_id' => $comicId,
                'post_id' => $postId,
            ]);

            $sentCount++;
        }

        return response()->json([
            'message' => 'Đã chia sẻ thành công.',
            'sent_count' => $sentCount,
        ]);
    }
}
