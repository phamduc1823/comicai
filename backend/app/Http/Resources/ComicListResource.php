<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ComicListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Whitelists public catalog fields for comic list view.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'alternative_titles' => $this->alternative_titles,
            'author_name' => $this->author_name,
            'cover_path' => $this->cover_path,
            'cover_url' => $this->cover_path
                ? Storage::disk('public')->url($this->cover_path)
                : null,
            'age_rating' => $this->age_rating,
            'status' => $this->status,
            'views_count' => (int) $this->views_count,
            'follows_count' => (int) $this->follows_count,
            'is_featured' => (bool) $this->is_featured,
            'published_chapters_count' => (int) ($this->published_chapters_count ?? $this->chapters_count ?? 0),
            'latest_chapter' => $this->whenLoaded('latestPublishedChapter', function () {
                return $this->latestPublishedChapter ? [
                    'id' => $this->latestPublishedChapter->id,
                    'chapter_number' => (string) $this->latestPublishedChapter->chapter_number,
                    'title' => $this->latestPublishedChapter->title,
                    'slug' => $this->latestPublishedChapter->slug,
                    'created_at' => $this->latestPublishedChapter->created_at?->toISOString(),
                    'updated_at' => $this->latestPublishedChapter->updated_at?->toISOString(),
                ] : null;
            }),
            'genres' => GenreResource::collection($this->whenLoaded('genres')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'creator' => $this->whenLoaded('user', function () {
                return $this->user ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'avatar_path' => $this->user->avatar_path,
                    'avatar_url' => $this->user->avatar_path
                        ? Storage::disk('public')->url($this->user->avatar_path)
                        : null,
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
