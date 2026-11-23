<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $conversationId,
        public int $userId,
        public int $lastReadMessageId,
        public ?string $lastReadAt = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.' . $this->conversationId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'MessagesRead';
    }

    /**
     * Get the data to broadcast.
     * Strictly whitelists only the read receipt attributes required by frontend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => (int) $this->conversationId,
            'user_id' => (int) $this->userId,
            'last_read_message_id' => (int) $this->lastReadMessageId,
            'last_read_at' => $this->lastReadAt ? (string) $this->lastReadAt : null,
        ];
    }
}
