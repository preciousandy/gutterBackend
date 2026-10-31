<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsTransaction extends Model
{
    use HasFactory;

    protected $table = 'savings_transactions';

    protected $fillable = [
        'user_id',
        'user_saving_id',
        'type',
        'amount',
        'fee',
        'forfeited_interest',
        'principal',
        'net_payout',
        'balance_after',
        'reference',
        'description',
        'status',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee' => 'float',
        'forfeited_interest' => 'float',
        'principal' => 'float',
        'net_payout' => 'float',
        'balance_after' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userSaving(): BelongsTo
    {
        return $this->belongsTo(UserSaving::class, 'user_saving_id');
    }
}
