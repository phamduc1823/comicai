<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NewMessageNotification extends Notification
{
    public const KIND = 'new_message';

    public function __construct(
        public readonly Message $message,
        public readonly User $sender,
        public readonly Conversation $conversation
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Unique deduplication key per recipient to prevent duplicate notifications for the same message.
     */
    public function dedupeKey(): string
    {
        return self::KIND . ':' . $this->message->id;
    }

    /**
     * Build the database notification payload.
     * Strictly whitelists only public sender fields and safe navigation metadata.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->toPayload();
    }

    /**
     * Build normalized notification data payload.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $avatarUrl = $this->sender->avatar_path
            ? Storage::disk('public')->url($this->sender->avatar_path)
            : null;

        $preview = Str::limit(trim((string) $this->message->body), 120, '...');

        return [
            'kind' => self::KIND,
            'title' => $this->sender->name . ' đã gửi tin nhắn mới',
            'body' => $preview,
            'actor' => [
                'id' => (int) $this->sender->id,
                'name' => (string) $this->sender->name,
                'avatar_path' => $this->sender->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $this->sender->role,
            ],
            'target' => [
                'type' => 'conversation',
                'id' => (int) $this->conversation->id,
                'message_id' => (int) $this->message->id,
                'url' => '/chat?conversation_id=' . $this->conversation->id,
            ],
        ];
    }
}
