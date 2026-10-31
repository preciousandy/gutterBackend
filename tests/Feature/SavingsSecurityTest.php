<?php

namespace Tests\Feature;

use App\Models\SavingsPlan;
use App\Models\User;
use App\Models\UserSaving;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SavingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure default savings plans exist
        if (SavingsPlan::count() === 0) {
            SavingsPlan::create([
                'key' => 'gvault',
                'name' => 'G-Vault',
                'category' => 'gvault',
                'tagline' => 'Daily interest with free anytime withdrawal',
                'annual_rate' => 15.00,
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
                'min_deposit' => 1000.00,
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 3,
            ]);
        }
    }

    public function test_deposit_prevents_overdraft_when_insufficient_wallet_balance(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 200.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/savings/deposit', [
            'plan_id' => 'gvault',
            'amount' => 500.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Insufficient wallet balance. Please fund your Naira wallet first.',
            ]);

        $user->refresh();
        $this->assertEquals(200.00, $user->wallet_balance);
        $this->assertEquals(0, UserSaving::count());
    }

    public function test_deposit_succeeds_and_debits_wallet_atomically(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 5000.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/savings/deposit', [
            'plan_id' => 'gvault',
            'amount' => 1000.00,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $user->refresh();
        $this->assertEquals(4000.00, $user->wallet_balance);

        $saving = UserSaving::where('user_id', $user->id)->first();
        $this->assertNotNull($saving);
        $this->assertEquals(1000.00, $saving->balance);
    }

    public function test_withdrawal_fails_with_incorrect_pin_and_locks_after_repeated_attempts(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'gvault')->first();
        UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'G-Vault',
            'balance' => 5000.00,
            'annual_rate' => 15.00,
            'status' => 'active',
        ]);

        // Attempt 1: Wrong PIN
        $res = $this->actingAs($user)->postJson('/api/v1/savings/withdraw', [
            'plan_id' => 'gvault',
            'amount' => 1000.00,
            'pin' => '9999',
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        // Fail 4 more times to trigger 5-attempt brute-force lockout
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->postJson('/api/v1/savings/withdraw', [
                'plan_id' => 'gvault',
                'amount' => 1000.00,
                'pin' => '9999',
            ]);
        }

        // 6th attempt should be blocked with 429 lockout
        $resBlocked = $this->actingAs($user)->postJson('/api/v1/savings/withdraw', [
            'plan_id' => 'gvault',
            'amount' => 1000.00,
            'pin' => '1234', // Even correct PIN is blocked during cooldown
        ]);

        $resBlocked->assertStatus(429)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_withdrawal_prevents_double_spend_overdraft(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'gvault')->first();
        UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'G-Vault',
            'balance' => 500.00,
            'annual_rate' => 15.00,
            'status' => 'active',
        ]);

        // Requesting 1000 when balance is only 500
        $response = $this->actingAs($user)->postJson('/api/v1/savings/withdraw', [
            'plan_id' => 'gvault',
            'amount' => 1000.00,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $user->refresh();
        $this->assertEquals(0.00, $user->wallet_balance);
    }

    public function test_withdrawal_blocks_early_liquidation_of_locked_funds(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 0.00,
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'status' => 'active',
        ]);

        $plan = SavingsPlan::where('key', 'fixed')->first();
        UserSaving::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'title' => 'Fixed 90 Days',
            'balance' => 50000.00,
            'annual_rate' => 20.00,
            'locked_until' => now()->addDays(90),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/savings/withdraw', [
            'plan_id' => 'fixed',
            'amount' => 10000.00,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);

        $user->refresh();
        $this->assertEquals(0.00, $user->wallet_balance);
    }

    public function test_idempotency_key_prevents_duplicate_deductions(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 5000.00,
            'status' => 'active',
        ]);

        $idempotencyKey = 'test_idem_key_12345';

        // First deposit
        $res1 = $this->actingAs($user)->postJson('/api/v1/savings/deposit', [
            'plan_id' => 'gvault',
            'amount' => 1000.00,
            'idempotency_key' => $idempotencyKey,
        ]);
        $res1->assertStatus(200);

        $user->refresh();
        $this->assertEquals(4000.00, $user->wallet_balance);

        // Replay with exact same idempotency key (e.g. network retry / duplicate click)
        $res2 = $this->actingAs($user)->postJson('/api/v1/savings/deposit', [
            'plan_id' => 'gvault',
            'amount' => 1000.00,
            'idempotency_key' => $idempotencyKey,
        ]);
        $res2->assertStatus(200);

        // Wallet must still be 4000 (NOT deducted twice)
        $user->refresh();
        $this->assertEquals(4000.00, $user->wallet_balance);
    }

    public function test_user_can_fetch_target_categories(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/v1/savings/target-categories');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertGreaterThan(0, count($response->json('data')));
    }

    public function test_target_savings_enforces_1000_minimum_deposit(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 5000.00,
            'status' => 'active',
        ]);

        // Attempting to deposit 500 into target savings (must fail, min is 1000)
        $response = $this->actingAs($user)->postJson('/api/v1/savings/deposit', [
            'plan_id' => 'target',
            'amount' => 500.00,
            'target_amount' => 50000.00,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'The minimum deposit for Target Savings is ₦1,000.00.',
            ]);

        $user->refresh();
        $this->assertEquals(5000.00, $user->wallet_balance);
    }

    public function test_target_savings_creates_goal_with_category_amount_and_maturity_date(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 10000.00,
            'status' => 'active',
        ]);

        $category = \App\Models\SavingsTargetCategory::first();
        $this->assertNotNull($category);

        $futureDate = now()->addDays(120)->format('Y-m-d');

        $response = $this->actingAs($user)->postJson('/api/v1/savings/deposit', [
            'plan_id' => 'target',
            'target_category_id' => $category->id,
            'title' => 'My House Rent 2027',
            'amount' => 2500.00,
            'target_amount' => 250000.00,
            'maturity_date' => $futureDate,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $user->refresh();
        $this->assertEquals(7500.00, $user->wallet_balance);

        $goal = UserSaving::where('user_id', $user->id)
            ->where('title', 'My House Rent 2027')
            ->first();

        $this->assertNotNull($goal);
        $this->assertEquals(2500.00, $goal->balance);
        $this->assertEquals(250000.00, $goal->target_amount);
        $this->assertEquals($category->id, $goal->target_category_id);
        $this->assertNotNull($goal->locked_until);
    }

    public function test_admin_can_manage_target_categories(): void
    {
        $admin = \App\Models\AdminUser::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@gutter.com',
            'password' => \Illuminate\Support\Facades\Hash::make('AdminSecret123!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // 1. Create target category
        $createRes = $this->actingAs($admin, 'admin')->postJson('/admin/app-controls/target-categories', [
            'name' => 'Gadgets & Laptops',
            'icon' => 'laptop-outline',
            'color' => '#8B5CF6',
            'description' => 'Save for your next tech upgrade',
            'is_enabled' => 1,
        ]);

        $createRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $catId = $createRes->json('data.id');

        // 2. Toggle target category
        $toggleRes = $this->actingAs($admin, 'admin')->postJson("/admin/app-controls/target-categories/{$catId}/toggle");
        $toggleRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_enabled' => false,
            ]);

        // 3. Delete target category
        $deleteRes = $this->actingAs($admin, 'admin')->deleteJson("/admin/app-controls/target-categories/{$catId}");
        $deleteRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }
}
