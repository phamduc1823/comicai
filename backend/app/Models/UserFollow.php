<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFollow extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'user_follows';

    protected $fillable = [
        'follower_id',
        'following_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'follower_id' => 'integer',
            'following_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($follow) {
            if (! $follow->created_at) {
                $follow->created_at = now();
            }
        });
    }

    /**
     * The follower (the user who pressed Follow).
     */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /**
     * The following (the user being followed).
     */
    public function following(): BelongsTo
    {
        return $this->belongsTo(User::class, 'following_id');
    }
}
