<?php

namespace App\Services;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OtpMailService
{
    public const TTL_MINUTES = 5;
    public const COOLDOWN_SECONDS = 60;

    /**
     * Generate a new 6-digit OTP, persist it, and dispatch it asynchronously.
     *
     * @throws ValidationException if requested before cooldown expires
     */
    public function generateAndSend(User $user, bool $enforceCooldown = true): EmailOtp
    {
        // 1. Enforce cooldown to prevent spam / rapid re-sending
        if ($enforceCooldown) {
            $latest = EmailOtp::where('user_id', $user->id)
                ->whereNull('verified_at')
                ->latest()
                ->first();

            if ($latest && $latest->created_at->diffInSeconds(now()) < self::COOLDOWN_SECONDS) {
                $secondsLeft = self::COOLDOWN_SECONDS - (int) $latest->created_at->diffInSeconds(now());
                throw ValidationException::withMessages([
                    'cooldown' => ["Please wait {$secondsLeft}s before requesting a new code."],
                ]);
            }
        }

        // 2. Generate a cryptographically secure 6-digit code
        $code = sprintf('%06d', random_int(0, 999999));

        // 3. Atomic database operations: invalidate old pending OTPs and insert new one
        $otp = DB::transaction(function () use ($user, $code) {
            EmailOtp::where('user_id', $user->id)
                ->whereNull('verified_at')
                ->delete();

            return EmailOtp::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'code_hash' => hash('sha256', $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        // 4. Log in local environment for easy debugging and offline testing
        if (app()->environment('local')) {
            Log::info("Generated OTP for {$user->email}: {$code} (Expires in " . self::TTL_MINUTES . " mins)");
        }

        // 5. Asynchronous non-blocking dispatch: API response finishes in < 50ms
        SendEmailOtpJob::dispatchAfterResponse($user, $code, self::TTL_MINUTES);

        return $otp;
    }

    /**
     * Verify the code against the user's active OTP with atomic locking and brute force defense.
     *
     * @return array{status: 'success'|'invalid'|'locked'|'expired'|'not_found', message: string}
     */
    public function verify(User $user, string $plainCode): array
    {
        return DB::transaction(function () use ($user, $plainCode) {
            /** @var EmailOtp|null $otp */
            $otp = EmailOtp::where('user_id', $user->id)
                ->whereNull('verified_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (!$otp) {
                return [
                    'status' => 'not_found',
                    'message' => 'No active verification code found. Please request a new code.',
                ];
            }

            if ($otp->isExpired()) {
                return [
                    'status' => 'expired',
                    'message' => 'The verification code has expired. Please request a new code.',
                ];
            }

            if ($otp->isLocked()) {
                return [
                    'status' => 'locked',
                    'message' => 'Too many failed attempts. This code has been invalidated. Please request a new code.',
                ];
            }

            // Increment attempt counter atomically
            $otp->increment('attempts');

            // Secure timing-safe verification
            if (!hash_equals($otp->code_hash, hash('sha256', $plainCode))) {
                if ($otp->attempts >= EmailOtp::MAX_ATTEMPTS) {
                    $otp->update(['expires_at' => now()]); // Invalidate permanently
                    return [
                        'status' => 'locked',
                        'message' => 'Too many failed attempts. This code has been invalidated. Please request a new code.',
                    ];
                }

                $remaining = $otp->remainingAttempts();
                return [
                    'status' => 'invalid',
                    'message' => "Invalid verification code. {$remaining} attempt(s) remaining.",
                ];
            }

            // Success: mark OTP and User as verified
            $otp->update(['verified_at' => now()]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return [
                'status' => 'success',
                'message' => 'Email verified successfully.',
            ];
        });
    }
}
