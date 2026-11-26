<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_code' => $this->application_code,
            'user_id' => $this->user_id,
            'pen_name' => $this->pen_name,
            'bio' => $this->bio,
            'reason' => $this->reason,
            'sample_works' => $this->sample_works,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'version' => $this->version,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'reviewer' => $this->whenLoaded('reviewer', function () {
                return [
                    'id' => $this->reviewer->id,
                    'name' => $this->reviewer->name,
                    'email' => $this->reviewer->email,
                ];
            }),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'avatar_path' => $this->user->avatar_path,
                ];
            }),
            'histories' => CreatorApplicationHistoryResource::collection($this->whenLoaded('histories')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
