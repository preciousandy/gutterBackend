<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected OtpMailService $otpService
    ) {}

    /**
     * Authenticate user with phone or email and password.
     *
     * Route: POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'login.required' => 'Please enter your phone number or email address.',
            'password.required' => 'Please enter your password.',
        ]);

        $rawLogin = trim((string) $validated['login']);
        $normalizedEmail = strtolower($rawLogin);

        // Normalize Nigerian phone formats (e.g. 08034128877, 2348034128877, +2348034128877)
        $digits = preg_replace('/\D/', '', $rawLogin);
        $normalizedPhone = null;
        if (str_starts_with($rawLogin, '+234')) {
            $normalizedPhone = '+' . $digits;
        } elseif (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $normalizedPhone = '+' . $digits;
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $normalizedPhone = '+234' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $normalizedPhone = '+234' . $digits;
        }

        // Query user by email OR phone
        $user = User::where(function ($query) use ($normalizedEmail, $normalizedPhone, $rawLogin) {
            $query->where('email', $normalizedEmail);
            if ($normalizedPhone) {
                $query->orWhere('phone', $normalizedPhone);
            }
            $query->orWhere('phone', $rawLogin);
        })->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid credentials. Please check your details and try again.'],
            ]);
        }

        // Check if account is suspended or blocked
        if ($user->isSuspended()) {
            $reason = $user->status_reason ? " Reason: {$user->status_reason}." : '';
            return response()->json([
                'message' => "Your account has been {$user->status} by administration.{$reason} Please contact customer support.",
                'status' => $user->status,
            ], 403);
        }

        // Track user login time
        $user->update(['last_login_at' => now()]);

        $deviceName = $validated['device_name'] ?? 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 200);
    }

    /**
     * Handle user registration, issue Sanctum token, and dispatch initial email OTP.
     *
     * Route: POST /api/v1/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        // 1. Sanitize and normalize inputs
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'first_name' => trim((string) $request->input('first_name')),
            'last_name' => trim((string) $request->input('last_name')),
            'phone' => preg_replace('/\s+/', '', (string) $request->input('phone')),
        ]);

        // 2. Strict validation rules
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\s\-]+$/u'],
            'last_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\s\-]+$/u'],
            'phone' => [
                'required',
                'string',
                'regex:/^\+234[789][01]\d{8}$/',
                'unique:users,phone',
            ],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'first_name.regex' => 'The first name should only contain letters, spaces, or hyphens.',
            'last_name.regex' => 'The last name should only contain letters, spaces, or hyphens.',
            'phone.regex' => 'Please provide a valid Nigerian phone number in +234 format (e.g. +2348012345678).',
            'phone.unique' => 'An account is already registered with this phone number.',
            'email.unique' => 'An account is already registered with this email address.',
        ]);

        // 3. Create user in database transaction
        $user = DB::transaction(function () use ($validated) {
            return User::create([
                'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'tier' => 1,
                'kyc_status' => 'unverified',
                'has_pin' => false,
            ]);
        });

        // 4. Issue personal access token (Sanctum)
        $deviceName = $validated['device_name'] ?? 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        // 5. Asynchronously dispatch the 6-digit email OTP (no cooldown on initial signup)
        $this->otpService->generateAndSend($user, enforceCooldown: false);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Request a new 6-digit verification code with 60-second cooldown protection.
     *
     * Route: POST /api/v1/auth/email/otp
     */
    public function sendEmailOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified.',
            ], 200);
        }

        try {
            $this->otpService->generateAndSend($user, enforceCooldown: true);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first('cooldown') ?: 'Please wait before requesting another code.',
            ], 422);
        }

        return response()->json([
            'message' => 'Verification code sent to your email.',
        ]);
    }

    /**
     * Verify the email OTP code with atomic locking & brute force lockout.
     *
     * Route: POST /api/v1/auth/email/verify
     */
    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'Verification code must be 6 numeric digits.',
        ]);

        $user = $request->user();

        $result = $this->otpService->verify($user, $request->code);

        if ($result['status'] !== 'success') {
            return response()->json([
                'message' => $result['message'],
                'status' => $result['status'],
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    /**
     * Update user email address and dispatch new OTP code.
     *
     * Route: POST /api/v1/auth/email/change
     */
    public function changeEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email is already verified and cannot be changed here.',
            ], 422);
        }

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                'unique:users,email',
                function ($attribute, $value, $fail) use ($user) {
                    if (strtolower($value) === strtolower($user->email)) {
                        $fail('The new email must be different from your current email.');
                    }
                },
            ],
        ], [
            'email.unique' => 'An account is already registered with this email address.',
            'email.email' => 'Please provide a valid email address.',
        ]);

        $user->update(['email' => $validated['email']]);

        // Dispatch fresh OTP to the new email address immediately (no cooldown on address change)
        $this->otpService->generateAndSend($user, enforceCooldown: false);

        return response()->json([
            'message' => 'Email updated successfully. A new verification code has been sent.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Get authenticated user profile.
     *
     * Route: GET /api/v1/user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * Revoke current access token and log out the user.
     *
     * Route: POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
