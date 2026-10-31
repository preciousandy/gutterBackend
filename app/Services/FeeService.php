<?php

namespace App\Services;

use App\Models\AppControl;

class FeeService
{
    /**
     * Master registry of all services that have admin fee & operational controls.
     */
    public const SERVICES = [
        'bank_transfer' => [
            'key' => 'fee_bank_transfer',
            'service_key' => 'bank_transfer',
            'label' => 'Bank Transfer',
            'category' => 'transfers',
            'category_label' => 'Bank Transfers',
            'icon' => '🏦',
            'default_flat' => 10.00,
            'default_percent' => 0.00,
            'description' => 'Outward transfers to Nigerian commercial banks & fintech accounts.',
        ],
        'gutter_to_gutter' => [
            'key' => 'fee_gutter_to_gutter',
            'service_key' => 'gutter_to_gutter',
            'label' => 'Gutter to Gutter',
            'category' => 'transfers',
            'category_label' => 'Bank Transfers',
            'icon' => '🤝',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Instant peer-to-peer transfers between Gutter usernames, emails & phone numbers.',
        ],
        'card_creation' => [
            'key' => 'fee_card_creation',
            'service_key' => 'card_creation',
            'label' => 'Cards Creation',
            'category' => 'cards',
            'category_label' => 'Virtual Cards',
            'icon' => '💳',
            'default_flat' => 1500.00,
            'default_percent' => 0.00,
            'description' => 'One-time issuance fee when a customer generates a new Virtual USD card.',
        ],
        'send_crypto' => [
            'key' => 'fee_send_crypto',
            'service_key' => 'send_crypto',
            'label' => 'Send Crypto',
            'category' => 'crypto',
            'category_label' => 'Crypto Platform',
            'icon' => '🚀',
            'default_flat' => 100.00,
            'default_percent' => 0.50,
            'description' => 'Platform fee on blockchain withdrawals & external crypto transfers.',
        ],
        'convert_crypto' => [
            'key' => 'fee_convert_crypto',
            'service_key' => 'convert_crypto',
            'label' => 'Convert Crypto to Naira',
            'category' => 'crypto',
            'category_label' => 'Crypto Platform',
            'icon' => '🔄',
            'default_flat' => 0.00,
            'default_percent' => 0.50,
            'description' => 'Exchange processing charge when converting/selling crypto into Naira wallet.',
        ],
        'buy_crypto' => [
            'key' => 'fee_buy_crypto',
            'service_key' => 'buy_crypto',
            'label' => 'Buy Crypto',
            'category' => 'crypto',
            'category_label' => 'Crypto Platform',
            'icon' => '⚡',
            'default_flat' => 0.00,
            'default_percent' => 0.50,
            'description' => 'Processing fee applied when purchasing crypto using Naira wallet balance.',
        ],
        'airtime' => [
            'key' => 'fee_airtime',
            'service_key' => 'airtime',
            'label' => 'Airtime Recharge',
            'category' => 'bills',
            'category_label' => 'Telecom & Bills',
            'icon' => '📞',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Surcharge or processing fee applied on mobile airtime top-ups.',
        ],
        'data' => [
            'key' => 'fee_data',
            'service_key' => 'data',
            'label' => 'Data Bundles',
            'category' => 'bills',
            'category_label' => 'Telecom & Bills',
            'icon' => '📶',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Surcharge or processing fee applied on mobile data bundle subscriptions.',
        ],
        'electricity' => [
            'key' => 'fee_electricity',
            'service_key' => 'electricity',
            'label' => 'Electricity Bills',
            'category' => 'bills',
            'category_label' => 'Telecom & Bills',
            'icon' => '⚡',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Surcharge or convenience charge applied on DISCO meter token purchases.',
        ],
        'betting' => [
            'key' => 'fee_betting',
            'service_key' => 'betting',
            'label' => 'Betting Wallet Top-up',
            'category' => 'bills',
            'category_label' => 'Telecom & Bills',
            'icon' => '⚽',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Surcharge or processing fee applied on bookmaker wallet funding (SportyBet, Bet9ja, etc).',
        ],
        'cable' => [
            'key' => 'fee_cable',
            'service_key' => 'cable',
            'label' => 'Cable TV Subscriptions',
            'category' => 'bills',
            'category_label' => 'Telecom & Bills',
            'icon' => '📺',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Surcharge or convenience charge applied on Cable TV renewals (DStv, GOtv, StarTimes, Showmax).',
        ],
    ];

    /**
     * Get setting array for a specific service.
     *
     * @param string $serviceKey (e.g. 'bank_transfer', 'gutter_to_gutter', etc.)
     */
    public static function getSetting(string $serviceKey): array
    {
        $cleanKey = str_starts_with($serviceKey, 'fee_') ? substr($serviceKey, 4) : $serviceKey;
        $meta = self::SERVICES[$cleanKey] ?? [
            'key' => 'fee_' . $cleanKey,
            'service_key' => $cleanKey,
            'label' => ucwords(str_replace('_', ' ', $cleanKey)),
            'category' => 'fees',
            'category_label' => 'Fees & Charges',
            'icon' => '⚙️',
            'default_flat' => 0.00,
            'default_percent' => 0.00,
            'description' => 'Service fee configuration',
        ];

        $control = AppControl::where('key', 'fee_' . $cleanKey)
            ->orWhere('key', $serviceKey)
            ->first();

        if (!$control) {
            return [
                'key' => 'fee_' . $cleanKey,
                'service_key' => $cleanKey,
                'label' => $meta['label'],
                'category' => $meta['category'],
                'category_label' => $meta['category_label'],
                'icon' => $meta['icon'],
                'is_enabled' => true,
                'flat_fee' => (float) $meta['default_flat'],
                'percent_fee' => (float) $meta['default_percent'],
                'description' => $meta['description'],
                'updated_by' => 'System Default',
                'updated_at' => null,
            ];
        }

        $ctrlMeta = is_array($control->metadata) ? $control->metadata : [];

        return [
            'key' => $control->key,
            'service_key' => $cleanKey,
            'label' => $control->label ?: $meta['label'],
            'category' => $meta['category'],
            'category_label' => $meta['category_label'],
            'icon' => $meta['icon'],
            'is_enabled' => (bool) $control->is_enabled,
            'flat_fee' => (float) ($ctrlMeta['flat_fee'] ?? $meta['default_flat']),
            'percent_fee' => (float) ($ctrlMeta['percent_fee'] ?? $meta['default_percent']),
            'description' => $control->description ?: $meta['description'],
            'updated_by' => $control->updated_by ?? 'System Default',
            'updated_at' => $control->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Get all 8 service settings structured for admin portal and API feeds.
     */
    public static function getAllSettings(): array
    {
        $results = [];
        foreach (self::SERVICES as $serviceKey => $def) {
            $results[$serviceKey] = self::getSetting($serviceKey);
        }

        return $results;
    }

    /**
     * Calculate fee amount for a given transaction size.
     * Formula: Flat Fee + (Amount * (Percentage Fee / 100))
     *
     * @param string $serviceKey
     * @param float $amount
     * @return float
     */
    public static function calculateFee(string $serviceKey, float $amount): float
    {
        $setting = self::getSetting($serviceKey);

        $flat = max(0, (float) $setting['flat_fee']);
        $percent = max(0, (float) $setting['percent_fee']);

        $calculated = $flat + ($amount * ($percent / 100.0));
        return round(max(0, $calculated), 2);
    }

    /**
     * Check if a specific service is enabled and operational.
     */
    public static function isEnabled(string $serviceKey): bool
    {
        $setting = self::getSetting($serviceKey);
        return (bool) $setting['is_enabled'];
    }

    /**
     * Update fee and control setting for a service.
     */
    public static function updateSetting(
        string $serviceKey,
        float $flatFee,
        float $percentFee,
        ?bool $isEnabled = null,
        ?string $updatedBy = null
    ): AppControl {
        $cleanKey = str_starts_with($serviceKey, 'fee_') ? substr($serviceKey, 4) : $serviceKey;
        $dbKey = 'fee_' . $cleanKey;

        $def = self::SERVICES[$cleanKey] ?? [
            'label' => ucwords(str_replace('_', ' ', $cleanKey)),
            'description' => 'Service fee configuration',
        ];

        $control = AppControl::firstOrNew(['key' => $dbKey]);
        $control->label = $def['label'] ?? $control->label;
        $control->category = 'fees';
        $control->description = $def['description'] ?? $control->description;
        
        if ($isEnabled !== null) {
            $control->is_enabled = $isEnabled;
        } elseif (!$control->exists) {
            $control->is_enabled = true;
        }

        $control->metadata = [
            'service_key' => $cleanKey,
            'flat_fee' => max(0, round($flatFee, 2)),
            'percent_fee' => max(0, min(100, round($percentFee, 4))),
            'currency' => 'NGN',
        ];

        if (!empty($updatedBy)) {
            $control->updated_by = $updatedBy;
        }

        $control->save();

        return $control;
    }
}
