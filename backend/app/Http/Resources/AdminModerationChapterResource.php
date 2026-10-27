<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminModerationChapterResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin Moderation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comic_id' => $this->comic_id,
            'chapter_number' => (string) $this->chapter_number,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'views_count' => (int) $this->views_count,
            'images_count' => (int) ($this->images_count ?? ($this->relationLoaded('images') ? $this->images->count() : 0)),
            'images' => ChapterImageResource::collection($this->whenLoaded('images')),
            'comic' => $this->whenLoaded('comic', function () {
                return $this->comic ? [
                    'id' => $this->comic->id,
                    'title' => $this->comic->title,
                    'slug' => $this->comic->slug,
                    'approval_status' => $this->comic->approval_status,
                    'status' => $this->comic->status,
                    'creator' => $this->comic->relationLoaded('user') && $this->comic->user ? [
                        'id' => $this->comic->user->id,
                        'name' => $this->comic->user->name,
                        'avatar_path' => $this->comic->user->avatar_path,
                    ] : null,
                ] : null;
            }),
            'approver' => $this->whenLoaded('approver', function () {
                return $this->approver ? [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
