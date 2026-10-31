<?php

namespace App\Services\Kyc;

use App\Models\KycVerification;
use App\Models\User;

class KycService
{
    protected KycProviderInterface $provider;

    public function __construct()
    {
        $driver = env('KYC_PROVIDER', 'mock');

        $this->provider = match ($driver) {
            'prembly' => new PremblyKycProvider(),
            default => new MockKycProvider(),
        };
    }

    public function getProviderName(): string
    {
        return $this->provider->getName();
    }

    /**
     * Validate regulatory document number formats upfront.
     */
    public function validateDocumentFormat(string $type, string $cleanNumber): ?string
    {
        return match ($type) {
            'nin' => (!preg_match('/^\d{11}$/', $cleanNumber) && !preg_match('/^[A-Z0-9]{16}$/', $cleanNumber))
                ? 'NIN must be 11 numeric digits (or a 16-character virtual NIN).'
                : null,
            'bvn' => (!preg_match('/^\d{11}$/', $cleanNumber))
                ? 'BVN must be exactly 11 numeric digits.'
                : null,
            'pvc' => (strlen($cleanNumber) < 10)
                ? 'Please enter a valid Voter Card (VIN) number.'
                : null,
            'passport' => (!preg_match('/^[A-Z]\d{8}$/', $cleanNumber) && strlen($cleanNumber) < 8)
                ? 'Please enter a valid Passport number (e.g. A01234567).'
                : null,
            default => "Unsupported document type: {$type}.",
        };
    }

    /**
     * Submit and verify an identity document.
     *
     * @return array{success: bool, message: string, verification?: KycVerification, error?: string}
     */
    public function processDocumentSubmission(User $user, string $type, string $number, ?string $documentImage = null): array
    {
        $cleanNumber = preg_replace('/[\s-]/', '', strtoupper(trim($number)));

        // Strict regulatory format validation upfront
        $formatError = $this->validateDocumentFormat($type, $cleanNumber);
        if ($formatError) {
            return [
                'success' => false,
                'message' => $formatError,
                'error' => 'invalid_format',
            ];
        }

        // Run verification via provider
        $result = $this->provider->verifyDocument($type, $cleanNumber, $user);

        if (!$result['success']) {
            if ($result['requires_manual_approval'] ?? false) {
                // Record submission as pending for Admin compliance review
                $idData = [
                    'document_image' => $documentImage,
                    'queued_reason' => $result['message'],
                    'provider_raw' => $result['raw'] ?? null,
                ];

                $verification = $user->kycVerifications()->create([
                    'document_type' => $type,
                    'document_number' => $cleanNumber,
                    'status' => 'pending',
                    'provider' => $this->provider->getName() . ' (manual_queue)',
                    'id_data' => $idData,
                    'id_verified_at' => null,
                ]);

                $user->update([
                    'kyc_status' => 'pending',
                ]);

                return [
                    'success' => true,
                    'message' => 'Your document has been submitted and is currently in review. Our compliance team will approve your Tier 2 upgrade shortly.',
                    'verification' => $verification,
                    'next_step' => 'face_verification',
                ];
            }

            return [
                'success' => false,
                'message' => $result['message'],
                'error' => $result['error'] ?? 'verification_failed',
            ];
        }

        // Record verification in database
        $idData = $result['data'] ?? [];
        if ($documentImage) {
            $idData['document_image'] = $documentImage;
        }

        $verification = $user->kycVerifications()->create([
            'document_type' => $type,
            'document_number' => $cleanNumber,
            'status' => 'id_verified',
            'provider' => $this->provider->getName(),
            'id_data' => $idData,
            'id_verified_at' => now(),
        ]);

        // Update user state to pending face check
        $user->update([
            'kyc_status' => 'pending',
        ]);

        return [
            'success' => true,
            'message' => $result['message'],
            'verification' => $verification,
            'next_step' => 'face_verification',
        ];
    }

    /**
     * Process face verification and finalize user KYC status.
     *
     * @return array{success: bool, message: string, user?: User, error?: string}
     */
    public function processFaceVerification(User $user, ?string $selfieData = null): array
    {
        $verification = $user->kycVerifications()
            ->whereIn('status', ['id_verified', 'pending'])
            ->latest()
            ->first();

        if (!$verification) {
            return [
                'success' => false,
                'message' => 'Please submit a valid identity document before performing face verification.',
                'error' => 'no_pending_document',
            ];
        }

        $result = $this->provider->verifyFace($user, $selfieData);

        if (!$result['success']) {
            if ($result['requires_manual_approval'] ?? false) {
                // Route to admin manual approval queue
                if ($verification) {
                    $existingFaceData = is_array($verification->face_data) ? $verification->face_data : [];
                    $verification->update([
                        'status' => 'pending',
                        'face_data' => array_merge($existingFaceData, [
                            'selfie_image' => $selfieData,
                            'queued_reason' => $result['message'],
                            'has_selfie' => !empty($selfieData),
                        ]),
                    ]);
                }

                $user->update([
                    'kyc_status' => 'pending',
                ]);

                return [
                    'success' => true,
                    'message' => 'Your selfie and documents have been received and are undergoing manual compliance review.',
                    'user' => $user->fresh(),
                ];
            }

            if ($verification) {
                $verification->update([
                    'status' => 'rejected',
                    'rejection_reason' => $result['message'],
                ]);
            }
            return [
                'success' => false,
                'message' => $result['message'],
                'error' => $result['error'] ?? 'face_check_failed',
            ];
        }

        // Successfully verified!
        if ($verification) {
            $verification->update([
                'status' => 'verified',
                'face_data' => [
                    'confidence' => $result['confidence'] ?? 98.0,
                    'liveness_score' => $result['liveness_score'] ?? 99.0,
                    'has_selfie' => !empty($selfieData),
                ],
                'face_verified_at' => now(),
            ]);
        }

        // Upgrade user to verified & Tier 2!
        $user->update([
            'kyc_status' => 'verified',
            'tier' => max((int) ($user->tier ?? 1), 2),
        ]);

        // Automatically issue dedicated PalmPay NUBAN virtual account for verified user
        try {
            app(\App\Services\PalmPayService::class)->createVirtualAccount($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed creating PalmPay virtual account during KYC: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'Identity and face verification successful. Your account is verified.',
            'user' => $user->fresh(),
        ];
    }

    /**
     * Admin manual approval of a user's KYC submission.
     */
    public function manualApprove(User $user, ?string $adminName = null): array
    {
        $verification = $user->kycVerifications()->latest()->first();

        if ($verification) {
            $verification->update([
                'status' => 'verified',
                'id_verified_at' => $verification->id_verified_at ?: now(),
                'face_verified_at' => $verification->face_verified_at ?: now(),
                'rejection_reason' => null,
            ]);
        }

        $user->update([
            'kyc_status' => 'verified',
            'tier' => max((int) ($user->tier ?? 1), 2),
        ]);

        // Automatically provision PalmPay Virtual Account if not yet active
        try {
            app(\App\Services\PalmPayService::class)->createVirtualAccount($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PalmPay account provision error in manual KYC approve: ' . $e->getMessage());
        }

        // Send in-app notification
        try {
            $user->notifications()->create([
                'title' => 'Identity Verified (Tier 2 Unlocked)',
                'body' => 'Congratulations! Your identity verification has been reviewed and approved. You now enjoy Tier 2 daily limits (₦5,000,000/day).',
                'type' => 'kyc',
                'data' => [
                    'tier' => 2,
                    'kyc_status' => 'verified',
                    'approved_by' => $adminName ?: 'Compliance Team',
                ],
            ]);
        } catch (\Throwable) {
            // Ignore notification error
        }

        return [
            'success' => true,
            'message' => "Customer #{$user->id} ({$user->first_name} {$user->last_name}) KYC manually approved. Tier 2 unlocked.",
            'user' => $user->fresh(),
        ];
    }

    /**
     * Admin manual rejection of a user's KYC submission.
     */
    public function manualReject(User $user, ?string $reason = null, ?string $adminName = null): array
    {
        $rejectionReason = $reason ?: 'Document could not be verified. Please ensure details match your account and re-upload.';

        $verification = $user->kycVerifications()->latest()->first();
        if ($verification) {
            $verification->update([
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
            ]);
        }

        $user->update([
            'kyc_status' => 'rejected',
        ]);

        // Send in-app notification explaining rejection
        try {
            $user->notifications()->create([
                'title' => 'KYC Verification Update',
                'body' => "Your identity verification could not be approved. Reason: {$rejectionReason}. Please re-submit your document in settings.",
                'type' => 'kyc',
                'data' => [
                    'kyc_status' => 'rejected',
                    'reason' => $rejectionReason,
                    'reviewed_by' => $adminName ?: 'Compliance Team',
                ],
            ]);
        } catch (\Throwable) {
            // Ignore notification error
        }

        return [
            'success' => true,
            'message' => "Customer #{$user->id} KYC rejected with reason: {$rejectionReason}",
            'user' => $user->fresh(),
        ];
    }
}
