<?php

namespace App\Services;

use DateTimeZone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VtpassService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $publicKey;
    protected ?string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.vtpass.base_url', 'https://vtpass.com/api/'), '/') . '/';
        $this->apiKey = config('services.vtpass.api_key');
        $this->publicKey = config('services.vtpass.public_key');
        $this->secretKey = config('services.vtpass.secret_key');
    }

    /**
     * Map network name to VTpass airtime serviceID.
     */
    public function serviceIdForAirtime(string $network): string
    {
        return match (strtoupper($network)) {
            'MTN' => 'mtn',
            'AIRTEL' => 'airtel',
            'GLO' => 'glo',
            '9MOBILE', 'ETISALAT' => 'etisalat',
            default => strtolower($network),
        };
    }

    /**
     * Map network name to VTpass data serviceID.
     */
    public function serviceIdForData(string $network): string
    {
        return match (strtoupper($network)) {
            'MTN' => 'mtn-data',
            'AIRTEL' => 'airtel-data',
            'GLO' => 'glo-data',
            '9MOBILE', 'ETISALAT' => 'etisalat-data',
            default => strtolower($network) . '-data',
        };
    }

    /**
     * Generate VTpass request_id conforming to GMT+1 / Lagos YYYYMMDDHHII format.
     */
    public function generateRequestId(): string
    {
        $lagosTime = now(new DateTimeZone('Africa/Lagos'))->format('YmdHi');
        $randomSuffix = strtolower(Str::random(6));

        return "{$lagosTime}{$randomSuffix}";
    }

    /**
     * Fetch variation codes for a data or TV service.
     */
    public function getVariations(string $serviceId): array
    {
        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'public-key' => $this->publicKey,
            ])->timeout(12)->get("{$this->baseUrl}service-variations", [
                'serviceID' => $serviceId,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['content']['variations'] ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning("VTpass getVariations failed for {$serviceId}: {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Purchase Airtime via VTpass.
     */
    public function purchaseAirtime(string $network, string $phone, float $amount): array
    {
        $serviceId = $this->serviceIdForAirtime($network);
        $requestId = $this->generateRequestId();

        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceId,
            'amount' => $amount,
            'phone' => $phone,
        ];

        return $this->executePurchase($payload, 'airtime');
    }

    /**
     * Purchase Mobile Data bundle via VTpass.
     */
    public function purchaseData(string $network, string $phone, float $amount, ?string $variationCode = null): array
    {
        $serviceId = $this->serviceIdForData($network);
        $requestId = $this->generateRequestId();

        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceId,
            'amount' => $amount,
            'phone' => $phone,
            'billersCode' => $phone,
        ];

        if ($variationCode) {
            $payload['variation_code'] = $variationCode;
        }

        return $this->executePurchase($payload, 'data');
    }

    /**
     * Execute POST request to VTpass pay endpoint.
     */
    protected function executePurchase(array $payload, string $category): array
    {
        $requestId = $payload['request_id'];

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'secret-key' => $this->secretKey,
                'Content-Type' => 'application/json',
            ])->timeout(35)->post("{$this->baseUrl}pay", $payload);

            $data = $response->json() ?? [];
            $code = (string) ($data['code'] ?? '');

            Log::info("VTpass {$category} purchase response: code={$code}", [
                'payload' => $payload,
                'response' => $data,
            ]);

            // VTpass "000" means transaction successfully completed
            if ($code === '000') {
                return [
                    'success' => true,
                    'status' => 'successful',
                    'request_id' => $requestId,
                    'reference' => $requestId,
                    'transaction_id' => $data['content']['transactions']['transactionId'] ?? null,
                    'data' => $data,
                ];
            }

            // "099" means transaction is pending/processing
            if ($code === '099') {
                return [
                    'success' => true,
                    'status' => 'pending',
                    'request_id' => $requestId,
                    'reference' => $requestId,
                    'transaction_id' => $data['content']['transactions']['transactionId'] ?? null,
                    'data' => $data,
                ];
            }

            // "028" product not whitelisted in user's VTpass account
            if ($code === '028') {
                Log::warning("VTpass product not whitelisted on account: {$payload['serviceID']}.");
                return [
                    'success' => false,
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'message' => 'Product is not activated on your VTpass account. Please activate it under VTpass API Product Settings.',
                    'data' => $data,
                ];
            }

            // "016" transaction failed (on sandbox, happens when using non-test phone numbers)
            if ($code === '016') {
                $isSandbox = str_contains($this->baseUrl, 'sandbox');
                $errorMsg = $isSandbox
                    ? 'Transaction failed on VTpass Sandbox. In sandbox mode, only test number 08011111111 is accepted for successful recharge. Real phone numbers require live API credentials.'
                    : ($data['response_description'] ?? 'Transaction failed on provider network.');

                return [
                    'success' => false,
                    'status' => 'failed',
                    'request_id' => $requestId,
                    'message' => $errorMsg,
                    'data' => $data,
                ];
            }

            $errorMsg = $data['response_description'] ?? 'Unable to process bill payment at this moment.';
            return [
                'success' => false,
                'status' => 'failed',
                'request_id' => $requestId,
                'message' => $errorMsg,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error("VTpass purchase exception: {$e->getMessage()}");

            return [
                'success' => false,
                'status' => 'failed',
                'request_id' => $requestId,
                'message' => 'VTU gateway timeout or network connection error. Your wallet was not debited.',
            ];
        }
    }

    /**
     * Requery status of a previous transaction from VTpass.
     */
    public function requery(string $requestId): array
    {
        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'secret-key' => $this->secretKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$this->baseUrl}requery", [
                'request_id' => $requestId,
            ]);

            $data = $response->json() ?? [];
            $code = (string) ($data['code'] ?? '');
            $txStatus = strtolower($data['content']['transactions']['status'] ?? '');

            if ($code === '000' && in_array($txStatus, ['delivered', 'successful', 'success'])) {
                return [
                    'status' => 'successful',
                    'delivered' => true,
                    'message' => 'Airtime / Data delivered successfully.',
                    'data' => $data,
                ];
            }

            if ($code === '099' || $txStatus === 'pending') {
                return [
                    'status' => 'pending',
                    'delivered' => false,
                    'message' => 'Transaction is currently processing with the telco network.',
                    'data' => $data,
                ];
            }

            return [
                'status' => 'failed',
                'delivered' => false,
                'message' => $data['response_description'] ?? 'Delivery failed or transaction not found.',
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error("VTpass requery exception: {$e->getMessage()}");
            return [
                'status' => 'pending',
                'delivered' => false,
                'message' => 'Unable to connect to VTU network for verification.',
            ];
        }
    }
}

