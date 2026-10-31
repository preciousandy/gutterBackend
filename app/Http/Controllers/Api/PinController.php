<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
    /**
     * Create the user's initial 4-digit transaction PIN.
     *
     * Route: POST /api/v1/pin
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // If user already has a PIN, require change endpoint with security verification
        if ($user->has_pin && ! empty($user->pin)) {
            return response()->json([
                'message' => 'A transaction PIN is already set on this account. Please use the Change PIN option.',
            ], 403);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ], [
            'pin.required' => 'Please enter a 4-digit PIN.',
            'pin.digits' => 'PIN must be exactly 4 digits.',
            'pin_confirmation.same' => 'PIN confirmation does not match.',
        ]);

        $pin = $validated['pin'];

        // Disallow trivial sequence / repeats
        if (preg_match('/^(\d)\1{3}$/', $pin)) {
            return response()->json([
                'message' => 'Please choose a less predictable PIN (avoid repeated digits like 1111).',
            ], 422);
        }

        if (in_array($pin, ['1234', '2345', '3456', '4567', '5678', '6789', '9876', '8765', '7654', '6543', '5432', '4321', '0123'], true)) {
            return response()->json([
                'message' => 'Please choose a less predictable PIN (avoid consecutive sequences).',
            ], 422);
        }

        $user->update([
            'pin' => Hash::make($pin),
            'has_pin' => true,
        ]);

        return response()->json([
            'message' => 'Transaction PIN set successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Change the user's 4-digit transaction PIN with security challenge.
     *
     * Route: POST /api/v1/pin/change
     */
    public function change(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Security Challenge: Verify either current_pin or password
        if ($user->has_pin && ! empty($user->pin)) {
            if ($request->filled('password')) {
                // Verified via account password
                if (! Hash::check($request->password, $user->password)) {
                    return response()->json([
                        'message' => 'The password you entered is incorrect.',
                        'errors' => [
                            'password' => ['The password you entered is incorrect.'],
                        ],
                    ], 422);
                }
            } elseif ($request->filled('current_pin')) {
                // Verified via current PIN
                if (! Hash::check($request->current_pin, $user->pin)) {
                    return response()->json([
                        'message' => 'Your current PIN is incorrect.',
                        'errors' => [
                            'current_pin' => ['Your current PIN is incorrect.'],
                        ],
                    ], 422);
                }
            } else {
                return response()->json([
                    'message' => 'Please provide your current PIN or account password to verify your identity.',
                    'errors' => [
                        'current_pin' => ['Current PIN or account password is required.'],
                    ],
                ], 422);
            }
        }

        // 2. Validate new PIN
        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ], [
            'pin.required' => 'Please enter a 4-digit PIN.',
            'pin.digits' => 'PIN must be exactly 4 digits.',
            'pin_confirmation.same' => 'PIN confirmation does not match.',
        ]);

        $newPin = $validated['pin'];

        // Disallow matching current PIN
        if ($user->has_pin && ! empty($user->pin) && Hash::check($newPin, $user->pin)) {
            return response()->json([
                'message' => 'New PIN must be different from your current PIN.',
                'errors' => [
                    'pin' => ['New PIN must be different from your current PIN.'],
                ],
            ], 422);
        }

        // Disallow trivial repeats (e.g. 1111)
        if (preg_match('/^(\d)\1{3}$/', $newPin)) {
            return response()->json([
                'message' => 'Please choose a less predictable PIN (avoid repeated digits like 1111).',
                'errors' => [
                    'pin' => ['Please choose a less predictable PIN (avoid repeated digits like 1111).'],
                ],
            ], 422);
        }

        // Disallow sequences
        if (in_array($newPin, ['1234', '2345', '3456', '4567', '5678', '6789', '9876', '8765', '7654', '6543', '5432', '4321', '0123'], true)) {
            return response()->json([
                'message' => 'Please choose a less predictable PIN (avoid consecutive sequences).',
                'errors' => [
                    'pin' => ['Please choose a less predictable PIN (avoid consecutive sequences).'],
                ],
            ], 422);
        }

        $user->update([
            'pin' => Hash::make($newPin),
            'has_pin' => true,
        ]);

        return response()->json([
            'message' => 'Transaction PIN changed successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Verify the user's 4-digit transaction PIN (for quick unlock or transaction authorization).
     *
     * Route: POST /api/v1/pin/verify
     */
    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->has_pin || empty($user->pin)) {
            return response()->json([
                'message' => 'No transaction PIN has been set on this account.',
                'errors' => [
                    'pin' => ['No transaction PIN has been set on this account.'],
                ],
            ], 422);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
        ], [
            'pin.required' => 'Please enter your 4-digit PIN.',
            'pin.digits' => 'PIN must be exactly 4 digits.',
        ]);

        if (! Hash::check($validated['pin'], $user->pin)) {
            return response()->json([
                'message' => 'Incorrect PIN. Please try again.',
                'errors' => [
                    'pin' => ['Incorrect PIN. Please try again.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'PIN verified successfully.',
            'verified' => true,
        ]);
    }
}
