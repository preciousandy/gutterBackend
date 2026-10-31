<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'document_type',
        'document_number',
        'status',
        'provider',
        'id_data',
        'face_data',
        'rejection_reason',
        'id_verified_at',
        'face_verified_at',
    ];

    protected $hidden = [
        'document_number',
    ];

    protected function casts(): array
    {
        return [
            'document_number' => 'encrypted',
            'id_data' => 'array',
            'face_data' => 'array',
            'id_verified_at' => 'datetime',
            'face_verified_at' => 'datetime',
        ];
    }

    /**
     * Get a safely masked version of the document number (e.g. *******8901).
     */
    public function getMaskedDocumentNumberAttribute(): string
    {
        $doc = (string) $this->document_number;
        if (strlen($doc) <= 4) {
            return str_repeat('*', strlen($doc));
        }
        return str_repeat('*', strlen($doc) - 4) . substr($doc, -4);
    }

    /**
     * Get the user that owns the KYC verification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
