<?php

namespace Tests\Feature;

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
                        ],
                    ],
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
        ]);

        $orderRes->assertOk();
        $this->assertEquals(50.0, $orderRes->json('details.crypto_debited'));
        $this->assertEquals(50.0, $orderRes->json('details.new_crypto_balance'));

        // Check user Naira wallet was credited
        $user->refresh();
        $this->assertEquals($expectedNaira, $user->wallet_balance);
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

    public function test_quidax_webhook_processes_deposit_and_auto_converts(): void
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
        $this->assertGreaterThan(100000, $user->wallet_balance);
        $this->assertDatabaseHas('crypto_deposits', [
            'user_id' => $user->id,
            'symbol' => 'USDT',
            'network' => 'TRC20',
            'amount' => 75.0,
            'converted_to_naira' => true,
        ]);
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

    public function test_user_can_send_crypto_to_external_address_via_quidax(): void
    {
        $sender = User::factory()->create([
            'has_pin' => true,
            'pin' => Hash::make('1234'),
        ]);
        $sender->cryptoWallets()->create(['symbol' => 'USDT', 'balance' => 100.0]);

        Sanctum::actingAs($sender);

        // TRC20 fee is 1.0 USDT, sending 50.0 USDT -> total debit 51.0 USDT
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
}


