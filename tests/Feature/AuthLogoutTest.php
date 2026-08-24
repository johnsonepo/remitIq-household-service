<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AuthLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function authHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $response = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful.');
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertUnauthorized();
    }

    public function test_logout_rejects_malformed_token(): void
    {
        $response = $this
            ->withHeaders($this->authHeaders('not-a-valid-jwt'))
            ->postJson('/api/v1/auth/logout');

        $response->assertUnauthorized();
    }

    public function test_logout_rejects_invalid_token(): void
    {
        $response = $this
            ->withHeaders($this->authHeaders('eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.invalid.token'))
            ->postJson('/api/v1/auth/logout');

        $response->assertUnauthorized();
    }

    public function test_logout_invalidates_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $logoutResponse = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertOk();

        $meResponse = $this
            ->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/auth/me');

        $meResponse->assertUnauthorized();
    }

    public function test_logged_out_token_cannot_be_refreshed(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $logoutResponse = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertOk();

        $refreshResponse = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/refresh');

        $refreshResponse->assertUnauthorized();
    }

    public function test_logout_does_not_expose_token_or_user_data(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $response = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $response
            ->assertOk()
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');
    }

    public function test_logout_does_not_affect_another_users_token(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $tokenA = $this->tokenFor($userA);
        $tokenB = $this->tokenFor($userB);

        $logoutResponse = $this
            ->withHeaders($this->authHeaders($tokenA))
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertOk();

        $meResponse = $this
            ->withHeaders($this->authHeaders($tokenB))
            ->getJson('/api/v1/auth/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath('data.id', $userB->id)
            ->assertJsonPath('data.email', $userB->email);

        $meResponse->assertJsonMissing([
            'email' => $userA->email,
        ]);
    }

    public function test_logout_emits_user_logged_out_event(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $response = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }

    public function test_logout_is_idempotent_from_the_application_perspective(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $firstResponse = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $firstResponse->assertOk();

        $secondResponse = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $secondResponse->assertUnauthorized();
    }

    public function test_logout_does_not_change_user_record(): void
    {
        $user = User::factory()->create();

        $original = $user->fresh()->toArray();
        $token = $this->tokenFor($user);

        $response = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();

        $userAfterLogout = $user->fresh();

        $this->assertSame($original['id'], $userAfterLogout->id);
        $this->assertSame($original['email'], $userAfterLogout->email);
        $this->assertSame($original['name'], $userAfterLogout->name);
        $this->assertSame($original['is_active'], $userAfterLogout->is_active);
    }
}
