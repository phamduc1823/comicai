<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenNameHistory extends Model
{
    protected $table = 'pen_name_histories';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'old_pen_name',
        'new_pen_name',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
