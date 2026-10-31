<?php

use App\Models\AppControl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $services = [
            [
                'key' => 'fee_bank_transfer',
                'label' => 'Bank Transfer',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Outward transfers to Nigerian commercial banks & fintechs (Access, PalmPay, OPay, GTBank, Zenith, etc.)',
                'metadata' => [
                    'service_key' => 'bank_transfer',
                    'flat_fee' => 10.00,
                    'percent_fee' => 0.00,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_gutter_to_gutter',
                'label' => 'Gutter to Gutter',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Instant peer-to-peer transfers between Gutter user handles, emails, and account numbers',
                'metadata' => [
                    'service_key' => 'gutter_to_gutter',
                    'flat_fee' => 0.00,
                    'percent_fee' => 0.00,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_card_creation',
                'label' => 'Cards Creation',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Issuance charge deducted when a user creates a new Virtual USD Mastercard / Visa',
                'metadata' => [
                    'service_key' => 'card_creation',
                    'flat_fee' => 1500.00,
                    'percent_fee' => 0.00,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_send_crypto',
                'label' => 'Send Crypto',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Platform withdrawal & routing charge when user sends crypto to an external wallet or user',
                'metadata' => [
                    'service_key' => 'send_crypto',
                    'flat_fee' => 100.00,
                    'percent_fee' => 0.50,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_convert_crypto',
                'label' => 'Convert Crypto to Naira',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Processing fee applied when user swaps / sells crypto holdings into Naira wallet',
                'metadata' => [
                    'service_key' => 'convert_crypto',
                    'flat_fee' => 0.00,
                    'percent_fee' => 0.50,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_buy_crypto',
                'label' => 'Buy Crypto',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Processing fee applied when user purchases crypto using Naira wallet balance',
                'metadata' => [
                    'service_key' => 'buy_crypto',
                    'flat_fee' => 0.00,
                    'percent_fee' => 0.50,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_airtime',
                'label' => 'Airtime Recharge',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Processing fee or surcharge applied on mobile airtime top-ups across all networks',
                'metadata' => [
                    'service_key' => 'airtime',
                    'flat_fee' => 0.00,
                    'percent_fee' => 0.00,
                    'currency' => 'NGN',
                ],
            ],
            [
                'key' => 'fee_data',
                'label' => 'Data Bundles',
                'category' => 'fees',
                'is_enabled' => true,
                'description' => 'Processing fee or surcharge applied on SME and direct mobile data bundle subscriptions',
                'metadata' => [
                    'service_key' => 'data',
                    'flat_fee' => 0.00,
                    'percent_fee' => 0.00,
                    'currency' => 'NGN',
                ],
            ],
        ];

        foreach ($services as $svc) {
            $existing = DB::table('app_controls')->where('key', $svc['key'])->first();
            if (!$existing) {
                DB::table('app_controls')->insert(array_merge($svc, [
                    'metadata' => json_encode($svc['metadata']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('app_controls')->where('category', 'fees')->delete();
    }
};
