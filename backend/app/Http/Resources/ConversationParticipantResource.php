<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationParticipantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Never exposes password, email, or internal authentication data.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'user_id' => (int) $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => (int) $this->user->id,
                    'name' => (string) $this->user->name,
                    'avatar_path' => $this->user->avatar_path,
                    'role' => (string) $this->user->role,
                ];
            }),
            'last_read_message_id' => $this->last_read_message_id !== null ? (int) $this->last_read_message_id : null,
            'last_read_at' => $this->last_read_at?->toISOString(),
            'is_muted' => (bool) $this->is_muted,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
