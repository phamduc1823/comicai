<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorApplicationHistory extends Model
{
    protected $table = 'creator_application_histories';

    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'version',
        'pen_name',
        'bio',
        'reason',
        'sample_works',
        'status',
        'rejection_reason',
        'action_type',
        'action_by',
        'action_at',
        'snapshot_data',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'sample_works' => 'array',
            'snapshot_data' => 'array',
            'action_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CreatorApplication::class, 'application_id');
    }

    public function actionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by');
    }
}
