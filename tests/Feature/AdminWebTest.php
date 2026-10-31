<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AppControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_screen_renders_successfully(): void
    {
        $response = $this->get('/admin/login');
        $response->assertStatus(200);
        $response->assertSee('Sign in to Administrative Console');
    }

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $response = $this->post('/admin/login', [
            'email' => 'admin@gutter.com',
            'password' => 'Admin@123456',
        ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_authenticated_admin_can_view_dashboard(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $response = $this->actingAs($admin, 'admin')->get('/admin');
        $response->assertStatus(200);
        $response->assertSee('Executive Financial Overview');
        $response->assertSee('User Account Governance', false);
        $response->assertSee('Active Users');
        $response->assertSee('Frozen Users');
        $response->assertSee('Suspended / Blocked');
        $response->assertSee('Financial Rails Breakdown');
    }

    public function test_super_admin_can_toggle_mobile_feature_switch(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/admin/app-controls/feature_airtime/toggle');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_enabled' => false,
        ]);

        $this->assertFalse(AppControl::where('key', 'feature_airtime')->first()->is_enabled);
    }

    public function test_admin_menu_shows_notification_count(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        // Seed 1 pending KYC user and 1 open complaint
        \App\Models\User::factory()->create(['kyc_status' => 'pending']);
        \App\Models\Complaint::create([
            'ticket_id' => 'TKT-TEST-999',
            'category' => 'transaction_issue',
            'message' => 'Pending resolution',
            'status' => 'open',
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('Notifications');
        $response->assertSee('2 Action Items');
        $response->assertSee('2 New');
        $response->assertSee('2 Alerts');
    }

    public function test_admin_can_view_kyc_dashboard_with_prembly_and_tabs(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $user = \App\Models\User::factory()->create([
            'kyc_status' => 'pending',
            'tier' => 1,
        ]);

        $user->kycVerifications()->create([
            'document_type' => 'nin',
            'document_number' => '12345678901',
            'status' => 'pending',
            'provider' => 'prembly',
            'id_data' => ['document_image' => 'https://example.com/id.jpg'],
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/kyc?tab=pending');
        $response->assertStatus(200);
        $response->assertSee('Prembly Live');
        $response->assertSee('Pending Review');
        $response->assertSee($user->first_name);
        $response->assertSee('Approve Tier 2');
    }

    public function test_admin_can_manually_approve_user_kyc_upgrading_to_tier_2(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $user = \App\Models\User::factory()->create([
            'kyc_status' => 'pending',
            'tier' => 1,
        ]);

        $user->kycVerifications()->create([
            'document_type' => 'bvn',
            'document_number' => '22222222222',
            'status' => 'pending',
            'provider' => 'prembly',
        ]);

        $response = $this->actingAs($admin, 'admin')->post("/admin/kyc/{$user->id}/approve");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('verified', $user->kyc_status);
        $this->assertEquals(2, $user->tier);
        $this->assertEquals('verified', $user->latestKycVerification->status);
    }

    public function test_admin_can_manually_reject_user_kyc_with_reason(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@gutter.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $user = \App\Models\User::factory()->create([
            'kyc_status' => 'pending',
            'tier' => 1,
        ]);

        $user->kycVerifications()->create([
            'document_type' => 'nin',
            'document_number' => '12345678901',
            'status' => 'pending',
            'provider' => 'prembly',
        ]);

        $response = $this->actingAs($admin, 'admin')->post("/admin/kyc/{$user->id}/reject", [
            'reason' => 'Photo of ID is blurry. Please re-take in good lighting.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('rejected', $user->kyc_status);
        $this->assertEquals('rejected', $user->latestKycVerification->status);
        $this->assertStringContainsString('blurry', $user->latestKycVerification->rejection_reason);
    }
}
