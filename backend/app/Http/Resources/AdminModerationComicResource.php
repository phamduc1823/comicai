<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AdminModerationComicResource extends JsonResource
{
    /**
     * Transform the resource into an array for Admin Moderation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pendingChanges = $this->pending_changes;
        if (is_array($pendingChanges) && ! empty($pendingChanges['cover_path'])) {
            $pendingChanges['cover_url'] = Storage::disk('public')->url($pendingChanges['cover_path']);
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
            'approval_status' => $this->approval_status,
            'rejection_reason' => $this->rejection_reason,
            'pending_changes' => $pendingChanges,
            'has_pending_changes' => $this->pending_changes !== null,
            'views_count' => (int) $this->views_count,
            'follows_count' => (int) $this->follows_count,
            'is_featured' => (bool) $this->is_featured,
            'genres' => GenreResource::collection($this->whenLoaded('genres')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'creator' => $this->whenLoaded('user', function () {
                return $this->user ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'avatar_path' => $this->user->avatar_path,
                ] : null;
            }),
            'approver' => $this->whenLoaded('approver', function () {
                return $this->approver ? [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                ] : null;
            }),
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
