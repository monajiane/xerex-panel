<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'name'     => 'Test User',
            'email'    => 'test@example.com',
            'password' => 'super-secret-1234',
        ]);

        $res->assertCreated();
        $res->assertJsonStructure(['user' => ['id', 'email'], 'token']);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    public function test_user_can_login(): void
    {
        User::factory()->create([
            'email'    => 'login@example.com',
            'password' => bcrypt('super-secret-1234'),
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email'    => 'login@example.com',
            'password' => 'super-secret-1234',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['user' => ['id', 'email'], 'token']);
    }

    public function test_login_rejects_bad_password(): void
    {
        User::factory()->create([
            'email'    => 'login@example.com',
            'password' => bcrypt('super-secret-1234'),
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email'    => 'login@example.com',
            'password' => 'WRONG',
        ]);

        $res->assertStatus(401);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create();
        $res = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me');
        $res->assertOk();
        $res->assertJsonPath('email', $user->email);
    }
}
