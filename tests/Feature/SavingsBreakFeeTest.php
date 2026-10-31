<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SavingsPlan;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSaving;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SavingsBreakFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (SavingsPlan::count() === 0) {
            SavingsPlan::create([
                'key' => 'gvault',
                'name' => 'G-Vault',
                'category' => 'gvault',
                'tagline' => 'Daily interest with free anytime withdrawal',
                'annual_rate' => 15.00,
                'early_break_fee_percentage' => 0.00,
                'min_deposit' => 500.00,
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 1,
            ]);

            SavingsPlan::create([
                'key' => 'fixed',
                'name' => 'SafeBox (Fixed)',
                'category' => 'fixed',
                'tagline' => 'Lock funds for maximum guaranteed returns',
                'annual_rate' => 20.00,
                'early_break_fee_percentage' => 2.50,
                'min_deposit' => 1000.00,
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 2,
            ]);

            SavingsPlan::create([
                'key' => 'target',
                'name' => 'Target Savings',
                'category' => 'target',
                'tagline' => 'Set financial goals and hit your targets',
                'annual_rate' => 16.50,
                'early_break_fee_percentage' => 2.50,
                'min_deposit' => 1000.00,
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 3,
            ]);
        }
    }

    /**
     * Test 1, 3, 4, 5: Early withdrawal with accrued interest.
     * Verifies:
     * - 2.5% fee calculation on principal
     * - 100% forfeiture of accrued interest
     * - Net payout credited to wallet
     * - UserSaving balance becomes 0.00 and status becomes 'broken'
     */
    public function test_early_withdrawal_with_accrued_interest(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 10000.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        // Account with Principal = 100,000 and Interest = 5,000 => Total Balance = 105,000
        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Emergency Fund 2027',
            'balance' => 105000.00,
            'annual_rate' => 20.00,
            'total_interest_earned' => 5000.00,
            'locked_until' => now()->addDays(90),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'savings_balance' => 0.00,
                    'principal' => 100000.00,
                    'early_break_fee' => 2500.00,
                    'early_break_fee_percentage' => 2.50,
                    'forfeited_interest' => 5000.00,
                    'net_payout' => 97500.00,
                    'status' => 'broken',
                    'is_before_maturity' => true,
                ],
            ]);

        // Verify wallet balance: initial 10,000 + 97,500 = 107,500
        $user->refresh();
        $this->assertEquals(107500.00, $user->wallet_balance);

        // Verify saving record
        $saving->refresh();
        $this->assertEquals(0.00, $saving->balance);
        $this->assertEquals('broken', $saving->status);

        // Verify savings transaction audit record
        $savTx = SavingsTransaction::where('user_saving_id', $saving->id)->latest()->first();
        $this->assertNotNull($savTx);
        $this->assertEquals('break', $savTx->type);
        $this->assertEquals(97500.00, $savTx->amount);
        $this->assertEquals(2500.00, $savTx->fee);
        $this->assertEquals(5000.00, $savTx->forfeited_interest);
        $this->assertEquals(100000.00, $savTx->principal);
        $this->assertEquals(97500.00, $savTx->net_payout);
        $this->assertEquals(0.00, $savTx->balance_after);

        // Verify global ledger transaction
        $tx = Transaction::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($tx);
        $this->assertEquals(97500.00, $tx->amount);
        $this->assertEquals(2500.00, $tx->fee);
        $this->assertEquals('in', $tx->direction);
        $this->assertEquals('savings', $tx->type);
    }

    /**
     * Test 2: Early withdrawal when no interest has accrued.
     */
    public function test_early_withdrawal_when_no_interest_has_accrued(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 5000.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        // 100,000 principal with 0 accrued interest
        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Discipline SafeBox',
            'balance' => 100000.00,
            'annual_rate' => 20.00,
            'total_interest_earned' => 0.00,
            'locked_until' => now()->addDays(60),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'savings_balance' => 0.00,
                    'principal' => 100000.00,
                    'early_break_fee' => 2500.00,
                    'forfeited_interest' => 0.00,
                    'net_payout' => 97500.00,
                    'status' => 'broken',
                ],
            ]);

        $user->refresh();
        $this->assertEquals(102500.00, $user->wallet_balance);

        $saving->refresh();
        $this->assertEquals(0.00, $saving->balance);
        $this->assertEquals('broken', $saving->status);
    }

    /**
     * Test 6: Withdrawal after maturity without early break penalty.
     */
    public function test_withdrawal_after_maturity_without_early_break_penalty(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 1000.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        // Matured plan (locked_until in past) with 100,000 principal + 5,000 interest
        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Matured Fixed Plan',
            'balance' => 105000.00,
            'annual_rate' => 20.00,
            'total_interest_earned' => 5000.00,
            'locked_until' => now()->subDay(), // already matured!
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'savings_balance' => 0.00,
                    'principal' => 105000.00,
                    'early_break_fee' => 0.00,
                    'forfeited_interest' => 0.00,
                    'net_payout' => 105000.00,
                    'status' => 'completed',
                    'is_before_maturity' => false,
                ],
            ]);

        $user->refresh();
        // 1000 initial + 105,000 full payout = 106,000
        $this->assertEquals(106000.00, $user->wallet_balance);

        $saving->refresh();
        $this->assertEquals(0.00, $saving->balance);
        $this->assertEquals('completed', $saving->status);
    }

    /**
     * Test 7: Insufficient or inconsistent balances.
     */
    public function test_cannot_break_empty_or_inactive_savings(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 1000.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        // Empty balance (0.00)
        $emptySaving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Empty Plan',
            'balance' => 0.00,
            'annual_rate' => 20.00,
            'status' => 'active',
        ]);

        $res1 = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $emptySaving->id,
            'pin' => '1234',
        ]);

        $res1->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
                'message' => 'This savings plan has no funds available to break or withdraw.',
            ]);

        // Already broken / closed saving
        $closedSaving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Already Broken',
            'balance' => 50000.00,
            'annual_rate' => 20.00,
            'status' => 'broken',
        ]);

        $res2 = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $closedSaving->id,
            'pin' => '1234',
        ]);

        $res2->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
                'message' => 'The specified savings plan was not found or is no longer active.',
            ]);
    }

    /**
     * Test 8: Duplicate withdrawal requests (Idempotency).
     */
    public function test_idempotency_prevents_duplicate_break_payouts(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Fixed Savings',
            'balance' => 100000.00,
            'annual_rate' => 20.00,
            'locked_until' => now()->addDays(30),
            'status' => 'active',
        ]);

        $idempotencyKey = 'break_idem_key_999';

        // 1st request
        $res1 = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
            'idempotency_key' => $idempotencyKey,
        ]);

        $res1->assertStatus(200);

        $user->refresh();
        $this->assertEquals(97500.00, $user->wallet_balance);

        // 2nd request (exact replay / double-click)
        $res2 = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
            'idempotency_key' => $idempotencyKey,
        ]);

        $res2->assertStatus(200);

        // Wallet must NOT be credited twice
        $user->refresh();
        $this->assertEquals(97500.00, $user->wallet_balance);
    }

    /**
     * Test 9: Concurrent withdrawal attempts.
     */
    public function test_concurrent_break_attempts_prevented(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Fixed Savings Concurrent',
            'balance' => 50000.00,
            'annual_rate' => 20.00,
            'locked_until' => now()->addDays(45),
            'status' => 'active',
        ]);

        // First attempt succeeds
        $res1 = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ]);
        $res1->assertStatus(200);

        // Second attempt fails immediately because the row is marked broken and balance is 0
        $res2 = $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ]);
        $res2->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);

        $user->refresh();
        // 50,000 principal - 2.5% (1250) = 48,750 credited only once
        $this->assertEquals(48750.00, $user->wallet_balance);
    }

    /**
     * Test 10: Database failures and rollback behavior.
     */
    public function test_database_failure_rolls_back_atomically(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 2000.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Rollback Test',
            'balance' => 40000.00,
            'annual_rate' => 20.00,
            'locked_until' => now()->addDays(30),
            'status' => 'active',
        ]);

        // Simulate database failure during Transaction creation
        Transaction::saving(function () {
            throw new \RuntimeException('Database disk I/O failure during ledger write.');
        });

        try {
            $this->actingAs($user)->postJson('/api/v1/savings/break', [
                'user_saving_id' => $saving->id,
                'pin' => '1234',
            ]);
        } finally {
            // Clear event listener
            Transaction::flushEventListeners();
        }

        // Balances must remain completely untouched due to rollback
        $user->refresh();
        $this->assertEquals(2000.00, $user->wallet_balance);

        $saving->refresh();
        $this->assertEquals(40000.00, $saving->balance);
        $this->assertEquals('active', $saving->status);
    }

    /**
     * Test 11: Prevention of further interest accrual after the break.
     */
    public function test_prevention_of_further_interest_accrual_after_break(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();

        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Break Interest Test',
            'balance' => 100000.00,
            'annual_rate' => 20.00,
            'total_interest_earned' => 0.00,
            'locked_until' => now()->addDays(30),
            'status' => 'active',
        ]);

        // Break the plan
        $this->actingAs($user)->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ])->assertStatus(200);

        $saving->refresh();
        $this->assertEquals('broken', $saving->status);
        $this->assertEquals(0.00, $saving->balance);

        // Run the daily interest accrual command
        Artisan::call('savings:accrue-interest');

        // Check saving has not accrued any interest
        $saving->refresh();
        $this->assertEquals(0.00, $saving->balance);
        $this->assertEquals(0.00, $saving->total_interest_earned);
        $this->assertEquals('broken', $saving->status);
    }

    /**
     * Test 12: Preview calculation & confirmation cancellation without financial changes.
     */
    public function test_break_preview_calculation_and_cancellation(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 15000.00,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'target')->first();

        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'House Rent Goal',
            'balance' => 105000.00,
            'annual_rate' => 16.50,
            'total_interest_earned' => 5000.00,
            'locked_until' => now()->addDays(120),
            'status' => 'active',
        ]);

        // 1. Fetch preview
        $previewRes = $this->actingAs($user)->getJson("/api/v1/savings/{$saving->id}/break-preview");

        $previewRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_saving_id' => $saving->id,
                    'title' => 'House Rent Goal',
                    'balance' => 105000.00,
                    'principal' => 100000.00,
                    'accrued_interest' => 5000.00,
                    'forfeited_interest' => 5000.00,
                    'fee_percentage' => 2.50,
                    'early_break_fee' => 2500.00,
                    'net_payout' => 97500.00,
                    'is_before_maturity' => true,
                ],
            ]);

        $this->assertStringContainsString('Breaking this savings plan early will attract a 2.5% fee', $previewRes->json('data.warning_message'));

        // 2. Cancellation: User cancels the dialog (does not call /break)
        // Verify balances remain 100% untouched
        $user->refresh();
        $this->assertEquals(15000.00, $user->wallet_balance);

        $saving->refresh();
        $this->assertEquals(105000.00, $saving->balance);
        $this->assertEquals(5000.00, $saving->total_interest_earned);
        $this->assertEquals('active', $saving->status);
    }

    /**
     * Test 13: Admin can adjust breaking fee for each saving option.
     */
    public function test_admin_can_adjust_breaking_fee_for_each_plan(): void
    {
        $admin = AdminUser::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@gutter.com',
            'password' => Hash::make('AdminSecret123!'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();
        $this->assertEquals(2.50, $plan->early_break_fee_percentage);

        // Admin updates SafeBox break fee to 5.0%
        $updateRes = $this->actingAs($admin, 'admin')->postJson("/admin/app-controls/savings/{$plan->id}", [
            'name' => 'SafeBox (Fixed)',
            'annual_rate' => 20.00,
            'early_break_fee_percentage' => 5.00,
            'min_deposit' => 1000.00,
            'lock_period' => '30 - 365 Days',
            'is_enabled' => 1,
            'allow_withdrawals' => 1,
        ]);

        $updateRes->assertStatus(200);

        $plan->refresh();
        $this->assertEquals(5.00, $plan->early_break_fee_percentage);

        // Now user breaks a plan under this SafeBox option
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $saving = UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'SafeBox with 5% Fee',
            'balance' => 100000.00,
            'annual_rate' => 20.00,
            'total_interest_earned' => 0.00,
            'locked_until' => now()->addDays(90),
            'status' => 'active',
        ]);

        $breakRes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/savings/break', [
            'user_saving_id' => $saving->id,
            'pin' => '1234',
        ]);

        $breakRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'principal' => 100000.00,
                    'early_break_fee_percentage' => 5.00,
                    'early_break_fee' => 5000.00, // 5% of 100,000 = 5,000
                    'net_payout' => 95000.00,      // 100,000 - 5,000 = 95,000
                    'status' => 'broken',
                ],
            ]);

        $user->refresh();
        $this->assertEquals(95000.00, $user->wallet_balance);
    }
}
