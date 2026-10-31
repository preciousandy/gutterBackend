<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountNotFrozen
{
    /**
     * Handle an incoming request.
     *
     * Ensures the user's account is not frozen (Post-No-Debit).
     * Frozen users can login and view read-only data, but cannot perform financial actions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isFrozen()) {
            $reason = $user->status_reason ? " Reason: {$user->status_reason}." : '';

            return response()->json([
                'message' => "Your account is temporarily frozen (Post-No-Debit). Outgoing transactions and financial actions are restricted.{$reason} Please contact customer support.",
                'error' => 'account_frozen',
                'status' => 'frozen',
                'is_frozen' => true,
                'status_reason' => $user->status_reason,
            ], 403);
        }

        return $next($request);
    }
}
