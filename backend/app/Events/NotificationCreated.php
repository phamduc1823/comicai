<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string, mixed> $notificationPayload
     */
    public function __construct(
        public int $recipientId,
        public array $notificationPayload,
        public int $unreadCount
    ) {}

    /**
     * Reuse the existing authorized private user channel (chat.user.{userId}).
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.user.' . $this->recipientId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /**
     * Strictly whitelist notification data and authoritative unread count.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notification' => $this->notificationPayload,
            'unread_count' => (int) $this->unreadCount,
        ];
    }
}
