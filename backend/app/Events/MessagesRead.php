<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcastNow
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
     * Broadcasts to conversation channel and participants' personal user channels
     * so read receipt status (✓✓) updates in true real-time regardless of conversation channel subscription.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.conversation.' . $this->conversationId),
        ];

        try {
            $participantIds = \App\Models\ConversationParticipant::where('conversation_id', $this->conversationId)
                ->pluck('user_id')
                ->all();

            foreach ($participantIds as $pId) {
                $channels[] = new PrivateChannel('chat.user.' . (int) $pId);
            }
        } catch (\Throwable) {
            // Fallback to conversation channel only
        }

        return $channels;
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
