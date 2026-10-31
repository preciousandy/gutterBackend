<?php

namespace App\Jobs;

use App\Mail\EmailOtpMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendEmailOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public string $code,
        public int $expiresInMinutes = 5
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $mailable = new EmailOtpMail($this->user, $this->code, $this->expiresInMinutes);

        // Attempt 1: Resend
        try {
            Mail::mailer('resend')->to($this->user->email)->send($mailable);
            Log::info("Async OTP dispatched to {$this->user->email} via Resend.");
            return;
        } catch (Throwable $e) {
            Log::warning("Resend async failed for {$this->user->email}: {$e->getMessage()}. Triggering hosting SMTP fallback...");
        }

        // Attempt 2: Fallback to Hosting SMTP
        try {
            Mail::mailer('smtp')->to($this->user->email)->send($mailable);
            Log::info("Async OTP dispatched to {$this->user->email} via Hosting SMTP fallback.");
        } catch (Throwable $e) {
            Log::error("Both Resend and Hosting SMTP async failed for {$this->user->email}: {$e->getMessage()}");
        }
    }
}
