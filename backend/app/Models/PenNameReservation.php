<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenNameReservation extends Model
{
    protected $table = 'pen_name_reservations';
    protected $primaryKey = 'pen_name_canonical';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'pen_name_canonical',
        'pen_name',
        'reserved_by_user_id',
        'reservation_type',
        'application_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(CreatorApplication::class, 'application_id');
    }
}
