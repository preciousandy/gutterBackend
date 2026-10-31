<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeneficiaryController extends Controller
{
    /**
     * List all saved beneficiaries for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->beneficiaries()->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    /**
     * Store or update a saved beneficiary.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_code' => 'nullable|string|max:32',
            'account_number' => 'nullable|string|max:30',
            'category' => 'nullable|string|max:32',
            'nickname' => 'nullable|string|max:100',
            'meter_type' => 'nullable|string|in:prepaid,postpaid',
        ]);

        $user = $request->user();
        $category = $validated['category'] ?? 'transfer';

        // Match existing beneficiary by account number + bank code (or bank name) within category
        $query = $user->beneficiaries()->where('category', $category);
        if (!empty($validated['account_number'])) {
            $query->where('account_number', $validated['account_number']);
        } else {
            $query->where('name', $validated['name']);
        }

        if (!empty($validated['bank_code'])) {
            $query->where('bank_code', $validated['bank_code']);
        } else {
            $query->where('bank_name', $validated['bank_name']);
        }

        $beneficiary = $query->first();

        if ($beneficiary) {
            $beneficiary->update($validated);
        } else {
            $beneficiary = $user->beneficiaries()->create($validated);
        }

        return response()->json([
            'message' => 'Beneficiary saved successfully.',
            'data' => $beneficiary,
        ], 201);
    }

    /**
     * Delete a saved beneficiary.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $beneficiary = $request->user()->beneficiaries()->findOrFail($id);
        $beneficiary->delete();

        return response()->json([
            'message' => 'Beneficiary removed successfully.',
        ]);
    }
}
