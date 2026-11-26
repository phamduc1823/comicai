<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ComicDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Whitelists full comic details and published chapters for reader view.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user() ?? $request->user('sanctum');
        $isFollowed = false;
        $isBookmarked = false;
        $isPurchased = false;

        if ($user) {
            if (isset($this->is_followed)) {
                $isFollowed = (bool) $this->is_followed;
            } else {
                $isFollowed = $this->follows()->where('user_id', $user->id)->exists();
            }

            if (isset($this->is_bookmarked)) {
                $isBookmarked = (bool) $this->is_bookmarked;
            } else {
                $isBookmarked = $this->bookmarks()->where('user_id', $user->id)->exists();
            }

            $isPurchased = app(\App\Services\Contracts\ComicPurchaseServiceInterface::class)->hasPurchasedComic($user, $this->resource);
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'alternative_titles' => $this->alternative_titles,
            'author_name' => $this->author_name,
            'description' => $this->description,
            'cover_path' => $this->cover_path,
            'cover_url' => $this->cover_path
                ? Storage::disk('public')->url($this->cover_path)
                : null,
            'banner_path' => $this->banner_path,
            'banner_url' => $this->banner_path
                ? Storage::disk('public')->url($this->banner_path)
                : null,
            'age_rating' => $this->age_rating,
            'status' => $this->status,
            'is_vip' => (bool) $this->is_vip,
            'price' => (int) ($this->price ?? 0),
            'is_purchased' => $isPurchased,
            'views_count' => (int) $this->views_count,
            'follows_count' => (int) $this->follows_count,
            'is_followed' => $isFollowed,
            'is_bookmarked' => $isBookmarked,
            'is_featured' => (bool) $this->is_featured,
            'ai_summary' => $this->ai_summary,
            'published_chapters_count' => (int) ($this->published_chapters_count ?? $this->chapters_count ?? 0),
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
            'chapters' => ChapterSummaryResource::collection($this->whenLoaded('chapters')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
