<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_fetch_dynamic_notifications(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'unverified',
            'has_pin' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'category',
                        'title',
                        'message',
                        'icon',
                        'icon_color',
                        'read',
                        'created_at',
                    ],
                ],
                'unread_count',
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // Ensure created_at values are relative times like "seconds ago", "just now", "1 minute ago" etc.
        foreach ($data as $item) {
            $this->assertNotEmpty($item['created_at']);
        }
    }

    public function test_user_can_mark_single_notification_read(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'unverified',
        ]);

        Sanctum::actingAs($user);

        $getInitial = $this->getJson('/api/v1/notifications');
        $getInitial->assertOk();
        $this->assertGreaterThan(0, $getInitial->json('unread_count'));

        // Mark 'kyc_prompt' as read
        $markResponse = $this->postJson('/api/v1/notifications/kyc_prompt/read');
        $markResponse->assertOk()
            ->assertJson([
                'message' => 'Notification marked as read.',
                'id' => 'kyc_prompt',
            ]);

        // Re-fetch and check persisted read status
        $getAfter = $this->getJson('/api/v1/notifications');
        $items = collect($getAfter->json('data'));
        $kycNotification = $items->firstWhere('id', 'kyc_prompt');

        $this->assertNotNull($kycNotification);
        $this->assertTrue($kycNotification['read']);
    }

    public function test_user_can_mark_all_notifications_read(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'unverified',
        ]);

        Sanctum::actingAs($user);

        $readAllResponse = $this->postJson('/api/v1/notifications/read-all');
        $readAllResponse->assertOk()
            ->assertJson([
                'message' => 'All notifications marked as read.',
                'unread_count' => 0,
            ]);

        // Re-fetch and verify unread_count is 0
        $getAfter = $this->getJson('/api/v1/notifications');
        $this->assertSame(0, $getAfter->json('unread_count'));
        foreach ($getAfter->json('data') as $item) {
            $this->assertTrue($item['read']);
        }
    }
}
