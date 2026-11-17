<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        if ($this->image_path) {
            $imageUrl = Storage::disk('public')->url($this->image_path);
        }

        $user = $request->user('sanctum') ?? $request->user();
        $isLiked = false;
        if ($user) {
            if (isset($this->is_liked)) {
                $isLiked = (bool) $this->is_liked;
            } else {
                $isLiked = $this->isLikedBy($user);
            }
        }

        return [
            'id' => $this->id,
            'content' => $this->content,
            'image_url' => $imageUrl,
            'likes_count' => (int) $this->likes_count,
            'comments_count' => (int) $this->comments_count,
            'is_liked' => $isLiked,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatar_path ? Storage::disk('public')->url($this->user->avatar_path) : null,
                'role' => $this->user->role,
            ],
            'comic' => $this->comic ? [
                'id' => $this->comic->id,
                'title' => $this->comic->title,
                'slug' => $this->comic->slug,
                'cover_url' => $this->comic->cover_path ? Storage::disk('public')->url($this->comic->cover_path) : null,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
