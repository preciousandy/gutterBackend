<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'phone',
        'password',
        'status',
        'status_reason',
        'status_updated_by',
        'status_updated_at',
        'last_login_at',
        'tier',
        'kyc_status',
        'has_pin',
        'pin',
        'wallet_balance',
        'bank_name',
        'account_number',
        'read_notification_ids',
        'notifications_read_at',
        'auto_convert_crypto',
        'quidax_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pin',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'status_updated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'has_pin' => 'boolean',
            'tier' => 'integer',
            'wallet_balance' => 'float',
            'read_notification_ids' => 'array',
            'notifications_read_at' => 'datetime',
            'auto_convert_crypto' => 'boolean',
        ];
    }

    /**
     * Status check helper methods.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->trashed();
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, ['suspended', 'blocked']) || $this->trashed();
    }

    public function canTransact(): bool
    {
        return $this->status === 'active' && !$this->trashed();
    }

    /**
     * Get the email OTPs for the user.
     */
    public function emailOtps()
    {
        return $this->hasMany(EmailOtp::class);
    }

    /**
     * Get the transactions for the user.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the saved beneficiaries for the user.
     */
    public function beneficiaries()
    {
        return $this->hasMany(Beneficiary::class);
    }

    /**
     * Get the KYC verifications for the user.
     */
    public function kycVerifications()
    {
        return $this->hasMany(KycVerification::class);
    }

    /**
     * Get the latest KYC verification for the user.
     */
    public function latestKycVerification()
    {
        return $this->hasOne(KycVerification::class)->latestOfMany();
    }

    /**
     * Get the crypto wallets for the user.
     */
    public function cryptoWallets()
    {
        return $this->hasMany(CryptoWallet::class);
    }

    /**
     * Get the crypto deposit addresses for the user.
     */
    public function cryptoAddresses()
    {
        return $this->hasMany(CryptoAddress::class);
    }

    /**
     * Get the crypto deposits for the user.
     */
    public function cryptoDeposits()
    {
        return $this->hasMany(CryptoDeposit::class);
    }
}

