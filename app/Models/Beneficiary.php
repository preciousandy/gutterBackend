<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category',
        'name',
        'nickname',
        'bank_name',
        'bank_code',
        'account_number',
        'meter_type',
    ];

    /**
     * Get the user that owns the beneficiary.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
