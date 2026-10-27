<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorChapterResource extends JsonResource
{
    /**
     * Transform the resource into an array for Creator Studio management.
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
            'views_count' => (int) $this->views_count,
            'images_count' => (int) ($this->images_count ?? ($this->relationLoaded('images') ? $this->images->count() : 0)),
            'images' => ChapterImageResource::collection($this->whenLoaded('images')),
            'comic' => $this->whenLoaded('comic', function () {
                return $this->comic ? [
                    'id' => $this->comic->id,
                    'title' => $this->comic->title,
                    'slug' => $this->comic->slug,
                    'user_id' => $this->comic->user_id,
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
