<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Admin Users Table
        if (!Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->enum('role', [
                    'super_admin',
                    'compliance_officer',
                    'support_lead',
                    'financial_auditor'
                ])->default('super_admin');
                $table->enum('status', ['active', 'suspended'])->default('active');
                $table->timestamp('last_login_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });

            // Seed default admin accounts for each role
            DB::table('admin_users')->insert([
                [
                    'name' => 'Gutter Super Admin',
                    'email' => 'admin@gutter.com',
                    'password' => Hash::make('Admin@123456'),
                    'role' => 'super_admin',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Sarah Compliance',
                    'email' => 'compliance@gutter.com',
                    'password' => Hash::make('Compliance@123456'),
                    'role' => 'compliance_officer',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'David Support',
                    'email' => 'support@gutter.com',
                    'password' => Hash::make('Support@123456'),
                    'role' => 'support_lead',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Alex Auditor',
                    'email' => 'auditor@gutter.com',
                    'password' => Hash::make('Auditor@123456'),
                    'role' => 'financial_auditor',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        // 2. App Controls & Feature Switches Table
        if (!Schema::hasTable('app_controls')) {
            Schema::create('app_controls', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('label');
                $table->text('description')->nullable();
                $table->string('category')->default('features'); // 'features', 'kyc', 'buttons', 'system'
                $table->boolean('is_enabled')->default(true);
                $table->json('metadata')->nullable();
                $table->string('updated_by')->nullable();
                $table->timestamps();
            });

            // Seed initial control switches
            $defaults = [
                // Core Mobile Features / Buttons
                ['key' => 'feature_airtime', 'label' => 'Airtime Recharge', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Show and allow Airtime purchases on mobile app.'],
                ['key' => 'feature_data', 'label' => 'Data Bundles', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Show and allow Data bundle subscriptions on mobile app.'],
                ['key' => 'feature_cable_tv', 'label' => 'Cable TV (DSTV / GOTV)', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Show and allow Cable TV subscriptions.'],
                ['key' => 'feature_electricity', 'label' => 'Electricity Bills', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Show and allow Electricity meter token payments.'],
                ['key' => 'feature_bank_transfer', 'label' => 'Bank Transfers', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Allow sending Naira to external bank accounts.'],
                ['key' => 'feature_crypto_deposit', 'label' => 'Crypto Deposits', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Generate and display crypto wallet addresses (BTC, USDT, etc.).'],
                ['key' => 'feature_crypto_swap', 'label' => 'Crypto Buy / Sell / Swap', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Enable instant crypto trading to Naira.'],
                ['key' => 'feature_virtual_cards', 'label' => 'Virtual USD Cards', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Allow users to create and fund virtual cards.'],
                ['key' => 'feature_peer_transfer', 'label' => 'Gutter-to-Gutter Tag Transfer', 'category' => 'buttons', 'is_enabled' => true, 'description' => 'Send money instantly via Gutter @username.'],

                // KYC Gateways & Controls
                ['key' => 'kyc_require_for_transfers', 'label' => 'Mandatory KYC for Bank Transfers', 'category' => 'kyc', 'is_enabled' => false, 'description' => 'Require BVN/NIN verification before sending money.'],
                ['key' => 'kyc_require_for_bills', 'label' => 'Mandatory KYC for Utility Bills', 'category' => 'kyc', 'is_enabled' => false, 'description' => 'Require verified KYC before buying airtime or data.'],
                ['key' => 'kyc_require_for_crypto', 'label' => 'Mandatory KYC for Crypto Trades', 'category' => 'kyc', 'is_enabled' => true, 'description' => 'Require Tier 2 KYC before depositing or trading crypto.'],
                ['key' => 'kyc_strict_name_match', 'label' => 'Strict Name-Match on Bank Accounts', 'category' => 'kyc', 'is_enabled' => true, 'description' => 'Account name on recipient bank must match KYC full name.'],

                // System & Global App State
                ['key' => 'system_maintenance_mode', 'label' => 'Mobile App Maintenance Mode', 'category' => 'system', 'is_enabled' => false, 'description' => 'Block all client operations and show maintenance banner.'],
                ['key' => 'system_announcement_active', 'label' => 'Global Announcement Banner', 'category' => 'system', 'is_enabled' => false, 'description' => 'Display custom banner across user dashboard.'],
            ];

            foreach ($defaults as $item) {
                DB::table('app_controls')->insert(array_merge($item, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // 3. Complaints & Dispute Tickets Table
        if (!Schema::hasTable('complaints')) {
            Schema::create('complaints', function (Blueprint $table) {
                $table->id();
                $table->string('ticket_id')->unique();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->string('category', 50);
                $table->string('reference', 100)->nullable();
                $table->text('message');
                $table->enum('status', ['open', 'in_progress', 'resolved', 'rejected'])->default('open');
                $table->text('admin_notes')->nullable();
                $table->string('assigned_to')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('app_controls');
        Schema::dropIfExists('admin_users');
    }
};
