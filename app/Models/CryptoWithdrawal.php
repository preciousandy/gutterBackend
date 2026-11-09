<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoWithdrawal extends Model
{
    use HasFactory;

    protected $table = 'crypto_withdrawals';

    protected $fillable = [
        'user_id',
        'crypto_transaction_id',
        'reference',
        'idempotency_key',
        'quidax_withdrawal_id',
        'symbol',
        'network',
        'destination_type',
        'recipient_address',
        'memo',
        'amount',
        'network_fee',
        'platform_fee',
        'platform_fee_ngn',
        'total_amount',
        'exchange_rate',
        'tx_hash',
        'status',
        'requires_admin_approval',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'fee_config_snapshot',
        'quidax_response',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'network_fee' => 'float',
            'platform_fee' => 'float',
            'platform_fee_ngn' => 'float',
            'total_amount' => 'float',
            'exchange_rate' => 'float',
            'requires_admin_approval' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'fee_config_snapshot' => 'array',
            'quidax_response' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cryptoTransaction(): BelongsTo
    {
        return $this->belongsTo(CryptoTransaction::class, 'crypto_transaction_id');
    }
}

