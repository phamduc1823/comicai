<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Strictly avoids exposing user passwords, emails, or internal data.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comicAttachment = null;
        if ($this->comic_id || $this->type === 'comic') {
            if ($this->comic_id && $this->comic) {
                $comicAttachment = [
                    'id' => (int) $this->comic->id,
                    'title' => (string) $this->comic->title,
                    'slug' => (string) $this->comic->slug,
                    'cover_url' => $this->comic->cover_path ? Storage::disk('public')->url($this->comic->cover_path) : null,
                    'author_name' => (string) ($this->comic->author_name ?? ''),
                    'is_unavailable' => false,
                ];
            } else {
                $comicAttachment = [
                    'id' => $this->comic_id ? (int) $this->comic_id : null,
                    'title' => 'Truyện không khả dụng hoặc đã bị gỡ',
                    'slug' => '',
                    'cover_url' => null,
                    'author_name' => '',
                    'is_unavailable' => true,
                ];
            }
        }

        $postAttachment = null;
        if ($this->post_id || $this->type === 'post') {
            if ($this->post_id && $this->post) {
                $postUser = $this->post->user;
                $postAttachment = [
                    'id' => (int) $this->post->id,
                    'content' => (string) $this->post->content,
                    'created_at' => $this->post->created_at?->toISOString(),
                    'user' => $postUser ? [
                        'id' => (int) $postUser->id,
                        'name' => (string) $postUser->name,
                        'avatar_url' => $postUser->avatar_path ? Storage::disk('public')->url($postUser->avatar_path) : null,
                    ] : null,
                    'comic' => $this->post->comic ? [
                        'id' => (int) $this->post->comic->id,
                        'title' => (string) $this->post->comic->title,
                        'slug' => (string) $this->post->comic->slug,
                    ] : null,
                    'is_unavailable' => false,
                ];
            } else {
                $postAttachment = [
                    'id' => $this->post_id ? (int) $this->post_id : null,
                    'content' => 'Bài viết không khả dụng hoặc đã bị gỡ',
                    'created_at' => null,
                    'user' => null,
                    'comic' => null,
                    'is_unavailable' => true,
                ];
            }
        }

        $images = is_array($this->images) ? array_map(function ($img) {
            if (is_array($img)) {
                return [
                    'path' => $img['path'] ?? '',
                    'url' => $img['url'] ?? (isset($img['path']) ? Storage::disk('public')->url($img['path']) : ''),
                    'name' => $img['name'] ?? '',
                    'size' => (int) ($img['size'] ?? 0),
                ];
            }
            return $img;
        }, $this->images) : [];

        return [
            'id' => (int) $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'sender_id' => (int) $this->sender_id,
            'type' => (string) $this->type,
            'body' => (string) ($this->body ?? ''),
            'attachments' => [
                'images' => $images,
                'comic' => $comicAttachment,
                'post' => $postAttachment,
            ],
            'client_message_id' => $this->client_message_id ? (string) $this->client_message_id : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
