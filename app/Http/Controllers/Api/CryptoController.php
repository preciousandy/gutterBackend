<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CryptoDeposit;
use App\Models\CryptoTransaction;
use App\Models\CryptoWebhookLog;
use App\Services\CryptoService;
use App\Services\FeeService;
use App\Services\QuidaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

        try {
            $config = $this->cryptoService->assertServiceAndAssetEnabled('receive_crypto', $symbol);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        }

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
                'warning' => "Only send {$symbol} via the {$net['name']} ({$net['network']}) network to this address. Sending any other asset or using a wrong network may result in permanent loss of funds.",
            ];
        }

        $recentDeposits = [];
        try {
            if (Schema::hasTable('crypto_deposits')) {
                $recentDeposits = CryptoDeposit::where('user_id', $user->id)
                    ->where('symbol', $symbol)
                    ->latest()
                    ->limit(10)
                    ->get();
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return response()->json([
            'data' => [
                'symbol' => $symbol,
                'auto_convert_crypto' => (bool) $user->auto_convert_crypto,
                'deposit_fee_percentage' => (float) ($config['percentage_fee'] ?? 0),
                'deposit_fixed_fee_ngn' => (float) ($config['fixed_fee_ngn'] ?? 0),
                'addresses' => $addresses,
                'recent_deposits' => $recentDeposits,
            ],
        ]);
    }

    /**
     * Get instant live conversion quote (supports NGN or crypto input).
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'side' => 'required|in:buy,sell',
            'symbol' => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
            'amount_type' => 'nullable|in:fiat,crypto',
        ]);

        $side = $validated['side'];
        $symbol = strtoupper($validated['symbol']);
        $amount = (float) $validated['amount'];
        $amountType = $validated['amount_type'] ?? ($side === 'buy' ? 'fiat' : 'crypto');
        $rate = $this->cryptoService->getRate($symbol);
        $rateStr = number_format($rate, 8, '.', '');

        if ($side === 'buy') {
            if (!FeeService::isEnabled('buy_crypto')) {
                return response()->json(['message' => 'Crypto purchases are temporarily paused for system maintenance.'], 503);
            }

            try {
                $config = $this->cryptoService->assertServiceAndAssetEnabled('buy_crypto', $symbol);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            if ($amountType === 'crypto') {
                // User entered target crypto amount; calculate total NGN needed
                $cryptoAmtStr = number_format($amount, 8, '.', '');
                $netNgnNeededStr = bcmul($cryptoAmtStr, $rateStr, 8);
                $feeStr = $this->cryptoService->calculateNgnFeeFromConfig($config, $netNgnNeededStr);
                $fiatAmount = round((float) bcadd($netNgnNeededStr, $feeStr, 8), 2);
                $fee = round((float) $feeStr, 2);
                $cryptoAmount = round($amount, 8);
            } else {
                // User entered NGN amount to spend
                $fiatStr = number_format($amount, 8, '.', '');
                $feeStr = $this->cryptoService->calculateNgnFeeFromConfig($config, $fiatStr);
                $netNgnStr = bcsub($fiatStr, $feeStr, 8);
                $cryptoAmount = bccomp($netNgnStr, '0', 8) > 0
                    ? round((float) bcdiv($netNgnStr, $rateStr, 8), 8)
                    : 0.0;
                $fiatAmount = round($amount, 2);
                $fee = round((float) $feeStr, 2);
            }
        } else {
            if (!FeeService::isEnabled('convert_crypto')) {
                return response()->json(['message' => 'Crypto conversions to Naira are temporarily paused for system maintenance.'], 503);
            }

            try {
                $config = $this->cryptoService->assertServiceAndAssetEnabled('convert_to_naira', $symbol);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            $cryptoStr = number_format($amount, 8, '.', '');
            $grossFiatStr = bcmul($cryptoStr, $rateStr, 8);
            $feeStr = $this->cryptoService->calculateNgnFeeFromConfig($config, $grossFiatStr);
            $netFiatStr = bcsub($grossFiatStr, $feeStr, 8);

            $grossFiat = round((float) $grossFiatStr, 2);
            $fee = round((float) $feeStr, 2);
            $fiatAmount = max(0.0, round((float) $netFiatStr, 2));
            $cryptoAmount = round($amount, 8);
        }

        return response()->json([
            'data' => [
                'side' => $side,
                'symbol' => $symbol,
                'fiat_amount' => $fiatAmount,
                'gross_fiat_amount' => $grossFiat ?? $fiatAmount,
                'crypto_amount' => $cryptoAmount,
                'rate' => $rate,
                'fee' => $fee,
                'fee_percentage' => (float) ($config['percentage_fee'] ?? 0.5),
                'fixed_fee_ngn' => (float) ($config['fixed_fee_ngn'] ?? 0),
                'min_amount' => (float) ($config['min_amount'] ?? 0),
                'max_amount' => (float) ($config['max_amount'] ?? 100000000),
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
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $side = $validated['side'];
        $symbol = strtoupper($validated['symbol']);
        $amount = (float) $validated['amount'];
        $pin = $validated['pin'];
        $idempotencyKey = $validated['idempotency_key'] ?? $request->header('X-Idempotency-Key');

        try {
            if ($side === 'sell') {
                // Convert crypto to Naira
                $result = $this->cryptoService->convertCryptoToNaira($user, $symbol, $amount, $pin, $idempotencyKey);
                return response()->json([
                    'message' => "Successfully converted {$amount} {$symbol} to ₦" . number_format($result['naira_credited'], 2),
                    'data' => $result['transaction'],
                    'details' => $result,
                ]);
            } else {
                // Buy crypto with Naira
                $result = $this->cryptoService->buyCryptoWithNaira($user, $symbol, $amount, $pin, $idempotencyKey);
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
            : "Deposit confirmed! Credited {$result['net_amount']} {$validated['symbol']} to your crypto wallet.";

        return response()->json([
            'message' => $msg,
            'data' => $result,
        ]);
    }

    /**
     * Public Quidax Webhook Endpoint for deposit, withdrawal, and swap events.
     */
    public function quidaxWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('Quidax-Signature')
            ?? $request->header('Qdx-Signature')
            ?? $request->header('X-Quidax-Signature');

        if (!$this->quidaxService->verifyWebhookSignature($request->getContent(), $signature)) {
            Log::warning('Quidax webhook signature verification failed', [
                'ip' => $request->ip(),
            ]);

            try {
                if (Schema::hasTable('crypto_webhook_logs')) {
                    CryptoWebhookLog::create([
                        'event_type' => $request->input('event', 'invalid_signature'),
                        'signature' => $signature,
                        'signature_valid' => false,
                        'status' => 'failed',
                        'payload' => $request->all() ?: ['raw' => $request->getContent()],
                        'processing_notes' => 'Rejected due to invalid webhook signature.',
                        'ip_address' => $request->ip(),
                    ]);
                }
            } catch (\Throwable $e) {
                // Ignore logging errors
            }

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $parsed = $this->quidaxService->parseWebhook($request->all());
        if (!$parsed) {
            return response()->json(['status' => 'ignored']);
        }

        $result = $this->cryptoService->handleWebhookEvent(
            $parsed,
            $request->all(),
            $signature,
            $request->ip()
        );

        return response()->json($result);
    }

    /**
     * Get transfer & network fee breakdown for sending crypto.
     */
    public function sendFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string',
            'destination_type' => 'required|in:gutter_user,external_address',
            'network' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $breakdown = $this->cryptoService->getSendFeeBreakdown(
            $validated['symbol'],
            $validated['destination_type'],
            $validated['network'] ?? null,
            (float) ($validated['amount'] ?? 0)
        );

        return response()->json([
            'data' => [
                'symbol' => strtoupper($validated['symbol']),
                'fee' => $breakdown['total_fee'],
                'network_fee' => $breakdown['network_fee'],
                'platform_fee' => $breakdown['platform_fee'],
                'platform_fee_ngn' => $breakdown['platform_fee_ngn'],
                'fee_naira' => $breakdown['fee_naira'],
                'rate' => $breakdown['rate'],
                'min_withdrawal' => $breakdown['min_withdrawal'],
                'max_withdrawal' => $breakdown['max_withdrawal'],
                'destination_type' => $validated['destination_type'],
            ],
        ]);
    }

    /**
     * Validate a recipient wallet address and network.
     */
    public function validateAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => 'required|string',
            'network' => 'required|string',
            'address' => 'required|string|max:255',
        ]);

        $check = $this->quidaxService->validateWalletAddress(
            $validated['symbol'],
            $validated['network'],
            $validated['address']
        );

        return response()->json([
            'data' => $check,
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
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $idempotencyKey = $validated['idempotency_key'] ?? $request->header('X-Idempotency-Key');

        try {
            $result = $this->cryptoService->sendCrypto(
                $user,
                $validated['symbol'],
                $validated['destination_type'],
                $validated['recipient'],
                (float) $validated['amount'],
                $validated['pin'],
                $validated['network'] ?? null,
                $validated['memo'] ?? null,
                $idempotencyKey
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

    /**
     * Get user's dedicated crypto transaction history.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!Schema::hasTable('crypto_transactions')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                ],
            ]);
        }

        $query = CryptoTransaction::where('user_id', $user->id)->latest();

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('symbol') && $request->query('symbol') !== 'all') {
            $query->where('symbol', strtoupper($request->query('symbol')));
        }

        if ($request->filled('network') && $request->query('network') !== 'all') {
            $query->where('network', strtoupper($request->query('network')));
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', strtolower($request->query('status')));
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('quidax_reference', 'like', "%{$search}%")
                  ->orWhere('tx_hash', 'like', "%{$search}%")
                  ->orWhere('wallet_address', 'like', "%{$search}%")
                  ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        $paginated = $query->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Get full details of a specific crypto transaction belonging to the user.
     */
    public function showTransaction(Request $request, string|int $id): JsonResponse
    {
        $user = $request->user();

        $tx = CryptoTransaction::with(['withdrawal', 'exchangeOrder', 'refunds'])
            ->where('user_id', $user->id)
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', (int) $id)->orWhere('transaction_id', (int) $id);
                } else {
                    $q->where('reference', $id);
                }
            })
            ->first();

        if (!$tx) {
            return response()->json([
                'message' => 'Crypto transaction not found.',
            ], 404);
        }

        return response()->json([
            'data' => $tx,
        ]);
    }
}
