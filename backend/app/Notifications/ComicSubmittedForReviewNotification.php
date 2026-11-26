<?php

namespace App\Notifications;

use App\Models\Comic;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ComicSubmittedForReviewNotification extends Notification
{
    public const KIND = 'comic_submitted_for_review';

    public function __construct(
        public readonly Comic $comic,
        public readonly User $creator,
        public readonly int $chaptersCount = 0,
        public readonly ?string $batchKey = null
    ) {}

    /**
     * Delivery channels: database only.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Deduplication key to prevent duplicate notifications on retry or rapid clicks.
     */
    public function dedupeKey(): string
    {
        $batch = $this->batchKey ?? ($this->comic->updated_at?->timestamp ?? time());
        return self::KIND . ':' . $this->comic->id . ':c' . $this->chaptersCount . ':b' . $batch;
    }

    /**
     * Build the database notification payload.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->toPayload();
    }

    /**
     * Build normalized notification data payload for bell & API.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $avatarUrl = $this->creator->avatar_path
            ? Storage::disk('public')->url($this->creator->avatar_path)
            : null;

        $body = $this->chaptersCount > 0
            ? "Truyện \"{$this->comic->title}\" có {$this->chaptersCount} chương cần duyệt"
            : "Truyện \"{$this->comic->title}\" cần được duyệt";

        return [
            'kind' => self::KIND,
            'title' => 'Yêu cầu kiểm duyệt truyện',
            'body' => $body,
            'actor' => [
                'id' => (int) $this->creator->id,
                'name' => (string) $this->creator->name,
                'avatar_path' => $this->creator->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $this->creator->role,
            ],
            'target' => [
                'type' => 'comic_moderation',
                'id' => (int) $this->comic->id,
                'url' => "/admin/comics/{$this->comic->id}",
            ],
            'meta' => [
                'comic_id' => (int) $this->comic->id,
                'comic_title' => $this->comic->title,
                'chapters_count' => $this->chaptersCount,
            ],
        ];
    }
}
