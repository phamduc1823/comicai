<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Banner extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'catalog:banners:active';
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
     * Invalidate catalog active banners cache.
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
    protected $table = 'banners';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'comic_id',
        'title_override',
        'desktop_banner_path',
        'mobile_banner_path',
        'sort_order',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The comic featured in this banner.
     */
    public function comic(): BelongsTo
    {
        return $this->belongsTo(Comic::class, 'comic_id');
    }
}
