<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportComplaintTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_submit_complaint_successfully(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/support/complaint', [
            'category' => 'airtime_data',
            'reference' => '202609291150ndrca9',
            'message' => 'I was debited ₦1,000 for Glo airtime but my line has not received it.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('category', 'airtime_data')
            ->assertJsonStructure(['message', 'ticket_id', 'category']);
    }

    public function test_complaint_submission_requires_message(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/support/complaint', [
            'category' => 'bank_transfer',
            'message' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }
}
