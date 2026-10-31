<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AppControl;
use App\Models\User;
use App\Services\BigisubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigisubVasTest extends TestCase
{
    use RefreshDatabase;

    public function test_bigisub_service_wallet_balance_and_plans(): void
    {
        Http::fake([
            'https://api.bigisub.ng/api/v2/financial/wallet/balance/' => Http::response([
                'success' => true,
                'message' => 'Wallet balance retrieved successfully',
                'data' => [
                    'id' => 65155,
                    'username' => 'andyprecious6',
                    'full_name' => 'Andy Precious',
                    'balance' => '25000.00',
                ],
            ], 200),
            'https://api.bigisub.ng/api/v2/vtu/data/plans/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 135,
                        'network' => 1,
                        'network_name' => 'mtn',
                        'plantype' => 'SME',
                        'size' => 1.0,
                        'plan_volume' => 'GB',
                        'validity' => '30 Days',
                        'amount' => 280.0,
                    ],
                ],
            ], 200),
        ]);

        $service = new BigisubService();
        $balance = $service->getWalletBalance();

        $this->assertTrue($balance['success']);
        $this->assertEquals(25000.0, $balance['balance']);
        $this->assertEquals('Andy Precious', $balance['account_name']);

        $plans = $service->getPlans('MTN');
        $this->assertCount(1, $plans);
        $this->assertEquals('135', $plans[0]['variation_code']);
        $this->assertEquals(280.0, $plans[0]['price']);
    }

    public function test_admin_can_update_bigisub_api_key_from_admin_portal(): void
    {
        $admin = AdminUser::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@test.com',
            'password' => Hash::make('Secret123!'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        Http::fake([
            'https://api.bigisub.ng/api/v2/financial/wallet/balance/' => Http::response([
                'success' => true,
                'data' => [
                    'full_name' => 'Andy Precious',
                    'balance' => '50000.00',
                ],
            ], 200),
        ]);

        $newKey = 'test_new_bigisub_api_key_abc123';
        $newPin = '4321';

        $response = $this->actingAs($admin, 'admin')->post('/admin/app-controls/vas-settings', [
            'vas_provider' => 'bigisub',
            'bigisub_api_key' => $newKey,
            'bigisub_pin' => $newPin,
            'allow_fallback' => '1',
        ]);

        $response->assertRedirect();
        $this->assertEquals($newKey, AppControl::getSetting('bigisub_api_key'));
        $this->assertEquals($newPin, AppControl::getSetting('bigisub_pin'));
        $this->assertEquals('bigisub', AppControl::getSetting('vas_provider'));
        $this->assertTrue(AppControl::isFeatureEnabled('vas_allow_fallback'));
    }

    public function test_user_can_purchase_airtime_via_bigisub(): void
    {
        AppControl::setSetting('vas_provider', 'bigisub');
        AppControl::setSetting('bigisub_api_key', 'test_key');
        AppControl::setSetting('bigisub_pin', '1234');

        $mockBigisub = $this->createMock(BigisubService::class);
        $mockBigisub->method('purchaseAirtime')->willReturn([
            'success' => true,
            'status' => 'successful',
            'reference' => 'BIGI_AIR_123',
            'transaction_id' => 'Air98765',
            'provider' => 'bigisub',
        ]);
        $this->app->instance(BigisubService::class, $mockBigisub);

        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'airtime',
            'network' => 'MTN',
            'phone' => '08031234567',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('provider', 'bigisub')
            ->assertJsonPath('data.reference', 'BIGI_AIR_123')
            ->assertJsonPath('data.amount', 1000);

        $this->assertEquals(4000, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'reference' => 'BIGI_AIR_123',
            'amount' => 1000,
        ]);
    }
}
