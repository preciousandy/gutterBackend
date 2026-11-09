<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CryptoFeeSetting extends Model
{
    use HasFactory;

    protected $table = 'crypto_fee_settings';

    protected $fillable = [
        'service_type',
        'label',
        'description',
        'is_enabled',
        'maintenance_mode',
        'maintenance_message',
        'percentage_fee',
        'fixed_fee_ngn',
        'fixed_fee_crypto',
        'min_amount',
        'max_amount',
        'min_withdrawal_amount',
        'max_withdrawal_amount',
        'manual_approval_threshold',
        'supported_coins',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'maintenance_mode' => 'boolean',
            'percentage_fee' => 'float',
            'fixed_fee_ngn' => 'float',
            'fixed_fee_crypto' => 'float',
            'min_amount' => 'float',
            'max_amount' => 'float',
            'min_withdrawal_amount' => 'float',
            'max_withdrawal_amount' => 'float',
            'manual_approval_threshold' => 'float',
            'supported_coins' => 'array',
        ];
    }
}

