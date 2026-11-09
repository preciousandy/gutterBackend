<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoRefund extends Model
{
    use HasFactory;

    protected $table = 'crypto_refunds';

    protected $fillable = [
        'refund_reference',
        'crypto_transaction_id',
        'user_id',
        'admin_id',
        'admin_name',
        'asset_type',
        'currency',
        'original_amount',
        'refunded_amount',
        'balance_before',
        'balance_after',
        'status',
        'reason',
        'verification_notes',
        'original_transaction_snapshot',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'float',
            'refunded_amount' => 'float',
            'balance_before' => 'float',
            'balance_after' => 'float',
            'original_transaction_snapshot' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function cryptoTransaction(): BelongsTo
    {
        return $this->belongsTo(CryptoTransaction::class, 'crypto_transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

