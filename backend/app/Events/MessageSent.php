<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     * Strips loaded relations to ensure minimal serialized payload and memory footprint.
     */
    public function __construct(
        public Message $message
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
            new PrivateChannel('chat.conversation.' . $this->message->conversation_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    /**
     * Get the data to broadcast.
     * Strictly whitelists only the communication attributes required by frontend.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $messageData = [
            'id' => (int) $this->message->id,
            'conversation_id' => (int) $this->message->conversation_id,
            'sender_id' => (int) $this->message->sender_id,
            'type' => (string) $this->message->type,
            'body' => (string) $this->message->body,
            'client_message_id' => $this->message->client_message_id ? (string) $this->message->client_message_id : null,
            'created_at' => $this->message->created_at?->toISOString(),
        ];

        return [
            'message' => $messageData,
            ...$messageData,
        ];
    }
}
