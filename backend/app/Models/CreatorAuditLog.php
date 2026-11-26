<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorAuditLog extends Model
{
    protected $table = 'creator_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor_id',
        'application_id',
        'event_type',
        'old_values',
        'new_values',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CreatorApplication::class, 'application_id');
    }
}
