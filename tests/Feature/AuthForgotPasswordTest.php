<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;
use Illuminate\Support\Facades\RateLimiter;


class AuthForgotPasswordTest extends TestCase
{
    use RefreshDatabase;
    protected string $testIp;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Give every test a unique IP address so that the global
         * throttle:api middleware does not share rate-limit counters
         * between tests.
         */
        $this->testIp = '10.202.'
            .random_int(1, 254)
            .'.'
            .random_int(1, 254);

        /*
         * Clear the common limiter key formats before each test.
         */
        RateLimiter::clear($this->testIp);
        RateLimiter::clear("api|{$this->testIp}");

        /*
         * Apply the unique test IP to every HTTP request.
         */
        $this->withServerVariables([
            'REMOTE_ADDR' => $this->testIp,
        ]);
    }

    /**
     * A valid registered email should receive a password reset notification.
     */
    public function test_registered_user_can_request_password_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.')
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * The forgot-password endpoint must not reveal whether an email
     * belongs to an existing account.
     */
    public function test_nonexistent_email_returns_same_response_as_existing_email(): void
    {
        Notification::fake();

        $existingUser = User::factory()->create();

        $existingResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $existingUser->email,
        ]);

        Notification::assertSentTo($existingUser, ResetPasswordNotification::class);

        Notification::fake();

        $nonexistentResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'nonexistent@example.com',
        ]);

        $existingResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.')
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $nonexistentResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.')
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        Notification::assertNothingSent();
    }

    /**
     * Email is required.
     */
    public function test_forgot_password_requires_email(): void
    {
        $response = $this->postJson('/api/v1/auth/password/forgot', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * Empty email values must be rejected.
     */
    public function test_forgot_password_rejects_empty_email(): void
    {
        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => '',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * Malformed email addresses must be rejected.
     */
    public function test_forgot_password_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'not-an-email',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * Email must be a string.
     */
    public function test_forgot_password_rejects_non_string_email(): void
    {
        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 123456,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * Email addresses longer than the application's supported length
     * should be rejected by the validation layer.
     */
    public function test_forgot_password_rejects_email_longer_than_255_characters(): void
    {
        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => str_repeat('a', 250).'@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * The endpoint must be publicly accessible and must not require JWT
     * authentication.
     */
    public function test_forgot_password_does_not_require_authentication(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * An authenticated user should also be able to use the endpoint.
     *
     * Forgot-password should not depend on the authenticated identity.
     */
    public function test_authenticated_user_can_request_password_reset_for_another_email(): void
    {
        Notification::fake();

        $authenticatedUser = User::factory()->create();
        $targetUser = User::factory()->create();

        $token = $this->loginToken($authenticatedUser);

        $response = $this
            ->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/password/forgot', [
                'email' => $targetUser->email,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        Notification::assertSentTo($targetUser, ResetPasswordNotification::class);

        Notification::assertNotSentTo($authenticatedUser, ResetPasswordNotification::class);
    }

    /**
     * Invalid bearer tokens must not cause the forgot-password endpoint
     * to become dependent on JWT authentication.
     */
    public function test_forgot_password_does_not_require_a_valid_bearer_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer malformed-token',
                'Accept' => 'application/json',
            ])
            ->postJson('/api/v1/auth/password/forgot', [
                'email' => $user->email,
            ]);

        /*
         * The endpoint is intentionally public. If authentication
         * middleware is not attached to the route, the request succeeds.
         */
        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * A valid reset request must not return the password reset token
     * directly in the API response.
     */
    public function test_forgot_password_does_not_expose_reset_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('reset_token')
            ->assertJsonMissingPath('password_reset_token')
            ->assertJsonMissingPath('user')
            ->assertJsonMissingPath('email');
    }

    /**
     * The response must contain no sensitive user information.
     */
    public function test_forgot_password_does_not_expose_sensitive_user_data(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('user')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_confirmation')
            ->assertJsonMissingPath('email');
    }

    /**
     * Requesting a password reset must not modify the user's password.
     */
    public function test_forgot_password_does_not_change_user_password(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => 'OriginalPassword123!',
        ]);

        $passwordHashBefore = $user->password;

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $this->assertSame($passwordHashBefore, $user->fresh()->password);
    }

    /**
     * Requesting a password reset must not verify the user's email.
     */
    public function test_forgot_password_does_not_verify_email(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->assertFalse($user->hasVerifiedEmail());

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * Requesting a password reset must not change the user's account
     * activation state.
     */
    public function test_forgot_password_does_not_change_account_state(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $freshUser = $user->fresh();

        $this->assertTrue($freshUser->is_active);
        $this->assertSame($user->email, $freshUser->email);
        $this->assertSame($user->name, $freshUser->name);
        $this->assertSame($user->username, $freshUser->username);
    }

    /**
     * Password reset requests should work for unverified accounts unless
     * an explicit business rule says otherwise.
     */
    public function test_unverified_user_can_request_password_reset(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * Password reset requests should not expose whether an inactive
     * account exists.
     */
    public function test_inactive_user_receives_same_public_response(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        /*
         * The current AuthService delegates directly to Laravel's password
         * broker and does not explicitly reject inactive accounts.
         */
        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * Password reset notification should be sent exactly once per request.
     */
    public function test_forgot_password_sends_exactly_one_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    /**
     * Email matching should work with the normalized email stored by the
     * application.
     */
    public function test_forgot_password_accepts_registered_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'john@example.com',
        ]);

        $response->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * Unexpected request fields must not affect the operation or become
     * part of the password reset response.
     */
    public function test_forgot_password_ignores_unexpected_fields(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
            'password' => 'MaliciousPassword123!',
            'is_admin' => true,
            'token' => 'malicious-token',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user')
            ->assertJsonMissingPath('password');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * The endpoint should use the password broker rather than directly
     * generating or returning a reset token.
     */
    public function test_forgot_password_uses_password_broker_for_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Password::shouldReceive('broker')
            ->once()
            ->andReturnSelf();

        Password::shouldReceive('sendResetLink')
            ->once()
            ->with([
                'email' => $user->email,
            ])
            ->andReturn(Password::RESET_LINK_SENT);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    /**
     * The endpoint should always use the expected public success message.
     */
    public function test_forgot_password_returns_standard_success_message(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');
    }

    /**
     * The endpoint must not return an authentication token.
     */
    public function test_forgot_password_does_not_authenticate_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('token_type')
            ->assertJsonMissingPath('expires_in');

        $this->assertGuest('api');
    }

    private function loginToken(User $user): string
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

    public function test_multiple_password_reset_requests_are_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $firstResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $secondResponse = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $firstResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        /*
         * The API intentionally hides whether the password-reset request
         * was throttled in order to avoid leaking account state.
         */
        $secondResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.')
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }
}
