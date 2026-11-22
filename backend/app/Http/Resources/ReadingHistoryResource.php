<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadingHistoryResource extends JsonResource
{
    /**
     * Transform the reading history resource into an array.
     * Includes the full ComicListResource representation for the comic,
     * and the verified last read chapter summary.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comic' => new ComicListResource($this->whenLoaded('comic')),
            'last_read_chapter' => $this->whenLoaded('chapter', function () {
                return $this->chapter ? [
                    'id' => $this->chapter->id,
                    'chapter_number' => (string) $this->chapter->chapter_number,
                    'title' => $this->chapter->title,
                    'slug' => $this->chapter->slug,
                ] : null;
            }),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
