<?php

namespace App\Notifications;

use App\Models\Comic;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ChapterBatchReviewResultNotification extends Notification
{
    public const KIND = 'chapter_batch_review_result';

    public function __construct(
        public readonly Comic $comic,
        public readonly User $admin,
        public readonly int $approvedCount = 0,
        public readonly int $rejectedCount = 0,
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
        return self::KIND . ':' . $this->comic->id . ':a' . $this->approvedCount . ':r' . $this->rejectedCount . ':b' . $batch;
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

        if ($this->approvedCount > 0 && $this->rejectedCount === 0) {
            $title = 'Chương truyện đã được duyệt';
            $body = "{$this->approvedCount} chương của truyện \"{$this->comic->title}\" đã được duyệt và xuất bản.";
        } elseif ($this->rejectedCount > 0 && $this->approvedCount === 0) {
            $title = 'Chương truyện bị từ chối';
            $body = "{$this->rejectedCount} chương của truyện \"{$this->comic->title}\" đã bị từ chối" . ($this->reason ? ": {$this->reason}" : '.');
        } else {
            $title = 'Kết quả duyệt chương truyện';
            $body = "{$this->approvedCount} chương của truyện \"{$this->comic->title}\" đã được duyệt và {$this->rejectedCount} chương bị từ chối.";
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
                'approved_count' => $this->approvedCount,
                'rejected_count' => $this->rejectedCount,
                'reason' => $this->reason,
            ],
        ];
    }
}
