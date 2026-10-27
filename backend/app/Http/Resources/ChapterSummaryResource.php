<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Whitelists chapter summary fields for comic detail page.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapter_number' => (string) $this->chapter_number,
            'title' => $this->title,
            'slug' => $this->slug,
            'views_count' => (int) $this->views_count,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
