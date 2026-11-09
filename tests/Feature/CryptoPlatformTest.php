<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CryptoFeeSetting;
use App\Models\CryptoTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CryptoPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_fetch_market_and_addresses(): void
    {
        $user = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
        ]);

        Sanctum::actingAs($user);

        // 1. Market
        $marketRes = $this->getJson('/api/v1/crypto/market');
        $marketRes->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'coins',
                    'portfolio_value',
                    'portfolio_change',
                    'auto_convert_crypto',
                ],
            ]);

        // 2. Addresses for USDT
        $addressRes = $this->getJson('/api/v1/crypto/addresses?symbol=USDT');
        $addressRes->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'symbol',
                    'auto_convert_crypto',
                    'addresses' => [
                        '*' => [
                            'symbol',
                            'network',
                            'network_name',
                            'address',
                            'qr_code_url',
                            'warning',
                        ],
                    ],
                    'recent_deposits',
                ],
            ]);

        $addresses = $addressRes->json('data.addresses');
        $networks = array_column($addresses, 'network');
        $this->assertContains('TRC20', $networks);
        $this->assertContains('BEP20', $networks);
        $this->assertContains('ERC20', $networks);
    }

    public function test_user_can_receive_crypto_and_convert_to_naira_manually(): void
    {
        $user = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
            'wallet_balance' => 0.00,
            'auto_convert_crypto' => false,
        ]);

        Sanctum::actingAs($user);

        // 1. Simulate incoming deposit of 100 USDT (TRC20)
        $depositRes = $this->postJson('/api/v1/crypto/receive/simulate', [
            'symbol' => 'USDT',
            'network' => 'TRC20',
            'amount' => 100.0,
        ]);

        $depositRes->assertOk()
            ->assertJsonPath('data.auto_converted', false);
        $this->assertEquals(100.0, $depositRes->json('data.crypto_credited'));

        // Verify crypto balance is now 100 USDT
        $balanceRes = $this->getJson('/api/v1/crypto/balances');
        $balanceRes->assertOk();
        $usdt = collect($balanceRes->json('data.balances'))->firstWhere('symbol', 'USDT');
        $this->assertEquals(100.0, $usdt['amount']);

        // 2. Get quote to convert 50 USDT to Naira
        $quoteRes = $this->postJson('/api/v1/crypto/quote', [
            'side' => 'sell',
            'symbol' => 'USDT',
            'amount' => 50.0,
        ]);

        $quoteRes->assertOk()
            ->assertJsonPath('data.side', 'sell')
            ->assertJsonPath('data.symbol', 'USDT');

        $expectedNaira = $quoteRes->json('data.fiat_amount');
        $this->assertGreaterThan(50000, $expectedNaira);

        // 3. Execute conversion order with PIN
        $orderRes = $this->postJson('/api/v1/crypto/orders', [
            'side' => 'sell',
            'symbol' => 'USDT',
            'amount' => 50.0,
            'pin' => '1234',
            'idempotency_key' => 'idem_sell_usdt_001',
        ]);

        $orderRes->assertOk();
        $this->assertEquals(50.0, $orderRes->json('details.crypto_debited'));
        $this->assertEquals(50.0, $orderRes->json('details.new_crypto_balance'));

        // Check user Naira wallet was credited
        $user->refresh();
        $this->assertEquals($expectedNaira, $user->wallet_balance);

        // Verify idempotency: repeating same idempotency_key does not double-debit
        $dupRes = $this->postJson('/api/v1/crypto/orders', [
            'side' => 'sell',
            'symbol' => 'USDT',
            'amount' => 50.0,
            'pin' => '1234',
            'idempotency_key' => 'idem_sell_usdt_001',
        ]);
        $dupRes->assertOk();
        $this->assertEquals(50.0, $user->cryptoWallets()->where('symbol', 'USDT')->first()->balance);
    }

    public function test_user_can_buy_crypto_with_naira_and_view_history_with_idor_protection(): void
    {
        $user = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
            'wallet_balance' => 200000.00,
        ]);

        $otherUser = User::factory()->create();

        Sanctum::actingAs($user);

        // 1. Quote for buying 100,000 NGN worth of USDT
        $quoteRes = $this->postJson('/api/v1/crypto/quote', [
            'side' => 'buy',
            'symbol' => 'USDT',
            'amount' => 100000,
            'amount_type' => 'fiat',
        ]);
        $quoteRes->assertOk();
        $expectedCrypto = $quoteRes->json('data.crypto_amount');
        $this->assertGreaterThan(50, $expectedCrypto);

        // 2. Place buy order
        $buyRes = $this->postJson('/api/v1/crypto/orders', [
            'side' => 'buy',
            'symbol' => 'USDT',
            'amount' => 100000,
            'pin' => '1234',
        ]);
        $buyRes->assertOk();

        $user->refresh();
        $this->assertEquals(100000.00, (float) $user->wallet_balance);
        $this->assertEqualsWithDelta($expectedCrypto, (float) $user->cryptoWallets()->where('symbol', 'USDT')->first()->balance, 0.0001);

        // 3. User Crypto Transaction History
        $historyRes = $this->getJson('/api/v1/crypto/transactions?type=buy_crypto');
        $historyRes->assertOk();
        $items = $historyRes->json('data');
        $this->assertCount(1, $items);
        $cryptoTxId = $items[0]['id'];

        // 4. View single transaction details
        $detailRes = $this->getJson("/api/v1/crypto/transactions/{$cryptoTxId}");
        $detailRes->assertOk()->assertJsonPath('data.symbol', 'USDT');

        // 5. IDOR Protection: otherUser cannot view user's transaction
        Sanctum::actingAs($otherUser);
        $idorRes = $this->getJson("/api/v1/crypto/transactions/{$cryptoTxId}");
        $idorRes->assertStatus(404);
    }

    public function test_auto_convert_automatically_credits_naira_wallet(): void
    {
        $user = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
            'wallet_balance' => 0.00,
            'auto_convert_crypto' => false,
        ]);

        Sanctum::actingAs($user);

        // 1. Enable auto-convert
        $toggleRes = $this->postJson('/api/v1/crypto/auto-convert', [
            'enabled' => true,
        ]);
        $toggleRes->assertOk()->assertJsonPath('auto_convert_crypto', true);

        // 2. Simulate deposit of 200 USDT
        $depositRes = $this->postJson('/api/v1/crypto/receive/simulate', [
            'symbol' => 'USDT',
            'network' => 'TRC20',
            'amount' => 200.0,
        ]);

        $depositRes->assertOk()
            ->assertJsonPath('data.auto_converted', true);

        // User should have 0 crypto holding and full Naira wallet balance credited
        $user->refresh();
        $this->assertGreaterThan(250000, $user->wallet_balance);

        $wallet = $user->cryptoWallets()->where('symbol', 'USDT')->first();
        $this->assertEquals(0, $wallet?->balance ?? 0);
    }

    public function test_quidax_webhook_processes_deposit_and_blocks_duplicate_replays(): void
    {
        $user = User::factory()->create([
            'quidax_user_id' => 'quidax_user_test_123',
            'wallet_balance' => 0.00,
            'auto_convert_crypto' => true,
        ]);

        $payload = [
            'event' => 'deposit.transaction.confirmed',
            'data' => [
                'id' => 'dep_qdx_999888',
                'txid' => '0xmockedtxhash123456789',
                'currency' => 'usdt',
                'amount' => '75.00',
                'network' => 'trc20',
                'user' => [
                    'id' => 'quidax_user_test_123',
                    'email' => $user->email,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/crypto/quidax/webhook', $payload);
        $response->assertOk()->assertJsonPath('status', 'success');

        $user->refresh();
        $balanceAfterFirstWebhook = (float) $user->wallet_balance;
        $this->assertGreaterThan(100000, $balanceAfterFirstWebhook);
        $this->assertDatabaseHas('crypto_deposits', [
            'user_id' => $user->id,
            'symbol' => 'USDT',
            'network' => 'TRC20',
            'amount' => 75.0,
            'converted_to_naira' => true,
        ]);

        // Replay the exact same webhook: must be detected as duplicate and not double-credit
        $dupResponse = $this->postJson('/api/v1/crypto/quidax/webhook', $payload);
        $dupResponse->assertOk()->assertJsonPath('status', 'duplicate');

        $user->refresh();
        $this->assertEquals($balanceAfterFirstWebhook, (float) $user->wallet_balance);
    }

    public function test_user_can_send_crypto_to_another_gutter_user_instantly(): void
    {
        $sender = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
        ]);
        $sender->cryptoWallets()->create(['symbol' => 'USDT', 'balance' => 100.0]);

        $recipient = User::factory()->create([
            'email' => 'receiver@gutter.test',
            'auto_convert_crypto' => false,
        ]);

        Sanctum::actingAs($sender);

        $res = $this->postJson('/api/v1/crypto/send', [
            'symbol' => 'USDT',
            'destination_type' => 'gutter_user',
            'recipient' => 'receiver@gutter.test',
            'amount' => 40.0,
            'pin' => '1234',
        ]);

        $res->assertOk();
        $this->assertEquals(60.0, $sender->cryptoWallets()->where('symbol', 'USDT')->first()->balance);
        $this->assertEquals(40.0, $recipient->cryptoWallets()->where('symbol', 'USDT')->first()->balance);
    }

    public function test_user_can_send_crypto_to_external_address_via_quidax_and_validates_address(): void
    {
        $sender = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
        ]);
        $sender->cryptoWallets()->create(['symbol' => 'USDT', 'balance' => 100.0]);

        Sanctum::actingAs($sender);

        // 1. Invalid TRC20 address should be rejected
        $badRes = $this->postJson('/api/v1/crypto/send', [
            'symbol' => 'USDT',
            'destination_type' => 'external_address',
            'recipient' => '0xInvalidTronAddress12345',
            'network' => 'TRC20',
            'amount' => 50.0,
            'pin' => '1234',
        ]);
        $badRes->assertStatus(422);

        // 2. Valid TRC20 address succeeds (TRC20 fee is 1.0 USDT, sending 50.0 USDT -> total debit 51.0 USDT)
        $res = $this->postJson('/api/v1/crypto/send', [
            'symbol' => 'USDT',
            'destination_type' => 'external_address',
            'recipient' => 'TJY9hYm8U3vW6G9xK12345678901234567',
            'network' => 'TRC20',
            'amount' => 50.0,
            'pin' => '1234',
        ]);

        $res->assertOk();
        $this->assertEquals(49.0, $sender->cryptoWallets()->where('symbol', 'USDT')->first()->balance);
    }

    public function test_admin_can_configure_fees_and_refund_eligible_crypto_and_naira_transactions(): void
    {
        $admin = AdminUser::create([
            'name' => 'Chief Compliance Admin',
            'email' => 'admin@gutter.test',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $adminToken = $admin->createToken('admin-test', ['admin'])->plainTextToken;

        $user = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
            'wallet_balance' => 100000.00,
        ]);
        $user->cryptoWallets()->create(['symbol' => 'USDT', 'balance' => 100.0]);

        // 1. Admin updates send_crypto fee settings (e.g., adds 0.5 USDT fixed platform fee)
        $feeUpdateRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson('/api/v1/admin/crypto/settings/send_crypto', [
                'percentage_fee' => 0.0,
                'fixed_fee_ngn' => 0.0,
                'fixed_fee_crypto' => 0.5,
                'min_amount' => 2.0,
                'max_amount' => 50000.0,
                'min_withdrawal_amount' => 2.0,
                'max_withdrawal_amount' => 50000.0,
                'is_enabled' => true,
                'maintenance_mode' => false,
            ]);
        $feeUpdateRes->assertOk();

        // 2. User initiates external withdrawal of 20 USDT (1.0 network fee + 0.5 platform fee = 21.5 USDT deducted)
        Sanctum::actingAs($user);
        $sendRes = $this->postJson('/api/v1/crypto/send', [
            'symbol' => 'USDT',
            'destination_type' => 'external_address',
            'recipient' => 'TJY9hYm8U3vW6G9xK12345678901234567',
            'network' => 'TRC20',
            'amount' => 20.0,
            'pin' => '1234',
        ]);
        $sendRes->assertOk();
        $this->assertEquals(78.5, (float) $user->cryptoWallets()->where('symbol', 'USDT')->first()->balance);

        $cryptoTx = CryptoTransaction::where('user_id', $user->id)->where('type', 'send_crypto')->firstOrFail();

        // 3. Rule check: Admin CANNOT auto-refund a 'successful' blockchain withdrawal
        $blockedRefundRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/admin/crypto/transactions/{$cryptoTx->id}/refund", [
                'reason' => 'Attempting to refund a settled blockchain withdrawal',
            ]);
        $blockedRefundRes->assertStatus(422);

        // 4. Simulate a failed external withdrawal (e.g., Quidax webhook reported withdrawal rejected)
        $cryptoTx->update(['status' => 'rejected']);

        // 5. Admin refunds the rejected crypto withdrawal -> full 21.5 USDT (amount + fees) returned to user's USDT wallet
        $refundRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/admin/crypto/transactions/{$cryptoTx->id}/refund", [
                'reason' => 'Quidax rejected external withdrawal due to network congestion',
            ]);
        $refundRes->assertOk();
        $this->assertEquals(100.0, (float) $user->cryptoWallets()->where('symbol', 'USDT')->first()->balance);

        $cryptoTx->refresh();
        $this->assertTrue($cryptoTx->is_refunded);
        $this->assertEquals('refunded', $cryptoTx->status);

        // 6. Rule check: Duplicate refund on same transaction is strictly blocked
        $dupRefundRes = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/v1/admin/crypto/transactions/{$cryptoTx->id}/refund", [
                'reason' => 'Second refund attempt',
            ]);
        $dupRefundRes->assertStatus(422);

        // 7. Changing fees later must not modify historical transaction's fee_config_snapshot
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson('/api/v1/admin/crypto/settings/send_crypto', [
                'percentage_fee' => 2.5,
                'fixed_fee_ngn' => 500.0,
                'fixed_fee_crypto' => 3.0,
                'min_amount' => 5.0,
                'max_amount' => 50000.0,
                'is_enabled' => true,
            ])->assertOk();

        $cryptoTx->refresh();
        $this->assertEquals('0.50000000', $cryptoTx->fee_config_snapshot['fixed_fee_crypto']);
    }
}
