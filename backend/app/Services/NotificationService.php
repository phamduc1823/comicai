<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\CreatorApplication;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CreatorApplicationApprovedNotification;
use App\Notifications\CreatorApplicationRejectedNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\PostCommentedNotification;
use App\Notifications\PostLikedNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Notification types strictly displayed in the Notification Bell.
     * CAI-MSG-04: Excludes message and chat notifications from the bell.
     * CAI-CREATOR-01: Includes creator application approval and rejection.
     */
    public const BELL_NOTIFICATION_TYPES = [
        PostLikedNotification::class,
        PostCommentedNotification::class,
        CreatorApplicationApprovedNotification::class,
        CreatorApplicationRejectedNotification::class,
    ];
    /**
     * Format a DatabaseNotification model into a strictly whitelisted API/broadcast payload.
     *
     * @return array<string, mixed>
     */
    public static function formatNotification(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        $actor = is_array($data['actor'] ?? null) ? [
            'id' => isset($data['actor']['id']) ? (int) $data['actor']['id'] : null,
            'name' => (string) ($data['actor']['name'] ?? 'Người dùng'),
            'avatar_path' => $data['actor']['avatar_path'] ?? null,
            'avatar_url' => $data['actor']['avatar_url'] ?? null,
            'role' => (string) ($data['actor']['role'] ?? 'reader'),
        ] : null;

        $target = is_array($data['target'] ?? null) ? [
            'type' => (string) ($data['target']['type'] ?? ''),
            'id' => isset($data['target']['id']) ? (int) $data['target']['id'] : null,
            'message_id' => isset($data['target']['message_id']) ? (int) $data['target']['message_id'] : null,
            'url' => (string) ($data['target']['url'] ?? '/'),
        ] : null;

        return [
            'id' => (string) $notification->id,
            'type' => (string) ($data['kind'] ?? class_basename((string) $notification->type)),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'actor' => $actor,
            'target' => $target,
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    /**
     * Create database notifications and dispatch realtime events for a newly saved message.
     * Skips the sender and inactive users. Enforces database-level deduplication per message.
     *
     * @param array<int, int> $recipientIds
     */
    public function notifyNewMessage(
        Message $message,
        User $sender,
        Conversation $conversation,
        array $recipientIds
    ): void {
        $filteredIds = array_values(array_unique(array_filter(
            array_map('intval', $recipientIds),
            fn (int $id) => $id > 0 && $id !== (int) $sender->id
        )));

        if (empty($filteredIds)) {
            return;
        }

        // Load active recipient IDs in a single query (zero N+1)
        $activeRecipients = User::whereIn('id', $filteredIds)
            ->where('is_active', true)
            ->get(['id']);

        if ($activeRecipients->isEmpty()) {
            return;
        }

        $notificationObj = new NewMessageNotification($message, $sender, $conversation);
        $payload = $notificationObj->toPayload();
        $dedupeKey = $notificationObj->dedupeKey();

        foreach ($activeRecipients as $recipient) {
            $record = $this->createDeduplicatedNotification(
                recipient: $recipient,
                typeClass: NewMessageNotification::class,
                data: $payload,
                dedupeKey: $dedupeKey
            );

            if ($record) {
                $this->broadcastNotificationCreated($recipient, $record);
            }
        }
    }

    /**
     * Create a database notification and dispatch realtime event when a community post is liked.
     * Skips self-likes, inactive authors, and duplicates.
     */
    public function notifyPostLiked(Post $post, User $liker): ?DatabaseNotification
    {
        if ((int) $post->user_id === (int) $liker->id) {
            return null;
        }

        $author = User::where('id', $post->user_id)
            ->where('is_active', true)
            ->first(['id']);

        if (! $author) {
            return null;
        }

        $notificationObj = new PostLikedNotification($post, $liker);

        $record = $this->createDeduplicatedNotification(
            recipient: $author,
            typeClass: PostLikedNotification::class,
            data: $notificationObj->toPayload(),
            dedupeKey: $notificationObj->dedupeKey()
        );

        if ($record) {
            $this->broadcastNotificationCreated($author, $record);
        }

        return $record;
    }

    /**
     * Create a database notification and dispatch realtime event when a comment is created.
     * Skips self-comments/replies and inactive authors.
     */
    public function notifyCommentCreated(Comment $comment, User $commenter): void
    {
        $post = null;
        $comic = null;
        $chapter = null;
        $rootAuthorId = null;

        // 1. If it's a comment on a Post, notify the Post author
        if ($comment->commentable_type === 'post') {
            $post = Post::find($comment->commentable_id);
            if ($post) {
                $rootAuthorId = (int) $post->user_id;
                if ($rootAuthorId !== (int) $commenter->id) {
                    $postAuthor = User::where('id', $rootAuthorId)
                        ->where('is_active', true)
                        ->first();

                    if ($postAuthor) {
                        $notif = new PostCommentedNotification($comment, $commenter, post: $post, isReply: false);
                        $record = $this->createDeduplicatedNotification(
                            recipient: $postAuthor,
                            typeClass: PostCommentedNotification::class,
                            data: $notif->toPayload(),
                            dedupeKey: $notif->dedupeKey()
                        );
                        if ($record) {
                            $this->broadcastNotificationCreated($postAuthor, $record);
                        }
                    }
                }
            }
        } elseif ($comment->commentable_type === 'comic') {
            // 2. If it's a comment on a Comic, notify the Comic creator
            $comic = Comic::find($comment->commentable_id);
            if ($comic) {
                $rootAuthorId = (int) $comic->user_id;
                if ($rootAuthorId !== (int) $commenter->id) {
                    $comicAuthor = User::where('id', $rootAuthorId)
                        ->where('is_active', true)
                        ->first();

                    if ($comicAuthor) {
                        $notif = new PostCommentedNotification($comment, $commenter, isReply: false, comic: $comic);
                        $record = $this->createDeduplicatedNotification(
                            recipient: $comicAuthor,
                            typeClass: PostCommentedNotification::class,
                            data: $notif->toPayload(),
                            dedupeKey: $notif->dedupeKey()
                        );
                        if ($record) {
                            $this->broadcastNotificationCreated($comicAuthor, $record);
                        }
                    }
                }
            }
        } elseif ($comment->commentable_type === 'chapter') {
            // 3. If it's a comment on a Chapter, notify the Comic creator
            $chapter = Chapter::with('comic')->find($comment->commentable_id);
            if ($chapter && $chapter->comic) {
                $rootAuthorId = (int) $chapter->comic->user_id;
                if ($rootAuthorId !== (int) $commenter->id) {
                    $comicAuthor = User::where('id', $rootAuthorId)
                        ->where('is_active', true)
                        ->first();

                    if ($comicAuthor) {
                        $notif = new PostCommentedNotification($comment, $commenter, isReply: false, chapter: $chapter);
                        $record = $this->createDeduplicatedNotification(
                            recipient: $comicAuthor,
                            typeClass: PostCommentedNotification::class,
                            data: $notif->toPayload(),
                            dedupeKey: $notif->dedupeKey()
                        );
                        if ($record) {
                            $this->broadcastNotificationCreated($comicAuthor, $record);
                        }
                    }
                }
            }
        }

        // 4. If it's a reply to another comment, notify the parent comment author
        if ($comment->parent_id !== null) {
            $parent = Comment::find($comment->parent_id);
            if ($parent && (int) $parent->user_id !== (int) $commenter->id) {
                // Avoid duplicate notification if parent author is also the root target author (already notified above)
                $alreadyNotified = $rootAuthorId !== null && (int) $parent->user_id === $rootAuthorId;
                if (! $alreadyNotified) {
                    $parentAuthor = User::where('id', $parent->user_id)
                        ->where('is_active', true)
                        ->first();

                    if ($parentAuthor) {
                        $notif = new PostCommentedNotification(
                            $comment,
                            $commenter,
                            post: $post,
                            isReply: true,
                            comic: $comic,
                            chapter: $chapter
                        );
                        $record = $this->createDeduplicatedNotification(
                            recipient: $parentAuthor,
                            typeClass: PostCommentedNotification::class,
                            data: $notif->toPayload(),
                            dedupeKey: $notif->dedupeKey() . ':reply'
                        );
                        if ($record) {
                            $this->broadcastNotificationCreated($parentAuthor, $record);
                        }
                    }
                }
            }
        }
    }

    /**
     * Notify applicant that their creator application has been approved.
     * Enforces deduplication by application ID and version.
     */
    public function notifyCreatorApplicationApproved(CreatorApplication $application, User $admin): ?DatabaseNotification
    {
        $applicant = User::where('id', $application->user_id)
            ->where('is_active', true)
            ->first();

        if (! $applicant) {
            return null;
        }

        $notificationObj = new CreatorApplicationApprovedNotification($application, $admin);

        $record = $this->createDeduplicatedNotification(
            recipient: $applicant,
            typeClass: CreatorApplicationApprovedNotification::class,
            data: $notificationObj->toPayload(),
            dedupeKey: $notificationObj->dedupeKey()
        );

        if ($record) {
            $this->broadcastNotificationCreated($applicant, $record);
        }

        return $record;
    }

    /**
     * Notify applicant that their creator application has been rejected.
     * Enforces deduplication by application ID and version.
     */
    public function notifyCreatorApplicationRejected(CreatorApplication $application, User $admin, string $reason): ?DatabaseNotification
    {
        $applicant = User::where('id', $application->user_id)
            ->where('is_active', true)
            ->first();

        if (! $applicant) {
            return null;
        }

        $notificationObj = new CreatorApplicationRejectedNotification($application, $admin, $reason);

        $record = $this->createDeduplicatedNotification(
            recipient: $applicant,
            typeClass: CreatorApplicationRejectedNotification::class,
            data: $notificationObj->toPayload(),
            dedupeKey: $notificationObj->dedupeKey()
        );

        if ($record) {
            $this->broadcastNotificationCreated($applicant, $record);
        }

        return $record;
    }

    /**
     * Get paginated notifications for the authenticated user, newest first.
     */
    public function getNotificationsForUser(User $user, int $perPage = 15, bool $unreadOnly = false): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 50);

        $query = $user->notifications()
            ->whereIn('type', self::BELL_NOTIFICATION_TYPES)
            ->select(['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get exact unread notification count using a single indexed SQL COUNT(*).
     */
    public function getUnreadCount(User $user): int
    {
        return (int) $user->unreadNotifications()
            ->whereIn('type', self::BELL_NOTIFICATION_TYPES)
            ->count();
    }

    /**
     * Mark a single notification as read with strict ownership enforcement.
     *
     * @return array{status: 'ok'|'invalid_id'|'not_found'|'forbidden', notification?: DatabaseNotification, unread_count?: int}
     */
    public function markAsRead(User $user, string $notificationId): array
    {
        if (! Str::isUuid($notificationId)) {
            return ['status' => 'invalid_id'];
        }

        $notification = DatabaseNotification::find($notificationId);

        if (! $notification) {
            return ['status' => 'not_found'];
        }

        if (
            (int) $notification->notifiable_id !== (int) $user->id ||
            $notification->notifiable_type !== $user->getMorphClass()
        ) {
            return ['status' => 'forbidden'];
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            $notification->refresh();
        }

        return [
            'status' => 'ok',
            'notification' => $notification,
            'unread_count' => $this->getUnreadCount($user),
        ];
    }

    /**
     * Mark all unread notifications for the authenticated user as read in a single SQL UPDATE.
     *
     * @return array{updated_count: int, unread_count: int}
     */
    public function markAllAsRead(User $user): array
    {
        $updatedCount = $user->unreadNotifications()
            ->whereIn('type', self::BELL_NOTIFICATION_TYPES)
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'updated_count' => (int) $updatedCount,
            'unread_count' => $this->getUnreadCount($user),
        ];
    }

    /**
     * Persist a DatabaseNotification with database-enforced deduplication.
     * Returns null gracefully if a notification with the same dedupe_key already exists for this recipient.
     *
     * @param array<string, mixed> $data
     */
    protected function createDeduplicatedNotification(
        User $recipient,
        string $typeClass,
        array $data,
        ?string $dedupeKey = null
    ): ?DatabaseNotification {
        $morphClass = $recipient->getMorphClass();

        if ($dedupeKey !== null) {
            $alreadyExists = DatabaseNotification::where('notifiable_type', $morphClass)
                ->where('notifiable_id', $recipient->id)
                ->where('dedupe_key', $dedupeKey)
                ->exists();

            if ($alreadyExists) {
                return null;
            }
        }

        try {
            return DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => $typeClass,
                'notifiable_type' => $morphClass,
                'notifiable_id' => $recipient->id,
                'data' => $data,
                'dedupe_key' => $dedupeKey,
                'read_at' => null,
            ]);
        } catch (QueryException $e) {
            if ($dedupeKey !== null && $this->isDuplicateDedupeKeyException($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Broadcast NotificationCreated event immediately via Event dispatcher without breaking primary API flow on WebSocket outage.
     * Enforces that only bell notification types (Likes and Comments) trigger NotificationCreated.
     * Uses event() instead of broadcast() to avoid PendingBroadcast destructor timing latency.
     */
    protected function broadcastNotificationCreated(User $recipient, DatabaseNotification $record): void
    {
        if (! in_array($record->type, self::BELL_NOTIFICATION_TYPES, true)) {
            return;
        }

        try {
            $unreadCount = $this->getUnreadCount($recipient);
            event(new NotificationCreated(
                recipientId: (int) $recipient->id,
                notificationPayload: self::formatNotification($record),
                unreadCount: $unreadCount
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Check whether a QueryException represents a duplicate dedupe_key unique constraint violation.
     */
    protected function isDuplicateDedupeKeyException(QueryException $e): bool
    {
        $message = $e->getMessage();

        if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062 && str_contains($message, 'notifications_notifiable_dedupe_unique')) {
            return true; // MySQL
        }

        if (
            str_contains($message, 'notifications_notifiable_dedupe_unique') ||
            str_contains($message, 'notifications.notifiable_type, notifications.notifiable_id, notifications.dedupe_key')
        ) {
            return true; // SQLite
        }

        return false;
    }
}
