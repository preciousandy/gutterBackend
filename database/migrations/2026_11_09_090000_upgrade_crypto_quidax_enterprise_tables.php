<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Enhance crypto_wallets with locked_balance for pending withdrawals/orders
        Schema::table('crypto_wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('crypto_wallets', 'locked_balance')) {
                $table->decimal('locked_balance', 20, 8)->default(0)->after('balance');
            }
            if (!Schema::hasColumn('crypto_wallets', 'quidax_wallet_id')) {
                $table->string('quidax_wallet_id')->nullable()->after('locked_balance');
            }
        });

        // 2. Enhance crypto_addresses with Quidax metadata
        Schema::table('crypto_addresses', function (Blueprint $table) {
            if (!Schema::hasColumn('crypto_addresses', 'quidax_address_id')) {
                $table->string('quidax_address_id')->nullable()->after('memo');
            }
            if (!Schema::hasColumn('crypto_addresses', 'provider')) {
                $table->string('provider', 32)->default('quidax')->after('status');
            }
        });

        // 3. Enhance crypto_deposits with fees, confirmations & Quidax reference
        Schema::table('crypto_deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('crypto_deposits', 'quidax_deposit_id')) {
                $table->string('quidax_deposit_id')->nullable()->index()->after('tx_hash');
            }
            if (!Schema::hasColumn('crypto_deposits', 'confirmations')) {
                $table->unsignedInteger('confirmations')->default(0)->after('amount');
            }
            if (!Schema::hasColumn('crypto_deposits', 'platform_fee')) {
                $table->decimal('platform_fee', 20, 8)->default(0)->after('confirmations');
            }
            if (!Schema::hasColumn('crypto_deposits', 'net_amount')) {
                $table->decimal('net_amount', 20, 8)->nullable()->after('platform_fee');
            }
            if (!Schema::hasColumn('crypto_deposits', 'fee_config_snapshot')) {
                $table->json('fee_config_snapshot')->nullable()->after('rate');
            }
            if (!Schema::hasColumn('crypto_deposits', 'quidax_response')) {
                $table->json('quidax_response')->nullable()->after('fee_config_snapshot');
            }
        });

        // 4. Unified Crypto Transactions Ledger (Receive, Send, Convert to Naira, Buy Crypto)
        Schema::create('crypto_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('quidax_reference', 100)->nullable()->index();
            $table->string('type', 32)->index(); // receive_crypto, send_crypto, convert_to_naira, buy_crypto
            $table->string('symbol', 12)->index(); // USDT, BTC, ETH, SOL, BNB
            $table->string('network', 24)->nullable()->index(); // TRC20, BEP20, ERC20, BTC, SOL, INTERNAL
            $table->string('destination_type', 24)->nullable(); // external_address, gutter_user, wallet
            $table->string('wallet_address')->nullable()->index();
            $table->string('memo', 120)->nullable();
            $table->string('tx_hash')->nullable()->index();

            // Decimal-safe financial columns
            $table->decimal('crypto_amount', 20, 8)->default(0);
            $table->decimal('fiat_amount', 16, 2)->default(0);
            $table->decimal('exchange_rate', 16, 2)->default(0);
            $table->decimal('network_fee', 20, 8)->default(0);
            $table->decimal('platform_fee_crypto', 20, 8)->default(0);
            $table->decimal('platform_fee_ngn', 16, 2)->default(0);
            $table->decimal('total_crypto_deducted', 20, 8)->default(0);
            $table->decimal('net_crypto_amount', 20, 8)->default(0);
            $table->decimal('net_fiat_amount', 16, 2)->default(0);

            // Statuses: pending, processing, successful, failed, rejected, refunded, refund_processing
            $table->string('status', 32)->default('pending')->index();
            $table->string('failure_reason')->nullable();

            // Snapshots & Audit
            $table->json('fee_config_snapshot')->nullable();
            $table->json('quidax_payload')->nullable();
            $table->json('quidax_response')->nullable();

            // Refund & Admin Action Tracking
            $table->boolean('is_refunded')->default(false)->index();
            $table->decimal('refunded_amount', 20, 8)->nullable();
            $table->string('refunded_currency', 12)->nullable();
            $table->string('refund_reference', 64)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        // 5. Crypto Exchange Orders (Convert Crypto to Naira & Buy Crypto with Naira via Quidax Swap/Instant Orders)
        Schema::create('crypto_exchange_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('crypto_transaction_id')->nullable()->constrained('crypto_transactions')->nullOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('quidax_quotation_id', 100)->nullable()->index();
            $table->string('quidax_swap_id', 100)->nullable()->index();
            $table->string('side', 16); // buy, sell
            $table->string('from_currency', 12);
            $table->string('to_currency', 12);
            $table->decimal('from_amount', 20, 8);
            $table->decimal('to_amount', 20, 8);
            $table->decimal('quoted_rate', 16, 2);
            $table->decimal('executed_rate', 16, 2)->nullable();
            $table->decimal('platform_fee_ngn', 16, 2)->default(0);
            $table->decimal('platform_fee_crypto', 20, 8)->default(0);
            $table->decimal('net_amount_received', 20, 8)->default(0);
            $table->json('fee_config_snapshot')->nullable();
            $table->string('status', 32)->default('pending')->index(); // quoted, pending, processing, completed, failed, expired, refunded
            $table->json('quidax_response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'side', 'status']);
        });

        // 6. Crypto Withdrawals (Send Crypto to External Blockchain Addresses or Gutter P2P)
        Schema::create('crypto_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('crypto_transaction_id')->nullable()->constrained('crypto_transactions')->nullOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('quidax_withdrawal_id', 100)->nullable()->index();
            $table->string('symbol', 12)->index();
            $table->string('network', 24)->index();
            $table->string('destination_type', 24)->default('external_address'); // external_address, gutter_user
            $table->string('recipient_address');
            $table->string('memo', 120)->nullable();
            $table->decimal('amount', 20, 8); // Recipient amount
            $table->decimal('network_fee', 20, 8)->default(0); // Quidax network withdrawal fee
            $table->decimal('platform_fee', 20, 8)->default(0); // Gutter configurable platform withdrawal fee (in crypto)
            $table->decimal('platform_fee_ngn', 16, 2)->default(0);
            $table->decimal('total_amount', 20, 8); // Total debited from user's wallet (amount + network_fee + platform_fee)
            $table->decimal('exchange_rate', 16, 2)->default(0);
            $table->string('tx_hash')->nullable()->index();
            // Pending, Processing, Successful, Failed, Rejected, Refunded
            $table->string('status', 32)->default('pending')->index();
            $table->boolean('requires_admin_approval')->default(false);
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('fee_config_snapshot')->nullable();
            $table->json('quidax_response')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // 7. Crypto Fee Settings & Asset/Network Controls
        Schema::create('crypto_fee_settings', function (Blueprint $table) {
            $table->id();
            $table->string('service_type', 32)->unique(); // receive_crypto, send_crypto, convert_to_naira, buy_crypto
            $table->string('label', 100);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('maintenance_mode')->default(false);
            $table->string('maintenance_message')->nullable();
            $table->decimal('percentage_fee', 8, 4)->default(0.5000); // e.g., 0.50%
            $table->decimal('fixed_fee_ngn', 16, 2)->default(0.00);
            $table->decimal('fixed_fee_crypto', 20, 8)->default(0.00000000);
            $table->decimal('min_amount', 20, 8)->default(1.00000000);
            $table->decimal('max_amount', 20, 8)->default(100000000.00000000);
            $table->decimal('min_withdrawal_amount', 20, 8)->nullable();
            $table->decimal('max_withdrawal_amount', 20, 8)->nullable();
            $table->decimal('manual_approval_threshold', 20, 8)->nullable();
            $table->json('supported_coins')->nullable(); // Per-coin enable/disable, min/max, network configs
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        // 8. Crypto Refund Records
        Schema::create('crypto_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_reference', 64)->unique();
            $table->foreignId('crypto_transaction_id')->constrained('crypto_transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('admin_name');
            $table->string('asset_type', 16); // CRYPTO, NGN
            $table->string('currency', 12); // USDT, BTC, ETH, SOL, BNB, NGN
            $table->decimal('original_amount', 20, 8);
            $table->decimal('refunded_amount', 20, 8);
            $table->decimal('balance_before', 20, 8);
            $table->decimal('balance_after', 20, 8);
            $table->string('status', 32)->default('successful'); // pending, refund_processing, successful, failed
            $table->text('reason');
            $table->text('verification_notes')->nullable();
            $table->json('original_transaction_snapshot')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['crypto_transaction_id', 'status']);
        });

        // 9. Webhook Event Logs (Replay protection, signature verification & auditing)
        Schema::create('crypto_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('quidax');
            $table->string('event_id', 120)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('reference', 120)->nullable()->index();
            $table->string('signature')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('payload_hash', 64)->unique(); // SHA-256 hash of event+id+status for strict idempotency
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->string('processing_status', 32)->default('received')->index(); // received, processed, duplicate, ignored, failed
            $table->text('processing_notes')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // 10. Crypto Transaction Audit Logs
        Schema::create('crypto_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crypto_transaction_id')->nullable()->constrained('crypto_transactions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24)->default('system'); // user, admin, webhook, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 64)->index(); // created, status_changed, webhook_verified, approved, rejected, refunded, fee_updated, reconciled
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['crypto_transaction_id', 'created_at']);
        });

        // Seed default Crypto Fee Settings & Supported Coins/Networks
        $defaultSupportedCoins = [
            'USDT' => [
                'symbol' => 'USDT',
                'name' => 'Tether USD',
                'enabled' => true,
                'min_deposit' => 1.0,
                'min_withdrawal' => 2.0,
                'max_withdrawal' => 100000.0,
                'min_convert' => 1.0,
                'max_convert' => 100000.0,
                'networks' => [
                    'TRC20' => ['network' => 'TRC20', 'name' => 'Tron (TRC-20)', 'enabled' => true, 'network_fee' => 1.00, 'confirmations' => 1, 'min_deposit' => 1.0, 'fee_desc' => 'Low network fee · ~1-3 mins'],
                    'BEP20' => ['network' => 'BEP20', 'name' => 'BNB Smart Chain (BEP-20)', 'enabled' => true, 'network_fee' => 0.50, 'confirmations' => 15, 'min_deposit' => 1.0, 'fee_desc' => 'Very low fee · ~1 min'],
                    'ERC20' => ['network' => 'ERC20', 'name' => 'Ethereum (ERC-20)', 'enabled' => true, 'network_fee' => 4.00, 'confirmations' => 12, 'min_deposit' => 10.0, 'fee_desc' => 'Standard fee · ~3-5 mins'],
                ],
            ],
            'BTC' => [
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'enabled' => true,
                'min_deposit' => 0.0001,
                'min_withdrawal' => 0.0005,
                'max_withdrawal' => 10.0,
                'min_convert' => 0.0001,
                'max_convert' => 10.0,
                'networks' => [
                    'BTC' => ['network' => 'BTC', 'name' => 'Bitcoin Native', 'enabled' => true, 'network_fee' => 0.0002, 'confirmations' => 2, 'min_deposit' => 0.0001, 'fee_desc' => 'Standard network fee · ~10-30 mins'],
                ],
            ],
            'ETH' => [
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'enabled' => true,
                'min_deposit' => 0.005,
                'min_withdrawal' => 0.01,
                'max_withdrawal' => 100.0,
                'min_convert' => 0.001,
                'max_convert' => 100.0,
                'networks' => [
                    'ERC20' => ['network' => 'ERC20', 'name' => 'Ethereum (ERC-20)', 'enabled' => true, 'network_fee' => 0.0015, 'confirmations' => 12, 'min_deposit' => 0.005, 'fee_desc' => 'Standard network fee · ~3 mins'],
                ],
            ],
            'SOL' => [
                'symbol' => 'SOL',
                'name' => 'Solana',
                'enabled' => true,
                'min_deposit' => 0.05,
                'min_withdrawal' => 0.1,
                'max_withdrawal' => 1000.0,
                'min_convert' => 0.01,
                'max_convert' => 1000.0,
                'networks' => [
                    'SOL' => ['network' => 'SOL', 'name' => 'Solana Mainnet', 'enabled' => true, 'network_fee' => 0.005, 'confirmations' => 1, 'min_deposit' => 0.05, 'fee_desc' => 'Ultra-fast · ~30 secs'],
                ],
            ],
            'BNB' => [
                'symbol' => 'BNB',
                'name' => 'BNB Chain',
                'enabled' => true,
                'min_deposit' => 0.01,
                'min_withdrawal' => 0.02,
                'max_withdrawal' => 500.0,
                'min_convert' => 0.01,
                'max_convert' => 500.0,
                'networks' => [
                    'BEP20' => ['network' => 'BEP20', 'name' => 'BNB Smart Chain (BEP-20)', 'enabled' => true, 'network_fee' => 0.001, 'confirmations' => 15, 'min_deposit' => 0.01, 'fee_desc' => 'Fast · ~1 min'],
                ],
            ],
        ];

        DB::table('crypto_fee_settings')->insert([
            [
                'service_type' => 'receive_crypto',
                'label' => 'Receive Crypto (Deposits)',
                'description' => 'Incoming blockchain deposits via Quidax dedicated payment addresses.',
                'is_enabled' => true,
                'maintenance_mode' => false,
                'maintenance_message' => null,
                'percentage_fee' => 0.0000,
                'fixed_fee_ngn' => 0.00,
                'fixed_fee_crypto' => 0.00000000,
                'min_amount' => 0.00010000,
                'max_amount' => 1000000.00000000,
                'min_withdrawal_amount' => null,
                'max_withdrawal_amount' => null,
                'manual_approval_threshold' => null,
                'supported_coins' => json_encode($defaultSupportedCoins),
                'updated_by' => 'System Default',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_type' => 'send_crypto',
                'label' => 'Send Crypto (Withdrawals)',
                'description' => 'Outgoing blockchain withdrawals via Quidax & internal Gutter P2P transfers.',
                'is_enabled' => true,
                'maintenance_mode' => false,
                'maintenance_message' => null,
                'percentage_fee' => 0.5000,
                'fixed_fee_ngn' => 100.00,
                'fixed_fee_crypto' => 0.00000000,
                'min_amount' => 0.00010000,
                'max_amount' => 100000.00000000,
                'min_withdrawal_amount' => 0.00010000,
                'max_withdrawal_amount' => 100000.00000000,
                'manual_approval_threshold' => 50000.00000000,
                'supported_coins' => json_encode($defaultSupportedCoins),
                'updated_by' => 'System Default',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_type' => 'convert_to_naira',
                'label' => 'Convert Crypto to Naira',
                'description' => 'Instant conversion of crypto balance into Nigerian Naira wallet via Quidax.',
                'is_enabled' => true,
                'maintenance_mode' => false,
                'maintenance_message' => null,
                'percentage_fee' => 0.5000,
                'fixed_fee_ngn' => 0.00,
                'fixed_fee_crypto' => 0.00000000,
                'min_amount' => 0.00000100,
                'max_amount' => 1000000.00000000,
                'min_withdrawal_amount' => null,
                'max_withdrawal_amount' => null,
                'manual_approval_threshold' => null,
                'supported_coins' => json_encode($defaultSupportedCoins),
                'updated_by' => 'System Default',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_type' => 'buy_crypto',
                'label' => 'Buy Crypto with Naira',
                'description' => 'Instant purchase of cryptocurrency using Naira wallet balance via Quidax.',
                'is_enabled' => true,
                'maintenance_mode' => false,
                'maintenance_message' => null,
                'percentage_fee' => 0.5000,
                'fixed_fee_ngn' => 0.00,
                'fixed_fee_crypto' => 0.00000000,
                'min_amount' => 1000.00000000, // Min ₦1,000 NGN
                'max_amount' => 50000000.00000000, // Max ₦50,000,000 NGN
                'min_withdrawal_amount' => null,
                'max_withdrawal_amount' => null,
                'manual_approval_threshold' => null,
                'supported_coins' => json_encode($defaultSupportedCoins),
                'updated_by' => 'System Default',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_audit_logs');
        Schema::dropIfExists('crypto_webhook_logs');
        Schema::dropIfExists('crypto_refunds');
        Schema::dropIfExists('crypto_fee_settings');
        Schema::dropIfExists('crypto_withdrawals');
        Schema::dropIfExists('crypto_exchange_orders');
        Schema::dropIfExists('crypto_transactions');

        Schema::table('crypto_deposits', function (Blueprint $table) {
            $cols = array_filter(
                ['quidax_deposit_id', 'confirmations', 'platform_fee', 'net_amount', 'fee_config_snapshot', 'quidax_response'],
                fn ($c) => Schema::hasColumn('crypto_deposits', $c)
            );
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('crypto_addresses', function (Blueprint $table) {
            $cols = array_filter(
                ['quidax_address_id', 'provider'],
                fn ($c) => Schema::hasColumn('crypto_addresses', $c)
            );
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('crypto_wallets', function (Blueprint $table) {
            $cols = array_filter(
                ['locked_balance', 'quidax_wallet_id'],
                fn ($c) => Schema::hasColumn('crypto_wallets', $c)
            );
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

