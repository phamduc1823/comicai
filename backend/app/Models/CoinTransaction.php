<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CoinTransaction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'coin_transactions';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'refund_for_transaction_id',
        'idempotency_key',
        'payload_hash',
        'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'reference_id' => 'integer',
            'refund_for_transaction_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CoinTransaction $transaction) {
            throw new \LogicException('Giao dịch sổ cái CoinTransaction là bất biến (append-only) và không thể chỉnh sửa.');
        });

        static::deleting(function (CoinTransaction $transaction) {
            throw new \LogicException('Giao dịch sổ cái CoinTransaction là bất biến (append-only) và không thể xóa.');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(CoinTransaction::class, 'refund_for_transaction_id');
    }

    public function refundTransaction(): HasOne
    {
        return $this->hasOne(CoinTransaction::class, 'refund_for_transaction_id');
    }
}
