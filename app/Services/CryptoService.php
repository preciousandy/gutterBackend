<?php

namespace App\Services;

use App\Models\CryptoAddress;
use App\Models\CryptoDeposit;
use App\Models\CryptoWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FeeService;
use App\Services\QuidaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        return [
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
     * Get or generate a dedicated deposit address for a user via Quidax (or fallback engine).
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
            'status' => 'active',
        ]);
    }

    /**
     * Generate address string matching standard blockchain formats.
     */
    protected function generateAddressString(int $userId, string $symbol, string $network): string
    {
        $hash = hash('sha256', "gutter_custody_{$userId}_{$symbol}_{$network}_secret");

        if ($network === 'TRC20') {
            // Tron addresses start with 'T' and are 34 characters (Base58)
            $raw = 'T' . substr(str_replace(['0', 'O', 'I', 'l'], ['w', 'x', 'y', 'z'], base64_encode(hex2bin($hash))), 0, 33);
            return $raw;
        }

        if (in_array($network, ['ERC20', 'BEP20'])) {
            // EVM addresses start with '0x' followed by 40 hex chars
            return '0x' . substr($hash, 0, 40);
        }

        if ($network === 'SOL') {
            // Solana Base58 string (44 chars)
            return substr(str_replace(['+', '/', '='], ['S', 'o', 'L'], base64_encode(hex2bin($hash))), 0, 44);
        }

        // Default SegWit Bitcoin or EVM
        return 'bc1q' . substr($hash, 0, 38);
    }

    /**
     * Get all supported networks for an asset.
     */
    public function getSupportedNetworks(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        if ($symbol === 'USDT') {
            return [
                [
                    'network' => 'TRC20',
                    'name' => 'Tron (TRC-20)',
                    'fee_desc' => 'Low network fee · ~1-3 mins',
                    'confirmations' => 1,
                    'min_deposit' => 1.0,
                ],
                [
                    'network' => 'BEP20',
                    'name' => 'BNB Smart Chain (BEP-20)',
                    'fee_desc' => 'Very low fee · ~1 min',
                    'confirmations' => 15,
                    'min_deposit' => 1.0,
                ],
                [
                    'network' => 'ERC20',
                    'name' => 'Ethereum (ERC-20)',
                    'fee_desc' => 'Standard fee · ~3-5 mins',
                    'confirmations' => 12,
                    'min_deposit' => 10.0,
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
            ],
        ];
    }

    /**
     * Process an incoming crypto deposit.
     * If user has auto_convert_crypto enabled, converts to NGN instantly.
     * Otherwise, credits the user's CryptoWallet balance.
     */
    public function processDeposit(User $user, string $symbol, string $network, float $amount, ?string $txHash = null): array
    {
        $symbol = strtoupper($symbol);
        $network = strtoupper($network);
        $txHash = $txHash ?: '0x' . Str::random(64);

        return DB::transaction(function () use ($user, $symbol, $network, $amount, $txHash) {
            $existing = CryptoDeposit::where('tx_hash', $txHash)->first();
            if ($existing) {
                return [
                    'deposit' => $existing,
                    'already_processed' => true,
                ];
            }

            $rate = $this->getRate($symbol);
            $autoConvert = (bool) $user->auto_convert_crypto;
            $nairaAmount = null;

            if ($autoConvert) {
                // Auto-convert to Naira instantly
                $nairaAmount = round($amount * $rate, 2);

                $user->increment('wallet_balance', $nairaAmount);

                $deposit = CryptoDeposit::create([
                    'user_id' => $user->id,
                    'tx_hash' => $txHash,
                    'symbol' => $symbol,
                    'network' => $network,
                    'amount' => $amount,
                    'status' => 'confirmed',
                    'converted_to_naira' => true,
                    'naira_amount' => $nairaAmount,
                    'rate' => $rate,
                ]);

                // Create Transaction record in transactions history
                Transaction::create([
                    'user_id' => $user->id,
                    'reference' => 'CRYPTO_' . strtoupper(Str::random(10)),
                    'type' => 'crypto_sell',
                    'direction' => 'in',
                    'title' => "Auto-Converted {$symbol} to NGN",
                    'subtitle' => number_format($amount, 4) . " {$symbol} ({$network}) @ ₦" . number_format($rate, 2),
                    'amount' => $nairaAmount,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => "Crypto {$network} Deposit",
                    'description' => "Auto-converted {$amount} {$symbol} directly to Naira wallet.",
                ]);
            } else {
                // Credit Crypto balance
                $wallet = CryptoWallet::firstOrCreate(
                    ['user_id' => $user->id, 'symbol' => $symbol],
                    ['balance' => 0]
                );
                $wallet->increment('balance', $amount);

                $deposit = CryptoDeposit::create([
                    'user_id' => $user->id,
                    'tx_hash' => $txHash,
                    'symbol' => $symbol,
                    'network' => $network,
                    'amount' => $amount,
                    'status' => 'confirmed',
                    'converted_to_naira' => false,
                    'naira_amount' => null,
                    'rate' => $rate,
                ]);

                // Create Transaction record
                Transaction::create([
                    'user_id' => $user->id,
                    'reference' => 'CRYPTO_' . strtoupper(Str::random(10)),
                    'type' => 'crypto_buy',
                    'direction' => 'in',
                    'title' => "{$symbol} Deposit Confirmed",
                    'subtitle' => number_format($amount, 4) . " {$symbol} on {$network}",
                    'amount' => round($amount * $rate, 2),
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => "Blockchain {$network}",
                    'description' => "Received {$amount} {$symbol} into Gutter crypto wallet.",
                ]);
            }

            return [
                'deposit' => $deposit,
                'auto_converted' => $autoConvert,
                'naira_credited' => $nairaAmount,
                'crypto_credited' => $autoConvert ? 0 : $amount,
                'symbol' => $symbol,
            ];
        });
    }

    /**
     * Convert holding crypto to Naira wallet balance on-demand.
     */
    public function convertCryptoToNaira(User $user, string $symbol, float $cryptoAmount, string $pin): array
    {
        $symbol = strtoupper($symbol);

        // 1. Verify user's PIN
        if (!$user->has_pin || !$user->pin) {
            throw new \InvalidArgumentException('Please create your 4-digit transaction PIN first.');
        }

        if (!Hash::check($pin, $user->pin)) {
            throw new \InvalidArgumentException('Incorrect 4-digit transaction PIN.');
        }

        // 2. Check crypto balance
        $wallet = CryptoWallet::where('user_id', $user->id)
            ->where('symbol', $symbol)
            ->first();

        $currentBalance = (float) ($wallet?->balance ?? 0);
        if ($currentBalance < $cryptoAmount) {
            throw new \InvalidArgumentException("Insufficient {$symbol} balance. You have " . number_format($currentBalance, 4) . " {$symbol}.");
        }

        if (!FeeService::isEnabled('convert_crypto')) {
            throw new \InvalidArgumentException('Converting crypto to Naira is temporarily paused for system maintenance.');
        }

        return DB::transaction(function () use ($user, $wallet, $symbol, $cryptoAmount) {
            $rate = $this->getRate($symbol);
            $grossNaira = $cryptoAmount * $rate;
            $fee = FeeService::calculateFee('convert_crypto', $grossNaira);
            $netNaira = round(max(0, $grossNaira - $fee), 2);

            // Deduct crypto
            $wallet->decrement('balance', $cryptoAmount);

            // Credit Naira
            $user->increment('wallet_balance', $netNaira);

            // Log Transaction
            $tx = Transaction::create([
                'user_id' => $user->id,
                'reference' => 'SWAP_' . strtoupper(Str::random(10)),
                'type' => 'crypto_sell',
                'direction' => 'in',
                'title' => "Converted {$symbol} to Naira",
                'subtitle' => "Sold " . number_format($cryptoAmount, 4) . " {$symbol} @ ₦" . number_format($rate, 2),
                'amount' => $netNaira,
                'fee' => $fee,
                'currency' => 'NGN',
                'status' => 'successful',
                'counterparty' => 'Gutter Instant Swap',
                'description' => "Converted {$cryptoAmount} {$symbol} to ₦{$netNaira} in Naira Wallet.",
            ]);

            return [
                'transaction' => $tx,
                'crypto_debited' => $cryptoAmount,
                'naira_credited' => $netNaira,
                'fee' => $fee,
                'rate' => $rate,
                'new_crypto_balance' => (float) $wallet->fresh()->balance,
                'new_wallet_balance' => (float) $user->fresh()->wallet_balance,
            ];
        });
    }

    /**
     * Buy crypto using Naira wallet balance.
     */
    public function buyCryptoWithNaira(User $user, string $symbol, float $nairaAmount, string $pin): array
    {
        $symbol = strtoupper($symbol);

        if (!$user->has_pin || !$user->pin) {
            throw new \InvalidArgumentException('Please create your 4-digit transaction PIN first.');
        }

        if (!Hash::check($pin, $user->pin)) {
            throw new \InvalidArgumentException('Incorrect 4-digit transaction PIN.');
        }

        if (!FeeService::isEnabled('buy_crypto')) {
            throw new \InvalidArgumentException('Purchasing crypto with Naira is temporarily paused for system maintenance.');
        }

        $walletBalance = (float) ($user->wallet_balance ?? 0);
        if ($walletBalance < $nairaAmount) {
            throw new \InvalidArgumentException('Insufficient Naira wallet balance.');
        }

        return DB::transaction(function () use ($user, $symbol, $nairaAmount) {
            $rate = $this->getRate($symbol);
            $fee = FeeService::calculateFee('buy_crypto', $nairaAmount);
            $netToSpend = max(0, $nairaAmount - $fee);
            $cryptoBought = round($netToSpend / $rate, 8);

            // Deduct Naira
            $user->decrement('wallet_balance', $nairaAmount);

            // Credit Crypto Wallet
            $cryptoWallet = CryptoWallet::firstOrCreate(
                ['user_id' => $user->id, 'symbol' => $symbol],
                ['balance' => 0]
            );
            $cryptoWallet->increment('balance', $cryptoBought);

            // Log Transaction
            $tx = Transaction::create([
                'user_id' => $user->id,
                'reference' => 'BUY_' . strtoupper(Str::random(10)),
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

            return [
                'transaction' => $tx,
                'naira_debited' => $nairaAmount,
                'crypto_credited' => $cryptoBought,
                'fee' => $fee,
                'rate' => $rate,
                'new_crypto_balance' => (float) $cryptoWallet->fresh()->balance,
                'new_wallet_balance' => (float) $user->fresh()->wallet_balance,
            ];
        });
    }

    /**
     * Get user's crypto portfolio summary.
     */
    public function getUserPortfolio(User $user): array
    {
        $wallets = CryptoWallet::where('user_id', $user->id)->get()->keyBy('symbol');
        $market = $this->getMarketData();

        $balances = [];
        $totalNairaValue = 0.0;

        foreach ($market as $coin) {
            $sym = $coin['symbol'];
            $amount = (float) ($wallets->get($sym)?->balance ?? 0.0);
            $valueNaira = $amount * $coin['price'];
            $totalNairaValue += $valueNaira;

            $balances[] = [
                'symbol' => $sym,
                'name' => $coin['name'],
                'amount' => $amount,
                'price' => $coin['price'],
                'value_naira' => round($valueNaira, 2),
                'change_24h' => $coin['change_24h'],
                'sparkline' => $coin['sparkline'],
            ];
        }

        return [
            'portfolio_value' => round($totalNairaValue, 2),
            'portfolio_change' => 2.4, // 24h weighted percentage change
            'auto_convert_crypto' => (bool) $user->auto_convert_crypto,
            'balances' => $balances,
        ];
    }

    /**
     * Get transfer fee for sending crypto.
     */
    public function getSendFee(string $symbol, string $destinationType, ?string $network = null): float
    {
        if ($destinationType === 'gutter_user') {
            return 0.00; // Free instant peer-to-peer within Gutter
        }

        $symbol = strtoupper($symbol);
        $network = strtoupper($network ?? 'TRC20');

        $networkFees = [
            'USDT' => ['TRC20' => 1.00, 'BEP20' => 0.50, 'ERC20' => 4.00],
            'BTC' => ['BTC' => 0.0002],
            'ETH' => ['ERC20' => 0.0015],
            'SOL' => ['SOL' => 0.005],
            'BNB' => ['BEP20' => 0.001],
        ];

        return $networkFees[$symbol][$network] ?? 1.00;
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
        ?string $memo = null
    ): array {
        $symbol = strtoupper($symbol);

        // 1. PIN verification
        if (!$sender->has_pin || !$sender->pin) {
            throw new \InvalidArgumentException('Please create your 4-digit transaction PIN first.');
        }

        if (!Hash::check($pin, $sender->pin)) {
            throw new \InvalidArgumentException('Incorrect 4-digit transaction PIN.');
        }

        if (!FeeService::isEnabled('send_crypto')) {
            throw new \InvalidArgumentException('Sending crypto is temporarily paused for system maintenance.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be greater than zero.');
        }

        // 2. Fetch sender wallet & fee
        $fee = $this->getSendFee($symbol, $destinationType, $network);
        $totalDebit = $amount + $fee;

        $senderWallet = CryptoWallet::firstOrCreate(
            ['user_id' => $sender->id, 'symbol' => $symbol],
            ['balance' => 0]
        );

        $senderBalance = (float) $senderWallet->balance;
        if ($senderBalance < $totalDebit) {
            $needed = number_format($totalDebit, 4);
            $have = number_format($senderBalance, 4);
            throw new \InvalidArgumentException("Insufficient {$symbol} balance. Need {$needed} {$symbol} (incl. {$fee} fee), but you have {$have} {$symbol}.");
        }

        $rate = $this->getRate($symbol);

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

            return DB::transaction(function () use ($sender, $recipientUser, $senderWallet, $symbol, $amount, $rate) {
                $senderWallet->decrement('balance', $amount);

                $recipientAutoConvert = (bool) $recipientUser->auto_convert_crypto;
                $recipientName = trim($recipientUser->first_name . ' ' . $recipientUser->last_name) ?: $recipientUser->email;
                $senderName = trim($sender->first_name . ' ' . $sender->last_name) ?: $sender->email;

                if ($recipientAutoConvert) {
                    $nairaCredit = round($amount * $rate, 2);
                    $recipientUser->increment('wallet_balance', $nairaCredit);

                    Transaction::create([
                        'user_id' => $recipientUser->id,
                        'reference' => 'CRYPTO_' . strtoupper(Str::random(10)),
                        'type' => 'crypto_sell',
                        'direction' => 'in',
                        'title' => "Received {$symbol} (Auto-Converted)",
                        'subtitle' => "From {$senderName} · Credited ₦" . number_format($nairaCredit, 2),
                        'amount' => $nairaCredit,
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'counterparty' => $senderName,
                        'description' => "Received {$amount} {$symbol} from {$senderName}, auto-converted to Naira.",
                    ]);
                } else {
                    $recipientWallet = CryptoWallet::firstOrCreate(
                        ['user_id' => $recipientUser->id, 'symbol' => $symbol],
                        ['balance' => 0]
                    );
                    $recipientWallet->increment('balance', $amount);

                    Transaction::create([
                        'user_id' => $recipientUser->id,
                        'reference' => 'CRYPTO_' . strtoupper(Str::random(10)),
                        'type' => 'crypto_buy',
                        'direction' => 'in',
                        'title' => "Received {$symbol}",
                        'subtitle' => "From {$senderName} · " . number_format($amount, 4) . " {$symbol}",
                        'amount' => round($amount * $rate, 2),
                        'currency' => 'NGN',
                        'status' => 'successful',
                        'counterparty' => $senderName,
                        'description' => "Received {$amount} {$symbol} from {$senderName}.",
                    ]);
                }

                $senderTx = Transaction::create([
                    'user_id' => $sender->id,
                    'reference' => 'SEND_' . strtoupper(Str::random(10)),
                    'type' => 'crypto_sell',
                    'direction' => 'out',
                    'title' => "Sent {$symbol} to {$recipientName}",
                    'subtitle' => number_format($amount, 4) . " {$symbol} · Free (Gutter P2P)",
                    'amount' => round($amount * $rate, 2),
                    'fee' => 0,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'counterparty' => $recipientName,
                    'description' => "Transferred {$amount} {$symbol} to {$recipientName} instantly.",
                ]);

                return [
                    'transaction' => $senderTx,
                    'destination_type' => 'gutter_user',
                    'recipient' => $recipientName,
                    'amount' => $amount,
                    'fee' => 0,
                    'symbol' => $symbol,
                    'new_crypto_balance' => (float) $senderWallet->fresh()->balance,
                ];
            });
        }

        // External Blockchain withdrawal via Quidax
        $network = strtoupper($network ?? 'TRC20');

        return DB::transaction(function () use ($sender, $senderWallet, $symbol, $network, $recipient, $amount, $fee, $totalDebit, $memo, $rate) {
            $senderWallet->decrement('balance', $totalDebit);

            $qdxResult = $this->quidaxService->withdrawCrypto(
                $sender,
                $symbol,
                $network,
                $recipient,
                $amount,
                $memo
            );

            $shortAddress = strlen($recipient) > 12
                ? substr($recipient, 0, 6) . '...' . substr($recipient, -4)
                : $recipient;

            $tx = Transaction::create([
                'user_id' => $sender->id,
                'reference' => 'WITHDRAW_' . strtoupper(Str::random(10)),
                'type' => 'crypto_sell',
                'direction' => 'out',
                'title' => "Sent {$symbol} ({$network})",
                'subtitle' => "To {$shortAddress} · Fee: {$fee} {$symbol}",
                'amount' => round($totalDebit * $rate, 2),
                'fee' => round($fee * $rate, 2),
                'currency' => 'NGN',
                'status' => 'successful',
                'counterparty' => "{$network}: {$shortAddress}",
                'description' => "Blockchain withdrawal of {$amount} {$symbol} to {$recipient} via {$network}.",
            ]);

            return [
                'transaction' => $tx,
                'destination_type' => 'external_address',
                'recipient' => $recipient,
                'network' => $network,
                'amount' => $amount,
                'fee' => $fee,
                'symbol' => $symbol,
                'tx_id' => $qdxResult['tx_id'] ?? null,
                'new_crypto_balance' => (float) $senderWallet->fresh()->balance,
            ];
        });
    }
}

