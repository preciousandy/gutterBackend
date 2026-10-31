<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppControl;
use App\Models\Beneficiary;
use App\Models\Transaction;
use App\Services\BigisubService;
use App\Services\FeeService;
use App\Services\NetworkDetector;
use App\Services\VtpassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BillController extends Controller
{
    public function __construct(
        protected BigisubService $bigisub,
        protected VtpassService $vtpass
    ) {}

    /**
     * Get active telecom VAS provider (defaults to bigisub).
     */
    protected function getActiveProvider(): string
    {
        return (string) AppControl::getSetting('vas_provider', config('services.bigisub.default_provider', 'bigisub'));
    }

    /**
     * Get data variations / plans for a network via active provider (Bigisub / VTpass).
     */
    public function plans(Request $request): JsonResponse
    {
        $network = (string) $request->query('network', 'MTN');
        $period = $request->query('period');
        $provider = $this->getActiveProvider();

        if ($provider === 'bigisub') {
            $plans = $this->bigisub->getPlans($network);
            if (!empty($plans)) {
                $grouped = [
                    'daily' => array_values(array_filter($plans, fn($p) => ($p['period'] ?? '') === 'daily')),
                    'weekly' => array_values(array_filter($plans, fn($p) => ($p['period'] ?? '') === 'weekly')),
                    'monthly' => array_values(array_filter($plans, fn($p) => ($p['period'] ?? '') === 'monthly')),
                ];

                $filteredPlans = (!empty($period) && isset($grouped[$period])) ? $grouped[$period] : $plans;

                return response()->json([
                    'network' => $network,
                    'provider' => 'bigisub',
                    'plans' => $filteredPlans,
                    'grouped' => $grouped,
                ]);
            }
        }

        // VTpass fallback or selected provider
        $serviceId = $this->vtpass->serviceIdForData($network);
        $variations = $this->vtpass->getVariations($serviceId);

        $normalized = array_map(function ($v) {
            $name = $v['name'] ?? '';
            $period = $this->bigisub->determineValidityPeriod($name);
            return [
                'variation_code' => (string) ($v['variation_code'] ?? ''),
                'name' => $name,
                'variation_amount' => (string) ($v['variation_amount'] ?? '0'),
                'fixedPrice' => $v['fixedPrice'] ?? 'Yes',
                'id' => $v['variation_code'] ?? '',
                'size' => $name,
                'validity' => $name,
                'period' => $period,
                'price' => (float) ($v['variation_amount'] ?? 0),
                'plantype' => 'Direct',
            ];
        }, $variations);

        $grouped = [
            'daily' => array_values(array_filter($normalized, fn($p) => ($p['period'] ?? '') === 'daily')),
            'weekly' => array_values(array_filter($normalized, fn($p) => ($p['period'] ?? '') === 'weekly')),
            'monthly' => array_values(array_filter($normalized, fn($p) => ($p['period'] ?? '') === 'monthly')),
        ];

        return response()->json([
            'network' => $network,
            'provider' => 'vtpass',
            'service_id' => $serviceId,
            'plans' => (!empty($period) && isset($grouped[$period])) ? $grouped[$period] : $normalized,
            'grouped' => $grouped,
        ]);
    }

    /**
     * Get list of supported electricity distribution companies (DISCOs) from Bigisub.
     */
    public function electricityProviders(): JsonResponse
    {
        $providers = $this->bigisub->getElectricityProviders();

        if (empty($providers)) {
            $providers = [
                ['name' => 'Ikeja Electric (IKEDC)', 'code' => 'ikeja-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 500],
                ['name' => 'Eko Electric (EKEDC)', 'code' => 'eko-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 500],
                ['name' => 'Abuja Electric (AEDC)', 'code' => 'abuja-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 1000],
                ['name' => 'Ibadan Electric (IBEDC)', 'code' => 'ibadan-electric', 'min_amount_prepaid' => 2000, 'min_amount_postpaid' => 2000],
                ['name' => 'Enugu Electric (EEDC)', 'code' => 'enugu-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 500],
                ['name' => 'Kano Electric (KEDCO)', 'code' => 'kano-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 500],
                ['name' => 'Port Harcourt Electric (PHED)', 'code' => 'portharcourt-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 500],
                ['name' => 'Jos Electric (JED)', 'code' => 'jos-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 500],
                ['name' => 'Kaduna Electric (KAEDCO)', 'code' => 'kaduna-electric', 'min_amount_prepaid' => 1200, 'min_amount_postpaid' => 500],
                ['name' => 'Benin Electric (BEDC)', 'code' => 'benin-electric', 'min_amount_prepaid' => 1000, 'min_amount_postpaid' => 1000],
            ];
        }

        return response()->json([
            'success' => true,
            'provider' => 'bigisub',
            'providers' => $providers,
        ]);
    }

    /**
     * Verify electricity meter number with live DISCO switch via Bigisub.
     */
    public function verifyMeter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disco' => 'required|string',
            'meter_number' => 'required|string|min:8|max:16',
            'meter_type' => 'nullable|in:prepaid,postpaid',
        ]);

        $meterType = $validated['meter_type'] ?? 'prepaid';
        $result = $this->bigisub->verifyElectricityMeter($validated['disco'], $validated['meter_number'], $meterType);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Unable to verify meter number. Please check and try again.',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * List supported betting companies (bookmakers) from Bigisub.
     */
    public function bettingBillers(): JsonResponse
    {
        $billers = $this->bigisub->getBettingBillers();

        if (empty($billers)) {
            $billers = [
                ['code' => 'biller-sporty-bet', 'name' => 'SportyBet', 'min_amount' => 100, 'max_amount' => 100000],
                ['code' => 'BET9JA', 'name' => 'Bet9ja', 'min_amount' => 100, 'max_amount' => 100000],
                ['code' => 'biller-1x-bet', 'name' => '1xBet', 'min_amount' => 100, 'max_amount' => 1000000],
                ['code' => 'biller-bet-way', 'name' => 'BetWay', 'min_amount' => 100, 'max_amount' => 1000000],
                ['code' => 'biller-bet-king', 'name' => 'BetKing', 'min_amount' => 100, 'max_amount' => 100000],
                ['code' => 'biller-bang-bet', 'name' => 'BangBet', 'min_amount' => 100, 'max_amount' => 1000000],
                ['code' => 'biller-merry-bet', 'name' => 'MerryBet', 'min_amount' => 100, 'max_amount' => 1000000],
                ['code' => 'biller-nairabet', 'name' => 'Nairabet', 'min_amount' => 100, 'max_amount' => 1000000],
                ['code' => 'biller-m-sport', 'name' => 'MSport', 'min_amount' => 100, 'max_amount' => 1000000],
            ];
        }

        return response()->json([
            'success' => true,
            'provider' => 'bigisub',
            'billers' => array_values($billers),
        ]);
    }

    /**
     * Verify a betting account / customer ID with the bookmaker via Bigisub.
     */
    public function verifyBetting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'biller_code' => 'required|string|max:100',
            'customer_id' => 'required|string|min:3|max:64',
        ]);

        $result = $this->bigisub->validateBettingCustomer($validated['biller_code'], $validated['customer_id']);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Unable to verify betting account ID.',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * List supported Cable TV plans and bouquets from Bigisub.
     */
    public function cablePlans(Request $request): JsonResponse
    {
        $provider = $request->query('provider');
        $plans = $this->bigisub->getCablePlans($provider);
        $pricing = $this->bigisub->getCablePricing();

        return response()->json([
            'success' => true,
            'provider' => 'bigisub',
            'plans' => $plans,
            'providers' => $pricing,
        ]);
    }

    /**
     * Verify Cable TV smartcard / IUC number with the provider switch via Bigisub.
     */
    public function verifyCable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cable_name' => 'required|string|in:dstv,gotv,startimes,showmax',
            'smartcard_number' => 'required|string|min:8|max:20',
        ]);

        $result = $this->bigisub->verifyCable($validated['cable_name'], $validated['smartcard_number']);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Unable to verify smartcard number. Please check and try again.',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Purchase Airtime, Mobile Data, Electricity, Betting, or Cable TV via Bigisub (or VTpass).
     */
    public function purchase(Request $request): JsonResponse
    {
        $category = (string) $request->input('category');

        if ($category === 'electricity') {
            return $this->handleElectricityPurchase($request);
        }

        if ($category === 'betting') {
            return $this->handleBettingPurchase($request);
        }

        if ($category === 'cable') {
            return $this->handleCablePurchase($request);
        }

        $user = $request->user();

        // 0. Concurrency Lock: Prevent simultaneous double-click / parallel recharge race conditions
        $userLock = Cache::lock('user_bill_' . $user->id, 30);
        if (!$userLock->get()) {
            return response()->json([
                'message' => 'A recharge is already being processed on your account. Please wait a moment.',
            ], 429);
        }

        $validated = $request->validate([
            'category' => 'required|in:airtime,data,electricity',
            'network' => 'required|in:MTN,Airtel,Glo,9mobile',
            'phone' => 'required|string|min:10|max:14',
            'amount' => 'required|numeric|min:50',
            'plan_name' => 'nullable|string|max:100',
            'variation_code' => 'nullable|string|max:100',
            'pin' => 'required|digits:4',
        ]);

        $category = $validated['category'];
        $network = $validated['network'];
        $phone = $validated['phone'];
        $amount = (float) $validated['amount'];

        // 0.5. Phone Idempotency Guard: Prevent accidental duplicate recharges to same phone within 45s
        $phoneLockKey = "idempotency_bill_{$user->id}_{$category}_{$phone}_{$amount}";
        $phoneLock = Cache::lock($phoneLockKey, 45);
        if (!$phoneLock->get()) {
            $userLock->release();
            return response()->json([
                'message' => "A {$category} recharge of ₦" . number_format($amount, 2) . " to {$phone} was just initiated. Please wait a moment to avoid duplicate charges.",
            ], 429);
        }

        try {
            // Strict network operator validation: notify user if network mismatches phone prefix
            if ($networkMismatch = NetworkDetector::validateNetwork($network, $phone)) {
                optional($phoneLock)->release();
                return response()->json([
                    'message' => $networkMismatch,
                ], 422);
            }

            // 1. Verify user's transaction PIN
            if (!$user->has_pin || !$user->pin) {
                optional($phoneLock)->release();
                return response()->json([
                    'message' => 'Please create your 4-digit transaction PIN first.',
                ], 422);
            }

            if (!Hash::check($validated['pin'], $user->pin)) {
                optional($phoneLock)->release();
                return response()->json([
                    'message' => 'Incorrect 4-digit transaction PIN.',
                ], 422);
            }

            $serviceKey = $category === 'airtime' ? 'airtime' : 'data';
            if (!FeeService::isEnabled($serviceKey)) {
                optional($phoneLock)->release();
                return response()->json([
                    'message' => ($category === 'airtime' ? 'Airtime recharge' : 'Data bundle purchases') . ' are temporarily paused for system maintenance.',
                ], 503);
            }

            $fee = FeeService::calculateFee($serviceKey, $amount);
            $totalDebit = $amount + $fee;
            
            // 2. Atomic Balance check
            $walletBalance = (float) DB::table('users')->where('id', $user->id)->value('wallet_balance');
            if ($walletBalance < $totalDebit) {
                optional($phoneLock)->release();
                $msg = $fee > 0
                    ? "Insufficient Naira wallet balance. Need ₦" . number_format($totalDebit, 2) . " (includes ₦" . number_format($fee, 2) . " fee)."
                    : 'Insufficient Naira wallet balance. Please fund your account.';
                return response()->json([
                    'message' => $msg,
                ], 422);
            }

            $planName = $validated['plan_name'] ?? null;
            $variationCode = $validated['variation_code'] ?? null;
            $provider = $this->getActiveProvider();

            $vtu = null;

            // 3. Connect to active provider (Bigisub as default)
            if ($provider === 'bigisub') {
                if ($category === 'airtime') {
                    $vtu = $this->bigisub->purchaseAirtime($network, $phone, $amount);
                } else {
                    $vtu = $this->bigisub->purchaseData($network, $phone, $amount, $variationCode ?: $planName);
                }

                // Fallback to VTpass if Bigisub returns failure and fallback is permitted
                if (!$vtu['success'] && AppControl::isFeatureEnabled('vas_allow_fallback', false)) {
                    Log::warning("Bigisub purchase failed ({$vtu['message']}). Attempting fallback to VTpass.");
                    if ($category === 'airtime') {
                        $vtu = $this->vtpass->purchaseAirtime($network, $phone, $amount);
                    } else {
                        $vtu = $this->vtpass->purchaseData($network, $phone, $amount, $variationCode);
                    }
                }
            } else {
                // VTpass provider
                if ($category === 'airtime') {
                    $vtu = $this->vtpass->purchaseAirtime($network, $phone, $amount);
                } else {
                    $vtu = $this->vtpass->purchaseData($network, $phone, $amount, $variationCode);
                }
            }

            if (!$vtu['success']) {
                optional($phoneLock)->release();
                return response()->json([
                    'message' => $vtu['message'] ?? 'Failed to complete bill payment on provider network.',
                ], 422);
            }

            $vtuRef = $vtu['reference'] ?? ('BILL_' . strtoupper(Str::random(10)));
            $status = $vtu['status'] ?? 'successful';
            $resolvedProvider = $vtu['provider'] ?? $provider;

            // 4. Debit user wallet and record transaction with row lock
            $tx = DB::transaction(function () use ($user, $amount, $fee, $totalDebit, $category, $network, $phone, $planName, $vtuRef, $status, $resolvedProvider) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                DB::table('users')->where('id', $user->id)->decrement('wallet_balance', $totalDebit);

                $title = $category === 'data'
                    ? "{$network} Data Bundle"
                    : "{$network} Airtime";

                $subtitle = $category === 'data' && $planName
                    ? "{$planName} · {$phone}"
                    : "Recharge to {$phone}";

                $description = $category === 'data'
                    ? "Purchased {$network} data ({$planName}) for {$phone}."
                    : "Recharged {$phone} with ₦" . number_format($amount, 2) . " {$network} airtime.";

                return Transaction::create([
                    'user_id' => $user->id,
                    'reference' => $vtuRef,
                    'type' => 'airtime',
                    'direction' => 'out',
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'amount' => $amount,
                    'fee' => $fee,
                    'currency' => 'NGN',
                    'status' => $status,
                    'counterparty' => "{$network} · {$phone}",
                    'counterparty_bank' => strtoupper($network),
                    'description' => $description,
                ]);
            });

            $successMsg = $category === 'data'
                ? "Successfully recharged {$phone} with {$network} data!"
                : "Successfully recharged {$phone} with ₦" . number_format($amount, 2) . " airtime!";

            return response()->json([
                'message' => $successMsg,
                'data' => $tx,
                'provider' => $resolvedProvider,
                'vtu' => [
                    'reference' => $vtuRef,
                    'transaction_id' => $vtu['transaction_id'] ?? null,
                    'status' => $status,
                ],
            ]);
        } finally {
            optional($userLock)->release();
        }
    }

    /**
     * Handle Electricity recharge via Bigisub API (Prepaid token / Postpaid bill).
     * Minimum electricity payment: 1000 NGN.
     */
    protected function handleElectricityPurchase(Request $request): JsonResponse
    {
        $user = $request->user();

        // 0. Concurrency Lock: Prevent parallel double-tap / concurrent payment race conditions
        $userLock = Cache::lock('user_bill_' . $user->id, 30);
        if (!$userLock->get()) {
            return response()->json([
                'message' => 'An electricity payment is already being processed on your account. Please wait a moment.',
            ], 429);
        }

        $validated = $request->validate([
            'disco' => 'required|string',
            'meter_number' => 'required|string|min:8|max:16',
            'meter_type' => 'required|in:prepaid,postpaid',
            'amount' => 'required|numeric|min:1000',
            'customer_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:25',
            'phone_number' => 'nullable|string|max:25',
            'pin' => 'required|digits:4',
        ], [
            'amount.min' => 'Minimum payment for electricity recharge is ₦1,000.',
        ]);

        $disco = $validated['disco'];
        $meterNumber = preg_replace('/\D/', '', $validated['meter_number']);
        $meterType = $validated['meter_type'];
        $customerName = $validated['customer_name'] ?? null;
        $amount = (float) $validated['amount'];
        $dbPhone = DB::table('users')->where('id', $user->id)->value('phone') ?: $user->phone;
        $phone = $validated['phone_number'] ?? ($validated['phone'] ?? $dbPhone);

        // 0.5. Meter Idempotency Guard: Prevent accidental duplicate recharge to same meter within 60s
        $meterLockKey = "idempotency_meter_{$user->id}_{$meterNumber}_{$amount}";
        $meterLock = Cache::lock($meterLockKey, 60);
        if (!$meterLock->get()) {
            $userLock->release();
            return response()->json([
                'message' => "A recharge of ₦" . number_format($amount, 2) . " for meter {$meterNumber} was just initiated. Please wait a minute to avoid duplicate payments.",
            ], 429);
        }

        try {
            // 1. PIN verification
            if (!$user->has_pin || !$user->pin) {
                optional($meterLock)->release();
                return response()->json([
                    'message' => 'Please create your 4-digit transaction PIN first.',
                ], 422);
            }

            if (!Hash::check($validated['pin'], $user->pin)) {
                optional($meterLock)->release();
                return response()->json([
                    'message' => 'Incorrect 4-digit transaction PIN.',
                ], 422);
            }

            // 2. Feature & Fee Check
            if (!FeeService::isEnabled('electricity')) {
                optional($meterLock)->release();
                return response()->json([
                    'message' => 'Electricity bill payments are temporarily paused for system maintenance.',
                ], 503);
            }

            $fee = FeeService::calculateFee('electricity', $amount);
            $totalDebit = $amount + $fee;

            // 2.5. Atomic Database Balance Verification
            $currentBalance = (float) DB::table('users')->where('id', $user->id)->value('wallet_balance');
            if ($currentBalance < $totalDebit) {
                optional($meterLock)->release();
                $msg = $fee > 0
                    ? "Insufficient Naira wallet balance. Need ₦" . number_format($totalDebit, 2) . " (includes ₦" . number_format($fee, 2) . " fee)."
                    : 'Insufficient Naira wallet balance. Please fund your account.';
                return response()->json([
                    'message' => $msg,
                ], 422);
            }

            // 3. Process via Bigisub API
            $vtu = $this->bigisub->purchaseElectricity($disco, $meterNumber, $meterType, $amount, $customerName, null, $phone);

            if (!$vtu['success']) {
                // Release meter lock on failure so user does not have to wait 60s to retry after error
                optional($meterLock)->release();
                return response()->json([
                    'message' => $vtu['message'] ?? 'Failed to complete electricity recharge on Bigisub gateway.',
                ], 422);
            }

            $vtuRef = $vtu['reference'] ?? ('ELEC_' . strtoupper(Str::random(10)));
            $token = $vtu['token'] ?? null;
            $units = $vtu['units'] ?? null;
            $status = $vtu['status'] ?? 'successful';
            $discoName = strtoupper($disco);

            // 4. Record transaction and debit user wallet atomically with row-level lock
            $tx = DB::transaction(function () use ($user, $amount, $fee, $totalDebit, $discoName, $meterNumber, $meterType, $vtuRef, $token, $units, $status) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                DB::table('users')->where('id', $user->id)->decrement('wallet_balance', $totalDebit);

                $title = "{$discoName} Electricity";
                $subtitle = $token ? "Token: {$token} · Meter {$meterNumber}" : "Meter {$meterNumber} ({$meterType})";
                $description = "Recharged meter {$meterNumber} with ₦" . number_format($amount, 2) . " electricity." . ($token ? " Token: {$token}" : '');

                return Transaction::create([
                    'user_id' => $user->id,
                    'reference' => $vtuRef,
                    'type' => 'electricity',
                    'direction' => 'out',
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'amount' => $amount,
                    'fee' => $fee,
                    'currency' => 'NGN',
                    'status' => $status,
                    'counterparty' => "{$discoName} · {$meterNumber}",
                    'counterparty_bank' => $discoName,
                    'description' => $description,
                ]);
            });

            // 5. Automatically save or update electricity beneficiary for the user
            if ($request->boolean('save_beneficiary', true)) {
                try {
                    Beneficiary::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'category' => 'electricity',
                            'account_number' => $meterNumber,
                            'bank_code' => $disco,
                        ],
                        [
                            'name' => $customerName ?: "{$discoName} Meter",
                            'nickname' => $request->input('beneficiary_nickname') ?: null,
                            'bank_name' => $discoName,
                            'meter_type' => $meterType,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning("Failed to auto-save electricity beneficiary: {$e->getMessage()}");
                }
            }

            $msg = $token
                ? "Electricity token generated successfully: {$token}"
                : "Successfully recharged meter {$meterNumber} with ₦" . number_format($amount, 2);

            return response()->json([
                'message' => $msg,
                'data' => $tx,
                'token' => $token,
                'units' => $units,
                'provider' => 'bigisub',
                'vtu' => [
                    'reference' => $vtuRef,
                    'token' => $token,
                    'units' => $units,
                    'status' => $status,
                ],
            ]);
        } finally {
            optional($userLock)->release();
        }
    }

    /**
     * Handle betting wallet funding via Bigisub with atomic pre-debit & race condition safeguards.
     */
    protected function handleBettingPurchase(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Concurrency Lock: Block parallel requests for this user across billing operations
        $userLock = Cache::lock('user_bill_' . $user->id, 30);
        if (!$userLock->get()) {
            return response()->json([
                'message' => 'A transaction is already being processed on your account. Please wait a moment.',
            ], 429);
        }

        try {
            $validated = $request->validate([
                'biller_code' => 'required|string|max:100',
                'biller_name' => 'nullable|string|max:100',
                'customer_id' => 'required|string|min:3|max:64',
                'customer_name' => 'nullable|string|max:150',
                'amount' => 'required|numeric|min:100',
                'pin' => 'required|digits:4',
                'idempotency_key' => 'nullable|string|max:64',
                'save_beneficiary' => 'nullable|boolean',
                'beneficiary_nickname' => 'nullable|string|max:100',
            ], [
                'amount.min' => 'Minimum betting wallet top-up is ₦100.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $userLock->release();
            throw $e;
        }

        $billerCode = $validated['biller_code'];
        $customerId = trim($validated['customer_id']);
        $amount = (float) $validated['amount'];

        // Resolve biller metadata
        $biller = collect($this->bigisub->getBettingBillers())->firstWhere('code', $billerCode);
        $billerName = $biller['name'] ?? ($validated['biller_name'] ?? strtoupper($billerCode));

        // 2. In-flight Pending Check: Block duplicate funding to same customer while one is already pending
        $recentPending = Transaction::where('user_id', $user->id)
            ->where('counterparty', "{$billerName} · {$customerId}")
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subSeconds(90))
            ->first();

        if ($recentPending) {
            $userLock->release();
            return response()->json([
                'message' => "A top-up of ₦" . number_format((float) $recentPending->amount, 2) . " to {$billerName} ({$customerId}) is currently processing. Please wait for confirmation before submitting again.",
            ], 429);
        }

        // 3. Idempotency Lock: Protect against rapid double-clicks and concurrent identical requests
        $idemKey = $validated['idempotency_key'] ?? ("idem_bet_{$user->id}_{$billerCode}_{$customerId}_" . round($amount));
        $idemLock = Cache::lock($idemKey, 60);
        if (!$idemLock->get()) {
            $userLock->release();
            return response()->json([
                'message' => "A top-up of ₦" . number_format($amount, 2) . " to {$billerName} ({$customerId}) was just initiated. Please wait a minute to avoid duplicate payments.",
            ], 429);
        }

        try {
            // Verify biller min/max limits
            if ($biller) {
                $min = (float) ($biller['min_amount'] ?? 100);
                $max = (float) ($biller['max_amount'] ?? 1000000);
                if ($amount < max(100, $min) || $amount > $max) {
                    $idemLock->release();
                    return response()->json([
                        'message' => "{$billerName} top-up must be between ₦" . number_format(max(100, $min)) . " and ₦" . number_format($max) . '.',
                    ], 422);
                }
            }

            // 4. Validate Transaction PIN
            if (!$user->has_pin || !$user->pin) {
                $idemLock->release();
                return response()->json(['message' => 'Please create your 4-digit transaction PIN first.'], 422);
            }

            if (!Hash::check($validated['pin'], $user->pin)) {
                $idemLock->release();
                return response()->json(['message' => 'Incorrect 4-digit transaction PIN.'], 422);
            }

            // 5. Verify Fee & Operational Status
            if (!FeeService::isEnabled('betting')) {
                $idemLock->release();
                return response()->json(['message' => 'Betting wallet top-ups are temporarily paused for system maintenance.'], 503);
            }

            $fee = FeeService::calculateFee('betting', $amount);
            $totalDebit = $amount + $fee;
            $customerName = $validated['customer_name'] ?? null;
            $localRef = 'BET_' . strtoupper(Str::random(14));

            // 6. ATOMIC PRE-DEBIT: Deduct user wallet upfront under row-level lock BEFORE external call
            // This prevents concurrent overdraws / double-spending races across all channels.
            try {
                $tx = DB::transaction(function () use ($user, $amount, $fee, $totalDebit, $localRef, $billerName, $customerId, $customerName) {
                    $lockedUser = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                    if (!$lockedUser || (float) $lockedUser->wallet_balance < $totalDebit) {
                        throw new \DomainException(
                            $fee > 0
                                ? "Insufficient Naira wallet balance. Need ₦" . number_format($totalDebit, 2) . " (includes ₦" . number_format($fee, 2) . " fee)."
                                : 'Insufficient Naira wallet balance. Please fund your account.'
                        );
                    }

                    DB::table('users')->where('id', $user->id)->decrement('wallet_balance', $totalDebit);

                    return Transaction::create([
                        'user_id' => $user->id,
                        'reference' => $localRef,
                        'type' => 'betting',
                        'direction' => 'out',
                        'title' => "{$billerName} Top-up",
                        'subtitle' => 'Account: ' . ($customerName ? "{$customerName} · " : '') . $customerId,
                        'amount' => $amount,
                        'fee' => $fee,
                        'currency' => 'NGN',
                        'status' => 'pending',
                        'counterparty' => "{$billerName} · {$customerId}",
                        'counterparty_bank' => strtoupper($billerName),
                        'description' => "Funding {$billerName} betting wallet ({$customerId}) with ₦" . number_format($amount, 2) . '.',
                    ]);
                });
            } catch (\DomainException $e) {
                $idemLock->release();
                return response()->json(['message' => $e->getMessage()], 422);
            }

            // 7. Dispatch External Request to Bigisub
            $products = $this->bigisub->getBettingProducts($billerCode);
            $productCode = $products[0]['code'] ?? null;

            $vtu = $this->bigisub->fundBetting($billerCode, $customerId, $amount, $productCode);

            // CASE A: Definitive Success from Bigisub
            if ($vtu['success'] && ($vtu['status'] ?? '') !== 'failed') {
                $providerTxId = $vtu['transaction_id'] ?? $vtu['reference'] ?? null;
                $finalRef = $providerTxId ? 'BET_' . $providerTxId : $localRef;
                $status = ($vtu['status'] ?? 'successful') === 'pending' ? 'pending' : 'successful';

                $tx->update([
                    'reference' => $finalRef,
                    'status' => $status,
                    'description' => "Funded {$billerName} betting wallet ({$customerId}) with ₦" . number_format($amount, 2) . '.',
                ]);

                // Auto-save betting beneficiary for quick access
                if ($request->boolean('save_beneficiary', true)) {
                    try {
                        Beneficiary::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'category' => 'betting',
                                'account_number' => $customerId,
                                'bank_code' => $billerCode,
                            ],
                            [
                                'name' => $customerName ?: "{$billerName} Account",
                                'nickname' => $validated['beneficiary_nickname'] ?? null,
                                'bank_name' => $billerName,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::warning("Failed to auto-save betting beneficiary: {$e->getMessage()}");
                    }
                }

                return response()->json([
                    'message' => $status === 'pending'
                        ? "Your {$billerName} top-up of ₦" . number_format($amount, 2) . ' is processing.'
                        : "₦" . number_format($amount, 2) . " credited to {$billerName} ({$customerId}).",
                    'data' => $tx->fresh(),
                    'provider' => 'bigisub',
                    'vtu' => [
                        'reference' => $finalRef,
                        'transaction_id' => $providerTxId,
                        'status' => $status,
                    ],
                ]);
            }

            // CASE B: Definitive Rejection by Provider (e.g. invalid account, bookmaker rejected)
            if (!$vtu['success'] && empty($vtu['is_ambiguous'])) {
                // Provider confirmed rejection without deducting float. Atomically refund user.
                DB::transaction(function () use ($user, $tx, $totalDebit, $vtu) {
                    $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                    if ($lockedTx && $lockedTx->status === 'pending') {
                        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                        DB::table('users')->where('id', $user->id)->increment('wallet_balance', $totalDebit);
                        $lockedTx->update([
                            'status' => 'failed',
                            'description' => 'Failed: ' . ($vtu['message'] ?? 'Rejected by provider.'),
                        ]);
                    }
                });

                $idemLock->release();
                return response()->json([
                    'message' => $vtu['message'] ?? 'Failed to fund betting wallet. Please try again.',
                ], 422);
            }

            // CASE C: Ambiguous State / Timeout (keep idempotency lock active to block double-taps)
            // Attempt immediate reconciliation with Bigisub recent history
            $reconciled = $this->bigisub->findRecentBettingTransaction($billerCode, $customerId, $amount);
            if ($reconciled) {
                $recStatus = strtolower((string) ($reconciled['status'] ?? ''));
                $recTxId = $reconciled['transaction_id'] ?? ($reconciled['id'] ?? null);
                $finalRef = $recTxId ? 'BET_' . $recTxId : $localRef;

                if (in_array($recStatus, ['successful', 'success', 'completed', 'delivered'], true)) {
                    $tx->update(['reference' => $finalRef, 'status' => 'successful']);
                    return response()->json([
                        'message' => "₦" . number_format($amount, 2) . " credited to {$billerName} ({$customerId}).",
                        'data' => $tx->fresh(),
                        'provider' => 'bigisub',
                        'vtu' => ['reference' => $finalRef, 'transaction_id' => $recTxId, 'status' => 'successful'],
                    ]);
                }

                if (in_array($recStatus, ['failed', 'reversed', 'refunded'], true)) {
                    DB::transaction(function () use ($user, $tx, $totalDebit) {
                        $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                        if ($lockedTx && $lockedTx->status === 'pending') {
                            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                            DB::table('users')->where('id', $user->id)->increment('wallet_balance', $totalDebit);
                            $lockedTx->update([
                                'status' => 'failed',
                                'description' => 'Transaction failed at provider.',
                            ]);
                        }
                    });

                    $idemLock->release();
                    return response()->json(['message' => 'Betting top-up was declined by the provider.'], 422);
                }

                if ($recTxId) {
                    $tx->update(['reference' => $finalRef]);
                }
            }

            // Reconcile was indeterminate; keep transaction as pending
            return response()->json([
                'message' => "Your {$billerName} top-up of ₦" . number_format($amount, 2) . ' is processing. We are verifying the delivery status.',
                'data' => $tx->fresh(),
                'provider' => 'bigisub',
                'vtu' => [
                    'reference' => $tx->reference,
                    'status' => 'pending',
                ],
            ]);
        } finally {
            optional($userLock)->release();
        }
    }

    /**
     * Handle Cable TV subscription renewals via Bigisub with atomic pre-debit & race protection.
     */
    protected function handleCablePurchase(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Concurrency Lock: Prevent parallel double-spend race conditions
        $userLock = Cache::lock('user_bill_' . $user->id, 30);
        if (!$userLock->get()) {
            return response()->json([
                'message' => 'A bill payment is already being processed on your account. Please wait a moment.',
            ], 429);
        }

        try {
            $validated = $request->validate([
                'cable_name' => 'required|string|in:dstv,gotv,startimes,showmax',
                'smartcard_number' => 'required|string|min:8|max:20',
                'cable_plan' => 'required',
                'package_name' => 'nullable|string|max:100',
                'amount' => 'required|numeric|min:500',
                'months' => 'nullable|integer|min:1|max:12',
                'customer_name' => 'nullable|string|max:150',
                'pin' => 'required|digits:4',
                'idempotency_key' => 'nullable|string|max:64',
                'save_beneficiary' => 'nullable|boolean',
                'beneficiary_nickname' => 'nullable|string|max:100',
            ], [
                'amount.min' => 'Minimum cable TV subscription amount is ₦500.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $userLock->release();
            throw $e;
        }

        $cableName = strtolower(trim($validated['cable_name']));
        $cardNo = preg_replace('/\D/', '', $validated['smartcard_number']);
        $amount = (float) $validated['amount'];
        $cablePlan = $validated['cable_plan'];
        $packageName = $validated['package_name'] ?? 'Cable TV';
        $customerName = $validated['customer_name'] ?? null;
        $months = (int) ($validated['months'] ?? 1);
        $providerLabel = match ($cableName) {
            'dstv' => 'DStv',
            'gotv' => 'GOtv',
            'startimes' => 'StarTimes',
            'showmax' => 'Showmax',
            default => strtoupper($cableName),
        };

        // 2. Strict In-flight & Recent Success Guard: Block duplicate renewals to same smartcard (within 90s)
        $recentTx = Transaction::where('user_id', $user->id)
            ->where(function ($q) use ($cardNo, $providerLabel) {
                $q->where('counterparty', "{$providerLabel} · {$cardNo}")
                  ->orWhere('counterparty', 'like', "%{$cardNo}%")
                  ->orWhere('subtitle', 'like', "%{$cardNo}%");
            })
            ->whereIn('status', ['pending', 'successful'])
            ->where('created_at', '>=', now()->subSeconds(90))
            ->first();

        if ($recentTx) {
            $userLock->release();
            if ($recentTx->status === 'pending') {
                return response()->json([
                    'message' => "A renewal of ₦" . number_format((float) $recentTx->amount, 2) . " to {$providerLabel} ({$cardNo}) is currently processing. Please wait for confirmation before submitting again.",
                ], 429);
            }
            return response()->json([
                'message' => "A renewal of ₦" . number_format((float) $recentTx->amount, 2) . " to {$providerLabel} ({$cardNo}) was recently completed. Please wait 90 seconds to prevent accidental duplicate charges.",
            ], 429);
        }

        // 3. Distributed Smartcard Idempotency Lock: Strict lock on the specific card and user (60s)
        $smartcardLockKey = "idem_cbl_{$user->id}_{$cardNo}";
        $idemLock = Cache::lock($smartcardLockKey, 60);
        if (!$idemLock->get()) {
            $userLock->release();
            return response()->json([
                'message' => "A renewal of ₦" . number_format($amount, 2) . " to {$providerLabel} ({$cardNo}) was just initiated. Please wait a minute to avoid duplicate charges.",
            ], 429);
        }

        try {
            // 4. Validate Transaction PIN
            if (!$user->has_pin || !$user->pin) {
                $idemLock->release();
                return response()->json(['message' => 'Please create your 4-digit transaction PIN first.'], 422);
            }

            if (!Hash::check($validated['pin'], $user->pin)) {
                $idemLock->release();
                return response()->json(['message' => 'Incorrect 4-digit transaction PIN.'], 422);
            }

            // 5. Verify Fee & Operational Status
            if (!FeeService::isEnabled('cable')) {
                $idemLock->release();
                return response()->json(['message' => 'Cable TV subscriptions are temporarily paused for system maintenance.'], 503);
            }

            $fee = FeeService::calculateFee('cable', $amount);
            $totalDebit = $amount + $fee;
            $localRef = 'CBL_' . strtoupper(Str::random(14));

            // 6. ATOMIC PRE-DEBIT: Deduct user wallet upfront under row-level lock BEFORE external call
            try {
                $tx = DB::transaction(function () use ($user, $amount, $fee, $totalDebit, $localRef, $providerLabel, $cardNo, $packageName, $customerName, $months) {
                    $lockedUser = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                    if (!$lockedUser || (float) $lockedUser->wallet_balance < $totalDebit) {
                        throw new \DomainException(
                            $fee > 0
                                ? "Insufficient Naira wallet balance. Need ₦" . number_format($totalDebit, 2) . " (includes ₦" . number_format($fee, 2) . " fee)."
                                : 'Insufficient Naira wallet balance. Please fund your account.'
                        );
                    }

                    DB::table('users')->where('id', $user->id)->decrement('wallet_balance', $totalDebit);

                    return Transaction::create([
                        'user_id' => $user->id,
                        'reference' => $localRef,
                        'type' => 'cable',
                        'direction' => 'out',
                        'title' => "{$providerLabel} Subscription",
                        'subtitle' => ($packageName ? "{$packageName}" . ($months > 1 ? " ({$months} mo)" : '') . ' · ' : '') . "IUC: {$cardNo}",
                        'amount' => $amount,
                        'fee' => $fee,
                        'currency' => 'NGN',
                        'status' => 'pending',
                        'counterparty' => "{$providerLabel} · {$cardNo}",
                        'counterparty_bank' => strtoupper($providerLabel),
                        'description' => "Renewed {$providerLabel} subscription ({$cardNo}) for {$packageName} with ₦" . number_format($amount, 2) . '.',
                    ]);
                });
            } catch (\DomainException $e) {
                $idemLock->release();
                return response()->json(['message' => $e->getMessage()], 422);
            }

            // 7. Dispatch External Request to Bigisub
            $vtu = $this->bigisub->purchaseCable($cableName, $cardNo, $amount, $cablePlan, $customerName);

            // CASE A: Definitive Success from Bigisub
            if ($vtu['success'] && ($vtu['status'] ?? '') !== 'failed') {
                $providerTxId = $vtu['transaction_id'] ?? $vtu['reference'] ?? null;
                $finalRef = $providerTxId ? 'CBL_' . $providerTxId : $localRef;
                $status = ($vtu['status'] ?? 'successful') === 'pending' ? 'pending' : 'successful';

                $tx->update([
                    'reference' => $finalRef,
                    'status' => $status,
                    'description' => "Renewed {$providerLabel} subscription ({$cardNo}) for {$packageName} with ₦" . number_format($amount, 2) . '.',
                ]);

                // Auto-save cable beneficiary for quick access
                if ($request->boolean('save_beneficiary', true)) {
                    try {
                        \App\Models\Beneficiary::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'account_number' => $cardNo,
                                'category' => 'cable',
                            ],
                            [
                                'name' => $customerName ?: ($providerLabel . ' Subscriber'),
                                'nickname' => $validated['beneficiary_nickname'] ?? null,
                                'bank_name' => $providerLabel,
                                'bank_code' => $cableName,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::warning("Failed to auto-save cable beneficiary: {$e->getMessage()}");
                    }
                }

                // Do not release $idemLock immediately on success: keep absorbing in-flight duplicate clicks for 60s

                return response()->json([
                    'message' => $status === 'successful'
                        ? "{$providerLabel} subscription renewed successfully!"
                        : "{$providerLabel} renewal is processing. We are confirming activation with the provider.",
                    'data' => $tx->fresh(),
                    'provider' => 'bigisub',
                    'vtu' => [
                        'reference' => $finalRef,
                        'status' => $status,
                    ],
                ]);
            }

            // CASE B: Definitive Failure -> Immediate Atomic Refund with Row Lock Protection
            if (!$vtu['success'] && ($vtu['status'] ?? '') === 'failed' && empty($vtu['is_ambiguous'])) {
                DB::transaction(function () use ($user, $totalDebit, $tx, $providerLabel, $cardNo) {
                    $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                    if (!$lockedTx || $lockedTx->status !== 'pending') {
                        return; // Prevent race condition with concurrent requery or webhook
                    }

                    DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                    DB::table('users')->where('id', $user->id)->increment('wallet_balance', $totalDebit);

                    $lockedTx->update(['status' => 'failed']);

                    Transaction::create([
                        'user_id' => $user->id,
                        'reference' => 'REF_' . strtoupper(Str::random(10)),
                        'type' => 'refund',
                        'direction' => 'in',
                        'title' => 'Cable Renewal Refund',
                        'subtitle' => "Failed renewal · {$providerLabel} ({$cardNo})",
                        'amount' => $totalDebit,
                        'fee' => 0,
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'counterparty' => "{$providerLabel} · {$cardNo}",
                        'counterparty_bank' => strtoupper($providerLabel),
                        'description' => "Automatic refund of ₦" . number_format($totalDebit, 2) . " for failed {$providerLabel} renewal.",
                    ]);
                });

                $idemLock->release();

                return response()->json([
                    'message' => $vtu['message'] ?? 'Cable TV renewal could not be completed. Your wallet has been refunded.',
                ], 422);
            }

            // CASE C: Ambiguous status -> Reconcile or remain pending
            $providerTxId = $vtu['transaction_id'] ?? $vtu['reference'] ?? null;
            if ($providerTxId) {
                $statusRes = $this->bigisub->requery($providerTxId);
                if (($statusRes['status'] ?? '') === 'successful') {
                    $tx->update(['status' => 'successful', 'reference' => 'CBL_' . $providerTxId]);
                    return response()->json([
                        'message' => "{$providerLabel} subscription renewed successfully!",
                        'data' => $tx->fresh(),
                        'provider' => 'bigisub',
                    ]);
                }

                if (($statusRes['status'] ?? '') === 'failed') {
                    DB::transaction(function () use ($user, $totalDebit, $tx, $providerLabel, $cardNo) {
                        $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                        if (!$lockedTx || $lockedTx->status !== 'pending') {
                            return;
                        }

                        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                        DB::table('users')->where('id', $user->id)->increment('wallet_balance', $totalDebit);

                        $lockedTx->update(['status' => 'failed']);

                        Transaction::create([
                            'user_id' => $user->id,
                            'reference' => 'REF_' . strtoupper(Str::random(10)),
                            'type' => 'refund',
                            'direction' => 'in',
                            'title' => 'Cable Renewal Refund',
                            'subtitle' => "Failed renewal · {$providerLabel} ({$cardNo})",
                            'amount' => $totalDebit,
                            'fee' => 0,
                            'currency' => 'NGN',
                            'status' => 'successful',
                            'counterparty' => "{$providerLabel} · {$cardNo}",
                            'counterparty_bank' => strtoupper($providerLabel),
                            'description' => "Automatic refund of ₦" . number_format($totalDebit, 2) . " for failed {$providerLabel} renewal.",
                        ]);
                    });

                    $idemLock->release();

                    return response()->json([
                        'message' => $statusRes['message'] ?? 'Cable TV renewal failed at the provider. Your wallet has been refunded.',
                    ], 422);
                }
            }

            return response()->json([
                'message' => "Your {$providerLabel} renewal of ₦" . number_format($amount, 2) . ' is processing. We are verifying the delivery status.',
                'data' => $tx->fresh(),
                'provider' => 'bigisub',
                'vtu' => [
                    'reference' => $tx->reference,
                    'status' => 'pending',
                ],
            ]);
        } finally {
            optional($userLock)->release();
        }
    }

    /**
     * Requery status of an existing bill payment with atomic double-refund prevention.
     */
    public function requery(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tx = Transaction::where('user_id', $user->id)
            ->whereIn('type', ['airtime', 'data', 'electricity', 'cable', 'betting'])
            ->findOrFail($id);

        if ($tx->status === 'failed') {
            return response()->json([
                'status' => 'failed',
                'message' => 'This recharge failed and has already been refunded.',
                'data' => $tx,
            ]);
        }

        if ($tx->status === 'successful') {
            return response()->json([
                'status' => 'successful',
                'message' => 'This recharge has already been confirmed delivered.',
                'data' => $tx,
            ]);
        }

        $reference = $tx->reference;
        if (str_starts_with($reference, 'BET_')) {
            $vtu = $this->bigisub->requeryBetting(substr($reference, 4));
        } elseif (str_starts_with($reference, 'CBL_') || str_starts_with($reference, 'BIGI_') || str_starts_with($reference, 'Air') || str_starts_with($reference, 'Data') || str_starts_with($reference, 'ELEC_')) {
            $vtu = $this->bigisub->requery($reference);
        } else {
            $vtu = $this->vtpass->requery($reference);
            if (($vtu['status'] ?? '') === 'pending' || !($vtu['delivered'] ?? false)) {
                $bigiRes = $this->bigisub->requery($reference);
                if (($bigiRes['status'] ?? '') === 'successful') {
                    $vtu = $bigiRes;
                }
            }
        }

        if ($vtu['status'] === 'successful') {
            DB::transaction(function () use ($tx) {
                $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                if ($lockedTx && $lockedTx->status === 'pending') {
                    $lockedTx->update(['status' => 'successful']);
                }
            });

            return response()->json([
                'status' => 'successful',
                'message' => 'Recharge has been confirmed delivered by the network.',
                'data' => $tx->fresh(),
            ]);
        }

        if ($vtu['status'] === 'failed') {
            // Atomic refund with pessimistic row lock to prevent double-refund race conditions
            $totalRefund = (float) $tx->amount + (float) $tx->fee;
            $refundProcessed = DB::transaction(function () use ($user, $tx, $totalRefund) {
                $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                if (!$lockedTx || $lockedTx->status !== 'pending') {
                    return false; // Already handled by concurrent requery
                }

                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                DB::table('users')->where('id', $user->id)->increment('wallet_balance', $totalRefund);
                $lockedTx->update(['status' => 'failed']);

                Transaction::create([
                    'user_id' => $user->id,
                    'reference' => 'REF_' . strtoupper(Str::random(10)),
                    'type' => 'airtime',
                    'direction' => 'in',
                    'title' => 'Recharge Refund',
                    'subtitle' => 'Failed delivery · ' . $lockedTx->counterparty,
                    'amount' => $totalRefund,
                    'fee' => 0,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => $lockedTx->counterparty,
                    'counterparty_bank' => $lockedTx->counterparty_bank,
                    'description' => "Automatic refund of ₦" . number_format($totalRefund, 2) . " for undelivered {$lockedTx->title}.",
                ]);

                return true;
            });

            return response()->json([
                'status' => 'failed',
                'message' => "Recharge could not be delivered by the network. ₦" . number_format($totalRefund, 2) . " has been refunded to your wallet.",
                'data' => $tx->fresh(),
                'refunded' => $refundProcessed,
            ]);
        }

        return response()->json([
            'status' => 'pending',
            'message' => 'Transaction is currently processing with the network provider.',
            'data' => $tx,
        ]);
    }
}
