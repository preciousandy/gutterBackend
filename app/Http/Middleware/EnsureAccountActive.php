<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    /**
     * Handle an incoming request.
     *
     * Ensures the user's account is not suspended or blocked.
     * If suspended, revokes all tokens immediately and blocks access.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            // Revoke active sessions immediately
            $user->tokens()->delete();

            $reason = $user->status_reason ? " Reason: {$user->status_reason}." : '';

            return response()->json([
                'message' => "Your account has been {$user->status} by administration.{$reason} Please contact customer support.",
                'error' => 'account_suspended',
                'status' => $user->status,
            ], 403);
        }

        return $next($request);
    }
}
