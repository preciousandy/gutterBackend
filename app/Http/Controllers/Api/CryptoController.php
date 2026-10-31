<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CryptoService;
use App\Services\FeeService;
use App\Services\QuidaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CryptoController extends Controller
{
    public function __construct(
        protected CryptoService $cryptoService,
        protected QuidaxService $quidaxService
    ) {}

    /**
     * Get market data and user's crypto portfolio summary.
     */
    public function market(Request $request): JsonResponse
    {
        $user = $request->user();
        $portfolio = $this->cryptoService->getUserPortfolio($user);

        return response()->json([
            'data' => [
                'coins' => $this->cryptoService->getMarketData(),
                'portfolio_value' => $portfolio['portfolio_value'],
                'portfolio_change' => $portfolio['portfolio_change'],
                'auto_convert_crypto' => $portfolio['auto_convert_crypto'],
            ],
        ]);
    }

    /**
     * Get user's crypto holdings and auto-convert setting.
     */
    public function balances(Request $request): JsonResponse
    {
        $user = $request->user();
        $portfolio = $this->cryptoService->getUserPortfolio($user);

        return response()->json([
            'data' => [
                'portfolio_value' => $portfolio['portfolio_value'],
                'auto_convert_crypto' => $portfolio['auto_convert_crypto'],
                'balances' => $portfolio['balances'],
            ],
        ]);
    }

    /**
     * Toggle or update auto-convert incoming crypto to Naira.
     */
    public function toggleAutoConvert(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
        ]);

        $nextState = isset($validated['enabled'])
            ? (bool) $validated['enabled']
            : !(bool) $user->auto_convert_crypto;

        $user->update(['auto_convert_crypto' => $nextState]);

        return response()->json([
            'message' => $nextState
                ? 'Auto-convert to Naira is now enabled for incoming deposits.'
                : 'Auto-convert disabled. Incoming deposits will be held in crypto.',
            'auto_convert_crypto' => $nextState,
        ]);
    }

    /**
     * Get deposit address and supported networks for an asset.
     */
    public function getAddresses(Request $request): JsonResponse
    {
        $user = $request->user();
        $symbol = strtoupper($request->query('symbol', 'USDT'));
        $networks = $this->cryptoService->getSupportedNetworks($symbol);

        $addresses = [];
        foreach ($networks as $net) {
            $addr = $this->cryptoService->getOrCreateAddress($user, $symbol, $net['network']);
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($addr->address);

            $addresses[] = [
                'symbol' => $symbol,
                'network' => $net['network'],
                'network_name' => $net['name'],
                'address' => $addr->address,
                'memo' => $addr->memo,
                'fee_desc' => $net['fee_desc'],
                'confirmations' => $net['confirmations'],
                'min_deposit' => $net['min_deposit'],
                'qr_code_url' => $qrUrl,
            ];
        }

        return response()->json([
            'data' => [
                'symbol' => $symbol,
                'auto_convert_crypto' => (bool) $user->auto_convert_crypto,
                'addresses' => $addresses,
            ],
        ]);
    }

    /**
     * Get instant live conversion quote.
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'side' => 'required|in:buy,sell',
            'symbol' => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
        ]);

        $side = $validated['side'];
        $symbol = strtoupper($validated['symbol']);
        $amount = (float) $validated['amount'];
        $rate = $this->cryptoService->getRate($symbol);

        if ($side === 'buy') {
            if (!FeeService::isEnabled('buy_crypto')) {
                return response()->json(['message' => 'Crypto purchases are temporarily paused for system maintenance.'], 503);
            }
            // User pays $amount in NGN
            $fee = FeeService::calculateFee('buy_crypto', $amount);
            $cryptoAmount = round(max(0, ($amount - $fee)) / $rate, 8);
            $fiatAmount = $amount;
        } else {
            if (!FeeService::isEnabled('convert_crypto')) {
                return response()->json(['message' => 'Crypto conversions to Naira are temporarily paused for system maintenance.'], 503);
            }
            // User sells $amount in Crypto
            $grossFiat = $amount * $rate;
            $fee = FeeService::calculateFee('convert_crypto', $grossFiat);
            $fiatAmount = round(max(0, $grossFiat - $fee), 2);
            $cryptoAmount = $amount;
        }

        return response()->json([
            'data' => [
                'side' => $side,
                'symbol' => $symbol,
                'fiat_amount' => $fiatAmount,
                'crypto_amount' => $cryptoAmount,
                'rate' => $rate,
                'fee' => $fee,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Place order: Buy crypto with Naira OR Convert/Sell crypto to Naira.
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'side' => 'required|in:buy,sell',
            'symbol' => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
            'pin' => 'required|digits:4',
        ]);

        $side = $validated['side'];
        $symbol = strtoupper($validated['symbol']);
        $amount = (float) $validated['amount'];
        $pin = $validated['pin'];

        try {
            if ($side === 'sell') {
                // Convert crypto to Naira
                $result = $this->cryptoService->convertCryptoToNaira($user, $symbol, $amount, $pin);
                return response()->json([
                    'message' => "Successfully converted {$amount} {$symbol} to ₦" . number_format($result['naira_credited'], 2),
                    'data' => $result['transaction'],
                    'details' => $result,
                ]);
            } else {
                // Buy crypto with Naira
                $result = $this->cryptoService->buyCryptoWithNaira($user, $symbol, $amount, $pin);
                return response()->json([
                    'message' => "Successfully purchased {$symbol}!",
                    'data' => $result['transaction'],
                    'details' => $result,
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Simulate an incoming deposit to user's address (for testing & verification).
     */
    public function simulateDeposit(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'symbol' => 'required|string',
            'network' => 'required|string',
            'amount' => 'required|numeric|min:0.001',
        ]);

        $result = $this->cryptoService->processDeposit(
            $user,
            $validated['symbol'],
            $validated['network'],
            (float) $validated['amount']
        );

        $msg = $result['auto_converted']
            ? "Deposit confirmed! {$validated['amount']} {$validated['symbol']} was auto-converted to ₦" . number_format($result['naira_credited'], 2) . ' in your Naira wallet.'
            : "Deposit confirmed! Credited {$validated['amount']} {$validated['symbol']} to your crypto wallet.";

        return response()->json([
            'message' => $msg,
            'data' => $result,
        ]);
    }

    /**
     * Public Quidax Webhook Endpoint for deposit confirmation events.
     */
    public function quidaxWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('Quidax-Signature')
            ?? $request->header('Qdx-Signature')
            ?? $request->header('X-Quidax-Signature');

        if (!$this->quidaxService->verifyWebhookSignature($request->getContent(), $signature)) {
            Log::warning('Quidax webhook signature verification failed');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $parsed = $this->quidaxService->parseWebhook($request->all());
        if (!$parsed) {
            return response()->json(['status' => 'ignored']);
        }

        $depositResult = $this->cryptoService->processDeposit(
            $parsed['user'],
            $parsed['symbol'],
            $parsed['network'],
            $parsed['amount'],
            $parsed['tx_hash']
        );

        return response()->json([
            'status' => 'success',
            'data' => $depositResult,
        ]);
    }

    /**
     * Get transfer fee for sending crypto.
     */
    public function sendFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string',
            'destination_type' => 'required|in:gutter_user,external_address',
            'network' => 'nullable|string',
        ]);

        $fee = $this->cryptoService->getSendFee(
            $validated['symbol'],
            $validated['destination_type'],
            $validated['network'] ?? null
        );

        $rate = $this->cryptoService->getRate($validated['symbol']);

        return response()->json([
            'data' => [
                'symbol' => strtoupper($validated['symbol']),
                'fee' => $fee,
                'fee_naira' => round($fee * $rate, 2),
                'destination_type' => $validated['destination_type'],
            ],
        ]);
    }

    /**
     * Send crypto to another Gutter user or external blockchain address.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'symbol' => 'required|string',
            'destination_type' => 'required|in:gutter_user,external_address',
            'recipient' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.0001',
            'pin' => 'required|digits:4',
            'network' => 'nullable|string',
            'memo' => 'nullable|string|max:100',
        ]);

        try {
            $result = $this->cryptoService->sendCrypto(
                $user,
                $validated['symbol'],
                $validated['destination_type'],
                $validated['recipient'],
                (float) $validated['amount'],
                $validated['pin'],
                $validated['network'] ?? null,
                $validated['memo'] ?? null
            );

            $msg = $validated['destination_type'] === 'gutter_user'
                ? "Successfully sent {$validated['amount']} {$validated['symbol']} to {$result['recipient']}!"
                : "Successfully initiated withdrawal of {$validated['amount']} {$validated['symbol']}!";

            return response()->json([
                'message' => $msg,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}


