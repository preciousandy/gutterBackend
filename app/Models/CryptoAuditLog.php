<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoAuditLog extends Model
{
    use HasFactory;

    protected $table = 'crypto_audit_logs';

    protected $fillable = [
        'crypto_transaction_id',
        'user_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'action',
        'previous_status',
        'new_status',
        'description',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function cryptoTransaction(): BelongsTo
    {
        return $this->belongsTo(CryptoTransaction::class, 'crypto_transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

