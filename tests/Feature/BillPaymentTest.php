<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_purchase_airtime_successfully(): void
    {
        $mockVtpass = $this->createMock(\App\Services\VtpassService::class);
        $mockVtpass->method('purchaseAirtime')->willReturn([
            'success' => true,
            'status' => 'successful',
            'request_id' => '202609281234test',
            'reference' => 'TX_TEST_1234',
        ]);
        $this->app->instance(\App\Services\VtpassService::class, $mockVtpass);

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
            ->assertJsonPath('data.type', 'airtime')
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.title', 'MTN Airtime');

        $this->assertEquals(4000, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'airtime',
            'amount' => 1000,
        ]);
    }

    public function test_user_can_purchase_data_bundle_successfully(): void
    {
        $mockVtpass = $this->createMock(\App\Services\VtpassService::class);
        $mockVtpass->method('purchaseData')->willReturn([
            'success' => true,
            'status' => 'successful',
            'request_id' => '202609281235test',
            'reference' => 'TX_TEST_1235',
        ]);
        $this->app->instance(\App\Services\VtpassService::class, $mockVtpass);

        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 10000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'data',
            'network' => 'Airtel',
            'phone' => '08029876543',
            'amount' => 1500,
            'plan_name' => '5GB Monthly',
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.type', 'airtime')
            ->assertJsonPath('data.amount', 1500)
            ->assertJsonPath('data.title', 'Airtel Data Bundle');

        $this->assertEquals(8500, (float) $user->fresh()->wallet_balance);
    }

    public function test_bill_purchase_fails_with_wrong_pin(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'airtime',
            'network' => 'Glo',
            'phone' => '08051112233',
            'amount' => 500,
            'pin' => '9999',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Incorrect 4-digit transaction PIN.']);
    }

    public function test_bill_purchase_fails_with_insufficient_balance(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 200,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'airtime',
            'network' => 'MTN',
            'phone' => '08031234567',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Insufficient Naira wallet balance. Please fund your account.']);
    }

    public function test_purchase_fails_when_network_mismatches_phone_prefix(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        // User selects MTN but inputs Glo number
        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'airtime',
            'network' => 'MTN',
            'phone' => '08077406889',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => '08077406889 is a Glo number, but you selected MTN. Please select Glo or enter an MTN number.',
            ]);

        // Verify wallet was not debited
        $this->assertEquals(5000, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'type' => 'airtime',
        ]);

        // User selects Glo but inputs MTN number
        $response2 = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'airtime',
            'network' => 'Glo',
            'phone' => '08031234567',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response2->assertStatus(422)
            ->assertJson([
                'message' => '08031234567 is a MTN number, but you selected Glo. Please select MTN or enter an Glo number.',
            ]);
    }

    public function test_user_can_fetch_data_plans(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/bills/plans?network=MTN');

        $response->assertStatus(200)
            ->assertJsonPath('network', 'MTN')
            ->assertJsonPath('service_id', 'mtn-data')
            ->assertJsonStructure(['network', 'service_id', 'plans']);
    }

    public function test_failed_bill_payment_triggers_auto_refund_on_requery(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 2000,
        ]);

        $tx = \App\Models\Transaction::create([
            'user_id' => $user->id,
            'reference' => 'BILL_TEST_FAILED',
            'type' => 'airtime',
            'direction' => 'out',
            'title' => 'MTN Airtime',
            'subtitle' => 'Recharge to 08011111111',
            'amount' => 1000,
            'fee' => 0,
            'currency' => 'NGN',
            'status' => 'pending',
            'counterparty' => 'MTN · 08011111111',
            'counterparty_bank' => 'MTN',
            'description' => 'Test pending bill payment',
        ]);

        // Mock VtpassService requery to return failed
        $mockVtpass = $this->createMock(\App\Services\VtpassService::class);
        $mockVtpass->method('requery')->willReturn([
            'status' => 'failed',
            'delivered' => false,
            'message' => 'Delivery failed on network',
        ]);
        $this->app->instance(\App\Services\VtpassService::class, $mockVtpass);

        $response = $this->actingAs($user)->postJson("/api/v1/bills/requery/{$tx->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('refunded', true);

        // Assert user's balance was refunded (+1000 -> 3000)
        $this->assertEquals(3000, (float) $user->fresh()->wallet_balance);
        $this->assertEquals('failed', $tx->fresh()->status);

        // Assert refund transaction exists
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'airtime',
            'direction' => 'in',
            'title' => 'Recharge Refund',
            'amount' => 1000,
        ]);
    }

    public function test_user_can_fetch_electricity_providers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/bills/electricity/providers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'providers' => [
                    '*' => ['name', 'code', 'min_amount_prepaid', 'min_amount_postpaid'],
                ],
            ]);
    }

    public function test_user_can_verify_electricity_meter(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/bills/electricity/verify', [
            'disco' => 'ikeja-electric',
            'meter_number' => '04192039201',
            'meter_type' => 'prepaid',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('name', 'ADEKUNLE OLAWALE SAMUEL')
            ->assertJsonPath('meter_number', '04192039201');
    }

    public function test_electricity_purchase_enforces_minimum_1000_amount(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 10000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'electricity',
            'disco' => 'ikeja-electric',
            'meter_number' => '04192039201',
            'meter_type' => 'prepaid',
            'amount' => 500, // Under 1000
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_user_can_purchase_electricity_successfully(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        // Mock BigisubService purchaseElectricity
        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseElectricity')->willReturn([
            'success' => true,
            'status' => 'successful',
            'reference' => 'BIGI_ELEC_TEST999',
            'token' => '4829-1940-2819-4920-1944',
            'units' => '14.7',
            'message' => 'Electricity payment successful',
            'provider' => 'bigisub',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'electricity',
            'disco' => 'ikeja-electric',
            'meter_number' => '04192039201',
            'meter_type' => 'prepaid',
            'amount' => 1000,
            'customer_name' => 'ADEKUNLE OLAWALE SAMUEL',
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('provider', 'bigisub')
            ->assertJsonPath('token', '4829-1940-2819-4920-1944')
            ->assertJsonPath('units', '14.7');

        // Assert user's balance was deducted (5000 - 1000 = 4000)
        $this->assertEquals(4000, (float) $user->fresh()->wallet_balance);

        // Assert transaction was recorded
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'electricity',
            'amount' => 1000,
            'direction' => 'out',
            'status' => 'successful',
            'reference' => 'BIGI_ELEC_TEST999',
        ]);
    }

    public function test_depleted_vendor_wallet_error_is_sanitized_for_users(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        // Mock BigisubService to simulate float depletion error from vendor
        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseElectricity')->willReturn([
            'success' => false,
            'status' => 'failed',
            'message' => 'Electricity recharge is temporarily unavailable due to routine provider maintenance. Please try again shortly.',
            'provider' => 'bigisub',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'electricity',
            'disco' => 'ikeja-electric',
            'meter_number' => '04192039201',
            'meter_type' => 'prepaid',
            'amount' => 1000,
            'customer_name' => 'ADEKUNLE OLAWALE SAMUEL',
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Electricity recharge is temporarily unavailable due to routine provider maintenance. Please try again shortly.');

        // User balance should remain untouched
        $this->assertEquals(5000, (float) $user->fresh()->wallet_balance);
    }

    public function test_duplicate_meter_payment_within_window_is_blocked_with_429(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseElectricity')->willReturn([
            'success' => true,
            'status' => 'successful',
            'reference' => 'BIGI_ELEC_DUP1',
            'token' => '1111-2222-3333-4444-5555',
            'units' => '14.7',
            'message' => 'Electricity payment successful',
            'provider' => 'bigisub',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        // First attempt succeeds
        $firstResponse = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'electricity',
            'disco' => 'ikeja-electric',
            'meter_number' => '04192039201',
            'meter_type' => 'prepaid',
            'amount' => 1000,
            'customer_name' => 'ADEKUNLE OLAWALE SAMUEL',
            'pin' => '1234',
        ]);
        $firstResponse->assertStatus(200);

        // Immediate second attempt with same meter and amount must be rejected with 429
        $secondResponse = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'electricity',
            'disco' => 'ikeja-electric',
            'meter_number' => '04192039201',
            'meter_type' => 'prepaid',
            'amount' => 1000,
            'customer_name' => 'ADEKUNLE OLAWALE SAMUEL',
            'pin' => '1234',
        ]);

        $secondResponse->assertStatus(429)
            ->assertJsonPath('message', 'A recharge of ₦1,000.00 for meter 04192039201 was just initiated. Please wait a minute to avoid duplicate payments.');

        // User was only charged once (5000 - 1000 = 4000)
        $this->assertEquals(4000, (float) $user->fresh()->wallet_balance);
    }

    public function test_electricity_recharge_saves_beneficiary_and_allows_retrieval_and_deletion(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 10000,
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseElectricity')->willReturn([
            'success' => true,
            'status' => 'successful',
            'reference' => 'BIGI_ELEC_BENEF_99',
            'token' => '4829-1029-4820-1928-3849',
            'units' => '42.5',
            'provider' => 'bigisub',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        // Purchase with save_beneficiary and custom nickname
        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'electricity',
            'disco' => 'ikeja-electric',
            'meter_number' => '01429810238',
            'meter_type' => 'prepaid',
            'amount' => 2000,
            'customer_name' => 'ABBE PAUL OSAGIE',
            'pin' => '1234',
            'save_beneficiary' => true,
            'beneficiary_nickname' => 'Home Flat',
        ]);

        $response->assertStatus(200);

        // Fetch saved electricity beneficiaries
        $listResponse = $this->actingAs($user)->getJson('/api/v1/beneficiaries?category=electricity');
        $listResponse->assertStatus(200)
            ->assertJsonPath('data.0.category', 'electricity')
            ->assertJsonPath('data.0.nickname', 'Home Flat')
            ->assertJsonPath('data.0.account_number', '01429810238')
            ->assertJsonPath('data.0.meter_type', 'prepaid');

        $beneficiaryId = $listResponse->json('data.0.id');

        // Delete beneficiary
        $deleteResponse = $this->actingAs($user)->deleteJson("/api/v1/beneficiaries/{$beneficiaryId}");
        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Beneficiary removed successfully.');

        $this->assertDatabaseMissing('beneficiaries', ['id' => $beneficiaryId]);
    }

    public function test_user_can_fund_betting_wallet_successfully(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('getBettingBillers')->willReturn([
            ['code' => 'biller-sporty-bet', 'name' => 'SportyBet', 'min_amount' => 100, 'max_amount' => 100000],
        ]);
        $mockBigisub->method('getBettingProducts')->willReturn([
            ['code' => 'prd-sporty-bet', 'name' => 'Sporty Bet'],
        ]);
        $mockBigisub->method('fundBetting')->willReturn([
            'success' => true,
            'status' => 'successful',
            'transaction_id' => 'BIGI_BET_888999',
            'reference' => 'BIGI_BET_888999',
            'provider' => 'bigisub',
            'message' => 'Betting wallet funded successfully',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'betting',
            'biller_code' => 'biller-sporty-bet',
            'biller_name' => 'SportyBet',
            'customer_id' => '08012345678',
            'customer_name' => 'JOHN DOE',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('provider', 'bigisub')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.reference', 'BET_BIGI_BET_888999')
            ->assertJsonPath('data.amount', 1000);

        // Wallet was debited exactly 1000 (5000 -> 4000)
        $this->assertEquals(4000, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'reference' => 'BET_BIGI_BET_888999',
            'status' => 'successful',
            'amount' => 1000,
        ]);
    }

    public function test_duplicate_betting_funding_within_window_is_blocked_with_429(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 10000,
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('getBettingBillers')->willReturn([
            ['code' => 'biller-sporty-bet', 'name' => 'SportyBet', 'min_amount' => 100, 'max_amount' => 100000],
        ]);
        $mockBigisub->method('getBettingProducts')->willReturn([
            ['code' => 'prd-sporty-bet', 'name' => 'Sporty Bet'],
        ]);
        $mockBigisub->method('fundBetting')->willReturn([
            'success' => true,
            'status' => 'successful',
            'transaction_id' => 'BIGI_BET_DUP_1',
            'provider' => 'bigisub',
            'message' => 'Betting wallet funded successfully',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        // First attempt succeeds
        $res1 = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'betting',
            'biller_code' => 'biller-sporty-bet',
            'biller_name' => 'SportyBet',
            'customer_id' => '08012345678',
            'customer_name' => 'JOHN DOE',
            'amount' => 1000,
            'pin' => '1234',
        ]);
        $res1->assertStatus(200);

        // Immediate duplicate attempt to same bookmaker & customer must be blocked with 429
        $res2 = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'betting',
            'biller_code' => 'biller-sporty-bet',
            'biller_name' => 'SportyBet',
            'customer_id' => '08012345678',
            'customer_name' => 'JOHN DOE',
            'amount' => 1000,
            'pin' => '1234',
        ]);
        $res2->assertStatus(429);

        // User was only charged once (10000 - 1000 = 9000)
        $this->assertEquals(9000, (float) $user->fresh()->wallet_balance);
    }

    public function test_betting_funding_failure_automatically_refunds_wallet(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('getBettingBillers')->willReturn([
            ['code' => 'biller-sporty-bet', 'name' => 'SportyBet', 'min_amount' => 100, 'max_amount' => 100000],
        ]);
        $mockBigisub->method('getBettingProducts')->willReturn([
            ['code' => 'prd-sporty-bet', 'name' => 'Sporty Bet'],
        ]);
        $mockBigisub->method('fundBetting')->willReturn([
            'success' => false,
            'status' => 'failed',
            'is_ambiguous' => false,
            'message' => 'Invalid customer ID. Account not found with bookmaker.',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'betting',
            'biller_code' => 'biller-sporty-bet',
            'biller_name' => 'SportyBet',
            'customer_id' => '00000000',
            'customer_name' => 'INVALID USER',
            'amount' => 1000,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Invalid customer ID. Account not found with bookmaker.');

        // User was refunded atomically; balance remains 5000
        $this->assertEquals(5000, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'status' => 'failed',
            'amount' => 1000,
        ]);
    }

    public function test_concurrent_betting_requery_prevents_double_refund(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 2000,
        ]);

        $tx = \App\Models\Transaction::create([
            'user_id' => $user->id,
            'reference' => 'BET_TX_FAILED_101',
            'type' => 'airtime',
            'direction' => 'out',
            'title' => 'SportyBet Top-up',
            'subtitle' => 'Account: 08012345678',
            'amount' => 1000,
            'fee' => 0,
            'currency' => 'NGN',
            'status' => 'pending',
            'counterparty' => 'SportyBet · 08012345678',
            'counterparty_bank' => 'SPORTYBET',
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('requeryBetting')->willReturn([
            'status' => 'failed',
            'delivered' => false,
            'message' => 'Transaction failed at provider.',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        // First requery executes refund (2000 + 1000 = 3000)
        $res1 = $this->actingAs($user)->postJson("/api/v1/bills/requery/{$tx->id}");
        $res1->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('refunded', true);

        $this->assertEquals(3000, (float) $user->fresh()->wallet_balance);

        // Second requery on already failed/refunded transaction must NOT refund again!
        $res2 = $this->actingAs($user)->postJson("/api/v1/bills/requery/{$tx->id}");
        $res2->assertStatus(200);

        // Balance MUST STILL be exactly 3000 (no double refund)
        $this->assertEquals(3000, (float) $user->fresh()->wallet_balance);
    }

    public function test_user_can_fetch_cable_plans_successfully(): void
    {
        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('getCablePlans')->willReturn([
            ['id' => 55, 'cable_name' => 'dstv', 'product_name' => 'DStv Padi', 'variation_code' => 'dstv-padi', 'amount' => 4400],
        ]);
        $mockBigisub->method('getCablePricing')->willReturn([
            ['provider' => 'DSTV', 'provider_code' => 'dstv', 'plans' => []],
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/bills/cable/plans');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('plans.0.product_name', 'DStv Padi');
    }

    public function test_user_can_verify_cable_smartcard_successfully(): void
    {
        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('verifyCable')->willReturn([
            'success' => true,
            'name' => 'CHUKWUDI EZE',
            'smartcard_number' => '1234567890',
            'cable_name' => 'dstv',
            'due_date' => '2026-11-01',
            'status' => 'Active',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/bills/cable/verify', [
            'cable_name' => 'dstv',
            'smartcard_number' => '1234567890',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('name', 'CHUKWUDI EZE');
    }

    public function test_user_can_renew_cable_subscription_successfully(): void
    {
        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseCable')->willReturn([
            'success' => true,
            'status' => 'successful',
            'transaction_id' => 'BS_CBL_998877',
            'message' => 'Subscription renewed successfully',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 10000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'cable',
            'cable_name' => 'dstv',
            'smartcard_number' => '1234567890',
            'cable_plan' => 'dstv-padi',
            'package_name' => 'DStv Padi',
            'customer_name' => 'CHUKWUDI EZE',
            'amount' => 4400,
            'months' => 1,
            'pin' => '1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.amount', 4400)
            ->assertJsonPath('data.counterparty', 'DStv · 1234567890');

        $this->assertEquals(5600, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'status' => 'successful',
            'amount' => 4400,
        ]);
        $this->assertDatabaseHas('beneficiaries', [
            'user_id' => $user->id,
            'account_number' => '1234567890',
            'category' => 'cable',
        ]);
    }

    public function test_duplicate_in_flight_cable_renewal_is_blocked_with_429(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 15000,
        ]);

        \App\Models\Transaction::create([
            'user_id' => $user->id,
            'reference' => 'CBL_PENDING_001',
            'type' => 'airtime',
            'direction' => 'out',
            'title' => 'DStv Subscription',
            'subtitle' => 'DStv Padi · IUC: 1234567890',
            'amount' => 4400,
            'fee' => 0,
            'currency' => 'NGN',
            'status' => 'pending',
            'counterparty' => 'DStv · 1234567890',
            'counterparty_bank' => 'DSTV',
            'created_at' => now()->subSeconds(20),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'cable',
            'cable_name' => 'dstv',
            'smartcard_number' => '1234567890',
            'cable_plan' => 'dstv-padi',
            'package_name' => 'DStv Padi',
            'customer_name' => 'CHUKWUDI EZE',
            'amount' => 4400,
            'months' => 1,
            'pin' => '1234',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('message', 'A renewal of ₦4,400.00 to DStv (1234567890) is currently processing. Please wait for confirmation before submitting again.');

        // Balance untouched
        $this->assertEquals(15000, (float) $user->fresh()->wallet_balance);
    }

    public function test_cable_subscription_failure_triggers_atomic_wallet_refund(): void
    {
        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseCable')->willReturn([
            'success' => false,
            'status' => 'failed',
            'is_ambiguous' => false,
            'message' => 'Smartcard suspended or invalid bouquet selection.',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 8000,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'cable',
            'cable_name' => 'gotv',
            'smartcard_number' => '2023456789',
            'cable_plan' => 'gotv-max',
            'package_name' => 'GOtv Max',
            'customer_name' => 'EMMANUEL NWANKWO',
            'amount' => 7200,
            'months' => 1,
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Smartcard suspended or invalid bouquet selection.');

        // Atomic pre-debit was automatically refunded
        $this->assertEquals(8000, (float) $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'status' => 'failed',
            'amount' => 7200,
        ]);
    }

    public function test_duplicate_cable_renewal_within_window_after_success_is_blocked_with_429(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 20000,
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('purchaseCable')->willReturn([
            'success' => true,
            'status' => 'successful',
            'transaction_id' => 'BS_CBL_SUCCESS_1',
            'message' => 'Subscription renewed successfully',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        // First attempt succeeds
        $firstResponse = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'cable',
            'cable_name' => 'dstv',
            'smartcard_number' => '1234567890',
            'cable_plan' => 'dstv-padi',
            'package_name' => 'DStv Padi',
            'customer_name' => 'CHUKWUDI EZE',
            'amount' => 4400,
            'months' => 1,
            'pin' => '1234',
        ]);
        $firstResponse->assertStatus(200);

        // Immediate duplicate attempt on the recently completed smartcard renewal must be rejected with 429
        $secondResponse = $this->actingAs($user)->postJson('/api/v1/bills/purchase', [
            'category' => 'cable',
            'cable_name' => 'dstv',
            'smartcard_number' => '1234567890',
            'cable_plan' => 'dstv-padi',
            'package_name' => 'DStv Padi',
            'customer_name' => 'CHUKWUDI EZE',
            'amount' => 4400,
            'months' => 1,
            'pin' => '1234',
        ]);

        $secondResponse->assertStatus(429)
            ->assertJsonPath('message', 'A renewal of ₦4,400.00 to DStv (1234567890) was recently completed. Please wait 90 seconds to prevent accidental duplicate charges.');

        // User was only debited once (20000 - 4400 = 15600)
        $this->assertEquals(15600, (float) $user->fresh()->wallet_balance);
    }

    public function test_concurrent_cable_requery_prevents_double_refund(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1234'),
            'has_pin' => true,
            'wallet_balance' => 5000,
        ]);

        $tx = \App\Models\Transaction::create([
            'user_id' => $user->id,
            'reference' => 'CBL_TX_PENDING_REFUND',
            'type' => 'airtime',
            'direction' => 'out',
            'title' => 'GOtv Subscription',
            'subtitle' => 'GOtv Jolli · IUC: 2023456789',
            'amount' => 3900,
            'fee' => 0,
            'currency' => 'NGN',
            'status' => 'pending',
            'counterparty' => 'GOtv · 2023456789',
            'counterparty_bank' => 'GOTV',
            'description' => 'Test pending cable subscription',
        ]);

        $mockBigisub = $this->createMock(\App\Services\BigisubService::class);
        $mockBigisub->method('requery')->willReturn([
            'status' => 'failed',
            'delivered' => false,
            'message' => 'Subscription failed at provider.',
        ]);
        $this->app->instance(\App\Services\BigisubService::class, $mockBigisub);

        // First requery marks failed and refunds wallet: 5000 + 3900 = 8900
        $res1 = $this->actingAs($user)->postJson("/api/v1/bills/requery/{$tx->id}");
        $res1->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('refunded', true);

        $this->assertEquals(8900, (float) $user->fresh()->wallet_balance);
        $this->assertEquals('failed', $tx->fresh()->status);

        // Subsequent requery must recognize already failed/refunded state and NOT issue double refund!
        $res2 = $this->actingAs($user)->postJson("/api/v1/bills/requery/{$tx->id}");
        $res2->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'This recharge failed and has already been refunded.');

        // Balance must still be exactly 8900
        $this->assertEquals(8900, (float) $user->fresh()->wallet_balance);
    }
}

