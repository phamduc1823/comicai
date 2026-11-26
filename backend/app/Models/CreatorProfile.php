<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorProfile extends Model
{
    protected $table = 'creator_profiles';

    protected $fillable = [
        'user_id',
        'creator_code',
        'pen_name',
        'pen_name_canonical',
        'bio',
        'status',
        'approved_at',
        'revoked_at',
        'can_reapply',
        'reapply_granted_at',
        'reapply_granted_by',
        'reapply_grant_reason',
        'reapplied_at',
        'reapplied_application_id',
    ];

    protected function casts(): array
    {
        return [
            'can_reapply' => 'boolean',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reapply_granted_at' => 'datetime',
            'reapplied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reapplyGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reapply_granted_by');
    }

    public function reappliedApplication(): BelongsTo
    {
        return $this->belongsTo(CreatorApplication::class, 'reapplied_application_id');
    }
}
