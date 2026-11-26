<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorApplication extends Model
{
    protected $table = 'creator_applications';

    protected $fillable = [
        'application_code',
        'user_id',
        'pen_name',
        'pen_name_canonical',
        'bio',
        'reason',
        'sample_works',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'canceled_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'sample_works' => 'array',
            'version' => 'integer',
            'reviewed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CreatorApplicationHistory::class, 'application_id')->orderBy('version', 'asc');
    }
}
