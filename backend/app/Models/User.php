<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'reader',
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     * Note: 'role' and 'is_active' are intentionally excluded to prevent unauthorized privilege escalation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Comics created/owned by this user.
     */
    public function comics(): HasMany
    {
        return $this->hasMany(Comic::class, 'user_id');
    }

    /**
     * Comics approved by this user (Admin).
     */
    public function approvedComics(): HasMany
    {
        return $this->hasMany(Comic::class, 'approved_by');
    }

    /**
     * Comics followed by this user.
     */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class, 'user_id');
    }

    /**
     * Comics bookmarked by this user.
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'user_id');
    }

    /**
     * Reading history records for this user.
     */
    public function readingHistories(): HasMany
    {
        return $this->hasMany(ReadingHistory::class, 'user_id');
    }
}
