<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoAuditLog;
use App\Models\CryptoDeposit;
use App\Models\CryptoExchangeOrder;
use App\Models\CryptoFeeSetting;
use App\Models\CryptoRefund;
use App\Models\CryptoTransaction;
use App\Models\CryptoWallet;
use App\Models\CryptoWebhookLog;
use App\Models\CryptoWithdrawal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CryptoService
{
    /**
     * Base exchange rates in NGN.
     */
    protected array $baseRates = [
        'USDT' => 1550.00,
        'BTC'  => 145250000.00,
        'ETH'  => 5240000.00,
        'BNB'  => 985000.00,
        'SOL'  => 242000.00,
    ];

    public function __construct(
        protected ?QuidaxService $quidaxService = null
    ) {
        $this->quidaxService = $quidaxService ?? app(QuidaxService::class);
    }

    /**
     * Record a detailed crypto audit log entry.
     */
    public function logAudit(
        ?int $cryptoTransactionId,
        ?int $userId,
        string $actorType,
        ?int $actorId,
        ?string $actorName,
        string $action,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $description = null,
        array $metadata = []
    ): ?CryptoAuditLog {
        try {
            if (!Schema::hasTable('crypto_audit_logs')) {
                return null;
            }

            return CryptoAuditLog::create([
                'crypto_transaction_id' => $cryptoTransactionId,
                'user_id' => $userId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'action' => $action,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'description' => $description,
                'metadata' => $metadata,
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the fee setting record and snapshot for a crypto service.
     */
    public function getFeeConfig(string $serviceType): array
    {
        $normalized = match ($serviceType) {
            'convert_crypto', 'convert_to_naira', 'sell' => 'convert_to_naira',
            'buy_crypto', 'buy' => 'buy_crypto',
            'send_crypto', 'withdraw' => 'send_crypto',
            'receive_crypto', 'deposit' => 'receive_crypto',
            default => $serviceType,
        };

        $feeServiceKey = $normalized === 'convert_to_naira' ? 'convert_crypto' : $normalized;
        $baseSetting = FeeService::getSetting($feeServiceKey);

        try {
            if (Schema::hasTable('crypto_fee_settings')) {
                $dbConfig = CryptoFeeSetting::where('service_type', $normalized)->first();
                if ($dbConfig) {
                    return [
                        'service_type' => $normalized,
                        'label' => $dbConfig->label,
                        'is_enabled' => (bool) $dbConfig->is_enabled && (bool) $baseSetting['is_enabled'] && !(bool) $dbConfig->maintenance_mode,
                        'maintenance_mode' => (bool) $dbConfig->maintenance_mode,
                        'maintenance_message' => $dbConfig->maintenance_message ?: 'This crypto service is temporarily undergoing scheduled maintenance.',
                        'percentage_fee' => (string) number_format((float) $dbConfig->percentage_fee, 4, '.', ''),
                        'fixed_fee_ngn' => (string) number_format((float) $dbConfig->fixed_fee_ngn, 2, '.', ''),
                        'fixed_fee_crypto' => (string) number_format((float) $dbConfig->fixed_fee_crypto, 8, '.', ''),
                        'min_amount' => (float) $dbConfig->min_amount,
                        'max_amount' => (float) $dbConfig->max_amount,
                        'min_withdrawal_amount' => $dbConfig->min_withdrawal_amount !== null ? (float) $dbConfig->min_withdrawal_amount : 0.0001,
                        'max_withdrawal_amount' => $dbConfig->max_withdrawal_amount !== null ? (float) $dbConfig->max_withdrawal_amount : 100000.0,
                        'manual_approval_threshold' => $dbConfig->manual_approval_threshold !== null ? (float) $dbConfig->manual_approval_threshold : 50000.0,
                        'supported_coins' => $dbConfig->supported_coins ?? [],
                        'updated_by' => $dbConfig->updated_by,
                        'captured_at' => now()->toIso8601String(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fallback
        }

        return [
            'service_type' => $normalized,
            'label' => $baseSetting['label'] ?? ucwords(str_replace('_', ' ', $normalized)),
            'is_enabled' => (bool) ($baseSetting['is_enabled'] ?? true),
            'maintenance_mode' => false,
            'maintenance_message' => null,
            'percentage_fee' => (string) number_format((float) ($baseSetting['percent_fee'] ?? 0.5), 4, '.', ''),
            'fixed_fee_ngn' => (string) number_format((float) ($baseSetting['flat_fee'] ?? 0.0), 2, '.', ''),
            'fixed_fee_crypto' => '0.00000000',
            'min_amount' => 0.000001,
            'max_amount' => 100000000.0,
            'min_withdrawal_amount' => 0.0001,
            'max_withdrawal_amount' => 100000.0,
            'manual_approval_threshold' => 50000.0,
            'supported_coins' => [],
            'updated_by' => $baseSetting['updated_by'] ?? 'System Default',
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Check if a coin and optionally network are enabled for a service.
     */
    public function assertServiceAndAssetEnabled(string $serviceType, string $symbol, ?string $network = null): array
    {
        $config = $this->getFeeConfig($serviceType);
        $symbol = strtoupper($symbol);

        if ($config['maintenance_mode']) {
            throw new \InvalidArgumentException($config['maintenance_message'] ?: 'Crypto services are temporarily in maintenance mode.');
        }

        if (!$config['is_enabled']) {
            throw new \InvalidArgumentException("{$config['label']} is temporarily paused for system maintenance.");
        }

        $coins = $config['supported_coins'] ?? [];
        if (isset($coins[$symbol]) && isset($coins[$symbol]['enabled']) && !$coins[$symbol]['enabled']) {
            throw new \InvalidArgumentException("{$symbol} is currently disabled for {$config['label']}.");
        }

        if ($network !== null && $network !== 'INTERNAL') {
            $netKey = strtoupper($network);
            if (
                isset($coins[$symbol]['networks'][$netKey]['enabled']) &&
                !$coins[$symbol]['networks'][$netKey]['enabled']
            ) {
                throw new \InvalidArgumentException("The {$netKey} network for {$symbol} is temporarily under maintenance.");
            }
        }

        return $config;
    }

    /**
     * Decimal-safe fee calculation in NGN using bcmath.
     * Formula: fixed_fee_ngn + (amount_ngn * (percentage_fee / 100))
     */
    public function calculateNgnFeeFromConfig(array $config, string|float $amountNgn): string
    {
        $amtStr = number_format((float) $amountNgn, 8, '.', '');
        $fixedStr = number_format((float) ($config['fixed_fee_ngn'] ?? 0), 8, '.', '');
        $percentStr = number_format((float) ($config['percentage_fee'] ?? 0), 8, '.', '');

        $percentRatio = bcdiv($percentStr, '100', 8);
        $percentFee = bcmul($amtStr, $percentRatio, 8);
        $totalFee = bcadd($fixedStr, $percentFee, 8);

        return number_format((float) $totalFee, 2, '.', '');
    }

    /**
     * Get live market coin data.
     */
    public function getMarketData(): array
    {
        $liveRates = $this->quidaxService?->getMarketRates();
        if ($liveRates) {
            foreach ($liveRates as $sym => $rate) {
                if (isset($this->baseRates[$sym])) {
                    $this->baseRates[$sym] = $rate;
                }
            }
        }

        $allCoins = [
            [
                'symbol' => 'USDT',
                'name' => 'Tether USD',
                'price' => $this->baseRates['USDT'],
                'change_24h' => 0.45,
                'sparkline' => [1542, 1545, 1548, 1546, 1550, 1549, 1550],
            ],
            [
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'price' => $this->baseRates['BTC'],
                'change_24h' => 2.84,
                'sparkline' => [141000000, 142500000, 141800000, 143900000, 144500000, 145250000],
            ],
            [
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'price' => $this->baseRates['ETH'],
                'change_24h' => -1.15,
                'sparkline' => [5320000, 5290000, 5300000, 5210000, 5240000],
            ],
            [
                'symbol' => 'SOL',
                'name' => 'Solana',
                'price' => $this->baseRates['SOL'],
                'change_24h' => 4.62,
                'sparkline' => [231000, 234000, 238000, 236000, 242000],
            ],
            [
                'symbol' => 'BNB',
                'name' => 'BNB Chain',
                'price' => $this->baseRates['BNB'],
                'change_24h' => 0.82,
                'sparkline' => [975000, 980000, 978000, 982000, 985000],
            ],
        ];

        $config = $this->getFeeConfig('buy_crypto');
        $supportedCoins = $config['supported_coins'] ?? [];

        return array_values(array_map(function ($coin) use ($supportedCoins) {
            $sym = $coin['symbol'];
            $coin['enabled'] = isset($supportedCoins[$sym]['enabled']) ? (bool) $supportedCoins[$sym]['enabled'] : true;
            return $coin;
        }, $allCoins));
    }

    /**
     * Get current price of a coin in NGN.
     */
    public function getRate(string $symbol): float
    {
        $symbol = strtoupper($symbol);
        $liveRates = $this->quidaxService?->getMarketRates();
        if ($liveRates && isset($liveRates[$symbol])) {
            return (float) $liveRates[$symbol];
        }

        return $this->baseRates[$symbol] ?? 1550.00;
    }

    /**
     * Get or generate a dedicated deposit address for a user via Quidax.
     */
    public function getOrCreateAddress(User $user, string $symbol, string $network): CryptoAddress
    {
        $symbol = strtoupper($symbol);
        $network = strtoupper($network);

        $existing = CryptoAddress::where('user_id', $user->id)
            ->where('symbol', $symbol)
            ->where('network', $network)
            ->first();

        if ($existing) {
            return $existing;
        }

        $addressInfo = $this->quidaxService->getDepositAddress($user, $symbol, $network);

        return CryptoAddress::create([
            'user_id' => $user->id,
            'symbol' => $symbol,
            'network' => $network,
            'address' => $addressInfo['address'],
            'memo' => $addressInfo['memo'] ?? null,
            'quidax_address_id' => $addressInfo['quidax_address_id'] ?? null,
            'status' => 'active',
            'provider' => $addressInfo['provider'] ?? 'quidax',
        ]);
    }

    /**
     * Get all supported networks for an asset, honoring admin network settings.
     */
    public function getSupportedNetworks(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        $config = $this->getFeeConfig('receive_crypto');
        $coins = $config['supported_coins'] ?? [];

        if (!empty($coins[$symbol]['networks']) && is_array($coins[$symbol]['networks'])) {
            $networks = [];
            foreach ($coins[$symbol]['networks'] as $netKey => $netData) {
                if (isset($netData['enabled']) && !$netData['enabled']) {
                    continue;
                }
                $networks[] = [
                    'network' => strtoupper($netData['network'] ?? $netKey),
                    'name' => $netData['name'] ?? $netKey,
                    'fee_desc' => $netData['fee_desc'] ?? 'Standard network fee',
                    'confirmations' => (int) ($netData['confirmations'] ?? 1),
                    'min_deposit' => (float) ($netData['min_deposit'] ?? 1.0),
                    'network_fee' => (float) ($netData['network_fee'] ?? 0.0),
                ];
            }
            if (!empty($networks)) {
                return $networks;
            }
        }

        if ($symbol === 'USDT') {
            return [
                [
                    'network' => 'TRC20',
                    'name' => 'Tron (TRC-20)',
                    'fee_desc' => 'Low network fee · ~1-3 mins',
                    'confirmations' => 1,
                    'min_deposit' => 1.0,
                    'network_fee' => 1.0,
                ],
                [
                    'network' => 'BEP20',
                    'name' => 'BNB Smart Chain (BEP-20)',
                    'fee_desc' => 'Very low fee · ~1 min',
                    'confirmations' => 15,
                    'min_deposit' => 1.0,
                    'network_fee' => 0.5,
                ],
                [
                    'network' => 'ERC20',
                    'name' => 'Ethereum (ERC-20)',
                    'fee_desc' => 'Standard fee · ~3-5 mins',
                    'confirmations' => 12,
                    'min_deposit' => 10.0,
                    'network_fee' => 4.0,
                ],
            ];
        }

        if ($symbol === 'BTC') {
            return [
                [
                    'network' => 'BTC',
                    'name' => 'Bitcoin Native',
                    'fee_desc' => 'Standard network fee · ~10-30 mins',
                    'confirmations' => 2,
                    'min_deposit' => 0.0001,
                    'network_fee' => 0.0002,
                ],
            ];
        }

        if ($symbol === 'SOL') {
            return [
                [
                    'network' => 'SOL',
                    'name' => 'Solana Mainnet',
                    'fee_desc' => 'Ultra-fast · ~30 secs',
                    'confirmations' => 1,
                    'min_deposit' => 0.05,
                    'network_fee' => 0.005,
                ],
            ];
        }

        if ($symbol === 'BNB') {
            return [
                [
                    'network' => 'BEP20',
                    'name' => 'BNB Smart Chain (BEP-20)',
                    'fee_desc' => 'Fast · ~1 min',
                    'confirmations' => 15,
                    'min_deposit' => 0.01,
                    'network_fee' => 0.001,
                ],
            ];
        }

        return [
            [
                'network' => 'ERC20',
                'name' => 'Ethereum (ERC-20)',
                'fee_desc' => 'Standard network fee · ~3 mins',
                'confirmations' => 12,
                'min_deposit' => 0.005,
                'network_fee' => 0.0015,
            ],
        ];
    }

    /**
     * Process an incoming crypto deposit with row-level locking, fee snapshot, and unified ledger logging.
     */
    public function processDeposit(
        User $user,
        string $symbol,
        string $network,
        float $amount,
        ?string $txHash = null,
        ?string $quidaxDepositId = null,
        int $confirmations = 1,
        array $quidaxResponse = []
    ): array {
        $symbol = strtoupper($symbol);
        $network = strtoupper($network);
        $txHash = $txHash ?: '0x' . Str::random(64);

        return DB::transaction(function () use ($user, $symbol, $network, $amount, $txHash, $quidaxDepositId, $confirmations, $quidaxResponse) {
            $existing = CryptoDeposit::where('tx_hash', $txHash)->lockForUpdate()->first();
            if ($existing) {
                return [
                    'deposit' => $existing,
                    'already_processed' => true,
                    'auto_converted' => (bool) $existing->converted_to_naira,
                    'naira_credited' => $existing->naira_amount,
                    'crypto_credited' => $existing->converted_to_naira ? 0 : (float) ($existing->net_amount ?? $existing->amount),
                    'net_amount' => (float) ($existing->net_amount ?? $existing->amount),
                    'symbol' => $symbol,
                ];
            }

            // Lock user row
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $config = $this->getFeeConfig('receive_crypto');
            $rate = $this->getRate($symbol);

            // Calculate deposit fee if configured by admin
            $amtStr = number_format($amount, 8, '.', '');
            $rateStr = number_format($rate, 8, '.', '');
            $pctRatio = bcdiv(number_format((float) ($config['percentage_fee'] ?? 0), 8, '.', ''), '100', 8);
            $pctFeeCrypto = bcmul($amtStr, $pctRatio, 8);
            $fixedFeeCrypto = number_format((float) ($config['fixed_fee_crypto'] ?? 0), 8, '.', '');
            $platformFeeCryptoStr = bcadd($pctFeeCrypto, $fixedFeeCrypto, 8);
            $netCryptoStr = bcsub($amtStr, $platformFeeCryptoStr, 8);
            if (bccomp($netCryptoStr, '0', 8) < 0) {
                $netCryptoStr = '0.00000000';
            }

            $platformFeeCrypto = (float) $platformFeeCryptoStr;
            $netCryptoAmount = (float) $netCryptoStr;
            $platformFeeNgn = (float) bcmul($platformFeeCryptoStr, $rateStr, 2);

            $autoConvert = (bool) $lockedUser->auto_convert_crypto;
            $nairaAmount = null;
            $reference = 'CRYPTO_' . strtoupper(Str::random(10));

            if ($autoConvert) {
                // Auto-convert to Naira instantly using decimal-safe math
                $grossNairaStr = bcmul($netCryptoStr, $rateStr, 8);
                $nairaAmount = (float) number_format((float) $grossNairaStr, 2, '.', '');

                $newWalletBalance = (float) bcadd(number_format((float) $lockedUser->wallet_balance, 2, '.', ''), number_format($nairaAmount, 2, '.', ''), 2);
                $lockedUser->wallet_balance = $newWalletBalance;
                $lockedUser->save();

                $deposit = CryptoDeposit::create([
                    'user_id' => $lockedUser->id,
                    'tx_hash' => $txHash,
                    'quidax_deposit_id' => $quidaxDepositId,
                    'symbol' => $symbol,
                    'network' => $network,
                    'amount' => $amount,
                    'confirmations' => $confirmations,
                    'platform_fee' => $platformFeeCrypto,
                    'net_amount' => $netCryptoAmount,
                    'status' => 'confirmed',
                    'converted_to_naira' => true,
                    'naira_amount' => $nairaAmount,
                    'rate' => $rate,
                    'fee_config_snapshot' => $config,
                    'quidax_response' => $quidaxResponse ?: null,
                ]);

                $tx = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'reference' => $reference,
                    'type' => 'crypto_sell',
                    'direction' => 'in',
                    'title' => "Auto-Converted {$symbol} to NGN",
                    'subtitle' => number_format($netCryptoAmount, 4) . " {$symbol} ({$network}) @ ₦" . number_format($rate, 2),
                    'amount' => $nairaAmount,
                    'fee' => $platformFeeNgn,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => "Crypto {$network} Deposit",
                    'description' => "Auto-converted {$netCryptoAmount} {$symbol} directly to Naira wallet.",
                ]);
            } else {
                // Credit Crypto balance with row-level lock
                $wallet = CryptoWallet::firstOrCreate(
                    ['user_id' => $lockedUser->id, 'symbol' => $symbol],
                    ['balance' => 0]
                );
                $lockedWallet = CryptoWallet::where('id', $wallet->id)->lockForUpdate()->first();
                $newCryptoBal = (float) bcadd(number_format((float) $lockedWallet->balance, 8, '.', ''), $netCryptoStr, 8);
                $lockedWallet->balance = $newCryptoBal;
                $lockedWallet->save();

                $fiatValue = (float) bcmul($netCryptoStr, $rateStr, 2);

                $deposit = CryptoDeposit::create([
                    'user_id' => $lockedUser->id,
                    'tx_hash' => $txHash,
                    'quidax_deposit_id' => $quidaxDepositId,
                    'symbol' => $symbol,
                    'network' => $network,
                    'amount' => $amount,
                    'confirmations' => $confirmations,
                    'platform_fee' => $platformFeeCrypto,
                    'net_amount' => $netCryptoAmount,
                    'status' => 'confirmed',
                    'converted_to_naira' => false,
                    'naira_amount' => null,
                    'rate' => $rate,
                    'fee_config_snapshot' => $config,
                    'quidax_response' => $quidaxResponse ?: null,
                ]);

                $tx = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'reference' => $reference,
                    'type' => 'crypto_buy',
                    'direction' => 'in',
                    'title' => "{$symbol} Deposit Confirmed",
                    'subtitle' => number_format($netCryptoAmount, 4) . " {$symbol} on {$network}",
                    'amount' => $fiatValue,
                    'fee' => $platformFeeNgn,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => "Blockchain {$network}",
                    'description' => "Received {$netCryptoAmount} {$symbol} into Gutter crypto wallet.",
                ]);
            }

            // Record in unified CryptoTransaction table
            if (Schema::hasTable('crypto_transactions')) {
                $cryptoTx = CryptoTransaction::create([
                    'user_id' => $lockedUser->id,
                    'transaction_id' => $tx->id,
                    'reference' => $reference,
                    'quidax_reference' => $quidaxDepositId ?? $txHash,
                    'type' => 'receive_crypto',
                    'symbol' => $symbol,
                    'network' => $network,
                    'destination_type' => 'wallet',
                    'tx_hash' => $txHash,
                    'crypto_amount' => $amount,
                    'fiat_amount' => $autoConvert ? $nairaAmount : (float) bcmul($netCryptoStr, $rateStr, 2),
                    'exchange_rate' => $rate,
                    'network_fee' => 0,
                    'platform_fee_crypto' => $platformFeeCrypto,
                    'platform_fee_ngn' => $platformFeeNgn,
                    'total_crypto_deducted' => 0,
                    'net_crypto_amount' => $netCryptoAmount,
                    'net_fiat_amount' => $autoConvert ? $nairaAmount : (float) bcmul($netCryptoStr, $rateStr, 2),
                    'status' => 'successful',
                    'fee_config_snapshot' => $config,
                    'quidax_response' => $quidaxResponse ?: ['tx_hash' => $txHash, 'confirmations' => $confirmations],
                    'completed_at' => now(),
                ]);

                $this->logAudit(
                    $cryptoTx->id,
                    $lockedUser->id,
                    'webhook',
                    null,
                    'Quidax Deposit Monitor',
                    'deposit_confirmed',
                    'pending',
                    'successful',
                    "Confirmed deposit of {$amount} {$symbol} via {$network}.",
                    ['tx_hash' => $txHash, 'auto_converted' => $autoConvert]
                );
            }

            return [
                'deposit' => $deposit,
                'transaction' => $tx,
                'auto_converted' => $autoConvert,
                'naira_credited' => $nairaAmount,
                'crypto_credited' => $autoConvert ? 0 : $netCryptoAmount,
                'net_amount' => $netCryptoAmount,
                'symbol' => $symbol,
            ];
        });
    }

    /**
     * Convert holding crypto to Naira wallet balance on-demand using Quidax Swap API & decimal-safe math.
     */
    public function convertCryptoToNaira(
        User $user,
        string $symbol,
        float $cryptoAmount,
        string $pin,
        ?string $idempotencyKey = null
    ): array {
        $symbol = strtoupper($symbol);

        // 1. Verify user's PIN
        if (!$user->has_pin || !$user->pin) {
            throw new \InvalidArgumentException('Please create your 4-digit transaction PIN first.');
        }

        if (!Hash::check($pin, $user->pin)) {
            throw new \InvalidArgumentException('Incorrect 4-digit transaction PIN.');
        }

        // 2. Check service availability, maintenance mode & coin status
        $config = $this->assertServiceAndAssetEnabled('convert_to_naira', $symbol);

        if ($cryptoAmount < $config['min_amount']) {
            throw new \InvalidArgumentException("Minimum conversion amount is {$config['min_amount']} {$symbol}.");
        }
        if ($cryptoAmount > $config['max_amount']) {
            throw new \InvalidArgumentException("Maximum single conversion amount is {$config['max_amount']} {$symbol}.");
        }

        // 3. Idempotency check
        if ($idempotencyKey && Schema::hasTable('crypto_transactions')) {
            $existingTx = CryptoTransaction::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $user->id)
                ->first();
            if ($existingTx && $existingTx->transaction) {
                return [
                    'transaction' => $existingTx->transaction,
                    'crypto_transaction' => $existingTx,
                    'crypto_debited' => $existingTx->crypto_amount,
                    'naira_credited' => $existingTx->net_fiat_amount,
                    'fee' => $existingTx->platform_fee_ngn,
                    'rate' => $existingTx->exchange_rate,
                    'new_crypto_balance' => (float) ($user->cryptoWallets()->where('symbol', $symbol)->value('balance') ?? 0),
                    'new_wallet_balance' => (float) $user->fresh()->wallet_balance,
                ];
            }
        }

        $lock = Cache::lock("crypto_convert_user_{$user->id}", 15);
        if (!$lock->get()) {
            throw new \InvalidArgumentException('A conversion request is already in progress. Please wait a moment.');
        }

        try {
            return DB::transaction(function () use ($user, $symbol, $cryptoAmount, $config, $idempotencyKey) {
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                $wallet = CryptoWallet::where('user_id', $lockedUser->id)
                    ->where('symbol', $symbol)
                    ->lockForUpdate()
                    ->first();

                $currentBalanceStr = number_format((float) ($wallet?->balance ?? 0), 8, '.', '');
                $reqAmountStr = number_format($cryptoAmount, 8, '.', '');

                if (bccomp($currentBalanceStr, $reqAmountStr, 8) < 0) {
                    throw new \InvalidArgumentException("Insufficient {$symbol} balance. You have " . number_format((float) $currentBalanceStr, 4) . " {$symbol}.");
                }

                // Request quotation and confirm via Quidax Swap API
                $subAccountId = $this->quidaxService->getOrCreateSubAccount($lockedUser);
                $quoteRes = $this->quidaxService->createSwapQuotation($symbol, 'NGN', $reqAmountStr, null, $subAccountId);
                $confirmRes = $this->quidaxService->confirmSwapQuotation($quoteRes['quotation_id'] ?? ('qdx_quote_' . Str::random(12)), $subAccountId);

                if (empty($confirmRes['success'])) {
                    throw new \InvalidArgumentException($confirmRes['message'] ?? 'Exchange execution failed on Quidax. Your crypto balance was not deducted.');
                }

                // Verify swap execution on backend before crediting Naira wallet
                $swapId = $confirmRes['swap_id'] ?? ('qdx_swap_' . Str::random(12));
                $verification = $this->quidaxService->verifySwapTransaction($swapId, $subAccountId);
                if ($verification && isset($verification['status']) && in_array(strtolower($verification['status']), ['failed', 'cancelled', 'rejected'], true)) {
                    throw new \InvalidArgumentException('Exchange verification failed on Quidax. Your crypto balance remains untouched.');
                }

                $rate = $this->getRate($symbol);
                $rateStr = number_format($rate, 8, '.', '');
                $grossNairaStr = bcmul($reqAmountStr, $rateStr, 8);
                $grossNaira = (float) number_format((float) $grossNairaStr, 2, '.', '');

                $feeStr = $this->calculateNgnFeeFromConfig($config, $grossNairaStr);
                $fee = (float) $feeStr;

                $netNairaStr = bcsub(number_format($grossNaira, 2, '.', ''), $feeStr, 2);
                if (bccomp($netNairaStr, '0.00', 2) < 0) {
                    $netNairaStr = '0.00';
                }
                $netNaira = (float) $netNairaStr;

                // Deduct crypto using decimal-safe math
                $wallet->balance = (float) bcsub($currentBalanceStr, $reqAmountStr, 8);
                $wallet->save();

                // Credit Naira wallet after verified exchange
                $lockedUser->wallet_balance = (float) bcadd(number_format((float) $lockedUser->wallet_balance, 2, '.', ''), $netNairaStr, 2);
                $lockedUser->save();

                $reference = 'SWAP_' . strtoupper(Str::random(10));

                $tx = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'reference' => $reference,
                    'type' => 'crypto_sell',
                    'direction' => 'in',
                    'title' => "Converted {$symbol} to Naira",
                    'subtitle' => "Sold " . number_format($cryptoAmount, 4) . " {$symbol} @ ₦" . number_format($rate, 2),
                    'amount' => $netNaira,
                    'fee' => $fee,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => 'Gutter Instant Swap',
                    'description' => "Converted {$cryptoAmount} {$symbol} to ₦" . number_format($netNaira, 2) . " in Naira Wallet.",
                ]);

                $cryptoTx = null;
                if (Schema::hasTable('crypto_transactions')) {
                    $cryptoTx = CryptoTransaction::create([
                        'user_id' => $lockedUser->id,
                        'transaction_id' => $tx->id,
                        'reference' => $reference,
                        'idempotency_key' => $idempotencyKey,
                        'quidax_reference' => $swapId,
                        'type' => 'convert_to_naira',
                        'symbol' => $symbol,
                        'network' => 'INTERNAL',
                        'destination_type' => 'wallet',
                        'crypto_amount' => $cryptoAmount,
                        'fiat_amount' => $grossNaira,
                        'exchange_rate' => $rate,
                        'network_fee' => 0,
                        'platform_fee_crypto' => 0,
                        'platform_fee_ngn' => $fee,
                        'total_crypto_deducted' => $cryptoAmount,
                        'net_crypto_amount' => $cryptoAmount,
                        'net_fiat_amount' => $netNaira,
                        'status' => 'successful',
                        'fee_config_snapshot' => $config,
                        'quidax_payload' => ['from_currency' => $symbol, 'to_currency' => 'NGN', 'from_amount' => $reqAmountStr],
                        'quidax_response' => $confirmRes['raw'] ?? $confirmRes,
                        'completed_at' => now(),
                    ]);

                    CryptoExchangeOrder::create([
                        'user_id' => $lockedUser->id,
                        'crypto_transaction_id' => $cryptoTx->id,
                        'reference' => $reference,
                        'quidax_quotation_id' => $quoteRes['quotation_id'] ?? null,
                        'quidax_swap_id' => $swapId,
                        'side' => 'sell',
                        'from_currency' => $symbol,
                        'to_currency' => 'NGN',
                        'from_amount' => $cryptoAmount,
                        'to_amount' => $netNaira,
                        'quoted_rate' => $rate,
                        'executed_rate' => $rate,
                        'platform_fee_ngn' => $fee,
                        'platform_fee_crypto' => 0,
                        'net_amount_received' => $netNaira,
                        'fee_config_snapshot' => $config,
                        'status' => 'completed',
                        'quidax_response' => $confirmRes['raw'] ?? $confirmRes,
                        'completed_at' => now(),
                    ]);

                    $this->logAudit(
                        $cryptoTx->id,
                        $lockedUser->id,
                        'user',
                        $lockedUser->id,
                        $lockedUser->first_name . ' ' . $lockedUser->last_name,
                        'convert_to_naira_completed',
                        'pending',
                        'successful',
                        "Converted {$cryptoAmount} {$symbol} to ₦{$netNaira} (Fee: ₦{$fee}, Rate: ₦{$rate}).",
                        ['quidax_swap_id' => $swapId]
                    );
                }

                return [
                    'transaction' => $tx,
                    'crypto_transaction' => $cryptoTx,
                    'crypto_debited' => $cryptoAmount,
                    'naira_credited' => $netNaira,
                    'gross_naira' => $grossNaira,
                    'fee' => $fee,
                    'rate' => $rate,
                    'quidax_reference' => $swapId,
                    'new_crypto_balance' => (float) $wallet->fresh()->balance,
                    'new_wallet_balance' => (float) $lockedUser->fresh()->wallet_balance,
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Buy crypto using Naira wallet balance via Quidax Swap API & decimal-safe math.
     */
    public function buyCryptoWithNaira(
        User $user,
        string $symbol,
        float $nairaAmount,
        string $pin,
        ?string $idempotencyKey = null
    ): array {
        $symbol = strtoupper($symbol);

        if (!$user->has_pin || !$user->pin) {
            throw new \InvalidArgumentException('Please create your 4-digit transaction PIN first.');
        }

        if (!Hash::check($pin, $user->pin)) {
            throw new \InvalidArgumentException('Incorrect 4-digit transaction PIN.');
        }

        $config = $this->assertServiceAndAssetEnabled('buy_crypto', $symbol);

        if ($nairaAmount < $config['min_amount']) {
            throw new \InvalidArgumentException('Minimum purchase amount is ₦' . number_format($config['min_amount'], 2) . '.');
        }
        if ($nairaAmount > $config['max_amount']) {
            throw new \InvalidArgumentException('Maximum single purchase amount is ₦' . number_format($config['max_amount'], 2) . '.');
        }

        if ($idempotencyKey && Schema::hasTable('crypto_transactions')) {
            $existingTx = CryptoTransaction::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $user->id)
                ->first();
            if ($existingTx && $existingTx->transaction) {
                return [
                    'transaction' => $existingTx->transaction,
                    'crypto_transaction' => $existingTx,
                    'naira_debited' => $existingTx->fiat_amount,
                    'crypto_credited' => $existingTx->net_crypto_amount,
                    'fee' => $existingTx->platform_fee_ngn,
                    'rate' => $existingTx->exchange_rate,
                    'new_crypto_balance' => (float) ($user->cryptoWallets()->where('symbol', $symbol)->value('balance') ?? 0),
                    'new_wallet_balance' => (float) $user->fresh()->wallet_balance,
                ];
            }
        }

        $lock = Cache::lock("crypto_buy_user_{$user->id}", 15);
        if (!$lock->get()) {
            throw new \InvalidArgumentException('A purchase request is already in progress. Please wait a moment.');
        }

        try {
            return DB::transaction(function () use ($user, $symbol, $nairaAmount, $config, $idempotencyKey) {
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                $walletBalanceStr = number_format((float) ($lockedUser->wallet_balance ?? 0), 2, '.', '');
                $nairaReqStr = number_format($nairaAmount, 2, '.', '');

                if (bccomp($walletBalanceStr, $nairaReqStr, 2) < 0) {
                    throw new \InvalidArgumentException('Insufficient Naira wallet balance.');
                }

                $rate = $this->getRate($symbol);
                $rateStr = number_format($rate, 8, '.', '');
                $feeStr = $this->calculateNgnFeeFromConfig($config, $nairaReqStr);
                $fee = (float) $feeStr;

                $netToSpendStr = bcsub($nairaReqStr, $feeStr, 8);
                if (bccomp($netToSpendStr, '0', 8) <= 0) {
                    throw new \InvalidArgumentException('Amount is too low to cover the platform fee of ₦' . number_format($fee, 2) . '.');
                }

                $cryptoBoughtStr = bcdiv($netToSpendStr, $rateStr, 8);
                $cryptoBought = (float) $cryptoBoughtStr;

                // Execute purchase through Quidax Swap Quotation & Confirm
                $subAccountId = $this->quidaxService->getOrCreateSubAccount($lockedUser);
                $quoteRes = $this->quidaxService->createSwapQuotation('NGN', $symbol, number_format((float) $netToSpendStr, 2, '.', ''), null, $subAccountId);
                $confirmRes = $this->quidaxService->confirmSwapQuotation($quoteRes['quotation_id'] ?? ('qdx_quote_' . Str::random(12)), $subAccountId);

                if (empty($confirmRes['success'])) {
                    throw new \InvalidArgumentException($confirmRes['message'] ?? 'Purchase execution failed on Quidax. Your Naira wallet was not debited.');
                }

                $swapId = $confirmRes['swap_id'] ?? ('qdx_swap_' . Str::random(12));
                $verification = $this->quidaxService->verifySwapTransaction($swapId, $subAccountId);
                if ($verification && isset($verification['status']) && in_array(strtolower($verification['status']), ['failed', 'cancelled', 'rejected'], true)) {
                    throw new \InvalidArgumentException('Exchange verification failed on Quidax. Your Naira wallet was not debited.');
                }

                // Deduct Naira
                $lockedUser->wallet_balance = (float) bcsub($walletBalanceStr, $nairaReqStr, 2);
                $lockedUser->save();

                // Credit Crypto Wallet only after verified execution
                $cryptoWallet = CryptoWallet::firstOrCreate(
                    ['user_id' => $lockedUser->id, 'symbol' => $symbol],
                    ['balance' => 0]
                );
                $lockedCryptoWallet = CryptoWallet::where('id', $cryptoWallet->id)->lockForUpdate()->first();
                $lockedCryptoWallet->balance = (float) bcadd(number_format((float) $lockedCryptoWallet->balance, 8, '.', ''), $cryptoBoughtStr, 8);
                $lockedCryptoWallet->save();

                $reference = 'BUY_' . strtoupper(Str::random(10));

                $tx = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'reference' => $reference,
                    'type' => 'crypto_buy',
                    'direction' => 'out',
                    'title' => "Bought {$symbol}",
                    'subtitle' => "Paid ₦" . number_format($nairaAmount, 2) . " for " . number_format($cryptoBought, 6) . " {$symbol}",
                    'amount' => $nairaAmount,
                    'fee' => $fee,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => 'Gutter Crypto',
                    'description' => "Purchased {$cryptoBought} {$symbol} using Naira wallet.",
                ]);

                $cryptoTx = null;
                if (Schema::hasTable('crypto_transactions')) {
                    $cryptoTx = CryptoTransaction::create([
                        'user_id' => $lockedUser->id,
                        'transaction_id' => $tx->id,
                        'reference' => $reference,
                        'idempotency_key' => $idempotencyKey,
                        'quidax_reference' => $swapId,
                        'type' => 'buy_crypto',
                        'symbol' => $symbol,
                        'network' => 'INTERNAL',
                        'destination_type' => 'wallet',
                        'crypto_amount' => $cryptoBought,
                        'fiat_amount' => $nairaAmount,
                        'exchange_rate' => $rate,
                        'network_fee' => 0,
                        'platform_fee_crypto' => 0,
                        'platform_fee_ngn' => $fee,
                        'total_crypto_deducted' => 0,
                        'net_crypto_amount' => $cryptoBought,
                        'net_fiat_amount' => (float) $netToSpendStr,
                        'status' => 'successful',
                        'fee_config_snapshot' => $config,
                        'quidax_payload' => ['from_currency' => 'NGN', 'to_currency' => $symbol, 'from_amount' => $nairaReqStr],
                        'quidax_response' => $confirmRes['raw'] ?? $confirmRes,
                        'completed_at' => now(),
                    ]);

                    CryptoExchangeOrder::create([
                        'user_id' => $lockedUser->id,
                        'crypto_transaction_id' => $cryptoTx->id,
                        'reference' => $reference,
                        'quidax_quotation_id' => $quoteRes['quotation_id'] ?? null,
                        'quidax_swap_id' => $swapId,
                        'side' => 'buy',
                        'from_currency' => 'NGN',
                        'to_currency' => $symbol,
                        'from_amount' => $nairaAmount,
                        'to_amount' => $cryptoBought,
                        'quoted_rate' => $rate,
                        'executed_rate' => $rate,
                        'platform_fee_ngn' => $fee,
                        'platform_fee_crypto' => 0,
                        'net_amount_received' => $cryptoBought,
                        'fee_config_snapshot' => $config,
                        'status' => 'completed',
                        'quidax_response' => $confirmRes['raw'] ?? $confirmRes,
                        'completed_at' => now(),
                    ]);

                    $this->logAudit(
                        $cryptoTx->id,
                        $lockedUser->id,
                        'user',
                        $lockedUser->id,
                        $lockedUser->first_name . ' ' . $lockedUser->last_name,
                        'buy_crypto_completed',
                        'pending',
                        'successful',
                        "Purchased {$cryptoBought} {$symbol} for ₦{$nairaAmount} (Fee: ₦{$fee}, Rate: ₦{$rate}).",
                        ['quidax_swap_id' => $swapId]
                    );
                }

                return [
                    'transaction' => $tx,
                    'crypto_transaction' => $cryptoTx,
                    'naira_debited' => $nairaAmount,
                    'crypto_credited' => $cryptoBought,
                    'fee' => $fee,
                    'rate' => $rate,
                    'quidax_reference' => $swapId,
                    'new_crypto_balance' => (float) $lockedCryptoWallet->fresh()->balance,
                    'new_wallet_balance' => (float) $lockedUser->fresh()->wallet_balance,
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Get user's crypto portfolio summary.
     */
    public function getUserPortfolio(User $user): array
    {
        $wallets = CryptoWallet::where('user_id', $user->id)->get()->keyBy('symbol');
        $market = $this->getMarketData();

        $balances = [];
        $totalNairaValue = '0.00';

        foreach ($market as $coin) {
            $sym = $coin['symbol'];
            $wallet = $wallets->get($sym);
            $amount = (float) ($wallet?->balance ?? 0.0);
            $locked = (float) ($wallet?->locked_balance ?? 0.0);
            $amtStr = number_format($amount, 8, '.', '');
            $priceStr = number_format((float) $coin['price'], 8, '.', '');
            $valueNairaStr = bcmul($amtStr, $priceStr, 2);
            $totalNairaValue = bcadd($totalNairaValue, $valueNairaStr, 2);

            $balances[] = [
                'symbol' => $sym,
                'name' => $coin['name'],
                'amount' => $amount,
                'locked_amount' => $locked,
                'price' => $coin['price'],
                'value_naira' => (float) $valueNairaStr,
                'change_24h' => $coin['change_24h'],
                'sparkline' => $coin['sparkline'],
                'enabled' => $coin['enabled'] ?? true,
            ];
        }

        return [
            'portfolio_value' => (float) $totalNairaValue,
            'portfolio_change' => 2.4,
            'auto_convert_crypto' => (bool) $user->auto_convert_crypto,
            'balances' => $balances,
        ];
    }

    /**
     * Get transfer fee breakdown for sending crypto (Quidax network fee + configurable platform fee).
     */
    public function getSendFeeBreakdown(string $symbol, string $destinationType, ?string $network = null, float $amount = 0.0): array
    {
        $symbol = strtoupper($symbol);
        $rate = $this->getRate($symbol);

        if ($destinationType === 'gutter_user') {
            return [
                'symbol' => $symbol,
                'network' => 'INTERNAL',
                'network_fee' => 0.0,
                'platform_fee' => 0.0,
                'platform_fee_ngn' => 0.0,
                'fee' => 0.0,
                'total_fee' => 0.0,
                'fee_naira' => 0.0,
                'rate' => $rate,
                'min_withdrawal' => 0.0001,
                'max_withdrawal' => 100000.0,
            ];
        }

        $network = strtoupper($network ?? 'TRC20');
        $config = $this->getFeeConfig('send_crypto');
        $coins = $config['supported_coins'] ?? [];

        // 1. Quidax network withdrawal fee (check live Quidax fee_rule first, then admin coin config, then standard table)
        $liveNetFee = $this->quidaxService?->getNetworkWithdrawalFee($symbol, $network, $amount > 0 ? $amount : 10.0);
        if ($liveNetFee !== null) {
            $networkFee = $liveNetFee;
        } elseif (isset($coins[$symbol]['networks'][$network]['network_fee'])) {
            $networkFee = (float) $coins[$symbol]['networks'][$network]['network_fee'];
        } else {
            $networkFees = [
                'USDT' => ['TRC20' => 1.00, 'BEP20' => 0.50, 'ERC20' => 4.00],
                'BTC' => ['BTC' => 0.0002],
                'ETH' => ['ERC20' => 0.0015],
                'SOL' => ['SOL' => 0.005],
                'BNB' => ['BEP20' => 0.001],
            ];
            $networkFee = $networkFees[$symbol][$network] ?? 1.00;
        }

        // 2. Platform withdrawal fee (if amount > 0 and admin configured fixed_fee_crypto or custom percentage beyond standard)
        $platformFeeCrypto = (float) ($config['fixed_fee_crypto'] ?? 0.0);
        $platformFeeNgn = (float) bcmul(number_format($platformFeeCrypto, 8, '.', ''), number_format($rate, 8, '.', ''), 2);

        $totalFeeStr = bcadd(number_format($networkFee, 8, '.', ''), number_format($platformFeeCrypto, 8, '.', ''), 8);
        $totalFee = (float) $totalFeeStr;
        $totalFeeNaira = (float) bcmul($totalFeeStr, number_format($rate, 8, '.', ''), 2);

        return [
            'symbol' => $symbol,
            'network' => $network,
            'network_fee' => $networkFee,
            'platform_fee' => $platformFeeCrypto,
            'platform_fee_ngn' => $platformFeeNgn,
            'fee' => $totalFee,
            'total_fee' => $totalFee,
            'fee_naira' => $totalFeeNaira,
            'rate' => $rate,
            'min_withdrawal' => (float) ($config['min_withdrawal_amount'] ?? $config['min_amount'] ?? 0.0001),
            'max_withdrawal' => (float) ($config['max_withdrawal_amount'] ?? $config['max_amount'] ?? 100000.0),
            'config_snapshot' => $config,
        ];
    }

    /**
     * Get transfer fee for sending crypto (backward compatible float return).
     */
    public function getSendFee(string $symbol, string $destinationType, ?string $network = null): float
    {
        $breakdown = $this->getSendFeeBreakdown($symbol, $destinationType, $network);
        return (float) $breakdown['fee'];
    }

    /**
     * Send crypto to another Gutter user OR external blockchain address via Quidax.
     */
    public function sendCrypto(
        User $sender,
        string $symbol,
        string $destinationType,
        string $recipient,
        float $amount,
        string $pin,
        ?string $network = null,
        ?string $memo = null,
        ?string $idempotencyKey = null
    ): array {
        $symbol = strtoupper($symbol);

        // 1. PIN verification
        if (!$sender->has_pin || !$sender->pin) {
            throw new \InvalidArgumentException('Please create your 4-digit transaction PIN first.');
        }

        if (!Hash::check($pin, $sender->pin)) {
            throw new \InvalidArgumentException('Incorrect 4-digit transaction PIN.');
        }

        $networkCode = $destinationType === 'gutter_user' ? 'INTERNAL' : strtoupper($network ?? 'TRC20');
        $config = $this->assertServiceAndAssetEnabled('send_crypto', $symbol, $networkCode);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be greater than zero.');
        }

        $minWd = (float) ($config['min_withdrawal_amount'] ?? $config['min_amount'] ?? 0.0001);
        $maxWd = (float) ($config['max_withdrawal_amount'] ?? $config['max_amount'] ?? 100000.0);
        if ($amount < $minWd) {
            throw new \InvalidArgumentException("Minimum withdrawal amount is {$minWd} {$symbol}.");
        }
        if ($amount > $maxWd) {
            throw new \InvalidArgumentException("Maximum withdrawal amount is {$maxWd} {$symbol}.");
        }

        // Validate external blockchain address format
        if ($destinationType === 'external_address') {
            $validation = $this->quidaxService->validateWalletAddress($symbol, $networkCode, $recipient);
            if (!$validation['valid']) {
                throw new \InvalidArgumentException($validation['message']);
            }
        }

        // Prevent duplicate withdrawal via idempotency key
        if ($idempotencyKey && Schema::hasTable('crypto_transactions')) {
            $existingTx = CryptoTransaction::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $sender->id)
                ->first();
            if ($existingTx && $existingTx->transaction) {
                return [
                    'transaction' => $existingTx->transaction,
                    'crypto_transaction' => $existingTx,
                    'destination_type' => $destinationType,
                    'recipient' => $recipient,
                    'network' => $networkCode,
                    'amount' => $existingTx->crypto_amount,
                    'fee' => (float) bcadd(number_format($existingTx->network_fee, 8, '.', ''), number_format($existingTx->platform_fee_crypto, 8, '.', ''), 8),
                    'symbol' => $symbol,
                    'new_crypto_balance' => (float) ($sender->cryptoWallets()->where('symbol', $symbol)->value('balance') ?? 0),
                ];
            }
        }

        // Transaction lock to prevent race conditions / double withdrawals
        $lock = Cache::lock("crypto_send_user_{$sender->id}", 15);
        if (!$lock->get()) {
            throw new \InvalidArgumentException('Another withdrawal request is currently processing. Please wait a moment.');
        }

        try {
            $feeBreakdown = $this->getSendFeeBreakdown($symbol, $destinationType, $networkCode, $amount);
            $fee = (float) $feeBreakdown['fee'];
            $networkFee = (float) $feeBreakdown['network_fee'];
            $platformFeeCrypto = (float) $feeBreakdown['platform_fee'];
            $rate = (float) $feeBreakdown['rate'];

            $amtStr = number_format($amount, 8, '.', '');
            $feeStr = number_format($fee, 8, '.', '');
            $totalDebitStr = bcadd($amtStr, $feeStr, 8);
            $totalDebit = (float) $totalDebitStr;

            if ($destinationType === 'gutter_user') {
                $cleanRecipient = strtolower(trim($recipient));
                $recipientUser = User::where('email', $cleanRecipient)
                    ->orWhere('phone', $cleanRecipient)
                    ->first();

                if (!$recipientUser && is_numeric($recipient)) {
                    $recipientUser = User::where('id', (int) $recipient)->first();
                }

                if (!$recipientUser) {
                    throw new \InvalidArgumentException('Gutter user not found. Check the email or phone number.');
                }

                if ($recipientUser->id === $sender->id) {
                    throw new \InvalidArgumentException('You cannot transfer crypto to yourself.');
                }

                return DB::transaction(function () use ($sender, $recipientUser, $symbol, $amount, $amtStr, $rate, $config, $idempotencyKey) {
                    $senderWallet = CryptoWallet::firstOrCreate(
                        ['user_id' => $sender->id, 'symbol' => $symbol],
                        ['balance' => 0]
                    );
                    $lockedSenderWallet = CryptoWallet::where('id', $senderWallet->id)->lockForUpdate()->first();
                    $senderBalStr = number_format((float) $lockedSenderWallet->balance, 8, '.', '');

                    if (bccomp($senderBalStr, $amtStr, 8) < 0) {
                        throw new \InvalidArgumentException("Insufficient {$symbol} balance. Need " . number_format($amount, 4) . " {$symbol}, but you have " . number_format((float) $senderBalStr, 4) . " {$symbol}.");
                    }

                    $lockedSenderWallet->balance = (float) bcsub($senderBalStr, $amtStr, 8);
                    $lockedSenderWallet->save();

                    $lockedRecipientUser = User::where('id', $recipientUser->id)->lockForUpdate()->firstOrFail();
                    $recipientAutoConvert = (bool) $lockedRecipientUser->auto_convert_crypto;
                    $recipientName = trim($lockedRecipientUser->first_name . ' ' . $lockedRecipientUser->last_name) ?: $lockedRecipientUser->email;
                    $senderName = trim($sender->first_name . ' ' . $sender->last_name) ?: $sender->email;
                    $fiatEquivalent = (float) bcmul($amtStr, number_format($rate, 8, '.', ''), 2);

                    if ($recipientAutoConvert) {
                        $lockedRecipientUser->wallet_balance = (float) bcadd(number_format((float) $lockedRecipientUser->wallet_balance, 2, '.', ''), number_format($fiatEquivalent, 2, '.', ''), 2);
                        $lockedRecipientUser->save();

                        Transaction::create([
                            'user_id' => $lockedRecipientUser->id,
                            'reference' => 'CRYPTO_' . strtoupper(Str::random(10)),
                            'type' => 'crypto_sell',
                            'direction' => 'in',
                            'title' => "Received {$symbol} (Auto-Converted)",
                            'subtitle' => "From {$senderName} · Credited ₦" . number_format($fiatEquivalent, 2),
                            'amount' => $fiatEquivalent,
                            'currency' => 'NGN',
                            'status' => 'successful',
                            'counterparty' => $senderName,
                            'description' => "Received {$amount} {$symbol} from {$senderName}, auto-converted to Naira.",
                        ]);
                    } else {
                        $recipientWallet = CryptoWallet::firstOrCreate(
                            ['user_id' => $lockedRecipientUser->id, 'symbol' => $symbol],
                            ['balance' => 0]
                        );
                        $lockedRecipientWallet = CryptoWallet::where('id', $recipientWallet->id)->lockForUpdate()->first();
                        $lockedRecipientWallet->balance = (float) bcadd(number_format((float) $lockedRecipientWallet->balance, 8, '.', ''), $amtStr, 8);
                        $lockedRecipientWallet->save();

                        Transaction::create([
                            'user_id' => $lockedRecipientUser->id,
                            'reference' => 'CRYPTO_' . strtoupper(Str::random(10)),
                            'type' => 'crypto_buy',
                            'direction' => 'in',
                            'title' => "Received {$symbol}",
                            'subtitle' => "From {$senderName} · " . number_format($amount, 4) . " {$symbol}",
                            'amount' => $fiatEquivalent,
                            'currency' => 'NGN',
                            'status' => 'successful',
                            'counterparty' => $senderName,
                            'description' => "Received {$amount} {$symbol} from {$senderName}.",
                        ]);
                    }

                    $reference = 'SEND_' . strtoupper(Str::random(10));
                    $senderTx = Transaction::create([
                        'user_id' => $sender->id,
                        'reference' => $reference,
                        'type' => 'crypto_sell',
                        'direction' => 'out',
                        'title' => "Sent {$symbol} to {$recipientName}",
                        'subtitle' => number_format($amount, 4) . " {$symbol} · Free (Gutter P2P)",
                        'amount' => $fiatEquivalent,
                        'fee' => 0,
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'counterparty' => $recipientName,
                        'description' => "Transferred {$amount} {$symbol} to {$recipientName} instantly.",
                    ]);

                    if (Schema::hasTable('crypto_transactions')) {
                        $cryptoTx = CryptoTransaction::create([
                            'user_id' => $sender->id,
                            'transaction_id' => $senderTx->id,
                            'reference' => $reference,
                            'idempotency_key' => $idempotencyKey,
                            'type' => 'send_crypto',
                            'symbol' => $symbol,
                            'network' => 'INTERNAL',
                            'destination_type' => 'gutter_user',
                            'wallet_address' => $lockedRecipientUser->email,
                            'crypto_amount' => $amount,
                            'fiat_amount' => $fiatEquivalent,
                            'exchange_rate' => $rate,
                            'network_fee' => 0,
                            'platform_fee_crypto' => 0,
                            'platform_fee_ngn' => 0,
                            'total_crypto_deducted' => $amount,
                            'net_crypto_amount' => $amount,
                            'net_fiat_amount' => $fiatEquivalent,
                            'status' => 'successful',
                            'fee_config_snapshot' => $config,
                            'completed_at' => now(),
                        ]);

                        CryptoWithdrawal::create([
                            'user_id' => $sender->id,
                            'crypto_transaction_id' => $cryptoTx->id,
                            'reference' => $reference,
                            'idempotency_key' => $idempotencyKey,
                            'symbol' => $symbol,
                            'network' => 'INTERNAL',
                            'destination_type' => 'gutter_user',
                            'recipient_address' => $lockedRecipientUser->email,
                            'amount' => $amount,
                            'network_fee' => 0,
                            'platform_fee' => 0,
                            'platform_fee_ngn' => 0,
                            'total_amount' => $amount,
                            'exchange_rate' => $rate,
                            'status' => 'successful',
                            'fee_config_snapshot' => $config,
                            'completed_at' => now(),
                        ]);

                        $this->logAudit(
                            $cryptoTx->id,
                            $sender->id,
                            'user',
                            $sender->id,
                            $senderName,
                            'p2p_crypto_transfer_completed',
                            'pending',
                            'successful',
                            "Sent {$amount} {$symbol} to Gutter user {$recipientName}."
                        );
                    }

                    return [
                        'transaction' => $senderTx,
                        'destination_type' => 'gutter_user',
                        'recipient' => $recipientName,
                        'amount' => $amount,
                        'fee' => 0,
                        'symbol' => $symbol,
                        'new_crypto_balance' => (float) $lockedSenderWallet->fresh()->balance,
                    ];
                });
            }

            // External Blockchain withdrawal via Quidax
            return DB::transaction(function () use (
                $sender,
                $symbol,
                $networkCode,
                $recipient,
                $amount,
                $amtStr,
                $fee,
                $networkFee,
                $platformFeeCrypto,
                $totalDebit,
                $totalDebitStr,
                $memo,
                $rate,
                $config,
                $idempotencyKey
            ) {
                $senderWallet = CryptoWallet::firstOrCreate(
                    ['user_id' => $sender->id, 'symbol' => $symbol],
                    ['balance' => 0]
                );
                $lockedSenderWallet = CryptoWallet::where('id', $senderWallet->id)->lockForUpdate()->first();
                $senderBalStr = number_format((float) $lockedSenderWallet->balance, 8, '.', '');

                if (bccomp($senderBalStr, $totalDebitStr, 8) < 0) {
                    $needed = number_format($totalDebit, 4);
                    $have = number_format((float) $senderBalStr, 4);
                    throw new \InvalidArgumentException("Insufficient {$symbol} balance. Need {$needed} {$symbol} (incl. {$fee} fee), but you have {$have} {$symbol}.");
                }

                // Deduct total amount (recipient amount + network fee + platform fee)
                $lockedSenderWallet->balance = (float) bcsub($senderBalStr, $totalDebitStr, 8);
                $lockedSenderWallet->save();

                $reference = 'WITHDRAW_' . strtoupper(Str::random(10));

                $qdxResult = $this->quidaxService->withdrawCrypto(
                    $sender,
                    $symbol,
                    $networkCode,
                    $recipient,
                    $amount,
                    $memo,
                    $reference
                );

                $shortAddress = strlen($recipient) > 12
                    ? substr($recipient, 0, 6) . '...' . substr($recipient, -4)
                    : $recipient;

                $fiatTotal = (float) bcmul($totalDebitStr, number_format($rate, 8, '.', ''), 2);
                $fiatFee = (float) bcmul(number_format($fee, 8, '.', ''), number_format($rate, 8, '.', ''), 2);
                $platformFeeNgn = (float) bcmul(number_format($platformFeeCrypto, 8, '.', ''), number_format($rate, 8, '.', ''), 2);
                $wdStatus = $qdxResult['status'] ?? 'successful';

                $tx = Transaction::create([
                    'user_id' => $sender->id,
                    'reference' => $reference,
                    'type' => 'crypto_sell',
                    'direction' => 'out',
                    'title' => "Sent {$symbol} ({$networkCode})",
                    'subtitle' => "To {$shortAddress} · Fee: {$fee} {$symbol}",
                    'amount' => $fiatTotal,
                    'fee' => $fiatFee,
                    'currency' => 'NGN',
                    'status' => $wdStatus === 'successful' ? 'successful' : ($wdStatus === 'processing' ? 'pending' : $wdStatus),
                    'counterparty' => "{$networkCode}: {$shortAddress}",
                    'description' => "Blockchain withdrawal of {$amount} {$symbol} to {$recipient} via {$networkCode}.",
                ]);

                if (Schema::hasTable('crypto_transactions')) {
                    $cryptoTx = CryptoTransaction::create([
                        'user_id' => $sender->id,
                        'transaction_id' => $tx->id,
                        'reference' => $reference,
                        'idempotency_key' => $idempotencyKey,
                        'quidax_reference' => $qdxResult['quidax_id'] ?? $qdxResult['tx_id'] ?? null,
                        'type' => 'send_crypto',
                        'symbol' => $symbol,
                        'network' => $networkCode,
                        'destination_type' => 'external_address',
                        'wallet_address' => $recipient,
                        'memo' => $memo,
                        'tx_hash' => $qdxResult['tx_id'] ?? null,
                        'crypto_amount' => $amount,
                        'fiat_amount' => $fiatTotal,
                        'exchange_rate' => $rate,
                        'network_fee' => $networkFee,
                        'platform_fee_crypto' => $platformFeeCrypto,
                        'platform_fee_ngn' => $platformFeeNgn,
                        'total_crypto_deducted' => $totalDebit,
                        'net_crypto_amount' => $amount,
                        'net_fiat_amount' => (float) bcmul($amtStr, number_format($rate, 8, '.', ''), 2),
                        'status' => $wdStatus,
                        'fee_config_snapshot' => $config,
                        'quidax_payload' => [
                            'currency' => $symbol,
                            'network' => $networkCode,
                            'recipient' => $recipient,
                            'amount' => $amount,
                            'memo' => $memo,
                        ],
                        'quidax_response' => $qdxResult['raw'] ?? $qdxResult,
                        'completed_at' => $wdStatus === 'successful' ? now() : null,
                    ]);

                    CryptoWithdrawal::create([
                        'user_id' => $sender->id,
                        'crypto_transaction_id' => $cryptoTx->id,
                        'reference' => $reference,
                        'idempotency_key' => $idempotencyKey,
                        'quidax_withdrawal_id' => $qdxResult['quidax_id'] ?? null,
                        'symbol' => $symbol,
                        'network' => $networkCode,
                        'destination_type' => 'external_address',
                        'recipient_address' => $recipient,
                        'memo' => $memo,
                        'amount' => $amount,
                        'network_fee' => $networkFee,
                        'platform_fee' => $platformFeeCrypto,
                        'platform_fee_ngn' => $platformFeeNgn,
                        'total_amount' => $totalDebit,
                        'exchange_rate' => $rate,
                        'tx_hash' => $qdxResult['tx_id'] ?? null,
                        'status' => $wdStatus,
                        'fee_config_snapshot' => $config,
                        'quidax_response' => $qdxResult['raw'] ?? $qdxResult,
                        'completed_at' => $wdStatus === 'successful' ? now() : null,
                    ]);

                    $this->logAudit(
                        $cryptoTx->id,
                        $sender->id,
                        'user',
                        $sender->id,
                        $sender->first_name . ' ' . $sender->last_name,
                        'external_withdrawal_initiated',
                        'pending',
                        $wdStatus,
                        "Initiated withdrawal of {$amount} {$symbol} (Total deducted: {$totalDebit} {$symbol}) to {$recipient} on {$networkCode}.",
                        ['quidax_id' => $qdxResult['quidax_id'] ?? null, 'tx_id' => $qdxResult['tx_id'] ?? null]
                    );
                }

                return [
                    'transaction' => $tx,
                    'destination_type' => 'external_address',
                    'recipient' => $recipient,
                    'network' => $networkCode,
                    'amount' => $amount,
                    'fee' => $fee,
                    'network_fee' => $networkFee,
                    'platform_fee' => $platformFeeCrypto,
                    'total_deducted' => $totalDebit,
                    'symbol' => $symbol,
                    'tx_id' => $qdxResult['tx_id'] ?? null,
                    'status' => $wdStatus,
                    'new_crypto_balance' => (float) $lockedSenderWallet->fresh()->balance,
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Handle incoming Quidax webhook events (Deposits, Withdrawals, Swaps) with idempotency & audit logging.
     */
    public function handleWebhookEvent(array $parsed, array $rawPayload = [], ?string $signature = null, ?string $ip = null): array
    {
        $event = $parsed['event'] ?? 'unknown';
        $quidaxId = $parsed['quidax_id'] ?? $parsed['tx_hash'] ?? $parsed['reference'] ?? Str::random(16);
        $status = $parsed['status'] ?? 'unknown';
        $payloadHash = hash('sha256', "{$event}|{$quidaxId}|{$status}");

        // Log and enforce webhook idempotency
        $webhookLog = null;
        if (Schema::hasTable('crypto_webhook_logs')) {
            $existingLog = CryptoWebhookLog::where('payload_hash', $payloadHash)->first();
            if ($existingLog && $existingLog->processing_status === 'processed') {
                $existingLog->increment('attempts');
                return [
                    'status' => 'duplicate',
                    'message' => 'Webhook event already processed.',
                ];
            }

            $webhookLog = CryptoWebhookLog::updateOrCreate(
                ['payload_hash' => $payloadHash],
                [
                    'provider' => 'quidax',
                    'event_id' => (string) $quidaxId,
                    'event_type' => $event,
                    'reference' => $parsed['reference'] ?? $parsed['tx_hash'] ?? null,
                    'signature' => $signature,
                    'signature_valid' => true,
                    'payload' => $rawPayload,
                    'processing_status' => 'received',
                    'ip_address' => $ip,
                ]
            );
        }

        $category = $parsed['category'] ?? 'deposit';

        if ($category === 'deposit') {
            if ($parsed['status'] === 'failed') {
                $webhookLog?->update([
                    'processing_status' => 'processed',
                    'processing_notes' => 'Deposit failed event recorded.',
                    'processed_at' => now(),
                ]);
                return ['status' => 'failed_deposit_logged'];
            }

            $depositResult = $this->processDeposit(
                $parsed['user'],
                $parsed['symbol'],
                $parsed['network'],
                $parsed['amount'],
                $parsed['tx_hash'],
                $parsed['quidax_id'] ?? null,
                $parsed['confirmations'] ?? 1,
                $parsed['raw'] ?? []
            );

            $webhookLog?->update([
                'processing_status' => 'processed',
                'processing_notes' => 'Deposit processed successfully.',
                'processed_at' => now(),
            ]);

            return [
                'status' => 'success',
                'data' => $depositResult,
            ];
        }

        if ($category === 'withdrawal') {
            $result = $this->processWithdrawalWebhook($parsed);
            $webhookLog?->update([
                'processing_status' => 'processed',
                'processing_notes' => $result['notes'] ?? 'Withdrawal status updated.',
                'processed_at' => now(),
            ]);
            return [
                'status' => 'success',
                'data' => $result,
            ];
        }

        if ($category === 'swap') {
            $result = $this->processSwapWebhook($parsed);
            $webhookLog?->update([
                'processing_status' => 'processed',
                'processing_notes' => $result['notes'] ?? 'Swap status updated.',
                'processed_at' => now(),
            ]);
            return [
                'status' => 'success',
                'data' => $result,
            ];
        }

        $webhookLog?->update([
            'processing_status' => 'ignored',
            'processed_at' => now(),
        ]);

        return ['status' => 'ignored'];
    }

    /**
     * Process a Quidax withdrawal status update webhook.
     */
    protected function processWithdrawalWebhook(array $parsed): array
    {
        if (!Schema::hasTable('crypto_transactions')) {
            return ['status' => 'ignored', 'notes' => 'Table not migrated'];
        }

        $qdxId = $parsed['quidax_id'] ?? null;
        $ref = $parsed['reference'] ?? null;
        $newStatus = $parsed['status']; // successful, rejected, failed

        $cryptoTx = CryptoTransaction::where('type', 'send_crypto')
            ->where(function ($q) use ($qdxId, $ref) {
                if ($qdxId) {
                    $q->where('quidax_reference', $qdxId);
                }
                if ($ref) {
                    $q->orWhere('reference', $ref);
                }
            })
            ->first();

        if (!$cryptoTx) {
            return ['status' => 'not_found', 'notes' => 'Matching withdrawal transaction not found'];
        }

        $oldStatus = $cryptoTx->status;
        if ($oldStatus === $newStatus) {
            return ['status' => 'unchanged', 'notes' => "Already in {$newStatus} status"];
        }

        $cryptoTx->update([
            'status' => $newStatus,
            'tx_hash' => $parsed['tx_hash'] ?? $cryptoTx->tx_hash,
            'failure_reason' => $parsed['reason'] ?? $cryptoTx->failure_reason,
            'quidax_response' => $parsed['raw'] ?? $cryptoTx->quidax_response,
            'completed_at' => $newStatus === 'successful' ? now() : $cryptoTx->completed_at,
        ]);

        if ($cryptoTx->withdrawal) {
            $cryptoTx->withdrawal->update([
                'status' => $newStatus,
                'tx_hash' => $parsed['tx_hash'] ?? $cryptoTx->withdrawal->tx_hash,
                'rejection_reason' => $parsed['reason'] ?? $cryptoTx->withdrawal->rejection_reason,
                'quidax_response' => $parsed['raw'] ?? $cryptoTx->withdrawal->quidax_response,
                'completed_at' => $newStatus === 'successful' ? now() : $cryptoTx->withdrawal->completed_at,
            ]);
        }

        if ($cryptoTx->transaction) {
            $cryptoTx->transaction->update([
                'status' => $newStatus === 'successful' ? 'successful' : 'failed',
            ]);
        }

        $this->logAudit(
            $cryptoTx->id,
            $cryptoTx->user_id,
            'webhook',
            null,
            'Quidax Webhook',
            'withdrawal_status_updated',
            $oldStatus,
            $newStatus,
            "Quidax webhook updated withdrawal #{$cryptoTx->reference} from {$oldStatus} to {$newStatus}."
        );

        return [
            'status' => 'updated',
            'reference' => $cryptoTx->reference,
            'new_status' => $newStatus,
            'notes' => "Updated {$cryptoTx->reference} to {$newStatus}",
        ];
    }

    /**
     * Process a Quidax swap / instant order webhook.
     */
    protected function processSwapWebhook(array $parsed): array
    {
        if (!Schema::hasTable('crypto_exchange_orders')) {
            return ['status' => 'ignored'];
        }

        $qdxId = $parsed['quidax_id'] ?? null;
        $ref = $parsed['reference'] ?? null;
        $newStatus = $parsed['status'] === 'completed' ? 'successful' : 'failed';

        $order = CryptoExchangeOrder::where(function ($q) use ($qdxId, $ref) {
            if ($qdxId) {
                $q->where('quidax_swap_id', $qdxId)->orWhere('quidax_quotation_id', $qdxId);
            }
            if ($ref) {
                $q->orWhere('reference', $ref);
            }
        })->first();

        if (!$order) {
            return ['status' => 'not_found', 'notes' => 'Matching swap order not found'];
        }

        $order->update([
            'status' => $parsed['status'],
            'quidax_response' => $parsed['raw'] ?? $order->quidax_response,
            'completed_at' => $parsed['status'] === 'completed' ? now() : $order->completed_at,
        ]);

        if ($order->cryptoTransaction && $order->cryptoTransaction->status !== $newStatus) {
            $oldStatus = $order->cryptoTransaction->status;
            $order->cryptoTransaction->update([
                'status' => $newStatus,
                'completed_at' => $newStatus === 'successful' ? now() : null,
            ]);

            $this->logAudit(
                $order->cryptoTransaction->id,
                $order->user_id,
                'webhook',
                null,
                'Quidax Webhook',
                'swap_status_updated',
                $oldStatus,
                $newStatus,
                "Quidax swap webhook updated order #{$order->reference} to {$newStatus}."
            );
        }

        return [
            'status' => 'updated',
            'reference' => $order->reference,
            'new_status' => $newStatus,
            'notes' => "Updated swap {$order->reference} to {$newStatus}",
        ];
    }

    /**
     * Admin Action: Approve, Reject, or Update Status of a Pending/Processing Crypto Request.
     */
    public function updateTransactionStatusByAdmin(
        CryptoTransaction|int $cryptoTx,
        string $actionOrStatus,
        int|string|null $adminIdOrName = null,
        string|int|null $adminNameOrId = null,
        ?string $reason = null
    ): CryptoTransaction {
        if (is_int($cryptoTx)) {
            $cryptoTx = CryptoTransaction::findOrFail($cryptoTx);
        }

        if (is_int($adminIdOrName)) {
            $adminId = $adminIdOrName;
            $adminName = (string) ($adminNameOrId ?: 'Admin');
        } else {
            $adminName = (string) ($adminIdOrName ?: 'Admin');
            $adminId = is_int($adminNameOrId) ? $adminNameOrId : null;
        }

        $action = match (strtolower($actionOrStatus)) {
            'approve', 'successful' => 'approve',
            'reject', 'rejected' => 'reject',
            'mark_failed', 'failed' => 'mark_failed',
            'processing' => 'processing',
            default => $actionOrStatus,
        };

        return DB::transaction(function () use ($cryptoTx, $action, $adminName, $adminId, $reason) {
            $lockedTx = CryptoTransaction::where('id', $cryptoTx->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedTx->status;

            if ($lockedTx->is_refunded || $oldStatus === 'refunded') {
                throw new \InvalidArgumentException('Cannot change status of an already refunded transaction.');
            }

            if ($action === 'processing') {
                $lockedTx->update(['status' => 'processing']);
                if ($lockedTx->withdrawal) {
                    $lockedTx->withdrawal->update(['status' => 'processing']);
                }
                $this->logAudit($lockedTx->id, $lockedTx->user_id, 'admin', $adminId, $adminName, 'admin_processing', $oldStatus, 'processing', "Admin {$adminName} marked transaction #{$lockedTx->reference} as processing.");
                return $lockedTx->fresh(['user', 'withdrawal', 'exchangeOrder', 'refunds', 'auditLogs']);
            }

            if ($action === 'approve') {
                if (!in_array($oldStatus, ['pending', 'processing'], true)) {
                    throw new \InvalidArgumentException("Only pending or processing requests can be approved (current status: {$oldStatus}).");
                }

                $lockedTx->update([
                    'status' => 'successful',
                    'approved_by' => $adminName,
                    'approved_at' => now(),
                    'completed_at' => now(),
                ]);

                if ($lockedTx->withdrawal) {
                    $lockedTx->withdrawal->update([
                        'status' => 'successful',
                        'approved_by' => $adminName,
                        'approved_at' => now(),
                        'completed_at' => now(),
                    ]);
                }

                if ($lockedTx->transaction) {
                    $lockedTx->transaction->update(['status' => 'successful']);
                }

                $this->logAudit(
                    $lockedTx->id,
                    $lockedTx->user_id,
                    'admin',
                    $adminId,
                    $adminName,
                    'admin_approved',
                    $oldStatus,
                    'successful',
                    "Admin {$adminName} approved transaction #{$lockedTx->reference}."
                );
            } elseif (in_array($action, ['reject', 'mark_failed'], true)) {
                $targetStatus = $action === 'reject' ? 'rejected' : 'failed';
                if ($oldStatus === 'successful' && $lockedTx->type === 'send_crypto' && $lockedTx->destination_type === 'external_address') {
                    throw new \InvalidArgumentException('Cannot reject an already settled external blockchain withdrawal.');
                }

                $lockedTx->update([
                    'status' => $targetStatus,
                    'failure_reason' => $reason ?: "Marked as {$targetStatus} by Admin ({$adminName}).",
                ]);

                if ($lockedTx->withdrawal) {
                    $lockedTx->withdrawal->update([
                        'status' => $targetStatus,
                        'rejected_by' => $adminName,
                        'rejected_at' => now(),
                        'rejection_reason' => $reason ?: "Rejected by Admin ({$adminName}).",
                    ]);
                }

                if ($lockedTx->transaction) {
                    $lockedTx->transaction->update(['status' => 'failed']);
                }

                $this->logAudit(
                    $lockedTx->id,
                    $lockedTx->user_id,
                    'admin',
                    $adminId,
                    $adminName,
                    'admin_' . $targetStatus,
                    $oldStatus,
                    $targetStatus,
                    "Admin {$adminName} marked transaction #{$lockedTx->reference} as {$targetStatus}. Reason: " . ($reason ?: 'N/A')
                );
            } else {
                throw new \InvalidArgumentException("Unsupported admin status workflow action: {$action}");
            }

            return $lockedTx->fresh(['user', 'withdrawal', 'exchangeOrder', 'refunds', 'auditLogs']);
        });
    }

    /**
     * Admin Action: Process an Auditable, Locked Refund for an Eligible Crypto Transaction.
     *
     * Strict Refund Rules Enforced:
     * 1. Never refund a successful blockchain withdrawal automatically just because an admin clicks Refund.
     * 2. Verify whether the funds are still held by the platform or can actually be returned (queries Quidax if external withdrawal).
     * 3. Do not refund transactions that have already been refunded.
     * 4. Use database transactions and row-level locking (`lockForUpdate()`) to prevent double refunds.
     * 5. For transactions involving crypto (`send_crypto`, `convert_to_naira`), refund the exact crypto asset and deducted amount.
     * 6. For Naira transactions (`buy_crypto`), refund the exact Naira amount debited.
     * 7. Record the admin who initiated the refund, generate a unique refund reference, notify the user via Transaction ledger, and log full audit trail.
     */
    public function refundTransaction(
        CryptoTransaction|int $cryptoTx,
        int|string $adminIdOrReason,
        string $adminName,
        ?string $reasonOrAdminId = null
    ): array {
        if (is_int($cryptoTx)) {
            $cryptoTx = CryptoTransaction::findOrFail($cryptoTx);
        }

        if (is_int($adminIdOrReason)) {
            $adminId = $adminIdOrReason;
            $reason = trim((string) $reasonOrAdminId);
        } else {
            $reason = trim($adminIdOrReason);
            $adminId = is_numeric($reasonOrAdminId) ? (int) $reasonOrAdminId : null;
        }

        if (empty($reason)) {
            throw new \InvalidArgumentException('A valid refund reason is required before processing a refund.');
        }

        $lock = Cache::lock("crypto_refund_tx_{$cryptoTx->id}", 15);
        if (!$lock->get()) {
            throw new \InvalidArgumentException('A refund is already being processed for this transaction.');
        }

        try {
            return DB::transaction(function () use ($cryptoTx, $reason, $adminName, $adminId) {
                $lockedTx = CryptoTransaction::where('id', $cryptoTx->id)->lockForUpdate()->firstOrFail();

                // Rule: Do not refund transactions that have already been refunded
                if ($lockedTx->is_refunded || in_array($lockedTx->status, ['refunded', 'refund_processing'], true)) {
                    throw new \InvalidArgumentException("Transaction #{$lockedTx->reference} has already been refunded (Ref: {$lockedTx->refund_reference}).");
                }

                $existingRefund = CryptoRefund::where('crypto_transaction_id', $lockedTx->id)
                    ->whereIn('status', ['successful', 'refund_processing'])
                    ->lockForUpdate()
                    ->first();
                if ($existingRefund) {
                    throw new \InvalidArgumentException("A refund record already exists for transaction #{$lockedTx->reference}.");
                }

                // Rule: Incoming deposits cannot be refunded to user wallet because nothing was debited
                if ($lockedTx->type === 'receive_crypto') {
                    throw new \InvalidArgumentException('Incoming crypto deposits did not debit the user and cannot be refunded.');
                }

                // Rule: Never refund a successful blockchain withdrawal automatically
                if ($lockedTx->type === 'send_crypto' && $lockedTx->status === 'successful') {
                    throw new \InvalidArgumentException('SECURITY BLOCK: Cannot automatically refund a successful blockchain withdrawal. On-chain transfers are irreversible.');
                }

                // Rule: Must be in failed, rejected, or stuck pending/processing state
                if (!in_array($lockedTx->status, ['failed', 'rejected', 'pending', 'processing'], true)) {
                    throw new \InvalidArgumentException("Transaction with status '{$lockedTx->status}' is not eligible for refund.");
                }

                // Rule: Verify with Quidax whether funds are still held by the platform if it was an external withdrawal
                if ($lockedTx->type === 'send_crypto' && $lockedTx->destination_type === 'external_address' && !empty($lockedTx->quidax_reference)) {
                    $qdxVerify = $this->quidaxService->verifyWithdrawal($lockedTx->quidax_reference, $lockedTx->user?->quidax_user_id ?? 'me');
                    if ($qdxVerify && isset($qdxVerify['status'])) {
                        $mappedStatus = $this->quidaxService->mapQuidaxWithdrawalStatus($qdxVerify['status']);
                        if ($mappedStatus === 'successful') {
                            // Update local status to match Quidax and block refund!
                            $lockedTx->update(['status' => 'successful', 'tx_hash' => $qdxVerify['txid'] ?? $lockedTx->tx_hash]);
                            throw new \InvalidArgumentException('Quidax verification indicates this withdrawal was already broadcast and completed on-chain. Refund blocked to prevent platform loss.');
                        }
                    }
                }

                $lockedUser = User::where('id', $lockedTx->user_id)->lockForUpdate()->firstOrFail();
                $refundReference = 'RFD_' . strtoupper(Str::random(12));
                $previousStatus = $lockedTx->status;

                // Determine exact asset & amount to refund based on what was originally debited from the user
                if ($lockedTx->type === 'buy_crypto') {
                    // User paid NGN from their Naira wallet to buy crypto
                    $assetType = 'NGN';
                    $currency = 'NGN';
                    $refundableStr = number_format((float) $lockedTx->fiat_amount, 2, '.', '');
                    $refundableAmount = (float) $refundableStr;

                    if ($refundableAmount <= 0) {
                        throw new \InvalidArgumentException('No refundable Naira amount found on this transaction.');
                    }

                    $balanceBefore = (float) $lockedUser->wallet_balance;
                    $balanceAfterStr = bcadd(number_format($balanceBefore, 2, '.', ''), $refundableStr, 2);
                    $balanceAfter = (float) $balanceAfterStr;

                    $lockedUser->wallet_balance = $balanceAfter;
                    $lockedUser->save();

                    // Record refund transaction in user's ledger (also triggers user notification in NotificationController)
                    Transaction::create([
                        'user_id' => $lockedUser->id,
                        'reference' => $refundReference,
                        'type' => 'refund',
                        'direction' => 'in',
                        'title' => "Refund: {$lockedTx->symbol} Purchase",
                        'subtitle' => "Ref: {$lockedTx->reference} · Credited ₦" . number_format($refundableAmount, 2),
                        'amount' => $refundableAmount,
                        'fee' => 0,
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'counterparty' => 'Gutter Crypto Treasury',
                        'description' => "Refund for {$lockedTx->symbol} purchase ({$lockedTx->reference}). Reason: {$reason}",
                    ]);
                } else {
                    // send_crypto OR convert_to_naira: User was debited crypto from their CryptoWallet
                    $assetType = 'CRYPTO';
                    $currency = $lockedTx->symbol;
                    $deducted = $lockedTx->total_crypto_deducted > 0
                        ? (float) $lockedTx->total_crypto_deducted
                        : (float) $lockedTx->crypto_amount;

                    $refundableStr = number_format($deducted, 8, '.', '');
                    $refundableAmount = (float) $refundableStr;

                    if ($refundableAmount <= 0) {
                        throw new \InvalidArgumentException("No refundable {$currency} amount found on this transaction.");
                    }

                    $cryptoWallet = CryptoWallet::firstOrCreate(
                        ['user_id' => $lockedUser->id, 'symbol' => $currency],
                        ['balance' => 0]
                    );
                    $lockedWallet = CryptoWallet::where('id', $cryptoWallet->id)->lockForUpdate()->first();
                    $balanceBefore = (float) $lockedWallet->balance;
                    $balanceAfterStr = bcadd(number_format($balanceBefore, 8, '.', ''), $refundableStr, 8);
                    $balanceAfter = (float) $balanceAfterStr;

                    $lockedWallet->balance = $balanceAfter;
                    $lockedWallet->save();

                    $nairaEquivalent = (float) bcmul($refundableStr, number_format((float) ($lockedTx->exchange_rate ?: $this->getRate($currency)), 8, '.', ''), 2);

                    Transaction::create([
                        'user_id' => $lockedUser->id,
                        'reference' => $refundReference,
                        'type' => 'refund',
                        'direction' => 'in',
                        'title' => "Refund: {$refundableAmount} {$currency}",
                        'subtitle' => "Ref: {$lockedTx->reference} · Returned to {$currency} Wallet",
                        'amount' => $nairaEquivalent,
                        'fee' => 0,
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'counterparty' => 'Gutter Crypto Vault',
                        'description' => "Refunded {$refundableAmount} {$currency} to your crypto wallet for {$lockedTx->reference}. Reason: {$reason}",
                    ]);
                }

                // Create immutable CryptoRefund record
                $refundRecord = CryptoRefund::create([
                    'refund_reference' => $refundReference,
                    'crypto_transaction_id' => $lockedTx->id,
                    'user_id' => $lockedUser->id,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'asset_type' => $assetType,
                    'currency' => $currency,
                    'original_amount' => $assetType === 'NGN' ? $lockedTx->fiat_amount : $lockedTx->crypto_amount,
                    'refunded_amount' => $refundableAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'status' => 'successful',
                    'reason' => $reason,
                    'verification_notes' => "Verified funds held by platform and returned {$refundableAmount} {$currency} to User #{$lockedUser->id}.",
                    'original_transaction_snapshot' => $lockedTx->toArray(),
                    'completed_at' => now(),
                ]);

                // Update CryptoTransaction & linked records
                $lockedTx->update([
                    'status' => 'refunded',
                    'is_refunded' => true,
                    'refunded_amount' => $refundableAmount,
                    'refunded_currency' => $currency,
                    'refund_reference' => $refundReference,
                    'refunded_at' => now(),
                ]);

                if ($lockedTx->withdrawal) {
                    $lockedTx->withdrawal->update(['status' => 'refunded']);
                }

                if ($lockedTx->exchangeOrder) {
                    $lockedTx->exchangeOrder->update(['status' => 'refunded']);
                }

                if ($lockedTx->transaction) {
                    $lockedTx->transaction->update([
                        'status' => 'failed',
                        'description' => $lockedTx->transaction->description . " [REFUNDED: {$refundReference}]",
                    ]);
                }

                $this->logAudit(
                    $lockedTx->id,
                    $lockedUser->id,
                    'admin',
                    $adminId,
                    $adminName,
                    'transaction_refunded',
                    $previousStatus,
                    'refunded',
                    "Admin {$adminName} refunded {$refundableAmount} {$currency} (Ref: {$refundReference}). Reason: {$reason}",
                    [
                        'refund_id' => $refundRecord->id,
                        'refund_reference' => $refundReference,
                        'asset_type' => $assetType,
                        'currency' => $currency,
                        'refunded_amount' => $refundableAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                    ]
                );

                return [
                    'refund' => $refundRecord,
                    'transaction' => $lockedTx->fresh(['user', 'withdrawal', 'exchangeOrder', 'refunds', 'auditLogs']),
                    'amount_refunded' => $refundableAmount,
                    'asset_refunded' => $currency,
                    'asset_type' => $assetType,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Reconcile pending/processing local crypto transactions against Quidax API.
     */
    public function reconcilePendingTransactions(): array
    {
        if (!Schema::hasTable('crypto_transactions')) {
            return ['checked' => 0, 'updated' => 0];
        }

        $pending = CryptoTransaction::with('user')
            ->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('quidax_reference')
            ->limit(50)
            ->get();

        $checked = 0;
        $updated = 0;

        foreach ($pending as $tx) {
            $checked++;
            $qdxUserId = $tx->user?->quidax_user_id ?? 'me';

            if ($tx->type === 'send_crypto' && $tx->destination_type === 'external_address') {
                $qdxData = $this->quidaxService->verifyWithdrawal($tx->quidax_reference, $qdxUserId);
                if ($qdxData && !empty($qdxData['status'])) {
                    $mapped = $this->quidaxService->mapQuidaxWithdrawalStatus($qdxData['status']);
                    if ($mapped !== $tx->status) {
                        $this->processWithdrawalWebhook([
                            'quidax_id' => $tx->quidax_reference,
                            'reference' => $tx->reference,
                            'status' => $mapped,
                            'tx_hash' => $qdxData['txid'] ?? $tx->tx_hash,
                            'reason' => $qdxData['reason'] ?? null,
                            'raw' => $qdxData,
                        ]);
                        $updated++;
                    }
                }
            } elseif (in_array($tx->type, ['convert_to_naira', 'buy_crypto'], true)) {
                $qdxData = $this->quidaxService->verifySwapTransaction($tx->quidax_reference, $qdxUserId);
                if ($qdxData && !empty($qdxData['status'])) {
                    $statusStr = strtolower($qdxData['status']);
                    if (in_array($statusStr, ['completed', 'done'], true) && $tx->status !== 'successful') {
                        $this->processSwapWebhook([
                            'quidax_id' => $tx->quidax_reference,
                            'reference' => $tx->reference,
                            'status' => 'completed',
                            'raw' => $qdxData,
                        ]);
                        $updated++;
                    }
                }
            }
        }

        return [
            'checked' => $checked,
            'updated' => $updated,
        ];
    }

    /**
     * Get aggregated Crypto KPI metrics for Admin Dashboard & Crypto Management.
     */
    public function getAdminCryptoMetrics(): array
    {
        if (!Schema::hasTable('crypto_transactions')) {
            return [
                'total_deposits_ngn' => 0.0,
                'total_deposits_count' => 0,
                'total_withdrawals_ngn' => 0.0,
                'total_withdrawals_count' => 0,
                'total_purchases_ngn' => 0.0,
                'total_purchases_count' => 0,
                'total_conversions_ngn' => 0.0,
                'total_conversions_count' => 0,
                'total_platform_fees_ngn' => 0.0,
                'pending_count' => 0,
                'failed_count' => 0,
                'refund_total_ngn' => 0.0,
                'refund_count' => 0,
            ];
        }

        $depositsQuery = CryptoTransaction::where('type', 'receive_crypto')->where('status', 'successful');
        $withdrawalsQuery = CryptoTransaction::where('type', 'send_crypto')->where('status', 'successful');
        $purchasesQuery = CryptoTransaction::where('type', 'buy_crypto')->where('status', 'successful');
        $conversionsQuery = CryptoTransaction::where('type', 'convert_to_naira')->where('status', 'successful');

        $totalFeesNgn = (float) CryptoTransaction::where('status', 'successful')->sum('platform_fee_ngn');
        $pendingCount = CryptoTransaction::whereIn('status', ['pending', 'processing'])->count();
        $failedCount = CryptoTransaction::whereIn('status', ['failed', 'rejected'])->count();
        $refundCount = CryptoTransaction::where('is_refunded', true)->count();
        $refundTotalNgn = (float) CryptoTransaction::where('is_refunded', true)->sum('fiat_amount');

        return [
            'total_deposits_ngn' => (float) $depositsQuery->sum('fiat_amount'),
            'total_deposits_count' => $depositsQuery->count(),
            'total_withdrawals_ngn' => (float) $withdrawalsQuery->sum('fiat_amount'),
            'total_withdrawals_count' => $withdrawalsQuery->count(),
            'total_purchases_ngn' => (float) $purchasesQuery->sum('fiat_amount'),
            'total_purchases_count' => $purchasesQuery->count(),
            'total_conversions_ngn' => (float) $conversionsQuery->sum('fiat_amount'),
            'total_conversions_count' => $conversionsQuery->count(),
            'total_platform_fees_ngn' => $totalFeesNgn,
            'pending_count' => $pendingCount,
            'pending_transactions_count' => $pendingCount,
            'failed_count' => $failedCount,
            'failed_transactions_count' => $failedCount,
            'refund_total_ngn' => $refundTotalNgn,
            'refund_totals_ngn' => $refundTotalNgn,
            'refund_count' => $refundCount,
            'refund_totals_count' => $refundCount,
        ];
    }
}
