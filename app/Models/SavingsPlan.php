<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsPlan extends Model
{
    use HasFactory;

    protected $table = 'savings_plans';

    protected $fillable = [
        'key',
        'name',
        'category',
        'tagline',
        'annual_rate',
        'early_break_fee_percentage',
        'badge',
        'badge_bg',
        'badge_text',
        'icon',
        'theme_color',
        'theme_bg',
        'description',
        'features',
        'min_deposit',
        'lock_period',
        'is_enabled',
        'allow_withdrawals',
        'order_index',
    ];

    protected $casts = [
        'annual_rate' => 'float',
        'early_break_fee_percentage' => 'float',
        'min_deposit' => 'float',
        'is_enabled' => 'boolean',
        'allow_withdrawals' => 'boolean',
        'features' => 'array',
        'order_index' => 'integer',
    ];

    public function userSavings(): HasMany
    {
        return $this->hasMany(UserSaving::class, 'plan_id');
    }
}
