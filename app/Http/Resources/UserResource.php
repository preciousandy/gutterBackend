<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array matching Gutter mobile client contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => (string) ($this->first_name ?? ''),
            'last_name' => (string) ($this->last_name ?? ''),
            'email' => (string) $this->email,
            'phone' => (string) ($this->phone ?? ''),
            'tier' => (int) ($this->tier ?? 1),
            'kyc_status' => (string) ($this->kyc_status ?? 'unverified'),
            'has_pin' => (bool) ($this->has_pin ?? false),
            'status' => (string) ($this->status ?? 'active'),
            'status_reason' => $this->status_reason,
            'is_frozen' => $this->isFrozen(),
            'is_suspended' => $this->isSuspended(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'email_verified' => !is_null($this->email_verified_at),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
