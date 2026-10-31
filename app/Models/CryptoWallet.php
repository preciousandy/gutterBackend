<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CryptoWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'symbol',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
