<?php

namespace App\Services;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MessageService
{
    public function __construct(
        protected ?ConversationService $conversationService = null,
        protected ?NotificationService $notificationService = null
    ) {
        $this->conversationService = $conversationService ?? app(ConversationService::class);
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }
    /**
     * Get paginated messages for a conversation with cursor pagination.
     * Always returns messages in chronological ascending order (id asc).
     *
     * @param array{limit?: int, before_id?: int|null, after_id?: int|null} $filters
     * @return array{messages: Collection<int, Message>, limit: int, has_more: bool, next_cursor: int|null}
     */
    public function getMessages(User $user, Conversation $conversation, array $filters = []): array
    {
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 25;
        if ($limit < 1) {
            $limit = 25;
        } elseif ($limit > 50) {
            $limit = 50;
        }

        $beforeId = isset($filters['before_id']) ? (int) $filters['before_id'] : null;
        $afterId = isset($filters['after_id']) ? (int) $filters['after_id'] : null;

        // Strictly isolate query to the authorized conversation
        $query = Message::where('conversation_id', $conversation->id)
            ->with([
                'comic:id,title,slug,cover_path,author_name',
                'post.user:id,name,avatar_path',
                'post.comic:id,title,slug',
            ]);

        if ($afterId !== null) {
            // Case 1: Polling or fetching newer messages (id > after_id)
            $query->where('id', '>', $afterId)
                ->orderBy('id', 'asc')
                ->limit($limit + 1);

            $messages = $query->get();
            $hasMore = $messages->count() > $limit;

            if ($hasMore) {
                $messages = $messages->slice(0, $limit);
            }

            $nextCursor = $hasMore && $messages->isNotEmpty() ? $messages->last()->id : null;

            return [
                'messages' => $messages->values(),
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ];
        }

        if ($beforeId !== null) {
            // Case 2: Scrolling up / fetching older messages (id < before_id)
            $query->where('id', '<', $beforeId)
                ->orderBy('id', 'desc')
                ->limit($limit + 1);

            $messages = $query->get();
            $hasMore = $messages->count() > $limit;

            if ($hasMore) {
                $messages = $messages->slice(0, $limit);
            }

            // Reverse to ensure chronological ascending order in response
            $messages = $messages->reverse()->values();
            $nextCursor = $hasMore && $messages->isNotEmpty() ? $messages->first()->id : null;

            return [
                'messages' => $messages,
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ];
        }

        // Case 3: Initial conversation open (fetch latest messages)
        $query->orderBy('id', 'desc')
            ->limit($limit + 1);

        $messages = $query->get();
        $hasMore = $messages->count() > $limit;

        if ($hasMore) {
            $messages = $messages->slice(0, $limit);
        }

        // Reverse to ensure chronological ascending order in response
        $messages = $messages->reverse()->values();
        $nextCursor = $hasMore && $messages->isNotEmpty() ? $messages->first()->id : null;

        return [
            'messages' => $messages,
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Send a new message to the conversation with strict idempotency and atomic updates.
     *
     * @param array{body: string, type?: string, client_message_id?: string|null} $data
     * @return array{message: Message, status: int}
     */
    public function sendMessage(User $user, Conversation $conversation, array $data): array
    {
        $body = isset($data['body']) && $data['body'] !== null ? (string) $data['body'] : '';
        $clientMessageId = isset($data['client_message_id']) && is_string($data['client_message_id']) && trim($data['client_message_id']) !== ''
            ? trim($data['client_message_id'])
            : null;

        $comicId = isset($data['comic_id']) && $data['comic_id'] ? (int) $data['comic_id'] : null;
        $postId = isset($data['post_id']) && $data['post_id'] ? (int) $data['post_id'] : null;

        // Process image uploads if any
        $uploadedImages = [];
        if (isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $imageItem) {
                if ($imageItem instanceof \Illuminate\Http\UploadedFile) {
                    $path = $imageItem->store('chat-images', 'public');
                    $uploadedImages[] = [
                        'path' => $path,
                        'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
                        'name' => $imageItem->getClientOriginalName(),
                        'size' => $imageItem->getSize(),
                    ];
                } elseif (is_array($imageItem) && !empty($imageItem['path'])) {
                    $uploadedImages[] = $imageItem;
                }
            }
        }

        if (!isset($data['type']) || $data['type'] === 'text') {
            if (!empty($uploadedImages) && $comicId) {
                $type = 'mixed';
            } elseif (!empty($uploadedImages)) {
                $type = 'image';
            } elseif ($comicId) {
                $type = 'comic';
            } elseif ($postId) {
                $type = 'post';
            } else {
                $type = 'text';
            }
        } else {
            $type = (string) $data['type'];
        }

        // Idempotency pre-check if client_message_id is provided
        if ($clientMessageId !== null) {
            $existing = Message::where('conversation_id', $conversation->id)
                ->where('sender_id', $user->id)
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existing) {
                if ($existing->body === $body && $existing->type === $type) {
                    // Retry with same key & identical content -> HTTP 200 OK (idempotent replay)
                    $existing->load([
                        'comic:id,title,slug,cover_path,author_name',
                        'post.user:id,name,avatar_path',
                        'post.comic:id,title,slug',
                    ]);
                    return [
                        'message' => $existing,
                        'status' => 200,
                    ];
                }

                // Retry with same key but different content -> HTTP 409 Conflict
                throw new HttpException(409, 'Xung đột idempotency: client_message_id này đã tồn tại với nội dung khác.');
            }
        }

        // Create message and update conversation within a single database transaction
        try {
            $result = DB::transaction(function () use ($user, $conversation, $body, $type, $clientMessageId, $comicId, $postId, $uploadedImages) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'type' => $type,
                    'body' => $body,
                    'comic_id' => $comicId,
                    'post_id' => $postId,
                    'images' => !empty($uploadedImages) ? $uploadedImages : null,
                    'client_message_id' => $clientMessageId,
                ]);

                // Atomically update conversation last_message if this message is newer
                $updateData = [
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                ];

                // Auto-accept pending message request when recipient replies
                if ($conversation->status === 'pending' && $conversation->initiator_id && (int) $user->id !== (int) $conversation->initiator_id) {
                    $updateData['status'] = 'accepted';
                    $conversation->status = 'accepted';
                }

                Conversation::where('id', $conversation->id)
                    ->where(function ($query) use ($message) {
                        $query->whereNull('last_message_id')
                            ->orWhere('last_message_id', '<', $message->id);
                    })
                    ->update($updateData);

                $message->load([
                    'comic:id,title,slug,cover_path,author_name',
                    'post.user:id,name,avatar_path',
                    'post.comic:id,title,slug',
                ]);

                return $message;
            });

            // Broadcast events OUTSIDE transaction after successful commit
            try {
                broadcast(new MessageSent($result))->toOthers();

                $recipients = ConversationParticipant::where('conversation_id', $conversation->id)
                    ->where('user_id', '!=', $user->id)
                    ->get();

                foreach ($recipients as $recipient) {
                    $unreadCount = $this->conversationService->calculateUnreadCount($conversation, $recipient->user_id);
                    broadcast(new ConversationUpdated(
                        $conversation->id,
                        $recipient->user_id,
                        $result,
                        $unreadCount
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
            }

            return [
                'message' => $result,
                'status' => 201,
            ];
        } catch (QueryException $e) {
            // Check if duplicate client_message_id race condition occurred
            $isDuplicateKey = false;
            if ($clientMessageId !== null) {
                if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062 && str_contains($e->getMessage(), 'unique_client_message')) {
                    $isDuplicateKey = true; // MySQL
                } elseif (str_contains($e->getMessage(), 'unique_client_message') || str_contains($e->getMessage(), 'messages.conversation_id, messages.sender_id, messages.client_message_id')) {
                    $isDuplicateKey = true; // SQLite
                }
            }

            if ($isDuplicateKey) {
                $existing = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', $user->id)
                    ->where('client_message_id', $clientMessageId)
                    ->firstOrFail();

                if ($existing->body === $body && $existing->type === $type) {
                    $existing->load([
                        'comic:id,title,slug,cover_path,author_name',
                        'post.user:id,name,avatar_path',
                        'post.comic:id,title,slug',
                    ]);
                    return [
                        'message' => $existing,
                        'status' => 200,
                    ];
                }

                throw new HttpException(409, 'Xung đột idempotency: client_message_id này đã tồn tại với nội dung khác.');
            }

            // Strictly rethrow any other database exception
            throw $e;
        }
    }

    /**
     * Atomically create or find direct conversation and send the first message in a single transaction.
     * Guarantees that if message creation or validation fails, NO empty conversation is left in the DB.
     *
     * @param array{body: string, type?: string, client_message_id?: string|null} $data
     * @return array{message: Message, conversation: Conversation, status: int}
     */
    public function sendDirectMessage(User $user, int $recipientId, array $data): array
    {
        if ($user->id === $recipientId) {
            throw ValidationException::withMessages([
                'user_id' => 'Không thể tạo cuộc trò chuyện với chính mình.',
            ]);
        }

        $recipient = User::find($recipientId);
        if (! $recipient || ! $recipient->is_active) {
            abort(404, 'Người nhận không tồn tại hoặc tài khoản đã bị vô hiệu hóa.');
        }

        return DB::transaction(function () use ($user, $recipientId, $data) {
            // Find or create direct conversation atomically
            $convResult = $this->conversationService->findOrCreateDirectConversation($user, $recipientId);
            $conversation = $convResult['conversation'];

            // Send message within the same transaction (sendMessage updates last_message atomically)
            $msgResult = $this->sendMessage($user, $conversation, $data);

            return [
                'message' => $msgResult['message'],
                'conversation' => $conversation,
                'status' => $msgResult['status'],
            ];
        });
    }
}
