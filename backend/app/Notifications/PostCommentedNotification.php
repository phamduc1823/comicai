<?php

namespace App\Notifications;

use App\Models\Chapter;
use App\Models\Comic;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PostCommentedNotification extends Notification
{
    public const KIND = 'post_commented';

    public function __construct(
        public readonly Comment $comment,
        public readonly User $commenter,
        public readonly ?Post $post = null,
        public readonly bool $isReply = false,
        public readonly ?Comic $comic = null,
        public readonly ?Chapter $chapter = null
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
     * for the same comment creation.
     */
    public function dedupeKey(): string
    {
        return self::KIND . ':' . $this->comment->id;
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
        $avatarUrl = $this->commenter->avatar_path
            ? Storage::disk('public')->url($this->commenter->avatar_path)
            : null;

        $commentExcerpt = Str::limit(trim((string) $this->comment->content), 100, '...');

        if ($this->isReply) {
            $title = $this->commenter->name . ' đã trả lời bình luận của bạn';
        } elseif ($this->comic) {
            $title = $this->commenter->name . ' đã bình luận về truyện của bạn';
        } elseif ($this->chapter) {
            $title = $this->commenter->name . ' đã bình luận về chương của bạn';
        } else {
            $title = $this->commenter->name . ' đã bình luận về bài viết của bạn';
        }

        $targetUrl = '/';
        $targetType = 'post';
        $targetId = (int) $this->comment->commentable_id;

        if ($this->post) {
            $targetUrl = '/posts/' . $this->post->id;
            $targetType = 'post';
            $targetId = (int) $this->post->id;
        } elseif ($this->comic) {
            $targetUrl = '/comics/' . $this->comic->id;
            $targetType = 'comic';
            $targetId = (int) $this->comic->id;
        } elseif ($this->chapter) {
            $targetUrl = '/comics/' . $this->chapter->comic_id . '/read/' . $this->chapter->id;
            $targetType = 'chapter';
            $targetId = (int) $this->chapter->id;
        }

        return [
            'kind' => self::KIND,
            'title' => $title,
            'body' => $commentExcerpt,
            'actor' => [
                'id' => (int) $this->commenter->id,
                'name' => (string) $this->commenter->name,
                'avatar_path' => $this->commenter->avatar_path,
                'avatar_url' => $avatarUrl,
                'role' => (string) $this->commenter->role,
            ],
            'target' => [
                'type' => $targetType,
                'id' => $targetId,
                'comment_id' => (int) $this->comment->id,
                'url' => $targetUrl,
            ],
        ];
    }
}
