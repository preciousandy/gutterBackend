<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserSaving extends Model
{
    use HasFactory;

    protected $table = 'user_savings';

    protected $fillable = [
        'user_id',
        'plan_id',
        'target_category_id',
        'title',
        'balance',
        'target_amount',
        'annual_rate',
        'total_interest_earned',
        'last_interest_at',
        'locked_until',
        'status',
    ];

    protected $casts = [
        'balance' => 'float',
        'target_amount' => 'float',
        'annual_rate' => 'float',
        'total_interest_earned' => 'float',
        'last_interest_at' => 'datetime',
        'locked_until' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SavingsPlan::class, 'plan_id');
    }

    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(SavingsTargetCategory::class, 'target_category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class, 'user_saving_id');
    }
}
