<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\FeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminFeeControlTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $superAdmin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = AdminUser::firstOrCreate(
            ['email' => 'superadmin_test@gutter.com'],
            [
                'name' => 'Super Admin Test',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'status' => 'active',
            ]
        );

        $this->user = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
            'has_pin' => true,
            'pin' => Hash::make('1234'),
            'wallet_balance' => 50000,
        ]);
    }

    public function test_admin_can_view_all_eight_service_fees(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')
            ->get('/admin/app-controls');

        $response->assertStatus(200)
            ->assertSee('Services, Fees')
            ->assertSee('Bank Transfer')
            ->assertSee('Gutter to Gutter')
            ->assertSee('Cards Creation')
            ->assertSee('Send Crypto')
            ->assertSee('Convert Crypto to Naira')
            ->assertSee('Buy Crypto')
            ->assertSee('Airtime Recharge')
            ->assertSee('Data Bundles');
    }

    public function test_admin_can_update_flat_and_percentage_fee_via_ajax(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/admin/app-controls/fees/bank_transfer', [
                'flat_fee' => 25.50,
                'percent_fee' => 0.75,
                'is_enabled' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.flat_fee', 25.5)
            ->assertJsonPath('data.percent_fee', 0.75);

        // Verify fee calculation with new values: 25.50 + (10000 * 0.75%) = 25.50 + 75 = 100.50
        $fee = FeeService::calculateFee('bank_transfer', 10000);
        $this->assertEquals(100.50, $fee);
    }

    public function test_admin_can_batch_update_fees(): void
    {
        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/admin/app-controls/fees-batch', [
                'fees' => [
                    'card_creation' => [
                        'flat_fee' => 2000.00,
                        'percent_fee' => 1.50,
                        'is_enabled' => true,
                    ],
                    'airtime' => [
                        'flat_fee' => 5.00,
                        'percent_fee' => 0.20,
                        'is_enabled' => true,
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $cardFee = FeeService::calculateFee('card_creation', 0);
        $this->assertEquals(2000.00, $cardFee);

        $airtimeFee = FeeService::calculateFee('airtime', 1000);
        // 5 + (1000 * 0.002) = 5 + 2 = 7.00
        $this->assertEquals(7.00, $airtimeFee);
    }

    public function test_disabled_service_halts_transfer_with_maintenance_status(): void
    {
        // Disable bank transfer
        FeeService::updateSetting('bank_transfer', 10.0, 0.0, false, 'Admin Test');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transfers', [
            'type' => 'bank',
            'amount' => 5000,
            'pin' => '1234',
            'bank_code' => '000014',
            'account_number' => '0103241444',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Bank transfers are temporarily paused for system maintenance.');
    }

    public function test_virtual_card_pricing_reflects_admin_fee_setting(): void
    {
        FeeService::updateSetting('card_creation', 3100.00, 0.0, true, 'Admin Test');

        $response = $this->actingAs($this->user)->getJson('/api/v1/cards/pricing');

        $response->assertStatus(200)
            ->assertJsonPath('data.creation_fee_ngn', 3100);

        $this->assertEquals(2.0, (float) $response->json('data.creation_fee_usd'));
    }

    public function test_public_app_config_includes_all_fee_settings(): void
    {
        $response = $this->getJson('/api/v1/app/config');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'features',
                'fees' => [
                    'bank_transfer',
                    'gutter_to_gutter',
                    'card_creation',
                    'send_crypto',
                    'convert_crypto',
                    'buy_crypto',
                    'airtime',
                    'data',
                ],
            ]);
    }
}
