<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
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
     * Comics followed by this user (Many-to-Many).
     */
    public function followedComics(): BelongsToMany
    {
        return $this->belongsToMany(Comic::class, 'follows', 'user_id', 'comic_id')
            ->withPivot('created_at');
    }

    /**
     * Comics bookmarked by this user (Many-to-Many).
     */
    public function bookmarkedComics(): BelongsToMany
    {
        return $this->belongsToMany(Comic::class, 'bookmarks', 'user_id', 'comic_id')
            ->withPivot('created_at');
    }

    /**
     * Reading history records for this user.
     */
    public function readingHistories(): HasMany
    {
        return $this->hasMany(ReadingHistory::class, 'user_id');
    }

    /**
     * Community posts created by this user.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    /**
     * Comments written by this user.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    /**
     * Likes given by this user.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class, 'user_id');
    }

    /**
     * Reports submitted by this user.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    /**
     * Reports resolved by this administrator.
     */
    public function resolvedReports(): HasMany
    {
        return $this->hasMany(Report::class, 'resolved_by');
    }

    /**
     * Conversation participant records for this user.
     */
    public function conversationParticipants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class, 'user_id');
    }

    /**
     * Conversations this user participates in (Many-to-Many).
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants', 'user_id', 'conversation_id')
            ->withPivot('last_read_message_id', 'last_read_at', 'is_muted')
            ->withTimestamps();
    }

    /**
     * Chat messages sent by this user.
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * User's coin wallet.
     */
    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    /**
     * User's coin transaction history ledger.
     */
    public function coinTransactions(): HasMany
    {
        return $this->hasMany(CoinTransaction::class, 'user_id');
    }

    /**
     * VIP chapters unlocked by this user.
     */
    public function chapterUnlocks(): HasMany
    {
        return $this->hasMany(ChapterUnlock::class, 'user_id');
    }

    /**
     * Comics purchased by this user.
     */
    public function comicPurchases(): HasMany
    {
        return $this->hasMany(ComicPurchase::class, 'user_id');
    }

    /**
     * Official creator profile for this user.
     */
    public function creatorProfile(): HasOne
    {
        return $this->hasOne(CreatorProfile::class, 'user_id');
    }

    /**
     * Creator applications submitted by this user.
     */
    public function creatorApplications(): HasMany
    {
        return $this->hasMany(CreatorApplication::class, 'user_id');
    }

    /**
     * Audit logs for creator management events.
     */
    public function creatorAuditLogs(): HasMany
    {
        return $this->hasMany(CreatorAuditLog::class, 'user_id');
    }

    /**
     * Pen name reservations held by this user.
     */
    public function penNameReservations(): HasMany
    {
        return $this->hasMany(PenNameReservation::class, 'reserved_by_user_id');
    }
}
