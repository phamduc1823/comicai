<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'creator_code' => $this->creator_code,
            'pen_name' => $this->pen_name,
            'bio' => $this->bio,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'can_reapply' => (bool) $this->can_reapply,
            'reapply_granted_at' => $this->reapply_granted_at?->toIso8601String(),
            'reapply_grant_reason' => $this->reapply_grant_reason,
            'reapplied_at' => $this->reapplied_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'avatar_path' => $this->user->avatar_path,
                ];
            }),
            'comics_count' => $this->when(isset($this->comics_count), $this->comics_count),
            'approved_comics_count' => $this->when(isset($this->approved_comics_count), $this->approved_comics_count),
            'pending_comics_count' => $this->when(isset($this->pending_comics_count), $this->pending_comics_count),
            'rejected_comics_count' => $this->when(isset($this->rejected_comics_count), $this->rejected_comics_count),
            'chapters_count' => $this->when(isset($this->chapters_count), $this->chapters_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
