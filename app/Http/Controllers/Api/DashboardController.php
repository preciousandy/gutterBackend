<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get user dashboard data (wallet, asset balances, recent transactions).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $phoneDigits = preg_replace('/\D/', '', $user->phone ?? '');
        $phoneSuffix = strlen($phoneDigits) >= 10 ? substr($phoneDigits, -10) : '';

        // Ensure user has an authentic PalmPay NUBAN (not their phone number)
        $needsVirtualAccount = empty($user->account_number)
            || strlen($user->account_number) !== 10
            || $user->account_number === $phoneSuffix
            || $user->bank_name !== 'PalmPay';

        if ($needsVirtualAccount) {
            try {
                app(\App\Services\PalmPayService::class)->createVirtualAccount($user);
                $user->refresh();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Dashboard virtual account generation error: ' . $e->getMessage());
            }
        }

        $accountNumber = $user->account_number;
        $bankName = $user->bank_name ?: 'PalmPay';
        $balance = (float) ($user->wallet_balance ?? 0.00);
        $recentTransactions = $user->transactions()->latest()->take(5)->get();

        $cryptoWallets = $user->cryptoWallets()->pluck('balance', 'symbol');

        return response()->json([
            'data' => [
                'wallet' => [
                    'balance' => $balance,
                    'currency' => 'NGN',
                    'bank_name' => $bankName,
                    'account_number' => $accountNumber,
                ],
                'balances' => [
                    [
                        'asset' => 'NGN',
                        'amount' => $balance,
                    ],
                    [
                        'asset' => 'USDT',
                        'amount' => (float) ($cryptoWallets['USDT'] ?? 0.00),
                    ],
                    [
                        'asset' => 'BTC',
                        'amount' => (float) ($cryptoWallets['BTC'] ?? 0.0000),
                    ],
                    [
                        'asset' => 'ETH',
                        'amount' => (float) ($cryptoWallets['ETH'] ?? 0.0000),
                    ],
                    [
                        'asset' => 'SOL',
                        'amount' => (float) ($cryptoWallets['SOL'] ?? 0.0000),
                    ],
                    [
                        'asset' => 'BNB',
                        'amount' => (float) ($cryptoWallets['BNB'] ?? 0.0000),
                    ],
                ],
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }
}
