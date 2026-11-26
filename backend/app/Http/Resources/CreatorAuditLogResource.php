<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'actor_id' => $this->actor_id,
            'actor' => $this->whenLoaded('actor', function () {
                return [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                    'email' => $this->actor->email,
                ];
            }),
            'application_id' => $this->application_id,
            'application_code' => $this->whenLoaded('application', function () {
                return $this->application?->application_code;
            }),
            'event_type' => $this->event_type,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
