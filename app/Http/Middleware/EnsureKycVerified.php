<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKycVerified
{
    /**
     * Handle an incoming request.
     *
     * Ensures the authenticated user has completed both identity and face verification.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->kyc_status !== 'verified') {
            return response()->json([
                'message' => 'Identity verification is required before performing this action.',
                'error' => 'kyc_required',
                'kyc_status' => $user?->kyc_status ?? 'unverified',
                'required_step' => ($user?->kyc_status === 'pending') ? 'face_verification' : 'verify_identity',
            ], 403);
        }

        return $next($request);
    }
}
