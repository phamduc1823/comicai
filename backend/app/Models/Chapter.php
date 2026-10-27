<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapter extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chapters';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'views_count' => 0,
    ];

    /**
     * The attributes that are mass assignable.
     * Note: 'views_count' is managed automatically and protected from mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'comic_id',
        'chapter_number',
        'title',
        'slug',
        'status',
        'rejection_reason',
    ];

    /**
     * Get the attributes that should be cast.
     * 'chapter_number' is cast to decimal:2 to preserve exact precision without binary float distortion.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chapter_number' => 'decimal:2',
            'views_count' => 'integer',
        ];
    }

    /**
     * The comic that owns this chapter.
     */
    public function comic(): BelongsTo
    {
        return $this->belongsTo(Comic::class, 'comic_id');
    }

    /**
     * The webtoon page images belonging to this chapter.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ChapterImage::class, 'chapter_id')->orderBy('page_number');
    }

    /**
     * Reading history records tracking this chapter as the latest read.
     */
    public function readingHistories(): HasMany
    {
        return $this->hasMany(ReadingHistory::class, 'chapter_id');
    }
}
