<?php

namespace App\Models;

use Database\Factories\LikeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Like extends Model
{
    /** @use HasFactory<LikeFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'likes';

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    public const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     * Note: The schema only tracks 'created_at'.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'likeable_type',
        'likeable_id',
    ];

    /**
     * The user who performed the like.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Polymorphic target being liked (Post, Comment).
     */
    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Bootstrap the model and its events.
     * Handles counter cache increments and decrements atomically.
     */
    protected static function booted(): void
    {
        static::created(function (Like $like) {
            if ($like->likeable_type === 'post') {
                Post::where('id', $like->likeable_id)->increment('likes_count');
            } elseif ($like->likeable_type === 'comment') {
                Comment::where('id', $like->likeable_id)->increment('likes_count');
            }
        });

        static::deleted(function (Like $like) {
            if ($like->likeable_type === 'post') {
                Post::where('id', $like->likeable_id)
                    ->where('likes_count', '>', 0)
                    ->decrement('likes_count');
            } elseif ($like->likeable_type === 'comment') {
                Comment::where('id', $like->likeable_id)
                    ->where('likes_count', '>', 0)
                    ->decrement('likes_count');
            }
        });
    }
}
