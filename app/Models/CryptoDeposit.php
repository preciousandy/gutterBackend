<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CryptoDeposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tx_hash',
        'quidax_deposit_id',
        'symbol',
        'network',
        'amount',
        'confirmations',
        'platform_fee',
        'net_amount',
        'status',
        'converted_to_naira',
        'naira_amount',
        'rate',
        'fee_config_snapshot',
        'quidax_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'confirmations' => 'integer',
            'platform_fee' => 'float',
            'net_amount' => 'float',
            'converted_to_naira' => 'boolean',
            'naira_amount' => 'float',
            'rate' => 'float',
            'fee_config_snapshot' => 'array',
            'quidax_response' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
