<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChapterReaderResource extends JsonResource
{
    /**
     * The navigation context (prev/next chapter).
     *
     * @var array{prev_chapter: ?array, next_chapter: ?array}|null
     */
    protected ?array $navigation;

    /**
     * Create a new resource instance.
     *
     * @param mixed $resource
     * @param array{prev_chapter: ?array, next_chapter: ?array}|null $navigation
     */
    public function __construct($resource, ?array $navigation = null)
    {
        parent::__construct($resource);
        $this->navigation = $navigation;
    }

    /**
     * Transform the resource into an array.
     * Whitelists chapter reader view including comic context, pages, and navigation.
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
            'comic' => $this->whenLoaded('comic', function () {
                return [
                    'id' => $this->comic->id,
                    'title' => $this->comic->title,
                    'slug' => $this->comic->slug,
                ];
            }),
            'images' => ChapterImageResource::collection($this->whenLoaded('images')),
            'prev_chapter' => $this->navigation['prev_chapter'] ?? null,
            'next_chapter' => $this->navigation['next_chapter'] ?? null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
