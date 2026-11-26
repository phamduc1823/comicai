<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComicPurchase extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'comic_purchases';

    protected $fillable = [
        'user_id',
        'comic_id',
        'status',
        'price_paid',
        'transaction_id',
        'refund_transaction_id',
        'purchase_count',
        'purchased_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'price_paid' => 'integer',
            'purchase_count' => 'integer',
            'purchased_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comic(): BelongsTo
    {
        return $this->belongsTo(Comic::class, 'comic_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CoinTransaction::class, 'transaction_id');
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(CoinTransaction::class, 'refund_transaction_id');
    }
}
