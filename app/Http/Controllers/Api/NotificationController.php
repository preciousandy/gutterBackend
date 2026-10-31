<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get account notifications based on user activity, security, and transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $readIds = $user->read_notification_ids ?? [];
        $readAt = $user->notifications_read_at;

        $notifications = [];

        // 1. KYC / Tier notification
        if ($user->kyc_status === 'verified') {
            $createdAt = $user->updated_at ?? now();
            $isRead = in_array('kyc_verified', $readIds) || ($readAt && $createdAt->lte($readAt));
            $notifications[] = [
                'id' => 'kyc_verified',
                'category' => 'system',
                'title' => 'Identity Verified (Tier 2 Active)',
                'message' => 'Your ID verification was approved! You can now transfer up to ₦5,000,000 daily.',
                'icon' => 'shield-checkmark',
                'icon_color' => '#00D09E',
                'read' => (bool) $isRead,
                'created_at' => $createdAt->diffForHumans(),
            ];
        } else {
            $createdAt = $user->created_at ?? now();
            $isRead = in_array('kyc_prompt', $readIds) || ($readAt && $createdAt->lte($readAt));
            $notifications[] = [
                'id' => 'kyc_prompt',
                'category' => 'system',
                'title' => 'Upgrade to Tier 2',
                'message' => 'Complete your KYC identity verification to increase your daily limit to ₦5,000,000.',
                'icon' => 'alert-circle',
                'icon_color' => '#E59A00',
                'read' => (bool) $isRead,
                'created_at' => $createdAt->diffForHumans(),
            ];
        }

        // 2. PIN setup notification
        if ($user->has_pin) {
            $createdAt = $user->updated_at ?? now();
            $isRead = in_array('pin_active', $readIds) || ($readAt && $createdAt->lte($readAt));
            $notifications[] = [
                'id' => 'pin_active',
                'category' => 'security',
                'title' => 'Transaction PIN Active',
                'message' => 'Your 4-digit transaction PIN is secured and protecting your transfers.',
                'icon' => 'lock-closed',
                'icon_color' => '#12306E',
                'read' => (bool) $isRead,
                'created_at' => $createdAt->diffForHumans(),
            ];
        }

        // 3. Transactions notifications (from recent user transactions)
        if (method_exists($user, 'transactions')) {
            $recentTx = $user->transactions()->latest()->take(5)->get();
            foreach ($recentTx as $tx) {
                $isOut = $tx->direction === 'out';
                $formattedAmount = '₦' . number_format((float) $tx->amount, 2);
                $txId = 'tx_' . $tx->id;
                $txDate = $tx->created_at ?? now();
                $isRead = in_array($txId, $readIds) || ($readAt && $txDate->lte($readAt));
                $notifications[] = [
                    'id' => $txId,
                    'category' => 'transactions',
                    'title' => $isOut ? 'Transfer Outgoing' : 'Money Received',
                    'message' => $isOut
                        ? "Sent {$formattedAmount} to {$tx->counterparty}."
                        : "Received {$formattedAmount} in your Gutter wallet.",
                    'icon' => $isOut ? 'arrow-up-circle' : 'arrow-down-circle',
                    'icon_color' => $isOut ? '#E25345' : '#00D09E',
                    'read' => (bool) $isRead,
                    'created_at' => $txDate->diffForHumans(),
                ];
            }
        }

        // 4. Welcome notification
        $welcomeAt = $user->created_at ?? now();
        $isWelcomeRead = in_array('welcome', $readIds) || ($readAt && $welcomeAt->lte($readAt));
        $notifications[] = [
            'id' => 'welcome',
            'category' => 'system',
            'title' => 'Welcome to Gutter',
            'message' => 'Your seamless multi-currency & fintech banking account is ready to use.',
            'icon' => 'sparkles',
            'icon_color' => '#12306E',
            'read' => (bool) $isWelcomeRead,
            'created_at' => $welcomeAt->diffForHumans(),
        ];

        return response()->json([
            'data' => $notifications,
            'unread_count' => count(array_filter($notifications, fn($n) => !$n['read'])),
        ]);
    }

    /**
     * Mark an individual notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $readIds = $user->read_notification_ids ?? [];
        if (!in_array($id, $readIds, true)) {
            $readIds[] = $id;
            $user->update(['read_notification_ids' => $readIds]);
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'id' => $id,
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'notifications_read_at' => now(),
        ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ]);
    }
}
