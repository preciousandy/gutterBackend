<?php

namespace App\Services\Kyc;

use App\Models\User;

class MockKycProvider implements KycProviderInterface
{
    public function getName(): string
    {
        return 'mock';
    }

    public function verifyDocument(string $type, string $number, User $user): array
    {
        $cleanNumber = preg_replace('/[\s-]/', '', strtoupper(trim($number)));

        // Strict validation according to Nigerian regulatory standards
        switch ($type) {
            case 'nin':
                // NIN is 11 numeric digits, or vNIN (Virtual NIN) is 16 alphanumeric characters
                if (!preg_match('/^\d{11}$/', $cleanNumber) && !preg_match('/^[A-Z0-9]{16}$/', $cleanNumber)) {
                    return [
                        'success' => false,
                        'message' => 'NIN must be 11 numeric digits (or a 16-character virtual NIN).',
                        'error' => 'invalid_format',
                    ];
                }
                break;

            case 'bvn':
                // BVN is exactly 11 numeric digits
                if (!preg_match('/^\d{11}$/', $cleanNumber)) {
                    return [
                        'success' => false,
                        'message' => 'BVN must be exactly 11 numeric digits.',
                        'error' => 'invalid_format',
                    ];
                }
                break;

            case 'pvc':
                // Nigerian Voter Card (VIN) is typically 17-19 characters
                if (strlen($cleanNumber) < 10) {
                    return [
                        'success' => false,
                        'message' => 'Please enter a valid Voter Card (VIN) number.',
                        'error' => 'invalid_format',
                    ];
                }
                break;

            case 'passport':
                // Standard Nigerian passport number: letter followed by 8 digits e.g. A01234567
                if (!preg_match('/^[A-Z]\d{8}$/', $cleanNumber) && strlen($cleanNumber) < 8) {
                    return [
                        'success' => false,
                        'message' => 'Please enter a valid Passport number (e.g. A01234567).',
                        'error' => 'invalid_format',
                    ];
                }
                break;

            default:
                return [
                    'success' => false,
                    'message' => "Unsupported document type: {$type}.",
                    'error' => 'unsupported_type',
                ];
        }

        // Simulate test rejection code for QA if number ends with '000'
        if (str_ends_with($cleanNumber, '000')) {
            return [
                'success' => false,
                'message' => 'Document could not be verified against the official registry. Please check your number.',
                'error' => 'registry_not_found',
            ];
        }

        // Return verified record matching user
        return [
            'success' => true,
            'message' => 'Identity document verified successfully.',
            'data' => [
                'document_type' => $type,
                'document_number' => $cleanNumber,
                'first_name' => $user->first_name ?: 'Hannah',
                'last_name' => $user->last_name ?: 'Joy',
                'full_name' => trim(($user->first_name ?: 'Hannah') . ' ' . ($user->last_name ?: 'Joy')),
                'date_of_birth' => '1996-08-14',
                'gender' => 'female',
                'phone' => $user->phone,
                'nin' => $type === 'nin' ? $cleanNumber : '10293847561',
                'verified_registry' => strtoupper($type === 'bvn' ? 'NIBSS' : ($type === 'nin' ? 'NIMC' : 'NIGERIAN_GOVERNMENT')),
                'photo_available' => true,
            ],
        ];
    }

    public function verifyFace(User $user, ?string $selfieData = null): array
    {
        // High confidence simulated biometric liveness & 1:1 match
        return [
            'success' => true,
            'message' => 'Face verification and liveness check passed.',
            'confidence' => 98.4,
            'liveness_score' => 99.1,
            'match_result' => 'passed',
        ];
    }
}
