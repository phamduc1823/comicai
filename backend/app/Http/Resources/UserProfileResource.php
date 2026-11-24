<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserProfileResource extends JsonResource
{
    /**
     * Additional metadata passed from controller.
     *
     * @var array<string, mixed>
     */
    protected array $meta;

    public function __construct($resource, array $meta = [])
    {
        parent::__construct($resource);
        $this->meta = $meta;
    }

    /**
     * Transform the resource into an array.
     * Whitelists strictly public profile fields.
     * Excludes email, password, wallet, and administrative data.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $avatarUrl = null;
        if ($this->avatar_path) {
            $avatarUrl = Storage::disk('public')->url($this->avatar_path);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'avatar_path' => $this->avatar_path,
            'avatar_url' => $avatarUrl,
            'created_at' => $this->created_at?->toISOString(),
            'stats' => [
                'posts_count' => (int) ($this->meta['posts_count'] ?? 0),
                'comics_count' => (int) ($this->meta['comics_count'] ?? 0),
            ],
        ];
    }
}
