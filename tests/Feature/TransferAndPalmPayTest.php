<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransferAndPalmPayTest extends TestCase
{
    use RefreshDatabase;

    public function test_banks_list_returns_banks(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/banks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['code', 'name'],
                ],
            ]);

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_verify_bank_account_returns_name(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers/verify-account', [
            'bank_code' => '999991',
            'account_number' => '9012345678',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.account_number', '9012345678')
            ->assertJsonPath('data.bank_code', '999991');

        $this->assertNotEmpty($response->json('data.account_name'));
    }

    public function test_user_can_verify_external_opay_account_matching_own_phone_number(): void
    {
        $user = User::factory()->create([
            'phone' => '+2348077406889',
            'account_number' => '6681835369',
            'bank_name' => 'PalmPay',
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        // Verifying an external OPay account whose account number matches the user's phone suffix
        $response = $this->actingAs($user)->postJson('/api/v1/transfers/verify-account', [
            'bank_code' => '999992', // OPay
            'account_number' => '8077406889',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.account_number', '8077406889');
        $this->assertNotEmpty($response->json('data.account_name'));
    }

    public function test_user_cannot_transfer_to_own_gutter_deposit_account(): void
    {
        $user = User::factory()->create([
            'phone' => '+2348077406889',
            'account_number' => '6681835369',
            'bank_name' => 'PalmPay',
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        // Entering user's exact Gutter deposit account number at PalmPay
        $response = $this->actingAs($user)->postJson('/api/v1/transfers/verify-account', [
            'bank_code' => '999991', // PalmPay
            'account_number' => '6681835369',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'You cannot transfer money to your own Gutter deposit account.');
    }

    public function test_resolve_gutter_user(): void
    {
        $sender = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        $recipient = User::factory()->create([
            'name' => 'Alice Johnson',
            'email' => 'alice@gutter.com',
            'phone' => '08099887766',
            'account_number' => '9099887766',
        ]);

        $response = $this->actingAs($sender)->postJson('/api/v1/transfers/resolve-gutter-user', [
            'identifier' => 'alice@gutter.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $recipient->id)
            ->assertJsonPath('data.name', 'Alice Johnson')
            ->assertJsonPath('data.is_gutter_user', true);
    }

    public function test_transfer_requires_kyc_verification(): void
    {
        $unverified = User::factory()->create([
            'kyc_status' => 'unverified',
            'status' => 'active',
            'wallet_balance' => 50000,
            'pin' => Hash::make('1984'),
            'has_pin' => true,
        ]);

        $response = $this->actingAs($unverified)->postJson('/api/v1/transfers', [
            'type' => 'bank',
            'bank_code' => '044',
            'account_number' => '0123456789',
            'amount' => 5000,
            'pin' => '1984',
        ]);

        $response->assertStatus(403);
    }

    public function test_gutter_to_gutter_transfer_is_instant_and_zero_fee(): void
    {
        $sender = User::factory()->create([
            'name' => 'Sender Bob',
            'kyc_status' => 'verified',
            'status' => 'active',
            'wallet_balance' => 20000,
            'pin' => Hash::make('2468'),
            'has_pin' => true,
            'tier' => 2,
        ]);

        $recipient = User::factory()->create([
            'name' => 'Recipient Jane',
            'email' => 'jane@gutter.com',
            'wallet_balance' => 1000,
        ]);

        $response = $this->actingAs($sender)->postJson('/api/v1/transfers', [
            'type' => 'gutter',
            'recipient_identifier' => 'jane@gutter.com',
            'amount' => 5000,
            'pin' => '2468',
            'description' => 'Dinner contribution',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.fee', 0)
            ->assertJsonPath('data.amount', 5000)
            ->assertJsonPath('data.status', 'successful');

        $this->assertEquals(15000, (float) $sender->fresh()->wallet_balance);
        $this->assertEquals(6000, (float) $recipient->fresh()->wallet_balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $sender->id,
            'direction' => 'out',
            'fee' => 0.00,
            'amount' => 5000,
            'counterparty' => 'Recipient Jane',
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipient->id,
            'direction' => 'in',
            'fee' => 0.00,
            'amount' => 5000,
            'counterparty' => 'Sender Bob',
        ]);
    }

    public function test_external_bank_transfer_deducts_fee_and_balance(): void
    {
        $sender = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
            'wallet_balance' => 50000,
            'pin' => Hash::make('5566'),
            'has_pin' => true,
            'tier' => 2,
        ]);

        $response = $this->actingAs($sender)->postJson('/api/v1/transfers', [
            'type' => 'bank',
            'bank_code' => '058',
            'account_number' => '0123456789',
            'amount' => 10000,
            'pin' => '5566',
            'description' => 'Office rent',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.amount', 10000)
            ->assertJsonPath('data.fee', 10)
            ->assertJsonPath('data.status', 'successful');

        // Total deducted: 10000 + 10 = 10010
        $this->assertEquals(39990, (float) $sender->fresh()->wallet_balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $sender->id,
            'direction' => 'out',
            'amount' => 10000,
            'fee' => 10,
        ]);
    }

    public function test_palmpay_webhook_deposits_funds_to_virtual_account(): void
    {
        $user = User::factory()->create([
            'account_number' => '9011223344',
            'bank_name' => 'PalmPay',
            'wallet_balance' => 5000,
        ]);

        $response = $this->postJson('/api/v1/webhooks/palmpay', [
            'accountNumber' => '9011223344',
            'amount' => 15000,
            'orderNo' => 'PLM_TEST_' . time(),
            'payerName' => 'David Adeleke',
            'payerBank' => 'Access Bank',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('respCode', '000000');

        $this->assertEquals(20000, (float) $user->fresh()->wallet_balance);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'direction' => 'in',
            'amount' => 15000,
            'counterparty' => 'David Adeleke',
        ]);
    }

    public function test_kyc_face_verification_issues_palmpay_virtual_account(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'pending',
            'account_number' => null,
            'bank_name' => 'Pending Verification',
        ]);

        $user->kycVerifications()->create([
            'document_type' => 'nin',
            'document_number' => '12345678901',
            'status' => 'id_verified',
            'provider' => 'mock',
            'id_data' => ['first_name' => 'John', 'last_name' => 'Doe'],
            'id_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/kyc/face-check', [
            'selfie_image' => 'data:image/jpeg;base64,mockselfiedata',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'verified');

        $freshUser = $user->fresh();
        $this->assertEquals('verified', $freshUser->kyc_status);
        $this->assertEquals('PalmPay', $freshUser->bank_name);
        $this->assertNotEmpty($freshUser->account_number);
        $this->assertEquals(10, strlen($freshUser->account_number));
    }

    public function test_palmpay_webhook_prevents_double_receive_on_duplicate_delivery(): void
    {
        $user = User::factory()->create([
            'account_number' => '9088776655',
            'bank_name' => 'PalmPay',
            'wallet_balance' => 1000,
        ]);

        $payload = [
            'accountNumber' => '9088776655',
            'amount' => 5000,
            'orderNo' => 'PLM_DUPLICATE_ORDER_001',
            'payerName' => 'Wizkid Balogun',
            'payerBank' => 'Zenith Bank',
        ];

        // First delivery
        $res1 = $this->postJson('/api/v1/webhooks/palmpay', $payload);
        $res1->assertStatus(200)->assertJsonPath('respCode', '000000');
        $this->assertEquals(6000, (float) $user->fresh()->wallet_balance);

        // Immediate duplicate delivery (retry / network replay)
        $res2 = $this->postJson('/api/v1/webhooks/palmpay', $payload);
        $res2->assertStatus(200)->assertJsonPath('respCode', '000000');

        // Balance MUST NOT be double credited
        $this->assertEquals(6000, (float) $user->fresh()->wallet_balance);
        $this->assertEquals(1, Transaction::where('reference', 'PLM_DUPLICATE_ORDER_001')->count());
    }

    public function test_transfer_prevents_concurrent_double_spend_via_mutex_lock(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 5000,
            'kyc_status' => 'verified',
            'has_pin' => true,
            'pin' => \Illuminate\Support\Facades\Hash::make('1234'),
        ]);

        // Simulate an existing in-flight transfer lock
        \Illuminate\Support\Facades\Cache::lock('user_transfer_' . $user->id, 15)->get();

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'type' => 'gutter',
            'amount' => 500,
            'pin' => '1234',
            'recipient_identifier' => 'someone_else@gutter.app',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('message', 'A transfer is already being processed on your account. Please wait a moment.');
    }

    public function test_banks_list_prepends_gutter_instant_and_free(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/banks');

        $response->assertStatus(200);
        $banks = $response->json('data');
        $this->assertNotEmpty($banks);
        $this->assertEquals('999991', $banks[0]['code']);
        $this->assertEquals('Gutter (Instant & Free)', $banks[0]['name']);
    }

    public function test_verify_account_auto_resolves_gutter_user_even_without_bank_code(): void
    {
        $sender = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
        ]);

        $recipient = User::factory()->create([
            'name' => 'Michael Ade',
            'account_number' => '8888123456',
            'phone' => '08088123456',
        ]);

        // Scenario 1: No bank code passed, just recipient's 10-digit account number
        $response1 = $this->actingAs($sender)->postJson('/api/v1/transfers/verify-account', [
            'account_number' => '8888123456',
        ]);

        $response1->assertStatus(200)
            ->assertJsonPath('data.account_name', 'MICHAEL ADE')
            ->assertJsonPath('data.is_gutter_user', true)
            ->assertJsonPath('data.bank_code', '999991')
            ->assertJsonPath('data.recipient_id', $recipient->id)
            ->assertJsonPath('data.fee', 0);

        // Scenario 2: Selecting Gutter bank code 999991 explicitly
        $response2 = $this->actingAs($sender)->postJson('/api/v1/transfers/verify-account', [
            'bank_code' => '999991',
            'account_number' => '8888123456',
        ]);

        $response2->assertStatus(200)
            ->assertJsonPath('data.account_name', 'MICHAEL ADE')
            ->assertJsonPath('data.is_gutter_user', true);
    }

    public function test_bank_transfer_auto_routes_to_zero_fee_when_recipient_is_gutter_user(): void
    {
        $sender = User::factory()->create([
            'kyc_status' => 'verified',
            'status' => 'active',
            'wallet_balance' => 50000,
            'pin' => Hash::make('1122'),
            'has_pin' => true,
            'tier' => 2,
        ]);

        $recipient = User::factory()->create([
            'name' => 'Chioma Obi',
            'account_number' => '7777123456',
            'wallet_balance' => 5000,
        ]);

        // Sender initiates transfer via 'type' => 'bank' and bank_code => '999991' or PalmPay '100033'
        $response = $this->actingAs($sender)->postJson('/api/v1/transfers', [
            'type' => 'bank',
            'bank_code' => '999991',
            'account_number' => '7777123456',
            'amount' => 12000,
            'pin' => '1122',
            'description' => 'OPay style auto routing test',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.amount', 12000)
            ->assertJsonPath('data.fee', 0)
            ->assertJsonPath('data.status', 'successful');

        // Sender was charged ₦12000 with ₦0 fee (not ₦10 external fee)
        $this->assertEquals(38000, (float) $sender->fresh()->wallet_balance);
        $this->assertEquals(17000, (float) $recipient->fresh()->wallet_balance);
    }
}
