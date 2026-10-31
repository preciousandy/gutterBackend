<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // General API rate limit (60 requests/minute)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Registration anti-bot rate limit (5 sign-ups/minute per IP)
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())->response(function () {
                return response()->json([
                    'message' => 'Too many registration requests. Please wait a moment before trying again.',
                ], 429);
            });
        });

        // Email OTP sending rate limit (1 per minute, max 5 per hour)
        RateLimiter::for('otp-send', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return [
                Limit::perMinute(1)->by($identifier)->response(function () {
                    return response()->json([
                        'message' => 'Please wait 60 seconds before requesting another code.',
                    ], 429);
                }),
                Limit::perHour(5)->by($identifier)->response(function () {
                    return response()->json([
                        'message' => 'Daily verification code limit reached. Please contact support.',
                    ], 429);
                }),
            ];
        });

        // Email OTP verification rate limit (5 attempts/minute)
        RateLimiter::for('otp-verify', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinute(5)->by($identifier)->response(function () {
                return response()->json([
                    'message' => 'Too many verification attempts. Please wait 1 minute before trying again.',
                ], 429);
            });
        });

        // Login anti-brute force rate limit (5 attempts/minute)
        RateLimiter::for('login', function (Request $request) {
            $login = strtolower(trim((string) $request->input('login', '')));
            $identifier = ($login ?: 'unknown') . '|' . $request->ip();
            return Limit::perMinute(5)->by($identifier)->response(function () {
                return response()->json([
                    'message' => 'Too many login attempts. Please wait 1 minute before trying again.',
                ], 429);
            });
        });

        // KYC verification rate limit (max 5 submissions per minute per user/IP)
        RateLimiter::for('kyc-submit', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinute(5)->by($identifier)->response(function () {
                return response()->json([
                    'message' => 'Too many verification attempts. Please wait 1 minute before trying again.',
                ], 429);
            });
        });

        // PIN change rate limit (max 5 attempts per 15 minutes per user/IP)
        RateLimiter::for('pin-change', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinutes(15, 5)->by($identifier)->response(function () {
                return response()->json([
                    'message' => 'Too many PIN change attempts. Please wait 15 minutes before trying again.',
                ], 429);
            });
        });

        // Bill payment protection (max 5 attempts per minute per user/IP to prevent brute-forcing PIN)
        RateLimiter::for('bill-payment', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinute(5)->by($identifier)->response(function () {
                return response()->json([
                    'message' => 'Too many bill payment attempts. Please wait 1 minute before trying again.',
                ], 429);
            });
        });

        // Savings deposit rate limit (max 10 requests per minute per user/IP to prevent spam & burst concurrency)
        RateLimiter::for('savings-deposit', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinute(10)->by($identifier)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many savings deposit attempts. Please wait a moment before trying again.',
                ], 429);
            });
        });

        // Savings withdrawal anti-brute force protection (max 5 attempts per minute per user/IP to guard PIN & balances)
        RateLimiter::for('savings-withdraw', function (Request $request) {
            $identifier = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
            return Limit::perMinute(5)->by($identifier)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many withdrawal attempts. Please wait 1 minute before trying again.',
                ], 429);
            });
        });

        // Share real-time notification counts across all Admin portal views
        \Illuminate\Support\Facades\View::composer(['admin.*'], function ($view) {
            try {
                $pendingKyc = \App\Models\User::where('kyc_status', 'pending')->count();
                $openComplaints = \App\Models\Complaint::where('status', 'open')->count();
                $frozenCount = \App\Models\User::where('status', 'frozen')->count();
                $suspendedCount = \App\Models\User::whereIn('status', ['suspended', 'blocked'])->count();
                $totalNotifications = $pendingKyc + $openComplaints;

                $view->with([
                    'pendingKyc' => $pendingKyc,
                    'openComplaints' => $openComplaints,
                    'frozenCount' => $frozenCount,
                    'suspendedCount' => $suspendedCount,
                    'totalAdminNotifications' => $totalNotifications,
                ]);
            } catch (\Throwable $e) {
                // Fail-safe if DB is offline or migrating
            }
        });
    }
}
