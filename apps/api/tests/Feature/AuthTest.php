<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_display_name_and_avatar(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sana',
            'email' => 'sana@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'avatar_emoji' => '📚',
            'avatar_color' => '#3b82f6',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.name', 'Sana')
            ->assertJsonPath('user.email', 'sana@example.com')
            ->assertJsonPath('user.avatar_emoji', '📚')
            ->assertJsonPath('user.avatar_color', '#3b82f6');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'sana@example.com']);
    }

    public function test_register_requires_display_name_between_2_and_24_chars(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/auth/register', [
            'name' => str_repeat('a', 25),
            'email' => 'x@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rizwan',
            'email' => 'taken@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_and_logout(): void
    {
        User::factory()->create([
            'email' => 'rizwan@example.com',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'rizwan@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('user.email', 'rizwan@example.com');

        $this->assertAuthenticated();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'rizwan@example.com',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'rizwan@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'banned@example.com',
            'password' => 'secret123',
            'is_suspended' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'banned@example.com',
            'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_guest_cannot_read_or_update_profile(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->patchJson('/api/v1/me', ['name' => 'Hacker'])->assertStatus(401);
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create(['name' => 'Gul']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me', [
                'name' => 'Group Gul',
                'avatar_emoji' => '🦉',
                'show_on_leaderboard' => false,
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Group Gul')
            ->assertJsonPath('user.avatar_emoji', '🦉')
            ->assertJsonPath('user.show_on_leaderboard', false);
    }

    public function test_avatar_must_come_from_the_fixed_set(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me', ['avatar_emoji' => 'NotAllowed'])
            ->assertStatus(422)->assertJsonValidationErrors(['avatar_emoji']);
    }

    public function test_account_deletion_removes_the_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_auth_endpoints_are_rate_limited(): void
    {
        $responses = collect(range(1, 6))->map(fn () => $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ]));

        $responses->last()->assertStatus(429);
    }
}
