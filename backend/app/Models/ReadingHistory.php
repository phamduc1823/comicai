<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingHistory extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'reading_histories';

    /**
     * The name of the "created at" column.
     * Note: The schema only tracks 'updated_at' via UPSERT.
     *
     * @var string|null
     */
    public const CREATED_AT = null;

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'comic_id',
        'chapter_id',
    ];

    /**
     * The reader owning this reading history entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The comic being read.
     */
    public function comic(): BelongsTo
    {
        return $this->belongsTo(Comic::class, 'comic_id');
    }

    /**
     * Standard Eloquent relationship to the chapter via chapter_id.
     *
     * ELOQUENT COMPOSITE FK LIMITATION (CAI-010):
     * Standard Eloquent belongsTo relations can only match on a single foreign key ('chapter_id' -> 'chapters.id').
     * For eager loading multiple reading history records simultaneously, use:
     *   ReadingHistory::with('chapter')->get()
     * and access the composite-verified chapter via $history->verified_chapter or $history->verifiedChapter().
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class, 'chapter_id');
    }

    /**
     * Accessor that strictly validates that the loaded chapter matches both 'chapter_id' AND 'comic_id'.
     *
     * Behavior:
     * - When eager loaded via with('chapter'): Validates in-memory (O(1)) without extra N+1 queries.
     * - When lazy loaded: Executes a scoped query: WHERE id = chapter_id AND comic_id = comic_id.
     * - Returns null if the chapter does not exist or belongs to a different comic.
     */
    public function getVerifiedChapterAttribute(): ?Chapter
    {
        if ($this->relationLoaded('chapter')) {
            return ($this->chapter && (int) $this->chapter->comic_id === (int) $this->comic_id)
                ? $this->chapter
                : null;
        }

        if (! $this->chapter_id || ! $this->comic_id) {
            return null;
        }

        return Chapter::where('id', $this->chapter_id)
            ->where('comic_id', $this->comic_id)
            ->first();
    }

    /**
     * Helper method to retrieve the verified chapter matching both chapter_id and comic_id.
     */
    public function verifiedChapter(): ?Chapter
    {
        return $this->verified_chapter;
    }

    /**
     * Scope a query to filter records matching both comic and chapter.
     */
    public function scopeForComicAndChapter($query, int $comicId, int $chapterId)
    {
        return $query->where('comic_id', $comicId)->where('chapter_id', $chapterId);
    }
}
