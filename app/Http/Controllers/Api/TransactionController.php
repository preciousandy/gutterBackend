<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * List user transactions with filters, search, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->transactions()->latest();

        // Filter by direction: 'in' or 'out'
        if ($request->filled('direction') && in_array($request->direction, ['in', 'out'], true)) {
            $query->where('direction', $request->direction);
        }

        // Search in title, counterparty, reference, or description
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('counterparty', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Date range filtering
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'links' => [
                'first' => $paginated->url(1),
                'last' => $paginated->url($paginated->lastPage()),
                'prev' => $paginated->previousPageUrl(),
                'next' => $paginated->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Get details for a single transaction.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();
        $transaction = $user->transactions()
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('reference', $id);
            })
            ->first();

        if (!$transaction) {
            return response()->json([
                'message' => 'Transaction not found.',
            ], 404);
        }

        return response()->json([
            'data' => $transaction,
        ]);
    }
}
