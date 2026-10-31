<?php

namespace App\Services\Kyc;

use App\Models\User;

interface KycProviderInterface
{
    /**
     * Get the provider identifier.
     */
    public function getName(): string;

    /**
     * Verify an identity document (NIN, BVN, PVC, Passport).
     *
     * @return array{
     *   success: bool,
     *   message: string,
     *   data?: array,
     *   error?: string
     * }
     */
    public function verifyDocument(string $type, string $number, User $user): array;

    /**
     * Perform biometric face verification & liveness check.
     *
     * @return array{
     *   success: bool,
     *   message: string,
     *   confidence?: float,
     *   liveness_score?: float,
     *   error?: string
     * }
     */
    public function verifyFace(User $user, ?string $selfieData = null): array;
}
