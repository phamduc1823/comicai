<?php

namespace App\Notifications;

use App\Models\Comic;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ComicReviewResultNotification extends Notification
{
    public const KIND = 'comic_review_result';

    public function __construct(
        public readonly Comic $comic,
        public readonly User $admin,
        public readonly string $action, // 'approved' | 'rejected'
        public readonly int $approvedChaptersCount = 0,
        public readonly ?string $reason = null,
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
     * Deduplication key to prevent duplicates on retries.
     */
    public function dedupeKey(): string
    {
        $batch = $this->batchKey ?? ($this->comic->updated_at?->timestamp ?? time());
        return self::KIND . ':' . $this->comic->id . ':' . $this->action . ':c' . $this->approvedChaptersCount . ':b' . $batch;
    }

    /**
     * Build database notification payload.
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
        $avatarUrl = $this->admin->avatar_path
            ? Storage::disk('public')->url($this->admin->avatar_path)
            : null;

        if ($this->action === 'approved') {
            $title = 'Bộ truyện đã được duyệt xuất bản';
            if ($this->approvedChaptersCount > 0) {
                $body = "Truyện \"{$this->comic->title}\" cùng {$this->approvedChaptersCount} chương đã được duyệt và xuất bản.";
            } else {
                $body = "Truyện \"{$this->comic->title}\" đã được duyệt.";
            }
        } else {
            $title = 'Yêu cầu duyệt truyện bị từ chối';
            $body = "Truyện \"{$this->comic->title}\" đã bị từ chối" . ($this->reason ? ": {$this->reason}" : '.');
        }

        return [
            'kind' => self::KIND,
            'title' => $title,
            'body' => $body,
            'actor' => [
                'id' => (int) $this->admin->id,
                'name' => (string) $this->admin->name,
                'avatar_path' => $this->admin->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $this->admin->role,
            ],
            'target' => [
                'type' => 'creator_comic',
                'id' => (int) $this->comic->id,
                'url' => "/creator/comics/{$this->comic->id}/chapters",
            ],
            'meta' => [
                'comic_id' => (int) $this->comic->id,
                'action' => $this->action,
                'approved_chapters_count' => $this->approvedChaptersCount,
                'reason' => $this->reason,
            ],
        ];
    }
}
