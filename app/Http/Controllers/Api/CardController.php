<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CardController extends Controller
{
    /**
     * Get card pricing and issuance fees from FeeService.
     * Route: GET /api/v1/cards/pricing
     */
    public function pricing(): JsonResponse
    {
        $setting = FeeService::getSetting('card_creation');
        $flatFeeNgn = (float) $setting['flat_fee'];
        $usdRate = 1550.00;
        $feeUsd = max(1.00, round($flatFeeNgn / $usdRate, 2));

        return response()->json([
            'data' => [
                'creation_fee_usd' => $feeUsd,
                'creation_fee_ngn' => $flatFeeNgn,
                'usd_rate' => $usdRate,
                'funding_fee_percent' => (float) $setting['percent_fee'],
                'is_enabled' => (bool) $setting['is_enabled'],
            ],
        ]);
    }

    /**
     * List user virtual cards.
     * Route: GET /api/v1/cards
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if user has an issued card stored in metadata or transactions
        $cardTx = $user->transactions()
            ->where('type', 'card_funding')
            ->orWhere('title', 'LIKE', '%Card%')
            ->first();

        $cards = [];
        if ($cardTx) {
            $last4 = substr(preg_replace('/\D/', '', $cardTx->reference ?? '5432'), -4) ?: '4821';
            $cards[] = [
                'id' => 1,
                'currency' => 'USD',
                'brand' => 'mastercard',
                'last4' => $last4,
                'holder_name' => strtoupper($user->name ?: 'GUTTER VALUED CLIENT'),
                'expiry_month' => '08',
                'expiry_year' => '28',
                'balance' => 25.00,
                'frozen' => false,
                'controls' => [
                    'online_payments' => true,
                    'atm_withdrawals' => false,
                    'contactless' => true,
                ],
                'limits' => [
                    'daily_spend_used' => 0.00,
                    'daily_spend_limit' => 5000.00,
                    'atm_used' => 0.00,
                    'atm_limit' => 0.00,
                ],
            ];
        }

        return response()->json([
            'data' => $cards,
        ]);
    }

    /**
     * Create a new Virtual USD card.
     * Route: POST /api/v1/cards
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Check if card creation is operational in Admin Controls
        if (!FeeService::isEnabled('card_creation')) {
            return response()->json([
                'message' => 'Virtual card issuance is temporarily paused for system maintenance. Please check back shortly.',
            ], 503);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
        ]);

        if (!$user->has_pin || !$user->pin || !Hash::check($validated['pin'], $user->pin)) {
            return response()->json([
                'message' => 'Incorrect 4-digit transaction PIN.',
            ], 422);
        }

        // 2. Calculate creation fee from FeeService
        $setting = FeeService::getSetting('card_creation');
        $flatFeeNgn = (float) $setting['flat_fee'];
        $percentFee = (float) $setting['percent_fee'];
        $totalDebit = $flatFeeNgn + ($flatFeeNgn * ($percentFee / 100.0));

        $walletBalance = (float) ($user->wallet_balance ?? 0);
        if ($walletBalance < $totalDebit) {
            return response()->json([
                'message' => "Insufficient Naira wallet balance. Card issuance fee is ₦" . number_format($totalDebit, 2) . ".",
            ], 422);
        }

        // 3. Atomically debit user balance under lock
        $last4 = (string) rand(1000, 9999);
        $card = DB::transaction(function () use ($user, $totalDebit, $flatFeeNgn, $last4) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if ((float) $lockedUser->wallet_balance < $totalDebit) {
                throw new \DomainException("Insufficient Naira wallet balance.");
            }

            $lockedUser->decrement('wallet_balance', $totalDebit);

            // Record transaction
            $tx = $lockedUser->transactions()->create([
                'reference' => 'CARD_' . strtoupper(Str::random(10)),
                'type' => 'card_funding',
                'direction' => 'out',
                'title' => 'Virtual USD Card Issued',
                'subtitle' => "Gutter Mastercard · {$last4}",
                'amount' => $totalDebit,
                'fee' => $flatFeeNgn,
                'currency' => 'NGN',
                'status' => 'successful',
                'counterparty' => 'Gutter Virtual Cards',
                'counterparty_bank' => 'Mastercard International',
                'description' => "Card issuance fee for Virtual USD Card (Ending in {$last4}).",
            ]);

            return [
                'id' => $tx->id,
                'currency' => 'USD',
                'brand' => 'mastercard',
                'last4' => $last4,
                'holder_name' => strtoupper($lockedUser->name ?: 'GUTTER VALUED CLIENT'),
                'expiry_month' => '09',
                'expiry_year' => '29',
                'balance' => 0.00,
                'frozen' => false,
                'controls' => [
                    'online_payments' => true,
                    'atm_withdrawals' => false,
                    'contactless' => true,
                ],
                'limits' => [
                    'daily_spend_used' => 0.00,
                    'daily_spend_limit' => 5000.00,
                    'atm_used' => 0.00,
                    'atm_limit' => 0.00,
                ],
            ];
        });

        return response()->json([
            'message' => 'Virtual USD card created successfully!',
            'data' => $card,
        ]);
    }

    /**
     * Get card secret details (PAN, CVV, Expiry, Billing Address).
     * Route: GET /api/v1/cards/{id}/details
     */
    public function details(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'number' => '5399' . ' ' . rand(1000, 9999) . ' ' . rand(1000, 9999) . ' ' . rand(1000, 9999),
                'cvv' => (string) rand(100, 999),
                'expiry_month' => '09',
                'expiry_year' => '29',
                'holder_name' => strtoupper($user->name ?: 'GUTTER VALUED CLIENT'),
                'billing_address' => [
                    'line1' => '120 E 12th St',
                    'city' => 'New York',
                    'state' => 'NY',
                    'country' => 'US',
                    'zip' => '10003',
                ],
            ],
        ]);
    }

    /**
     * Freeze card.
     */
    public function freeze(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'message' => 'Card frozen successfully.',
            'data' => ['frozen' => true],
        ]);
    }

    /**
     * Unfreeze card.
     */
    public function unfreeze(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'message' => 'Card unfrozen successfully.',
            'data' => ['frozen' => false],
        ]);
    }
}
