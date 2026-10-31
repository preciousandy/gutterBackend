<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    /**
     * Submit an in-app user complaint or dispute ticket.
     */
    public function storeComplaint(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'reference' => 'nullable|string|max:100',
            'message' => 'required|string|min:6|max:2000',
        ]);

        $ticketId = 'CMP-' . strtoupper(Str::random(8));

        Log::info("Support complaint filed [{$ticketId}]:", [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_name' => "{$user->first_name} {$user->last_name}",
            'category' => $validated['category'],
            'reference' => $validated['reference'] ?? null,
            'message' => $validated['message'],
        ]);

        $complaint = \App\Models\Complaint::create([
            'ticket_id' => $ticketId,
            'user_id' => $user->id,
            'user_name' => "{$user->first_name} {$user->last_name}",
            'user_email' => $user->email,
            'category' => $validated['category'],
            'reference' => $validated['reference'] ?? null,
            'message' => $validated['message'],
            'status' => 'open',
        ]);

        return response()->json([
            'message' => 'Your complaint has been submitted successfully. Our priority support team will review and resolve it promptly.',
            'ticket_id' => $ticketId,
            'category' => $validated['category'],
        ]);
    }
}
