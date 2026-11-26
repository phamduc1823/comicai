<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemBroadcastNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public string $broadcastType = 'announcement',
        public ?string $actionUrl = '/',
        public ?User $admin = null
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
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'system_broadcast',
            'title' => $this->title,
            'body' => $this->message,
            'actor' => [
                'id' => $this->admin?->id,
                'name' => $this->admin?->name ?? 'Ban Quản Trị ComicAI',
                'avatar_path' => $this->admin?->avatar_path,
                'role' => 'admin',
            ],
            'target' => [
                'type' => 'system',
                'id' => null,
                'url' => $this->actionUrl ?: '/',
            ],
            'is_broadcast' => true,
            'broadcast_type' => $this->broadcastType,
        ];
    }
}
