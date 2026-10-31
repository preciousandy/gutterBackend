<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(
        protected KycService $kycService
    ) {}

    /**
     * Submit an identity document for verification.
     *
     * Route: POST /api/v1/kyc/submit
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:nin,bvn,pvc,passport'],
            'document_number' => ['required', 'string', 'min:5', 'max:50'],
            'document_image' => ['required', 'string'],
        ], [
            'document_type.required' => 'Please select a document type.',
            'document_type.in' => 'Document type must be one of: nin, bvn, pvc, or passport.',
            'document_number.required' => 'Please enter your document number.',
            'document_image.required' => 'Please upload a photo or document copy of your identity card.',
        ]);

        $user = $request->user();

        // Prevent tampering if already verified
        if ($user->kyc_status === 'verified') {
            return response()->json([
                'message' => 'Your account is already verified. Re-submission is not required.',
            ], 422);
        }

        $result = $this->kycService->processDocumentSubmission(
            $user,
            $validated['document_type'],
            $validated['document_number'],
            $validated['document_image']
        );

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
                'error' => $result['error'] ?? 'verification_failed',
            ], 422);
        }

        $verStatus = $result['status'] ?? ($result['verification']->status ?? 'id_verified');
        $nextStep = $result['next_step'] ?? ($verStatus === 'pending' ? 'face_verification' : 'face_verification');

        return response()->json([
            'message' => $result['message'],
            'status' => $verStatus,
            'next_step' => $nextStep,
            'data' => [
                'document_type' => $validated['document_type'],
                'provider' => $this->kycService->getProviderName(),
                'status' => $verStatus,
            ],
        ]);
    }

    /**
     * Submit face check / liveness capture.
     *
     * Route: POST /api/v1/kyc/face-check
     */
    public function faceCheck(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->kyc_status === 'verified') {
            return response()->json([
                'message' => 'Your account is already verified.',
                'status' => 'verified',
                'user' => new UserResource($user),
            ]);
        }

        // Optional selfie data or photo payload
        $selfieData = $request->input('selfie') ?: $request->input('image') ?: $request->input('selfie_image');

        $result = $this->kycService->processFaceVerification($user, $selfieData);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
                'error' => $result['error'] ?? 'face_check_failed',
            ], 422);
        }

        $actualStatus = $result['user']->kyc_status ?? 'pending';

        return response()->json([
            'message' => $result['message'],
            'status' => $actualStatus,
            'user' => new UserResource($result['user']),
        ]);
    }

    /**
     * Get the current user's KYC verification status.
     *
     * Route: GET /api/v1/kyc/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $user->kycVerifications()->latest()->first();

        return response()->json([
            'data' => [
                'kyc_status' => $user->kyc_status ?? 'unverified',
                'tier' => $user->tier ?? 1,
                'has_submitted_document' => (bool) $latest,
                'document_type' => $latest?->document_type,
                'masked_document' => $latest?->masked_document_number,
                'verification_status' => $latest?->status ?? 'unverified',
                'verified_at' => $latest?->face_verified_at ?? $latest?->id_verified_at,
            ],
        ]);
    }
}
