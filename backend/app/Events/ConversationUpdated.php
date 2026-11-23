<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     * Strips loaded relations from message to ensure minimal broadcast payload.
     */
    public function __construct(
        public int $conversationId,
        public int $recipientId,
        public Message $message,
        public int $unreadCount
    ) {
        $this->message = $message->withoutRelations();
    }

    /**
     * Get the channels the event should broadcast on.
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
        return 'ConversationUpdated';
    }

    /**
     * Get the data to broadcast.
     * Strictly whitelists only the conversation update attributes required by frontend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => (int) $this->conversationId,
            'last_message' => [
                'id' => (int) $this->message->id,
                'conversation_id' => (int) $this->message->conversation_id,
                'sender_id' => (int) $this->message->sender_id,
                'type' => (string) $this->message->type,
                'body' => (string) $this->message->body,
                'client_message_id' => $this->message->client_message_id ? (string) $this->message->client_message_id : null,
                'created_at' => $this->message->created_at?->toISOString(),
            ],
            'unread_count' => (int) $this->unreadCount,
        ];
    }
}
