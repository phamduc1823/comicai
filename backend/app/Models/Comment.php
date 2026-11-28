<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'comments';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'likes_count' => 0,
        'replies_count' => 0,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'parent_id',
        'commentable_type',
        'commentable_id',
        'content',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
            'replies_count' => 'integer',
        ];
    }

    /**
     * The author of the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Parent comment (if this is a level-2 reply).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * Direct replies to this comment (level-2 comments only).
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    /**
     * Polymorphic target being commented on (Post, Comic, Chapter).
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Likes on this comment.
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /**
     * Reports filed against this comment.
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * Target comic/entity author ID set dynamically by controller to avoid N+1 queries.
     *
     * @var int|null
     */
    public ?int $targetAuthorId = null;

    /**
     * Check if the comment author is the creator/author of the target comic.
     */
    public function isAuthorOfTarget(): bool
    {
        if ($this->targetAuthorId !== null) {
            return (int) $this->user_id === (int) $this->targetAuthorId;
        }

        // Context-aware fallback: only comics and chapters have comic authors
        if ($this->commentable_type === 'comic') {
            if ($this->relationLoaded('commentable') && $this->commentable) {
                return (int) $this->user_id === (int) $this->commentable->user_id;
            }
            $ownerId = Comic::where('id', $this->commentable_id)->value('user_id');
            return $ownerId !== null && (int) $this->user_id === (int) $ownerId;
        }

        if ($this->commentable_type === 'chapter') {
            if ($this->relationLoaded('commentable') && $this->commentable) {
                $comicUserId = $this->commentable->relationLoaded('comic')
                    ? $this->commentable->comic?->user_id
                    : $this->commentable->comic()->value('user_id');
                return $comicUserId !== null && (int) $this->user_id === (int) $comicUserId;
            }
            $chapter = Chapter::where('id', $this->commentable_id)->with('comic:id,user_id')->first();
            return $chapter && $chapter->comic && (int) $this->user_id === (int) $chapter->comic->user_id;
        }

        return false;
    }

    /**
     * Check if this is a top-level root comment.
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Check if this is a level-2 reply comment.
     */
    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Check if comment is liked by a given user.
     */
    public function isLikedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->likes()->where('user_id', $user->id)->exists();
    }

    /**
     * Bootstrap the model and its events.
     * Enforces the 2-level nesting rule and maintains counter caches.
     */
    protected static function booted(): void
    {
        static::creating(function (Comment $comment) {
            if ($comment->parent_id) {
                $parent = static::find($comment->parent_id);
                if (! $parent) {
                    throw new InvalidArgumentException('Bình luận cha không tồn tại.');
                }
                if ($parent->parent_id !== null) {
                    throw new InvalidArgumentException('Không thể trả lời một bình luận cấp 2. Chỉ hỗ trợ tối đa 2 cấp bình luận.');
                }

                // Ensure reply inherits the target entity from the parent comment
                $comment->commentable_type = $parent->commentable_type;
                $comment->commentable_id = $parent->commentable_id;
            }
        });

        static::updating(function (Comment $comment) {
            if ($comment->isDirty('parent_id')) {
                throw new InvalidArgumentException('Không thể thay đổi cấp độ hoặc bình luận cha của bình luận đã tạo.');
            }
        });

        static::created(function (Comment $comment) {
            if ($comment->parent_id) {
                $comment->parent()->increment('replies_count');
            }
            if ($comment->commentable_type === 'post') {
                Post::where('id', $comment->commentable_id)->increment('comments_count');
            }
        });

        static::deleting(function (Comment $comment) {
            // When deleting a root comment, MySQL ON DELETE CASCADE will remove all child replies
            // directly in database without firing their Eloquent model events.
            // Deduct the child replies count from post's comments_count ahead of time
            // and clean up child replies' polymorphic likes and reports.
            if ($comment->isRoot()) {
                $replyIds = $comment->replies()->pluck('id');
                if ($replyIds->isNotEmpty()) {
                    Like::where('likeable_type', 'comment')->whereIn('likeable_id', $replyIds)->delete();
                    Report::where('reportable_type', 'comment')->whereIn('reportable_id', $replyIds)->delete();
                }

                if ($comment->commentable_type === 'post') {
                    $repliesCount = $replyIds->count();
                    if ($repliesCount > 0) {
                        Post::where('id', $comment->commentable_id)
                            ->where('comments_count', '>=', $repliesCount)
                            ->decrement('comments_count', $repliesCount);
                    }
                }
            }

            $comment->likes()->delete();
            $comment->reports()->delete();
        });

        static::deleted(function (Comment $comment) {
            if ($comment->parent_id) {
                $comment->parent()->where('replies_count', '>', 0)->decrement('replies_count');
            }
            if ($comment->commentable_type === 'post') {
                Post::where('id', $comment->commentable_id)->where('comments_count', '>', 0)->decrement('comments_count');
            }
        });
    }
}
