<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'admin_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Check if admin has specific permission or role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function canManageAppControls(): bool
    {
        return in_array($this->role, ['super_admin']);
    }

    public function canManageKyc(): bool
    {
        return in_array($this->role, ['super_admin', 'compliance_officer']);
    }

    public function canManageComplaints(): bool
    {
        return in_array($this->role, ['super_admin', 'support_lead']);
    }

    public function canViewFinancials(): bool
    {
        return in_array($this->role, ['super_admin', 'financial_auditor']);
    }
}
