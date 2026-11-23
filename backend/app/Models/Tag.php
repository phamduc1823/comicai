<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'catalog:tags:all';
    public const CACHE_TTL = 86400; // 24 hours

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * Invalidate catalog tags cache.
     */
    public static function flushCache(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Safe cache boundary
        }
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tags';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /**
     * Comics tagged with this feature tag.
     * Note: The pivot table 'comic_tag' does not have timestamps.
     */
    public function comics(): BelongsToMany
    {
        return $this->belongsToMany(Comic::class, 'comic_tag', 'tag_id', 'comic_id');
    }
}
