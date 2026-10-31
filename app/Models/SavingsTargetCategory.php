<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavingsTargetCategory extends Model
{
    use HasFactory;

    protected $table = 'savings_target_categories';

    protected $fillable = [
        'name',
        'icon',
        'color',
        'description',
        'is_enabled',
        'order_index',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'order_index' => 'integer',
    ];

    public function userSavings(): HasMany
    {
        return $this->hasMany(UserSaving::class, 'target_category_id');
    }
}
