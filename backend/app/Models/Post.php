<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'posts';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'likes_count' => 0,
        'comments_count' => 0,
    ];

    /**
     * The attributes that are mass assignable.
     * Note: 'likes_count' and 'comments_count' are counter caches and should
     * only be modified through explicit interaction workflows.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'comic_id',
        'content',
        'image_path',
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
            'comments_count' => 'integer',
        ];
    }

    /**
     * The author of the post.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Optional associated comic referenced in this post.
     */
    public function comic(): BelongsTo
    {
        return $this->belongsTo(Comic::class, 'comic_id');
    }

    /**
     * Comments on this post.
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Likes on this post.
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /**
     * Reports filed against this post.
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * Check if post is liked by a given user.
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
     * Handles cascading cleanup of polymorphic relations to prevent orphans
     * and deletes attached image file upon successful deletion.
     */
    protected static function booted(): void
    {
        static::deleting(function (Post $post) {
            $commentIds = $post->comments()->pluck('id');
            if ($commentIds->isNotEmpty()) {
                Like::where('likeable_type', 'comment')->whereIn('likeable_id', $commentIds)->delete();
                Report::where('reportable_type', 'comment')->whereIn('reportable_id', $commentIds)->delete();
            }

            $post->comments()->delete();
            $post->likes()->delete();
            $post->reports()->delete();
        });

        static::deleted(function (Post $post) {
            if (
                $post->image_path &&
                ! str_contains($post->image_path, '..') &&
                str_starts_with($post->image_path, 'posts/') &&
                Storage::disk('public')->exists($post->image_path)
            ) {
                Storage::disk('public')->delete($post->image_path);
            }
        });
    }
}
