<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class EmailOtp extends Model
{
    use HasFactory, Prunable;

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id',
        'email',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Determine which models should be pruned.
     * Stale OTP records older than 24 hours are automatically purged.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subHours(24));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    public function isLocked(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function remainingAttempts(): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->attempts);
    }

    public function isValid(string $plainCode): bool
    {
        if ($this->isExpired() || $this->isVerified() || $this->isLocked()) {
            return false;
        }

        return hash_equals($this->code_hash, hash('sha256', $plainCode));
    }
}
