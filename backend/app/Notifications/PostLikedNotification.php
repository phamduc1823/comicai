<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostLikedNotification extends Notification
{
    public const KIND = 'post_liked';

    public function __construct(
        public readonly Post $post,
        public readonly User $liker
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Unique deduplication key per recipient to prevent duplicate notifications
     * from repeated like requests or unlike/re-like toggling by the same actor on the same post.
     */
    public function dedupeKey(): string
    {
        return self::KIND . ':' . $this->post->id . ':actor:' . $this->liker->id;
    }

    /**
     * Build the database notification payload.
     * Strictly whitelists only public actor fields and safe navigation metadata.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->toPayload();
    }

    /**
     * Build normalized notification data payload.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $avatarUrl = $this->liker->avatar_path
            ? Storage::disk('public')->url($this->liker->avatar_path)
            : null;

        $postExcerpt = Str::limit(trim((string) $this->post->content), 100, '...');

        return [
            'kind' => self::KIND,
            'title' => $this->liker->name . ' đã thích bài viết của bạn',
            'body' => $postExcerpt,
            'actor' => [
                'id' => (int) $this->liker->id,
                'name' => (string) $this->liker->name,
                'avatar_path' => $this->liker->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $this->liker->role,
            ],
            'target' => [
                'type' => 'post',
                'id' => (int) $this->post->id,
                'url' => '/posts/' . $this->post->id,
            ],
        ];
    }
}
