<?php

namespace App\Services\Kyc;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PremblyKycProvider implements KycProviderInterface
{
    protected string $apiKey;
    protected string $appId;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.prembly.api_key', env('PREMBLY_API_KEY', ''));
        $this->appId = (string) config('services.prembly.app_id', env('PREMBLY_APP_ID', ''));
        $this->baseUrl = (string) config('services.prembly.base_url', env('PREMBLY_BASE_URL', 'https://api.prembly.com'));
    }

    public function getName(): string
    {
        return 'prembly';
    }

    public function verifyDocument(string $type, string $number, User $user): array
    {
        if (empty($this->apiKey) || app()->environment('testing')) {
            // Fall back to mock if keys are not configured or running in testing environment
            return (new MockKycProvider())->verifyDocument($type, $number, $user);
        }

        try {
            // Use verified live Prembly endpoints
            $endpoint = match ($type) {
                'bvn' => "{$this->baseUrl}/verification/bvn_validation",
                'nin' => "{$this->baseUrl}/verification/vnin-basic",
                'passport' => "{$this->baseUrl}/verification/international_passport",
                'pvc' => "{$this->baseUrl}/verification/voters_card",
                default => "{$this->baseUrl}/verification/{$type}",
            };

            $payload = ['number' => $number];
            if ($type === 'passport') {
                $payload['last_name'] = $user->last_name;
            }

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'app-id' => $this->appId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(20)->post($endpoint, $payload);

            // If primary NIN endpoint fails with 404, fallback to level-2 endpoint
            if ($type === 'nin' && $response->status() === 404) {
                $fallbackEndpoint = "{$this->baseUrl}/verification/nin-level-2";
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'app-id' => $this->appId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->timeout(20)->post($fallbackEndpoint, $payload);
            }

            $json = $response->json();
            $msg = (string) ($json['message'] ?? $json['detail'] ?? '');

            // Check if Prembly successfully verified the document
            if ($response->successful() && ($json['status'] ?? false)) {
                return [
                    'success' => true,
                    'message' => 'Document verified via Prembly.',
                    'data' => $json['data'] ?? [],
                    'raw' => $json,
                ];
            }

            // Detect insufficient Prembly wallet balance or rate limit -> queue for Admin manual review
            if (
                str_contains(strtolower($msg), 'insufficient wallet balance') ||
                str_contains(strtolower($msg), 'balance') ||
                ($json['response_code'] ?? '') === '04' ||
                $response->status() === 429
            ) {
                Log::warning("Prembly wallet balance low or rate-limited for user #{$user->id}: {$msg}");
                return [
                    'success' => false,
                    'message' => 'Your document has been securely submitted and is queued for manual compliance approval.',
                    'error' => 'provider_insufficient_funds',
                    'requires_manual_approval' => true,
                    'raw' => $json,
                ];
            }

            return [
                'success' => false,
                'message' => $msg ?: 'Document verification failed. Please check your document number.',
                'error' => 'provider_error',
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('Prembly verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Your document has been securely received and queued for manual compliance approval.',
                'error' => 'connection_failed',
                'requires_manual_approval' => true,
            ];
        }
    }

    public function verifyFace(User $user, ?string $selfieData = null): array
    {
        if (empty($this->apiKey) || app()->environment('testing')) {
            return (new MockKycProvider())->verifyFace($user, $selfieData);
        }

        try {
            // Live Prembly Face Liveliness endpoint
            $endpoint = "{$this->baseUrl}/verification/biometrics/face/liveliness_check";
            
            $payload = [
                'image' => $selfieData ?: '',
            ];

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'app-id' => $this->appId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(25)->post($endpoint, $payload);

            $json = $response->json();
            $msg = (string) ($json['message'] ?? $json['detail'] ?? '');

            if ($response->successful() && ($json['status'] ?? false)) {
                $confidence = (float) (
                    $json['data']['confidence_in_percentage'] ??
                    (($json['data']['confidence'] ?? 0.98) * 100)
                );

                return [
                    'success' => true,
                    'message' => 'Face liveliness verification passed.',
                    'confidence' => $confidence,
                    'liveness_score' => $confidence,
                    'raw' => $json,
                ];
            }

            // If wallet balance is insufficient or service is temporarily busy, route to manual admin review
            if (
                str_contains(strtolower($msg), 'insufficient wallet balance') ||
                str_contains(strtolower($msg), 'balance') ||
                ($json['response_code'] ?? '') === '04'
            ) {
                Log::warning("Prembly face verification low balance for user #{$user->id}: {$msg}");
                return [
                    'success' => false,
                    'message' => 'Your biometric selfie has been uploaded and queued for manual compliance review.',
                    'error' => 'provider_insufficient_funds',
                    'requires_manual_approval' => true,
                ];
            }

            return [
                'success' => false,
                'message' => $msg ?: 'Face verification could not confirm identity. Please capture a clear, well-lit selfie.',
                'error' => 'face_check_failed',
            ];
        } catch (\Throwable $e) {
            Log::error('Prembly face verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Your biometric selfie has been uploaded and queued for manual compliance review.',
                'error' => 'connection_failed',
                'requires_manual_approval' => true,
            ];
        }
    }
}
