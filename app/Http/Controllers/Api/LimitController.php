<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LimitController extends Controller
{
    /**
     * Tier limits configuration.
     */
    protected const TIERS = [
        1 => [
            'tier' => 1,
            'name' => 'Tier 1 (Basic)',
            'short_name' => 'Tier 1',
            'daily_limit' => 50000,
            'single_limit' => 20000,
            'max_balance' => 300000,
            'requirements' => 'Email & Phone Number verified',
            'benefits' => [
                'Send up to ₦20,000 per transaction',
                '₦50,000 daily transfer limit',
                'Hold up to ₦300,000 wallet balance',
            ],
        ],
        2 => [
            'tier' => 2,
            'name' => 'Tier 2 (Verified)',
            'short_name' => 'Tier 2',
            'daily_limit' => 5000000,
            'single_limit' => 1000000,
            'max_balance' => null, // Unlimited
            'requirements' => 'Valid ID (NIN / BVN / Passport) + Face Verification',
            'benefits' => [
                'Send up to ₦1,000,000 per transaction',
                '₦5,000,000 daily transfer limit',
                'Unlimited wallet balance holding',
                'Virtual & Physical cards access',
            ],
        ],
        3 => [
            'tier' => 3,
            'name' => 'Tier 3 (Enhanced)',
            'short_name' => 'Tier 3',
            'daily_limit' => 25000000,
            'single_limit' => 5000000,
            'max_balance' => null, // Unlimited
            'requirements' => 'Proof of Address (Utility bill) + Bank statement',
            'benefits' => [
                'Send up to ₦5,000,000 per transaction',
                '₦25,000,000 daily transfer limit',
                'Unlimited wallet balance holding',
                'Dedicated account manager & VIP support',
            ],
        ],
    ];

    /**
     * Get transaction limits, current tier status, and daily spent calculations.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userTier = (int) ($user->tier ?? 1);
        if ($userTier < 1 || $userTier > 3) {
            $userTier = 1;
        }

        $tierConfig = self::TIERS[$userTier] ?? self::TIERS[1];
        $dailyLimit = (float) $tierConfig['daily_limit'];
        $singleLimit = (float) $tierConfig['single_limit'];
        $maxBalance = $tierConfig['max_balance'];

        // Calculate today's total outgoing spend across transactions
        $dailySpent = 0.0;
        if (method_exists($user, 'transactions')) {
            $dailySpent = (float) $user->transactions()
                ->where('direction', 'out')
                ->whereIn('status', ['successful', 'completed', 'pending'])
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount');
        }

        $dailyRemaining = max(0.0, $dailyLimit - $dailySpent);
        $percentageUsed = $dailyLimit > 0 ? min(100, round(($dailySpent / $dailyLimit) * 100, 1)) : 0;

        $tierList = array_values(array_map(function ($tier) use ($userTier) {
            $status = 'locked';
            if ($tier['tier'] === $userTier) {
                $status = 'active';
            } elseif ($tier['tier'] < $userTier) {
                $status = 'completed';
            } elseif ($tier['tier'] === $userTier + 1) {
                $status = 'next';
            }

            return array_merge($tier, [
                'status' => $status,
                'is_current' => $tier['tier'] === $userTier,
            ]);
        }, self::TIERS));

        return response()->json([
            'data' => [
                'current_tier' => $userTier,
                'tier_name' => $tierConfig['name'],
                'short_name' => $tierConfig['short_name'],
                'daily_limit' => $dailyLimit,
                'daily_spent' => $dailySpent,
                'daily_remaining' => $dailyRemaining,
                'single_limit' => $singleLimit,
                'max_balance' => $maxBalance,
                'percentage_used' => $percentageUsed,
                'kyc_status' => (string) ($user->kyc_status ?? 'unverified'),
                'tiers' => $tierList,
            ],
        ]);
    }
}
