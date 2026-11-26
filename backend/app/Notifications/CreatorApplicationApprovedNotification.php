<?php

namespace App\Notifications;

use App\Models\CreatorApplication;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class CreatorApplicationApprovedNotification extends Notification
{
    public const KIND = 'creator_application_approved';

    public function __construct(
        public readonly CreatorApplication $application,
        public readonly User $admin
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
     * from repeated approval requests or retries.
     */
    public function dedupeKey(): string
    {
        return self::KIND . ':' . $this->application->id . ':v' . $this->application->version;
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
     * Build normalized notification data payload.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $avatarUrl = $this->admin->avatar_path
            ? Storage::disk('public')->url($this->admin->avatar_path)
            : null;

        return [
            'kind' => self::KIND,
            'title' => 'Hồ sơ Creator đã được duyệt',
            'body' => 'Chúc mừng! Hồ sơ đăng ký Creator của bạn đã được duyệt. Bạn đã trở thành Creator trên ComicAI.',
            'actor' => [
                'id' => (int) $this->admin->id,
                'name' => (string) $this->admin->name,
                'avatar_path' => $this->admin->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $this->admin->role,
            ],
            'target' => [
                'type' => 'creator_application',
                'id' => (int) $this->application->id,
                'url' => '/become-creator',
            ],
        ];
    }
}
