<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUserId = $request->user()?->id;

        // In direct conversation, find the recipient (the other participant)
        $recipient = null;
        if ($this->type === 'direct') {
            $otherUser = null;
            if ($this->relationLoaded('users')) {
                $otherUser = $this->users->firstWhere('id', '!=', $currentUserId);
            } elseif ($this->relationLoaded('participants')) {
                $otherUser = $this->participants->firstWhere('user_id', '!=', $currentUserId)?->user;
            }

            if ($otherUser) {
                $recipient = [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar_path' => $otherUser->avatar_path,
                    'role' => $otherUser->role,
                ];
            }
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'recipient' => $recipient,
            'last_message' => $this->whenLoaded('lastMessage', function () {
                if (! $this->lastMessage) {
                    return null;
                }
                return [
                    'id' => $this->lastMessage->id,
                    'sender_id' => $this->lastMessage->sender_id,
                    'type' => $this->lastMessage->type,
                    'body' => $this->lastMessage->body,
                    'created_at' => $this->lastMessage->created_at?->toISOString(),
                ];
            }),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'participants' => ConversationParticipantResource::collection($this->whenLoaded('participants')),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
