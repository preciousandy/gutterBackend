<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavingsPlan;
use App\Models\SavingsTargetCategory;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSaving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SavingsController extends Controller
{
    /**
     * Get active savings plans for the mobile app.
     *
     * Route: GET /api/v1/savings/plans
     */
    public function plans(): JsonResponse
    {
        $plans = SavingsPlan::where('is_enabled', true)
            ->orderBy('order_index')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Get active target savings categories for the mobile app.
     *
     * Route: GET /api/v1/savings/target-categories
     */
    public function targetCategories(): JsonResponse
    {
        $categories = SavingsTargetCategory::where('is_enabled', true)
            ->orderBy('order_index')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get the user's savings portfolio dashboard.
     *
     * Route: GET /api/v1/savings/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get all active plans
        $plans = SavingsPlan::orderBy('order_index')->get();

        // Get active target categories
        $targetCategories = SavingsTargetCategory::where('is_enabled', true)
            ->orderBy('order_index')
            ->get();

        // Get user's active savings accounts
        $userSavings = UserSaving::with(['plan', 'targetCategory'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        // Sum balances
        $totalSavingsBalance = (float) $userSavings->sum('balance');
        $totalInterestEarned = (float) $userSavings->sum('total_interest_earned');

        // Yesterday's interest estimate
        $yesterdayInterest = $totalSavingsBalance > 0
            ? round(($totalSavingsBalance * 0.15) / 365, 2)
            : 0.00;

        // Group balance by plan key
        $planBalances = [];
        foreach ($userSavings as $saving) {
            $planKey = $saving->plan ? $saving->plan->key : 'other';
            $planBalances[$planKey] = ($planBalances[$planKey] ?? 0.0) + (float) $saving->balance;
        }

        // Map plans with user-specific balance
        $mappedPlans = $plans->map(function ($plan) use ($planBalances) {
            $data = $plan->toArray();
            $data['user_balance'] = $planBalances[$plan->key] ?? 0.0;
            return $data;
        });

        // User goals & locked accounts
        $goals = $userSavings->filter(function ($saving) {
            return (!empty($saving->target_amount) && $saving->target_amount > 0)
                || !empty($saving->locked_until)
                || (float) $saving->balance > 0;
        })->map(function ($saving) {
            $daysRemaining = $saving->locked_until
                ? max(0, (int) now()->diffInDays($saving->locked_until, false))
                : null;
            $isLocked = (bool) ($saving->locked_until && $saving->locked_until->isFuture());
            $feePercentage = (float) ($saving->plan?->early_break_fee_percentage ?? 2.50);
            $balance = (float) $saving->balance;
            $interest = (float) $saving->total_interest_earned;
            $principal = max(0.0, round($balance - min($interest, $balance), 2));

            return [
                'id' => $saving->id,
                'title' => $saving->title,
                'plan_id' => $saving->plan_id,
                'plan_name' => $saving->plan ? $saving->plan->name : 'Savings Goal',
                'plan_key' => $saving->plan ? $saving->plan->key : 'target',
                'category_id' => $saving->target_category_id,
                'category_name' => $saving->targetCategory ? $saving->targetCategory->name : null,
                'category_icon' => $saving->targetCategory ? $saving->targetCategory->icon : ($saving->plan ? $saving->plan->icon : 'flag-outline'),
                'saved_amount' => $balance,
                'target_amount' => !empty($saving->target_amount) && $saving->target_amount > 0 ? (float) $saving->target_amount : $balance,
                'principal' => $principal,
                'total_interest_earned' => $interest,
                'annual_rate' => $saving->annual_rate,
                'maturity_date' => $saving->locked_until ? $saving->locked_until->toIso8601String() : null,
                'maturity_date_formatted' => $saving->locked_until ? $saving->locked_until->format('d M Y') : null,
                'days_remaining' => $daysRemaining,
                'is_locked' => $isLocked,
                'early_break_fee_percentage' => $feePercentage,
                'color' => $saving->targetCategory ? $saving->targetCategory->color : ($saving->plan ? $saving->plan->theme_color : '#7C3AED'),
            ];
        })->values();

        // Re-read fresh user balance
        $freshWalletBalance = (float) ($user->fresh()?->wallet_balance ?? $user->wallet_balance);

        return response()->json([
            'success' => true,
            'data' => [
                'total_savings_balance' => $totalSavingsBalance,
                'total_interest_earned' => $totalInterestEarned,
                'yesterday_interest' => $yesterdayInterest,
                'wallet_balance' => $freshWalletBalance,
                'plans' => $mappedPlans,
                'target_categories' => $targetCategories,
                'goals' => $goals,
            ],
        ]);
    }

    /**
     * Deposit into a savings plan.
     * Protected by:
     * - Distributed user-level mutex cache lock
     * - Pessimistic row locking on User and UserSaving (SELECT ... FOR UPDATE)
     * - Strict transactional balance verification
     * - Idempotency key deduplication
     *
     * Route: POST /api/v1/savings/deposit
     */
    public function deposit(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'plan_id' => 'required',
            'user_saving_id' => 'nullable|integer',
            'target_category_id' => 'nullable|integer',
            'target_amount' => 'nullable|numeric|min:1000',
            'amount' => ['required', 'numeric', 'min:50', 'max:50000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'title' => 'nullable|string|max:100',
            'duration_days' => 'nullable|integer|min:7|max:730',
            'maturity_date' => 'nullable|date',
            'idempotency_key' => 'nullable|string|max:64',
        ], [
            'amount.regex' => 'Amount must not exceed 2 decimal places.',
            'amount.min' => 'Minimum deposit amount is ₦50.00.',
        ]);

        $amount = round((float) $validated['amount'], 2);
        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid deposit amount.',
            ], 422);
        }

        // 1. Idempotency Guard: return existing result if exact request was recently processed
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        if (!empty($idempotencyKey)) {
            $cachedPayload = Cache::get("idempotency_savings_{$user->id}_{$idempotencyKey}");
            if ($cachedPayload) {
                return response()->json($cachedPayload);
            }
        }

        // 2. Distributed Mutex Lock: prevent concurrent double-clicks / racing requests for same user
        $mutex = Cache::lock("savings_user_mutex_{$user->id}", 15);
        if (!$mutex->block(5)) {
            return response()->json([
                'success' => false,
                'message' => 'Another savings transaction is currently being processed on your account. Please wait.',
            ], 429);
        }

        try {
            // Find plan by ID or Key
            $plan = SavingsPlan::where('id', $validated['plan_id'])
                ->orWhere('key', $validated['plan_id'])
                ->first();

            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid savings plan selected.',
                ], 404);
            }

            if (!$plan->is_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => "The {$plan->name} plan is currently disabled by admin. Please select another plan.",
                ], 422);
            }

            // Target Savings specific rule: Minimum deposit is ₦1,000 as configured
            $isTargetPlan = in_array($plan->key, ['target']) || $plan->category === 'target';
            $minDepositRequired = $isTargetPlan ? 1000.00 : (float) $plan->min_deposit;

            if ($amount < $minDepositRequired) {
                return response()->json([
                    'success' => false,
                    'message' => "The minimum deposit for {$plan->name} is ₦" . number_format($minDepositRequired, 2) . '.',
                ], 422);
            }

            // Resolve target category if target plan
            $targetCategory = null;
            if ($isTargetPlan && empty($validated['user_saving_id'])) {
                if (!empty($validated['target_category_id'])) {
                    $targetCategory = SavingsTargetCategory::where('id', $validated['target_category_id'])
                        ->where('is_enabled', true)
                        ->first();
                }

                // If not found or not provided, pick first available category
                if (!$targetCategory) {
                    $targetCategory = SavingsTargetCategory::where('is_enabled', true)
                        ->orderBy('order_index')
                        ->first();
                }
            }

            // Compute maturity lock date
            $lockedUntil = null;
            if (!empty($validated['maturity_date'])) {
                $lockedUntil = Carbon::parse($validated['maturity_date'])->endOfDay();
                if ($lockedUntil->isPast()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Target maturity date must be a date in the future.',
                    ], 422);
                }
            } elseif (!empty($validated['duration_days'])) {
                $lockedUntil = now()->addDays((int) $validated['duration_days']);
            } elseif ($isTargetPlan) {
                // Default 90 days for target goals if neither maturity date nor duration provided
                $lockedUntil = now()->addDays(90);
            } elseif ($plan->category === 'fixed') {
                $lockedUntil = now()->addDays(90);
            }

            $title = $validated['title'] ?? null;
            if (empty($title)) {
                if ($targetCategory) {
                    $title = $targetCategory->name;
                } else {
                    $title = $plan->name . ($lockedUntil ? " (Matures " . $lockedUntil->format('M Y') . ")" : '');
                }
            }

            $targetAmount = !empty($validated['target_amount']) ? (float) $validated['target_amount'] : null;

            // 3. Pessimistic Row Lock & Atomic Ledger Execution
            $result = DB::transaction(function () use (
                $user,
                $plan,
                $amount,
                $title,
                $lockedUntil,
                $targetCategory,
                $targetAmount,
                $validated
            ) {
                // LOCK 1: User row (prevent simultaneous wallet double-spend)
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                if (!$lockedUser) {
                    throw new \DomainException('User account could not be found.');
                }

                if (!$lockedUser->canTransact()) {
                    $status = $lockedUser->status ?? 'restricted';
                    throw new \DomainException("Your account is currently {$status}. Transactions are not permitted.");
                }

                if ((float) $lockedUser->wallet_balance < $amount) {
                    throw new \DomainException('Insufficient wallet balance. Please fund your Naira wallet first.');
                }

                // LOCK 2: Savings Plan row (ensure plan didn't get disabled mid-flight)
                $lockedPlan = SavingsPlan::where('id', $plan->id)->lockForUpdate()->first();
                if (!$lockedPlan || !$lockedPlan->is_enabled) {
                    throw new \DomainException("This savings plan is currently disabled by admin.");
                }

                // LOCK 3: Target UserSaving record
                $lockedSaving = null;

                if (!empty($validated['user_saving_id'])) {
                    // Top-up an existing savings goal or fixed account
                    $lockedSaving = UserSaving::where('id', $validated['user_saving_id'])
                        ->where('user_id', $lockedUser->id)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedSaving) {
                        throw new \DomainException('Target savings goal or account was not found.');
                    }
                } elseif (in_array($lockedPlan->key, ['gvault', 'spend_save'])) {
                    // Flexible rolling accounts: strictly one active account per plan
                    $lockedSaving = UserSaving::where('user_id', $lockedUser->id)
                        ->where('plan_id', $lockedPlan->id)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedSaving) {
                        $lockedSaving = UserSaving::create([
                            'user_id' => $lockedUser->id,
                            'plan_id' => $lockedPlan->id,
                            'title' => $title,
                            'balance' => 0.00,
                            'annual_rate' => $lockedPlan->annual_rate,
                            'status' => 'active',
                        ]);
                        $lockedSaving = UserSaving::where('id', $lockedSaving->id)->lockForUpdate()->first();
                    }
                } else {
                    // Create new dedicated goal / fixed deposit
                    $lockedSaving = UserSaving::create([
                        'user_id' => $lockedUser->id,
                        'plan_id' => $lockedPlan->id,
                        'target_category_id' => $targetCategory ? $targetCategory->id : null,
                        'title' => $title,
                        'balance' => 0.00,
                        'target_amount' => $targetAmount,
                        'annual_rate' => $lockedPlan->annual_rate,
                        'locked_until' => $lockedUntil,
                        'status' => 'active',
                    ]);
                    $lockedSaving = UserSaving::where('id', $lockedSaving->id)->lockForUpdate()->first();
                }

                // ATOMIC BALANCES MUTATION
                $newWalletBalance = round((float) $lockedUser->wallet_balance - $amount, 2);
                $newSavingsBalance = round((float) $lockedSaving->balance + $amount, 2);

                $lockedUser->wallet_balance = $newWalletBalance;
                $lockedUser->save();

                $lockedSaving->balance = $newSavingsBalance;
                $lockedSaving->save();

                $ref = 'SAV_DEP_' . strtoupper(Str::random(12));

                // Record savings ledger entry
                SavingsTransaction::create([
                    'user_id' => $lockedUser->id,
                    'user_saving_id' => $lockedSaving->id,
                    'type' => 'deposit',
                    'amount' => $amount,
                    'balance_after' => $newSavingsBalance,
                    'reference' => $ref,
                    'description' => "Saved into {$lockedPlan->name}",
                    'status' => 'successful',
                ]);

                // Record global transaction record
                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'title' => "Savings: {$lockedPlan->name}",
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'direction' => 'out',
                    'type' => 'savings',
                    'status' => 'successful',
                    'reference' => $ref,
                    'counterparty' => $lockedPlan->name,
                    'counterparty_bank' => 'Gutter Wealth Vault',
                    'fee' => 0.00,
                    'description' => "Deposited ₦" . number_format($amount, 2) . " into {$lockedPlan->name}.",
                ]);

                return [
                    'savings_balance' => $newSavingsBalance,
                    'wallet_balance' => $newWalletBalance,
                    'transaction' => $transaction,
                ];
            });

            $responsePayload = [
                'success' => true,
                'message' => "Successfully deposited ₦" . number_format($amount, 2) . " into {$plan->name}!",
                'data' => $result,
            ];

            // Cache response for idempotency key if supplied
            if (!empty($idempotencyKey)) {
                Cache::put("idempotency_savings_{$user->id}_{$idempotencyKey}", $responsePayload, now()->addMinutes(10));
            }

            return response()->json($responsePayload);
        } catch (\DomainException $de) {
            return response()->json([
                'success' => false,
                'message' => $de->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Savings Deposit Error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'amount' => $amount,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete savings deposit. Please try again later.',
            ], 500);
        } finally {
            $mutex->release();
        }
    }

    /**
     * Withdraw from a savings plan to the main Naira wallet.
     * Protected by:
     * - Strict 4-Digit Transaction PIN with anti-brute force lockout
     * - Distributed user-level mutex cache lock
     * - Pessimistic row locking on User and UserSaving (SELECT ... FOR UPDATE)
     * - Strict transactional balance verification (prevents double-spending)
     * - Early liquidation lock check
     * - Admin withdrawal permission check
     *
     * Route: POST /api/v1/savings/withdraw
     */
    public function withdraw(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->boolean('break_early')) {
            return $this->breakSavings($request);
        }

        $validated = $request->validate([
            'plan_id' => 'nullable',
            'user_saving_id' => 'nullable|integer',
            'amount' => ['required', 'numeric', 'min:50', 'max:50000000', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pin' => 'required|string|digits:4',
            'idempotency_key' => 'nullable|string|max:64',
            'break_early' => 'nullable|boolean',
        ], [
            'amount.regex' => 'Amount must not exceed 2 decimal places.',
            'amount.min' => 'Minimum withdrawal amount is ₦50.00.',
            'pin.required' => 'Please enter your 4-digit transaction PIN to authorize withdrawal.',
            'pin.digits' => 'PIN must be exactly 4 digits.',
        ]);

        $amount = round((float) $validated['amount'], 2);
        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid withdrawal amount.',
            ], 422);
        }

        // 1. PIN Brute-force & Anti-Tampering Shield
        $pinFailsKey = "savings_pin_fails_{$user->id}";
        $failedAttempts = (int) Cache::get($pinFailsKey, 0);

        if ($failedAttempts >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Your transaction PIN is temporarily locked due to too many failed attempts. Please wait 15 minutes or reset your PIN.',
                'errors' => [
                    'pin' => ['Too many incorrect PIN attempts. Please wait 15 minutes.'],
                ],
            ], 429);
        }

        if (!$user->has_pin || empty($user->pin) || !Hash::check($validated['pin'], $user->pin)) {
            $newFails = $failedAttempts + 1;
            Cache::put($pinFailsKey, $newFails, now()->addMinutes(15));
            $attemptsRemaining = max(0, 5 - $newFails);

            $msg = $attemptsRemaining > 0
                ? "Incorrect transaction PIN. {$attemptsRemaining} attempt(s) remaining before temporary lockout."
                : 'Incorrect transaction PIN. Your account PIN entry has been locked for 15 minutes.';

            return response()->json([
                'success' => false,
                'message' => $msg,
                'errors' => [
                    'pin' => [$msg],
                ],
            ], 422);
        }

        // PIN verified successfully — clear failed attempts counter
        Cache::forget($pinFailsKey);

        // 2. Idempotency Guard: return existing result if exact request was recently processed
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        if (!empty($idempotencyKey)) {
            $cachedPayload = Cache::get("idempotency_savings_{$user->id}_{$idempotencyKey}");
            if ($cachedPayload) {
                return response()->json($cachedPayload);
            }
        }

        // 3. Distributed Mutex Lock: prevent concurrent double-clicks / racing requests for same user
        $mutex = Cache::lock("savings_user_mutex_{$user->id}", 15);
        if (!$mutex->block(5)) {
            return response()->json([
                'success' => false,
                'message' => 'Another savings transaction is currently being processed on your account. Please wait.',
            ], 429);
        }

        try {
            // 4. Pessimistic Row Lock & Atomic Ledger Execution
            $result = DB::transaction(function () use (
                $user,
                $amount,
                $validated
            ) {
                // LOCK 1: User row (always lock User first to preserve global lock order & prevent deadlocks)
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                if (!$lockedUser) {
                    throw new \DomainException('User account could not be found.');
                }

                if (!$lockedUser->canTransact()) {
                    $status = $lockedUser->status ?? 'restricted';
                    throw new \DomainException("Your account is currently {$status}. Transactions are not permitted.");
                }

                // LOCK 2: Find target UserSaving row under lock
                $lockedSaving = null;

                if (!empty($validated['user_saving_id'])) {
                    $lockedSaving = UserSaving::where('id', $validated['user_saving_id'])
                        ->where('user_id', $lockedUser->id)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();
                } elseif (!empty($validated['plan_id'])) {
                    $plan = SavingsPlan::where('id', $validated['plan_id'])
                        ->orWhere('key', $validated['plan_id'])
                        ->first();

                    if ($plan) {
                        // Prefer active account with unlocked funds and sufficient balance
                        $lockedSaving = UserSaving::where('user_id', $lockedUser->id)
                            ->where('plan_id', $plan->id)
                            ->where('status', 'active')
                            ->where(function ($q) {
                                $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                            })
                            ->where('balance', '>=', $amount)
                            ->orderByDesc('balance')
                            ->lockForUpdate()
                            ->first();

                        // Fallback to any active account under this plan so user gets a precise error
                        if (!$lockedSaving) {
                            $lockedSaving = UserSaving::where('user_id', $lockedUser->id)
                                ->where('plan_id', $plan->id)
                                ->where('status', 'active')
                                ->orderByDesc('balance')
                                ->lockForUpdate()
                                ->first();
                        }
                    }
                } else {
                    // Fallback across all active plans (prefer unlocked with sufficient balance)
                    $lockedSaving = UserSaving::where('user_id', $lockedUser->id)
                        ->where('status', 'active')
                        ->where(function ($q) {
                            $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                        })
                        ->where('balance', '>=', $amount)
                        ->orderByDesc('balance')
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedSaving) {
                        $lockedSaving = UserSaving::where('user_id', $lockedUser->id)
                            ->where('status', 'active')
                            ->where('balance', '>', 0)
                            ->orderByDesc('balance')
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if (!$lockedSaving) {
                    throw new \DomainException('No active savings account found with funds to withdraw.');
                }

                // LOCK 3: Verify plan permissions under lock
                $lockedPlan = SavingsPlan::where('id', $lockedSaving->plan_id)->first();
                if ($lockedPlan && !$lockedPlan->allow_withdrawals) {
                    throw new \DomainException("Withdrawals for {$lockedPlan->name} are currently suspended by admin.");
                }

                // Check lock period
                if ($lockedSaving->locked_until && $lockedSaving->locked_until->isFuture()) {
                    throw new \DomainException("This fixed savings plan is locked until " . $lockedSaving->locked_until->format('d M Y') . ". Early liquidation is restricted.");
                }

                // CRITICAL ATOMIC CHECK: Verify savings balance under pessimistic lock
                if ((float) $lockedSaving->balance < $amount) {
                    throw new \DomainException("Insufficient savings balance. You only have ₦" . number_format($lockedSaving->balance, 2) . " available.");
                }

                // ATOMIC BALANCES MUTATION
                $newSavingsBalance = round((float) $lockedSaving->balance - $amount, 2);
                $newWalletBalance = round((float) $lockedUser->wallet_balance + $amount, 2);

                $lockedSaving->balance = $newSavingsBalance;
                $lockedSaving->save();

                $lockedUser->wallet_balance = $newWalletBalance;
                $lockedUser->save();

                $planName = $lockedPlan ? $lockedPlan->name : 'Savings Vault';
                $ref = 'SAV_WTH_' . strtoupper(Str::random(12));

                // Record savings ledger
                SavingsTransaction::create([
                    'user_id' => $lockedUser->id,
                    'user_saving_id' => $lockedSaving->id,
                    'type' => 'withdrawal',
                    'amount' => $amount,
                    'balance_after' => $newSavingsBalance,
                    'reference' => $ref,
                    'description' => "Withdrawn from {$planName} to Naira Wallet",
                    'status' => 'successful',
                ]);

                // Record global transaction record
                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'title' => "Withdrawal: {$planName}",
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'direction' => 'in',
                    'type' => 'savings',
                    'status' => 'successful',
                    'reference' => $ref,
                    'counterparty' => $planName,
                    'counterparty_bank' => 'Gutter Naira Wallet',
                    'fee' => 0.00,
                    'description' => "Withdrew ₦" . number_format($amount, 2) . " from {$planName} to your primary Naira wallet.",
                ]);

                return [
                    'savings_balance' => $newSavingsBalance,
                    'wallet_balance' => $newWalletBalance,
                    'transaction' => $transaction,
                ];
            });

            $responsePayload = [
                'success' => true,
                'message' => "Successfully withdrew ₦" . number_format($amount, 2) . " to your Naira wallet!",
                'data' => $result,
            ];

            // Cache response for idempotency key if supplied
            if (!empty($idempotencyKey)) {
                Cache::put("idempotency_savings_{$user->id}_{$idempotencyKey}", $responsePayload, now()->addMinutes(10));
            }

            return response()->json($responsePayload);
        } catch (\DomainException $de) {
            return response()->json([
                'success' => false,
                'message' => $de->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Savings Withdrawal Error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'amount' => $amount,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete savings withdrawal. Please try again later.',
            ], 500);
        } finally {
            $mutex->release();
        }
    }

    /**
     * Preview early break / liquidation figures before confirmation.
     * Route: GET /api/v1/savings/{id}/break-preview OR POST /api/v1/savings/break-preview
     */
    public function breakPreview(Request $request, int|string $id = null): JsonResponse
    {
        $user = $request->user();
        $savingId = $id ?? $request->input('user_saving_id') ?? $request->input('id');

        $query = UserSaving::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'active');

        if (!empty($savingId)) {
            $query->where('id', $savingId);
        } elseif ($request->filled('plan_id')) {
            $plan = SavingsPlan::where('id', $request->input('plan_id'))
                ->orWhere('key', $request->input('plan_id'))
                ->first();

            if ($plan) {
                $query->where('plan_id', $plan->id)->orderByDesc('balance');
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid savings plan selected.',
                ], 404);
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Please specify a savings plan or goal to preview.',
            ], 422);
        }

        $saving = $query->first();

        if (!$saving) {
            return response()->json([
                'success' => false,
                'message' => 'Active savings account not found.',
            ], 404);
        }

        $balance = round((float) $saving->balance, 2);
        $accruedInterest = round((float) $saving->total_interest_earned, 2);
        $isBeforeMaturity = (bool) ($saving->locked_until && $saving->locked_until->isFuture());
        $plan = $saving->plan;
        $planName = $plan ? $plan->name : 'Savings Plan';

        if ($isBeforeMaturity) {
            $forfeitedInterest = min($accruedInterest, $balance);
            $principal = max(0.00, round($balance - $forfeitedInterest, 2));
            $feePercentage = (float) ($plan?->early_break_fee_percentage ?? 2.50);
            $earlyBreakFee = round($principal * ($feePercentage / 100.0), 2);
            $netPayout = max(0.00, round($principal - $earlyBreakFee, 2));
            $warningMessage = "Breaking this savings plan early will attract a {$feePercentage}% fee on your eligible savings principal. You will also lose all interest earned on this plan. You will receive ₦" . number_format($netPayout, 2) . " from a principal of ₦" . number_format($principal, 2) . ". Do you want to continue?";
        } else {
            $forfeitedInterest = 0.00;
            $principal = $balance;
            $feePercentage = 0.00;
            $earlyBreakFee = 0.00;
            $netPayout = $balance;
            $warningMessage = null;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user_saving_id' => $saving->id,
                'title' => $saving->title,
                'plan_name' => $planName,
                'plan_key' => $plan ? $plan->key : 'fixed',
                'balance' => $balance,
                'principal' => $principal,
                'accrued_interest' => $accruedInterest,
                'forfeited_interest' => $forfeitedInterest,
                'fee_percentage' => $feePercentage,
                'early_break_fee' => $earlyBreakFee,
                'net_payout' => $netPayout,
                'is_before_maturity' => $isBeforeMaturity,
                'locked_until' => $saving->locked_until ? $saving->locked_until->toIso8601String() : null,
                'locked_until_formatted' => $saving->locked_until ? $saving->locked_until->format('d M Y') : null,
                'warning_message' => $warningMessage,
            ],
        ]);
    }

    /**
     * Break / prematurely withdraw an active savings plan to the main Naira wallet.
     * Enforces:
     * - Strict 4-Digit Transaction PIN with anti-brute force lockout
     * - Distributed user-level mutex cache lock
     * - Pessimistic row locking on User, SavingsPlan, and UserSaving (SELECT ... FOR UPDATE)
     * - Complete interest forfeiture on premature break
     * - Accurate 2.5% (or admin configured) early break fee on eligible principal
     * - Atomically sets savings balance to 0 and marks status as 'broken' (or 'completed' if matured)
     * - Atomically credits net payout to user's main wallet
     * - Detailed immutable audit trail in savings_transactions and transactions
     *
     * Route: POST /api/v1/savings/break
     */
    public function breakSavings(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'user_saving_id' => 'nullable|integer',
            'plan_id' => 'nullable',
            'pin' => 'required|string|digits:4',
            'idempotency_key' => 'nullable|string|max:64',
        ], [
            'pin.required' => 'Please enter your 4-digit transaction PIN to authorize early withdrawal.',
            'pin.digits' => 'PIN must be exactly 4 digits.',
        ]);

        // 1. PIN Brute-force & Anti-Tampering Shield
        $pinFailsKey = "savings_pin_fails_{$user->id}";
        $failedAttempts = (int) Cache::get($pinFailsKey, 0);

        if ($failedAttempts >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Your transaction PIN is temporarily locked due to too many failed attempts. Please wait 15 minutes or reset your PIN.',
                'errors' => [
                    'pin' => ['Too many incorrect PIN attempts. Please wait 15 minutes.'],
                ],
            ], 429);
        }

        if (!$user->has_pin || empty($user->pin) || !Hash::check($validated['pin'], $user->pin)) {
            $newFails = $failedAttempts + 1;
            Cache::put($pinFailsKey, $newFails, now()->addMinutes(15));
            $attemptsRemaining = max(0, 5 - $newFails);

            $msg = $attemptsRemaining > 0
                ? "Incorrect transaction PIN. {$attemptsRemaining} attempt(s) remaining before temporary lockout."
                : 'Incorrect transaction PIN. Your account PIN entry has been locked for 15 minutes.';

            return response()->json([
                'success' => false,
                'message' => $msg,
                'errors' => [
                    'pin' => [$msg],
                ],
            ], 422);
        }

        // PIN verified successfully — clear failed attempts counter
        Cache::forget($pinFailsKey);

        // 2. Idempotency Guard
        $idempotencyKey = $validated['idempotency_key'] ?? null;
        if (!empty($idempotencyKey)) {
            $cachedPayload = Cache::get("idempotency_savings_{$user->id}_{$idempotencyKey}");
            if ($cachedPayload) {
                return response()->json($cachedPayload);
            }
        }

        // 3. Distributed Mutex Lock
        $mutex = Cache::lock("savings_user_mutex_{$user->id}", 15);
        if (!$mutex->block(5)) {
            return response()->json([
                'success' => false,
                'message' => 'Another savings transaction is currently being processed on your account. Please wait.',
            ], 429);
        }

        try {
            $result = DB::transaction(function () use ($user, $validated) {
                // LOCK 1: User row
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                if (!$lockedUser) {
                    throw new \DomainException('User account could not be found.');
                }

                if (!$lockedUser->canTransact()) {
                    $status = $lockedUser->status ?? 'restricted';
                    throw new \DomainException("Your account is currently {$status}. Transactions are not permitted.");
                }

                // LOCK 2: UserSaving row
                $lockedSaving = null;
                if (!empty($validated['user_saving_id'])) {
                    $lockedSaving = UserSaving::where('id', $validated['user_saving_id'])
                        ->where('user_id', $lockedUser->id)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();
                } elseif (!empty($validated['plan_id'])) {
                    $plan = SavingsPlan::where('id', $validated['plan_id'])
                        ->orWhere('key', $validated['plan_id'])
                        ->first();

                    if ($plan) {
                        $lockedSaving = UserSaving::where('user_id', $lockedUser->id)
                            ->where('plan_id', $plan->id)
                            ->where('status', 'active')
                            ->orderByDesc('balance')
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if (!$lockedSaving) {
                    throw new \DomainException('The specified savings plan was not found or is no longer active.');
                }

                $balance = round((float) $lockedSaving->balance, 2);
                if ($balance <= 0) {
                    throw new \DomainException('This savings plan has no funds available to break or withdraw.');
                }

                // LOCK 3: SavingsPlan row
                $lockedPlan = SavingsPlan::where('id', $lockedSaving->plan_id)->lockForUpdate()->first();
                if ($lockedPlan && !$lockedPlan->allow_withdrawals) {
                    throw new \DomainException("Withdrawals for {$lockedPlan->name} are currently suspended by admin.");
                }

                $planName = $lockedPlan ? $lockedPlan->name : 'Savings Vault';
                $accruedInterest = round((float) $lockedSaving->total_interest_earned, 2);
                $isBeforeMaturity = (bool) ($lockedSaving->locked_until && $lockedSaving->locked_until->isFuture());

                if ($isBeforeMaturity) {
                    // Early break rules:
                    // 1. Forfeit 100% of accrued interest
                    $forfeitedInterest = min($accruedInterest, $balance);
                    // 2. Eligible principal = balance - forfeited interest
                    $principal = max(0.00, round($balance - $forfeitedInterest, 2));
                    // 3. Early break fee = principal * fee % (default 2.5%)
                    $feePercentage = (float) ($lockedPlan?->early_break_fee_percentage ?? 2.50);
                    $earlyBreakFee = round($principal * ($feePercentage / 100.0), 2);
                    // 4. Net payout = principal - early break fee
                    $netPayout = max(0.00, round($principal - $earlyBreakFee, 2));
                    $newStatus = 'broken';
                    $txTitle = "Break Savings: {$planName}";
                    $txDesc = "Liquidated {$lockedSaving->title} early. Principal: ₦" . number_format($principal, 2) . ", Fee ({$feePercentage}%): ₦" . number_format($earlyBreakFee, 2) . ", Forfeited Interest: ₦" . number_format($forfeitedInterest, 2) . ". Net payout ₦" . number_format($netPayout, 2) . " credited to your Naira wallet.";
                    $ledgerDesc = "Early break for {$lockedSaving->title} (-{$feePercentage}% fee: ₦" . number_format($earlyBreakFee, 2) . ", forfeited interest: ₦" . number_format($forfeitedInterest, 2) . ")";
                    $ledgerType = 'break';
                } else {
                    // Matured or flexible savings: no penalty, full principal & interest returned
                    $forfeitedInterest = 0.00;
                    $principal = $balance;
                    $feePercentage = 0.00;
                    $earlyBreakFee = 0.00;
                    $netPayout = $balance;
                    $newStatus = 'completed';
                    $txTitle = "Savings Payout: {$planName}";
                    $txDesc = "Full payout for matured {$lockedSaving->title}. ₦" . number_format($netPayout, 2) . " credited to your Naira wallet.";
                    $ledgerDesc = "Matured savings liquidation for {$lockedSaving->title}";
                    $ledgerType = 'withdrawal';
                }

                // Balance safety: wallet balance must not become negative
                $newWalletBalance = round((float) $lockedUser->wallet_balance + $netPayout, 2);
                if ($newWalletBalance < 0) {
                    throw new \DomainException('Financial balance calculation error: Wallet balance cannot be negative.');
                }

                // ATOMIC BALANCES MUTATION
                $lockedSaving->balance = 0.00;
                $lockedSaving->status = $newStatus;
                $lockedSaving->save();

                $lockedUser->wallet_balance = $newWalletBalance;
                $lockedUser->save();

                $ref = 'SAV_BRK_' . strtoupper(Str::random(12));

                // Record savings ledger with full financial audit trail
                SavingsTransaction::create([
                    'user_id' => $lockedUser->id,
                    'user_saving_id' => $lockedSaving->id,
                    'type' => $ledgerType,
                    'amount' => $netPayout,
                    'fee' => $earlyBreakFee,
                    'forfeited_interest' => $forfeitedInterest,
                    'principal' => $principal,
                    'net_payout' => $netPayout,
                    'balance_after' => 0.00,
                    'reference' => $ref,
                    'description' => $ledgerDesc,
                    'status' => 'successful',
                ]);

                // Record global transaction record
                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'title' => $txTitle,
                    'amount' => $netPayout,
                    'currency' => 'NGN',
                    'direction' => 'in',
                    'type' => 'savings',
                    'status' => 'successful',
                    'reference' => $ref,
                    'counterparty' => $planName,
                    'counterparty_bank' => 'Gutter Savings Vault',
                    'fee' => $earlyBreakFee,
                    'description' => $txDesc,
                ]);

                return [
                    'savings_balance' => 0.00,
                    'wallet_balance' => $newWalletBalance,
                    'principal' => $principal,
                    'early_break_fee' => $earlyBreakFee,
                    'early_break_fee_percentage' => $feePercentage,
                    'forfeited_interest' => $forfeitedInterest,
                    'net_payout' => $netPayout,
                    'status' => $newStatus,
                    'is_before_maturity' => $isBeforeMaturity,
                    'transaction' => $transaction,
                ];
            });

            $payoutFormatted = number_format($result['net_payout'], 2);
            $msg = $result['is_before_maturity']
                ? "Savings plan successfully broken early. ₦{$payoutFormatted} has been credited to your Naira wallet."
                : "Savings plan successfully liquidated. ₦{$payoutFormatted} has been credited to your Naira wallet.";

            $responsePayload = [
                'success' => true,
                'message' => $msg,
                'data' => $result,
            ];

            if (!empty($idempotencyKey)) {
                Cache::put("idempotency_savings_{$user->id}_{$idempotencyKey}", $responsePayload, now()->addMinutes(10));
            }

            return response()->json($responsePayload);
        } catch (\DomainException $de) {
            return response()->json([
                'success' => false,
                'message' => $de->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Savings Break Error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to complete early savings break. Please try again later.',
            ], 500);
        } finally {
            $mutex->release();
        }
    }
}
