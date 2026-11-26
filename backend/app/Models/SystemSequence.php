<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSequence extends Model
{
    protected $table = 'system_sequences';
    protected $primaryKey = 'name';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'current_val',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'current_val' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
