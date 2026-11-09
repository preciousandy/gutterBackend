<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CryptoWebhookLog extends Model
{
    use HasFactory;

    protected $table = 'crypto_webhook_logs';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'reference',
        'signature',
        'signature_valid',
        'payload_hash',
        'headers',
        'payload',
        'processing_status',
        'processing_notes',
        'attempts',
        'ip_address',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'headers' => 'array',
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}

