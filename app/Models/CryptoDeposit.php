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
        'symbol',
        'network',
        'amount',
        'status',
        'converted_to_naira',
        'naira_amount',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'converted_to_naira' => 'boolean',
            'naira_amount' => 'float',
            'rate' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
