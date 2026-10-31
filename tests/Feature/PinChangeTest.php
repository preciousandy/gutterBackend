<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PinChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_change_pin(): void
    {
        $response = $this->postJson('/api/v1/pin/change', [
            'current_pin' => '1357',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_with_existing_pin_must_provide_correct_current_pin(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1357'),
            'has_pin' => true,
        ]);

        // Wrong current PIN
        $response = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'current_pin' => '9999',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_pin']);
    }

    public function test_user_can_verify_with_password_if_current_pin_is_unknown(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('SecretPassword123!'),
            'pin' => Hash::make('1357'),
            'has_pin' => true,
        ]);

        // Wrong password
        $resWrong = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'password' => 'WrongPassword',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ]);
        $resWrong->assertStatus(422)->assertJsonValidationErrors(['password']);

        // Correct password
        $resCorrect = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'password' => 'SecretPassword123!',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ]);
        $resCorrect->assertStatus(200)
            ->assertJsonPath('message', 'Transaction PIN changed successfully.');

        $user->refresh();
        $this->assertTrue(Hash::check('2468', $user->pin));
    }

    public function test_user_cannot_set_same_pin_or_weak_sequence(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1357'),
            'has_pin' => true,
        ]);

        // Same as current PIN
        $resSame = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'current_pin' => '1357',
            'pin' => '1357',
            'pin_confirmation' => '1357',
        ]);
        $resSame->assertStatus(422)->assertJsonValidationErrors(['pin']);

        // Sequence
        $resSeq = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'current_pin' => '1357',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ]);
        $resSeq->assertStatus(422)->assertJsonValidationErrors(['pin']);

        // Repeats
        $resRepeat = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'current_pin' => '1357',
            'pin' => '0000',
            'pin_confirmation' => '0000',
        ]);
        $resRepeat->assertStatus(422)->assertJsonValidationErrors(['pin']);
    }

    public function test_user_successfully_changes_pin_with_valid_current_pin(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('1357'),
            'has_pin' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/pin/change', [
            'current_pin' => '1357',
            'pin' => '2468',
            'pin_confirmation' => '2468',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Transaction PIN changed successfully.')
            ->assertJsonPath('user.has_pin', true);

        $user->refresh();
        $this->assertTrue(Hash::check('2468', $user->pin));
    }

    public function test_user_cannot_verify_pin_if_not_set(): void
    {
        $user = User::factory()->create([
            'pin' => null,
            'has_pin' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/pin/verify', [
            'pin' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_user_fails_verification_with_wrong_pin(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('2468'),
            'has_pin' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/pin/verify', [
            'pin' => '9999',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pin'])
            ->assertJsonPath('message', 'Incorrect PIN. Please try again.');
    }

    public function test_user_successfully_verifies_correct_pin(): void
    {
        $user = User::factory()->create([
            'pin' => Hash::make('2468'),
            'has_pin' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/pin/verify', [
            'pin' => '2468',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('message', 'PIN verified successfully.');
    }
}
