<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user() ?? $request->user('sanctum');
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
            'parent_id' => $this->parent_id,
            'commentable_type' => $this->commentable_type,
            'commentable_id' => (int) $this->commentable_id,
            'content' => $this->content,
            'likes_count' => (int) $this->likes_count,
            'replies_count' => (int) $this->replies_count,
            'is_liked' => $isLiked,
            'is_author' => (bool) $this->isAuthorOfTarget(),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatar_path ? Storage::disk('public')->url($this->user->avatar_path) : null,
                'role' => $this->user->role,
            ],
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
