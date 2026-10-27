<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comic extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'comics';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'age_rating' => '13+',
        'status' => 'ongoing',
        'approval_status' => 'draft',
        'views_count' => 0,
        'follows_count' => 0,
        'is_featured' => false,
    ];

    /**
     * The attributes that are mass assignable.
     * Note: 'approval_status', 'approved_by', 'approved_at', 'rejection_reason',
     * 'views_count', 'follows_count', and 'is_featured' are protected against
     * mass assignment and must be modified through explicit workflows.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'alternative_titles',
        'author_name',
        'user_id',
        'description',
        'cover_path',
        'banner_path',
        'age_rating',
        'status',
        'pending_changes',
        'ai_summary',
        'embedding',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pending_changes' => 'array',
            'embedding' => 'array',
            'is_featured' => 'boolean',
            'views_count' => 'integer',
            'follows_count' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The creator/author account who owns this comic.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The administrator who approved or reviewed this comic.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The genres assigned to this comic.
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'comic_genre', 'comic_id', 'genre_id');
    }

    /**
     * The feature tags assigned to this comic (up to 10 tags).
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'comic_tag', 'comic_id', 'tag_id');
    }

    /**
     * The chapters belonging to this comic.
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'comic_id');
    }

    /**
     * Readers following this comic.
     */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class, 'comic_id');
    }

    /**
     * Readers bookmarking this comic.
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'comic_id');
    }

    /**
     * Reading history records associated with this comic.
     */
    public function readingHistories(): HasMany
    {
        return $this->hasMany(ReadingHistory::class, 'comic_id');
    }

    /**
     * Featured home banners promoting this comic.
     */
    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class, 'comic_id');
    }
}
