<?php

namespace App\Services;

use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    /**
     * Get paginated conversations for the authenticated user with unread counts.
     */
    public function getConversationsForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $unreadCountSubquery = DB::table('messages')
            ->selectRaw('count(*)')
            ->join('conversation_participants', function ($join) use ($user) {
                $join->on('conversation_participants.conversation_id', '=', 'messages.conversation_id')
                    ->where('conversation_participants.user_id', '=', $user->id);
            })
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where('messages.sender_id', '!=', $user->id)
            ->where(function ($q) {
                $q->whereNull('conversation_participants.last_read_message_id')
                    ->orWhereColumn('messages.id', '>', 'conversation_participants.last_read_message_id');
            });

        return Conversation::query()
            ->select('conversations.*')
            ->selectSub($unreadCountSubquery, 'unread_count')
            ->whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->where(function ($q) {
                $q->whereNotNull('last_message_id')
                    ->orWhereNotNull('last_message_at')
                    ->orWhereHas('messages');
            })
            ->with([
                'participants.user:id,name,avatar_path,role',
                'lastMessage',
            ])
            ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END, last_message_at DESC, conversations.id DESC')
            ->paginate($perPage);
    }

    /**
     * Find existing direct conversation between two users without creating one.
     */
    public function findDirectConversation(User $authUser, int $recipientId): ?Conversation
    {
        if ($authUser->id === $recipientId) {
            return null;
        }

        $userOneId = min($authUser->id, $recipientId);
        $userTwoId = max($authUser->id, $recipientId);

        $existing = Conversation::where('type', 'direct')
            ->where('user_one_id', $userOneId)
            ->where('user_two_id', $userTwoId)
            ->with([
                'users:id,name,avatar_path,role',
                'participants.user:id,name,avatar_path,role',
                'lastMessage',
            ])
            ->first();

        if ($existing) {
            $existing->unread_count = $this->calculateUnreadCount($existing, $authUser->id);
            return $existing;
        }

        return null;
    }

    /**
     * Find existing direct conversation or create a new one safely.
     *
     * @return array{conversation: Conversation, is_new: bool}
     */
    public function findOrCreateDirectConversation(User $authUser, int $recipientId): array
    {
        if ($authUser->id === $recipientId) {
            throw ValidationException::withMessages([
                'user_id' => 'Không thể tạo cuộc trò chuyện với chính mình.',
            ]);
        }

        $recipient = User::find($recipientId);
        if (! $recipient) {
            throw ValidationException::withMessages([
                'user_id' => 'Người dùng được chọn không tồn tại trong hệ thống.',
            ]);
        }

        if (! $recipient->is_active) {
            throw ValidationException::withMessages([
                'user_id' => 'Tài khoản người nhận hiện không hoạt động hoặc đã bị vô hiệu hóa.',
            ]);
        }

        $userOneId = min($authUser->id, $recipientId);
        $userTwoId = max($authUser->id, $recipientId);

        // Pre-check for existing direct conversation
        $existing = Conversation::where('type', 'direct')
            ->where('user_one_id', $userOneId)
            ->where('user_two_id', $userTwoId)
            ->with([
                'participants.user:id,name,avatar_path,role',
                'lastMessage',
            ])
            ->first();

        if ($existing) {
            $existing->unread_count = $this->calculateUnreadCount($existing, $authUser->id);

            return [
                'conversation' => $existing,
                'is_new' => false,
            ];
        }

        // Create new direct conversation in database transaction
        try {
            $conversation = DB::transaction(function () use ($userOneId, $userTwoId) {
                $conv = Conversation::create([
                    'type' => 'direct',
                    'user_one_id' => $userOneId,
                    'user_two_id' => $userTwoId,
                ]);

                $conv->participants()->createMany([
                    ['user_id' => $userOneId],
                    ['user_id' => $userTwoId],
                ]);

                return $conv;
            });

            $conversation->load([
                'users:id,name,avatar_path,role',
                'participants.user:id,name,avatar_path,role',
                'lastMessage',
            ]);
            $conversation->unread_count = 0;

            return [
                'conversation' => $conversation,
                'is_new' => true,
            ];
        } catch (QueryException $e) {
            // Check if duplicate direct pair race condition occurred
            $isUniqueViolation = false;
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062 && str_contains($e->getMessage(), 'unique_direct_pair')) {
                $isUniqueViolation = true; // MySQL
            } elseif (str_contains($e->getMessage(), 'unique_direct_pair') || str_contains($e->getMessage(), 'conversations.user_one_id, conversations.user_two_id')) {
                $isUniqueViolation = true; // SQLite
            }

            if ($isUniqueViolation) {
                $existing = Conversation::where('type', 'direct')
                    ->where('user_one_id', $userOneId)
                    ->where('user_two_id', $userTwoId)
                    ->with([
                        'users:id,name,avatar_path,role',
                        'participants.user:id,name,avatar_path,role',
                        'lastMessage',
                    ])
                    ->firstOrFail();

                $existing->unread_count = $this->calculateUnreadCount($existing, $authUser->id);

                return [
                    'conversation' => $existing,
                    'is_new' => false,
                ];
            }

            throw $e;
        }
    }

    /**
     * Get detailed information for a single conversation.
     */
    public function getConversationDetail(User $user, Conversation $conversation): Conversation
    {
        $conversation->loadMissing([
            'users:id,name,avatar_path,role',
            'participants.user:id,name,avatar_path,role',
            'lastMessage',
        ]);

        $conversation->unread_count = $this->calculateUnreadCount($conversation, $user->id);

        return $conversation;
    }

    /**
     * Calculate unread messages count for a specific user in a conversation.
     */
    public function calculateUnreadCount(Conversation $conversation, int $userId): int
    {
        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if (! $participant) {
            return 0;
        }

        $query = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $userId);

        if ($participant->last_read_message_id !== null) {
            $query->where('id', '>', (int) $participant->last_read_message_id);
        }

        return $query->count();
    }

    /**
     * Mark conversation as read up to a specific message ID with monotonic guarantee.
     *
     * @return array{conversation_id: int, last_read_message_id: int|null, last_read_at: string|null}
     */
    public function markAsRead(User $user, Conversation $conversation, int $lastReadMessageId): array
    {
        // 1. Verify message exists and strictly belongs to this conversation
        $message = Message::where('conversation_id', $conversation->id)
            ->where('id', $lastReadMessageId)
            ->first();

        if (! $message) {
            throw ValidationException::withMessages([
                'last_read_message_id' => 'Tin nhắn không tồn tại hoặc không thuộc cuộc trò chuyện này.',
            ]);
        }

        // 2. Monotonic update: only move forward, never backward
        $now = now();
        $affected = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where(function ($query) use ($lastReadMessageId) {
                $query->whereNull('last_read_message_id')
                    ->orWhere('last_read_message_id', '<', $lastReadMessageId);
            })
            ->update([
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at' => $now,
            ]);

        // 3. Return the actual state from DB
        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // 4. Broadcast MessagesRead ONLY if read state actually changed
        if ($affected > 0) {
            try {
                broadcast(new MessagesRead(
                    $conversation->id,
                    $user->id,
                    (int) $participant->last_read_message_id,
                    $participant->last_read_at?->toISOString()
                ))->toOthers();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'conversation_id' => $conversation->id,
            'last_read_message_id' => $participant->last_read_message_id,
            'last_read_at' => $participant->last_read_at?->toISOString(),
        ];
    }

    /**
     * Get aggregate unread messages count across all conversations for authenticated user.
     *
     * @return array{total_unread: int, conversations: list<array{conversation_id: int, unread_count: int}>}
     */
    public function getUnreadCountForUser(User $user): array
    {
        $unreadRows = DB::table('conversation_participants')
            ->join('messages', function ($join) {
                $join->on('messages.conversation_id', '=', 'conversation_participants.conversation_id')
                    ->whereColumn('messages.sender_id', '!=', 'conversation_participants.user_id')
                    ->where(function ($q) {
                        $q->whereNull('conversation_participants.last_read_message_id')
                            ->orWhereColumn('messages.id', '>', 'conversation_participants.last_read_message_id');
                    });
            })
            ->where('conversation_participants.user_id', $user->id)
            ->groupBy('conversation_participants.conversation_id')
            ->select([
                'conversation_participants.conversation_id',
                DB::raw('count(messages.id) as unread_count'),
            ])
            ->get();

        $conversations = $unreadRows->map(function ($row) {
            return [
                'conversation_id' => (int) $row->conversation_id,
                'unread_count' => (int) $row->unread_count,
            ];
        })->values();

        $totalUnread = (int) $conversations->sum('unread_count');

        return [
            'total_unread' => $totalUnread,
            'conversations' => $conversations->all(),
        ];
    }
}
