<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CryptoTransaction extends Model
{
    use HasFactory;

    protected $table = 'crypto_transactions';

    protected $appends = [
        'transaction_type',
        'network_fee_crypto',
    ];

    public function getTransactionTypeAttribute(): ?string
    {
        return $this->type;
    }

    public function getNetworkFeeCryptoAttribute(): float
    {
        return (float) ($this->network_fee ?? 0.0);
    }

    protected $fillable = [
        'user_id',
        'transaction_id',
        'reference',
        'idempotency_key',
        'quidax_reference',
        'type',
        'symbol',
        'network',
        'destination_type',
        'wallet_address',
        'memo',
        'tx_hash',
        'crypto_amount',
        'fiat_amount',
        'exchange_rate',
        'network_fee',
        'platform_fee_crypto',
        'platform_fee_ngn',
        'total_crypto_deducted',
        'net_crypto_amount',
        'net_fiat_amount',
        'status',
        'failure_reason',
        'fee_config_snapshot',
        'quidax_payload',
        'quidax_response',
        'is_refunded',
        'refunded_amount',
        'refunded_currency',
        'refund_reference',
        'refunded_at',
        'approved_by',
        'approved_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'crypto_amount' => 'float',
            'fiat_amount' => 'float',
            'exchange_rate' => 'float',
            'network_fee' => 'float',
            'platform_fee_crypto' => 'float',
            'platform_fee_ngn' => 'float',
            'total_crypto_deducted' => 'float',
            'net_crypto_amount' => 'float',
            'net_fiat_amount' => 'float',
            'fee_config_snapshot' => 'array',
            'quidax_payload' => 'array',
            'quidax_response' => 'array',
            'is_refunded' => 'boolean',
            'refunded_amount' => 'float',
            'refunded_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function exchangeOrder(): HasOne
    {
        return $this->hasOne(CryptoExchangeOrder::class, 'crypto_transaction_id');
    }

    public function withdrawal(): HasOne
    {
        return $this->hasOne(CryptoWithdrawal::class, 'crypto_transaction_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CryptoRefund::class, 'crypto_transaction_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CryptoAuditLog::class, 'crypto_transaction_id')->latest();
    }

    /**
     * Determine whether this transaction is eligible for an admin refund.
     * Rules:
     * - Must not already be refunded or in refund_processing
     * - Must be in failed, rejected, or pending/processing (if failed on Quidax or manually cancelled)
     * - Never auto-refund a 'successful' external blockchain withdrawal
     */
    public function isEligibleForRefund(): bool
    {
        if ($this->is_refunded || in_array($this->status, ['refunded', 'refund_processing'], true)) {
            return false;
        }

        if ($this->type === 'receive_crypto') {
            return false; // Incoming deposits did not debit user wallet
        }

        if ($this->type === 'send_crypto' && $this->status === 'successful') {
            return false; // Never refund a successful blockchain withdrawal
        }

        return in_array($this->status, ['failed', 'rejected', 'pending', 'processing'], true);
    }
}
