<?php

use App\Models\SavingsTransaction;
use App\Models\UserSaving;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Daily Savings Interest Accrual Command
 * Runs daily at 06:00 AM.
 * Fully thread-safe and race-condition free with row-level locks and idempotency checks.
 */
Artisan::command('savings:accrue-interest', function () {
    $this->info('Starting savings daily interest accrual process...');

    $todayStart = now()->startOfDay();

    // Find all active savings accounts with positive balance that haven't received interest today
    $activeSavings = UserSaving::where('status', 'active')
        ->where('balance', '>', 0)
        ->where(function ($q) use ($todayStart) {
            $q->whereNull('last_interest_at')
              ->orWhere('last_interest_at', '<', $todayStart);
        })
        ->get();

    $this->info("Found {$activeSavings->count()} accounts eligible for interest calculation.");

    $processed = 0;
    $totalInterestPaid = 0.00;

    foreach ($activeSavings as $account) {
        try {
            DB::transaction(function () use ($account, $todayStart, &$processed, &$totalInterestPaid) {
                // Lock row pessimistically to prevent race conditions with user deposits/withdrawals
                $lockedSaving = UserSaving::where('id', $account->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if (!$lockedSaving || (float) $lockedSaving->balance <= 0) {
                    return;
                }

                // Prevent duplicate interest on the same day under lock
                if ($lockedSaving->last_interest_at && $lockedSaving->last_interest_at >= $todayStart) {
                    return;
                }

                $annualRate = (float) $lockedSaving->annual_rate;
                if ($annualRate <= 0) {
                    return;
                }

                $dailyRate = ($annualRate / 100.0) / 365.0;
                $interestAmount = round((float) $lockedSaving->balance * $dailyRate, 2);

                if ($interestAmount < 0.01) {
                    return;
                }

                $newBalance = round((float) $lockedSaving->balance + $interestAmount, 2);
                $newTotalEarned = round((float) $lockedSaving->total_interest_earned + $interestAmount, 2);

                $lockedSaving->balance = $newBalance;
                $lockedSaving->total_interest_earned = $newTotalEarned;
                $lockedSaving->last_interest_at = now();
                $lockedSaving->save();

                $ref = 'SAV_INT_' . strtoupper(Str::random(12));

                SavingsTransaction::create([
                    'user_id' => $lockedSaving->user_id,
                    'user_saving_id' => $lockedSaving->id,
                    'type' => 'interest',
                    'amount' => $interestAmount,
                    'balance_after' => $newBalance,
                    'reference' => $ref,
                    'description' => "Daily interest payout ({$annualRate}% p.a.)",
                    'status' => 'successful',
                ]);

                $processed++;
                $totalInterestPaid += $interestAmount;
            });
        } catch (\Throwable $e) {
            Log::error("Failed to accrue interest for UserSaving #{$account->id}: " . $e->getMessage());
        }
    }

    $this->info("Completed. Accrued interest on {$processed} accounts totaling ₦" . number_format($totalInterestPaid, 2) . ".");
})->purpose('Accrue and credit daily compounding savings interest safely with row locks');

Schedule::command('savings:accrue-interest')->dailyAt('06:00');
