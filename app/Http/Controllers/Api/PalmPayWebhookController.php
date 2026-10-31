<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PalmPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PalmPayWebhookController extends Controller
{
    /**
     * Handle incoming deposit notifications from PalmPay Open Gateway.
     *
     * Route: POST /api/v1/webhooks/palmpay
     */
    public function handle(Request $request, PalmPayService $palmPayService): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-GSignature') ?? $request->input('signature');

        Log::info('PalmPay Webhook received:', [
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        // If a signature is provided and public key is available, verify it
        if (!empty($signature)) {
            $isValid = $palmPayService->verifyWebhookSignature($payload, $signature);
            if (!$isValid) {
                Log::warning('PalmPay Webhook: Signature verification failed.');
                // For high security, we reject invalid signatures if public key is configured
                if (!empty(config('services.palmpay.public_key'))) {
                    return response()->json([
                        'respCode' => 'OPEN_GW_000001',
                        'respMsg' => 'Invalid signature',
                    ], 401);
                }
            }
        }

        $data = $request->all();

        // 1. Check if this is an OUTWARD payout status update (orderId matching an outgoing transfer)
        $orderId = $data['orderId'] ?? $data['data']['orderId'] ?? null;
        if (!empty($orderId)) {
            $outgoingTx = Transaction::where('reference', $orderId)->first();
            if ($outgoingTx && $outgoingTx->direction === 'out') {
                return $this->handlePayoutCallback($outgoingTx, $data);
            }
        }

        // 2. Otherwise handle INBOUND deposit notification
        $accountNumber = $data['accountNumber'] 
            ?? $data['virtualAccountNo'] 
            ?? $data['data']['accountNumber'] 
            ?? $data['data']['virtualAccountNo'] 
            ?? null;

        $amountRaw = $data['amount'] 
            ?? $data['transAmount'] 
            ?? $data['data']['amount'] 
            ?? 0;

        // PalmPay might pass amount in minor units (Kobo) or float NGN
        $amount = (float) $amountRaw;
        if (isset($data['amountType']) && $data['amountType'] === 'kobo' || (is_int($amountRaw) && $amountRaw > 10000 && !str_contains((string) $amountRaw, '.'))) {
            // Check if amount is in kobo (if specified or large integer)
            // Usually, standard PalmPay specifies amount directly or in kobo
        }

        $reference = $data['orderNo'] 
            ?? $data['paymentOrderNo'] 
            ?? $data['reference'] 
            ?? $data['data']['orderNo'] 
            ?? null;

        if (empty($reference)) {
            // Compute deterministic reference based on account, amount, and timestamp to prevent random duplicate IDs on retry
            $seed = ($accountNumber ?? '') . '_' . $amount . '_' . ($data['payTime'] ?? $data['transTime'] ?? $data['createdTime'] ?? '');
            $reference = 'PLM_DEP_' . substr(hash('sha256', $seed), 0, 24);
        }

        $payerName = $data['payerName'] 
            ?? $data['senderName'] 
            ?? $data['data']['payerName'] 
            ?? 'External Bank Transfer';

        $payerBank = $data['payerBank'] 
            ?? $data['senderBank'] 
            ?? $data['data']['payerBank'] 
            ?? 'Commercial Bank';

        if (empty($accountNumber)) {
            return response()->json([
                'respCode' => '000000',
                'respMsg' => 'Account number missing, ignored.',
            ]);
        }

        // Locate recipient user
        $user = User::where('account_number', $accountNumber)->first();
        if (!$user) {
            Log::warning("PalmPay Webhook: User with account number {$accountNumber} not found.");
            return response()->json([
                'respCode' => '000000',
                'respMsg' => 'User not found, ignored.',
            ]);
        }

        // Distributed atomic lock to prevent concurrent execution of duplicate webhooks
        $lockKey = 'palmpay_webhook_' . md5($reference);
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 20);

        try {
            $lock->block(10);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::warning("PalmPay Webhook: Concurrent delivery timed out on lock for {$reference}");
            return response()->json([
                'respCode' => '000000',
                'respMsg' => 'Processing in progress',
            ]);
        }

        try {
            DB::transaction(function () use ($user, $amount, $reference, $payerName, $payerBank, $data) {
                // Idempotency: Pessimistic check under transaction lock to prevent double receive
                $existing = Transaction::where('reference', $reference)->lockForUpdate()->first();
                if ($existing) {
                    Log::info("PalmPay Webhook: Duplicate transaction {$reference} detected, skipping credit.");
                    return;
                }

                // Pessimistic row locking on recipient user row
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                if (!$lockedUser) {
                    return;
                }

                // Credit wallet balance
                $lockedUser->increment('wallet_balance', $amount);

                // Record transaction
                $lockedUser->transactions()->create([
                    'reference' => $reference,
                    'type' => 'deposit',
                    'direction' => 'in',
                    'title' => 'Bank Transfer Inflow',
                    'subtitle' => 'From ' . $payerName,
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'status' => 'successful',
                    'fee' => 0.00,
                    'description' => $data['remark'] ?? ('Deposit to PalmPay virtual account ' . $lockedUser->account_number),
                    'counterparty' => $payerName,
                    'counterparty_bank' => $payerBank,
                ]);

                Log::info("PalmPay Webhook: Successfully credited user #{$lockedUser->id} with ₦{$amount} [Ref: {$reference}]");
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL unique constraint on transactions.reference caught a duplicate race condition
            Log::info("PalmPay Webhook: Race condition caught by unique constraint on {$reference}. Handled safely.");
        } finally {
            optional($lock)->release();
        }

        return response()->json([
            'respCode' => '000000',
            'respMsg' => 'SUCCESS',
        ]);
    }

    /**
     * Handle PalmPay outward payout callback (asynchronous settlement or rejection).
     * Protected by distributed mutex and idempotent pessimistic updates.
     */
    protected function handlePayoutCallback(Transaction $tx, array $data): JsonResponse
    {
        $orderStatus = (int) ($data['orderStatus'] ?? $data['data']['orderStatus'] ?? 0);
        $lockKey = 'palmpay_payout_' . md5($tx->reference);
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 20);

        try {
            $lock->block(10);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json(['respCode' => '000000', 'respMsg' => 'Processing in progress']);
        }

        try {
            DB::transaction(function () use ($tx, $orderStatus, $data) {
                $lockedTx = Transaction::where('id', $tx->id)->lockForUpdate()->first();
                if (!$lockedTx) {
                    return;
                }

                // If transaction is already successful, do nothing (idempotent)
                if ($lockedTx->status === 'successful' && $orderStatus === 2) {
                    return;
                }

                if ($orderStatus === 2) {
                    $lockedTx->update(['status' => 'successful']);
                    Log::info("PalmPay Webhook: Outward payout {$lockedTx->reference} marked successful.");
                } elseif ($orderStatus === 3) {
                    // Only refund if it was not already failed/refunded
                    if ($lockedTx->status !== 'failed') {
                        $refundAmount = (float) $lockedTx->amount + (float) $lockedTx->fee;
                        $lockedUser = User::where('id', $lockedTx->user_id)->lockForUpdate()->first();
                        if ($lockedUser) {
                            $lockedUser->increment('wallet_balance', $refundAmount);
                        }
                        $reason = $data['errorMsg'] ?? $data['respMsg'] ?? 'Gateway rejected payout';
                        $lockedTx->update([
                            'status' => 'failed',
                            'description' => $lockedTx->description . ' (Reversed: ' . $reason . ')',
                        ]);
                        Log::info("PalmPay Webhook: Outward payout {$lockedTx->reference} failed. Refunded ₦{$refundAmount} to user #{$lockedTx->user_id}");
                    }
                }
            });
        } finally {
            optional($lock)->release();
        }

        return response()->json([
            'respCode' => '000000',
            'respMsg' => 'SUCCESS',
        ]);
    }
}
