<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Strictly avoids exposing user passwords, emails, or internal data.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'sender_id' => (int) $this->sender_id,
            'type' => (string) $this->type,
            'body' => (string) $this->body,
            'client_message_id' => $this->client_message_id ? (string) $this->client_message_id : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
