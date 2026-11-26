<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorApplicationHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'version' => $this->version,
            'pen_name' => $this->pen_name,
            'bio' => $this->bio,
            'reason' => $this->reason,
            'sample_works' => $this->sample_works,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'action_type' => $this->action_type,
            'action_by' => $this->action_by,
            'action_user' => $this->whenLoaded('actionBy', function () {
                return [
                    'id' => $this->actionBy->id,
                    'name' => $this->actionBy->name,
                ];
            }),
            'action_at' => $this->action_at?->toIso8601String(),
            'snapshot_data' => $this->snapshot_data,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
