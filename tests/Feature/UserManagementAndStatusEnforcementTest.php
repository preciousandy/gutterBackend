<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementAndStatusEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Compliance Super Admin',
            'email' => 'admin@gutter.ng',
            'password' => Hash::make('Secret123!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_user_management_with_kpi_counts(): void
    {
        // Seed users with various statuses
        User::factory()->create(['status' => 'active', 'kyc_status' => 'verified', 'last_login_at' => now()]);
        User::factory()->create(['status' => 'frozen', 'kyc_status' => 'pending']);
        User::factory()->create(['status' => 'suspended', 'kyc_status' => 'unverified']);
        User::factory()->create(['status' => 'blocked', 'kyc_status' => 'rejected']);

        $response = $this->actingAs($this->admin, 'admin')->get(route('admin.users'));

        $response->assertStatus(200);
        $response->assertSee('User Management & Account Controls');
        $response->assertSee('Active Users');
        $response->assertSee('Frozen Users');
        $response->assertSee('Suspended / Blocked');
        $response->assertSee('Pending KYC');
        $response->assertSee('Logged In Today');
    }

    public function test_admin_can_filter_users_by_status_and_kpis(): void
    {
        $activeUser = User::factory()->create(['status' => 'active', 'email' => 'active@example.com']);
        $frozenUser = User::factory()->create(['status' => 'frozen', 'email' => 'frozen@example.com']);
        $suspendedUser = User::factory()->create(['status' => 'suspended', 'email' => 'suspended@example.com']);

        // Filter by frozen
        $responseFrozen = $this->actingAs($this->admin, 'admin')->get(route('admin.users', ['filter' => 'frozen']));
        $responseFrozen->assertStatus(200);
        $responseFrozen->assertSee('frozen@example.com');
        $responseFrozen->assertDontSee('active@example.com');

        // Filter by suspended
        $responseSuspended = $this->actingAs($this->admin, 'admin')->get(route('admin.users', ['filter' => 'suspended']));
        $responseSuspended->assertStatus(200);
        $responseSuspended->assertSee('suspended@example.com');
        $responseSuspended->assertDontSee('active@example.com');
    }

    public function test_admin_can_freeze_and_unfreeze_a_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        // Freeze
        $freezeResponse = $this->actingAs($this->admin, 'admin')->put(route('admin.users.status', $user->id), [
            'action' => 'freeze',
            'reason' => 'Suspected irregular high volume',
        ]);

        $freezeResponse->assertRedirect();
        $user->refresh();
        $this->assertEquals('frozen', $user->status);
        $this->assertEquals('Suspected irregular high volume', $user->status_reason);
        $this->assertEquals($this->admin->name, $user->status_updated_by);
        $this->assertNotNull($user->status_updated_at);

        // Unfreeze
        $unfreezeResponse = $this->actingAs($this->admin, 'admin')->put(route('admin.users.status', $user->id), [
            'action' => 'unfreeze',
            'reason' => 'KYC docs verified, unfreezing',
        ]);

        $unfreezeResponse->assertRedirect();
        $user->refresh();
        $this->assertEquals('active', $user->status);
    }

    public function test_admin_can_suspend_user_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('mobile-test')->plainTextToken;
        $this->assertCount(1, $user->tokens);

        // Suspend
        $suspendResponse = $this->actingAs($this->admin, 'admin')->put(route('admin.users.status', $user->id), [
            'action' => 'suspend',
            'reason' => 'Chargeback dispute investigation',
        ]);

        $suspendResponse->assertRedirect();
        $user->refresh();
        $this->assertEquals('suspended', $user->status);
        $this->assertCount(0, $user->tokens); // Tokens revoked!
    }

    public function test_admin_can_soft_delete_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $deleteResponse = $this->actingAs($this->admin, 'admin')->delete(route('admin.users.destroy', $user->id));
        $deleteResponse->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended_login@gutter.ng',
            'password' => Hash::make('Password123!'),
            'status' => 'suspended',
            'status_reason' => 'Account suspended for policy violations',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'suspended_login@gutter.ng',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'suspended',
            'message' => 'Your account has been suspended by administration. Reason: Account suspended for policy violations. Please contact customer support.',
        ]);
    }

    public function test_blocked_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked_login@gutter.ng',
            'password' => Hash::make('Password123!'),
            'status' => 'blocked',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'blocked_login@gutter.ng',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_active_user_can_login_and_updates_last_login_at(): void
    {
        $user = User::factory()->create([
            'email' => 'active_login@gutter.ng',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'last_login_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'active_login@gutter.ng',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_frozen_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'frozen_login@gutter.ng',
            'password' => Hash::make('Password123!'),
            'status' => 'frozen',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'frozen_login@gutter.ng',
            'password' => 'Password123!',
        ]);

        // Frozen user CAN log in
        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);
        $this->assertEquals('frozen', $response->json('user.status'));
        $this->assertTrue($response->json('user.is_frozen'));
    }

    public function test_frozen_user_can_read_data_but_cannot_perform_financial_actions(): void
    {
        $user = User::factory()->create([
            'status' => 'frozen',
            'wallet_balance' => 50000.00,
            'pin' => Hash::make('1234'),
            'kyc_status' => 'verified',
        ]);

        Sanctum::actingAs($user);

        // 1. Read-only actions SUCCEED
        $meResponse = $this->getJson('/api/v1/user');
        $meResponse->assertStatus(200);

        $dashResponse = $this->getJson('/api/v1/dashboard');
        $dashResponse->assertStatus(200);

        $txResponse = $this->getJson('/api/v1/transactions');
        $txResponse->assertStatus(200);

        // 2. Financial mutations are strictly BLOCKED with 403
        $billResponse = $this->postJson('/api/v1/bills/purchase', [
            'type' => 'airtime',
            'service_id' => 'mtn',
            'phone' => '08031234567',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $billResponse->assertStatus(403);
        $billResponse->assertJsonFragment([
            'is_frozen' => true,
        ]);
        $this->assertStringContainsString('frozen (Post-No-Debit)', $billResponse->json('message'));

        // Beneficiary addition is BLOCKED
        $beneficiaryResponse = $this->postJson('/api/v1/beneficiaries', [
            'account_number' => '0123456789',
            'bank_code' => '057',
            'bank_name' => 'Zenith Bank',
            'account_name' => 'John Doe',
        ]);
        $beneficiaryResponse->assertStatus(403);

        // Crypto orders are BLOCKED
        $orderResponse = $this->postJson('/api/v1/crypto/orders', [
            'from_currency' => 'NGN',
            'to_currency' => 'USDT',
            'amount' => 5000,
            'pin' => '1234',
        ]);
        $orderResponse->assertStatus(403);
    }
}
