<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoExchangeOrder extends Model
{
    use HasFactory;

    protected $table = 'crypto_exchange_orders';

    protected $fillable = [
        'user_id',
        'crypto_transaction_id',
        'reference',
        'quidax_quotation_id',
        'quidax_swap_id',
        'side',
        'from_currency',
        'to_currency',
        'from_amount',
        'to_amount',
        'quoted_rate',
        'executed_rate',
        'platform_fee_ngn',
        'platform_fee_crypto',
        'net_amount_received',
        'fee_config_snapshot',
        'status',
        'quidax_response',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_amount' => 'float',
            'to_amount' => 'float',
            'quoted_rate' => 'float',
            'executed_rate' => 'float',
            'platform_fee_ngn' => 'float',
            'platform_fee_crypto' => 'float',
            'net_amount_received' => 'float',
            'fee_config_snapshot' => 'array',
            'quidax_response' => 'array',
            'expires_at' => 'datetime',
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

