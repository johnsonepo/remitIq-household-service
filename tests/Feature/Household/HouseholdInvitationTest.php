<?php

namespace Tests\Feature\Household;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class HouseholdInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const NOTIFICATION_URL = 'http://notification-service.test/api/v1/events';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.notification.url' => self::NOTIFICATION_URL,
            'services.notification.api_key' => 'test-api-key',
            'services.notification.timeout' => 5,
        ]);

        Http::fake();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function authenticatedRequest(User $user): static
    {
        return $this->withToken($this->tokenFor($user));
    }

    private function createHousehold(User $owner, array $overrides = []): Household
    {
        return Household::factory()->create(array_merge([
            'owner_id' => $owner->id,
        ], $overrides));
    }

    private function addMember(Household $household, User $user, string $role = 'member'): HouseholdMember
    {
        return HouseholdMember::factory()->create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    private function createInvitation(Household $household, User $inviter, array $overrides = []): HouseholdInvitation
    {
        return HouseholdInvitation::factory()->create(array_merge([
            'household_id' => $household->id,
            'invited_by' => $inviter->id,
        ], $overrides));
    }

    private function invitationsUrl(Household $household): string
    {
        return '/api/v1/households/'.$household->id.'/invitations';
    }

    private function invitationUrl(Household $household, HouseholdInvitation $invitation): string
    {
        return '/api/v1/households/'.$household->id.'/invitations/'.$invitation->id;
    }

    private function assertSuccessResponse($response, string $message, int $status = 200): void
    {
        $response
            ->assertStatus($status)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', $message)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'meta',
                'timestamp',
            ]);

        $this->assertNotEmpty($response->json('timestamp'));
    }

    private function assertErrorResponse($response, int $status): void
    {
        $response
            ->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
                'meta',
                'timestamp',
            ]);
    }

    private function assertNotificationEvent(string $eventType, string $userId, array $data): void
    {
        Http::assertSent(function ($request) use ($eventType, $userId, $data): bool {
            $payload = $request->data();

            return $request->url() === self::NOTIFICATION_URL
                && $request->method() === 'POST'
                && ($request->header('X-Service-API-Key')[0] ?? null) === 'test-api-key'
                && ($payload['eventType'] ?? null) === $eventType
                && ($payload['userId'] ?? null) === $userId
                && ($payload['source'] ?? null) === 'household-service'
                && isset($payload['eventId'])
                && is_string($payload['eventId'])
                && $payload['eventId'] !== ''
                && isset($payload['timestamp'])
                && is_string($payload['timestamp'])
                && $payload['timestamp'] !== ''
                && ($payload['data'] ?? null) === $data;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_index_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this->getJson($this->invitationsUrl($household));

        $this->assertErrorResponse($response, 401);
    }

    public function test_store_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this->postJson($this->invitationsUrl($household), [
            'email' => 'friend@example.com',
        ]);

        $this->assertErrorResponse($response, 401);
    }

    public function test_show_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner);

        $response = $this->getJson($this->invitationUrl($household, $invitation));

        $this->assertErrorResponse($response, 401);
    }

    public function test_delete_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner);

        $response = $this->deleteJson($this->invitationUrl($household, $invitation));

        $this->assertErrorResponse($response, 401);
    }

    public function test_accept_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner, ['token' => 'a-valid-token']);

        $response = $this->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 401);
    }

    public function test_decline_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner, ['token' => 'a-valid-token']);

        $response = $this->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertErrorResponse($response, 401);
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_list_invitations(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->createInvitation($household, $owner);
        $this->createInvitation($household, $owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->getJson($this->invitationsUrl($household));

        $this->assertSuccessResponse($response, 'Household invitations retrieved successfully.');
        $this->assertCount(2, $response->json('data'));
    }

    public function test_admin_can_list_invitations(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $admin, 'admin');

        $this->createInvitation($household, $owner);

        $response = $this
            ->authenticatedRequest($admin)
            ->getJson($this->invitationsUrl($household));

        $this->assertSuccessResponse($response, 'Household invitations retrieved successfully.');
    }

    public function test_member_cannot_list_invitations(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $response = $this
            ->authenticatedRequest($memberUser)
            ->getJson($this->invitationsUrl($household));

        $this->assertErrorResponse($response, 403);
    }

    public function test_unrelated_user_cannot_list_invitations(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $unrelated = User::factory()->create();

        $response = $this
            ->authenticatedRequest($unrelated)
            ->getJson($this->invitationsUrl($household));

        $this->assertErrorResponse($response, 403);
    }

    public function test_index_does_not_return_invitations_from_another_household(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $this->createInvitation($householdTwo, $ownerTwo);

        $response = $this
            ->authenticatedRequest($ownerOne)
            ->getJson($this->invitationsUrl($householdOne));

        $this->assertSuccessResponse($response, 'Household invitations retrieved successfully.');
        $this->assertCount(0, $response->json('data'));
    }

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_create_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
            ]);

        $this->assertSuccessResponse($response, 'Household invitation created successfully.', 201);

        $this->assertDatabaseHas('household_invitations', [
            'household_id' => $household->id,
            'email' => 'friend@example.com',
            'role' => 'member',
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_create_invitation(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $admin, 'admin');

        $response = $this
            ->authenticatedRequest($admin)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
            ]);

        $response->assertCreated();
    }

    public function test_member_cannot_create_invitation(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $response = $this
            ->authenticatedRequest($memberUser)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
            ]);

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseMissing('household_invitations', [
            'household_id' => $household->id,
            'email' => 'friend@example.com',
        ]);
    }

    public function test_unrelated_user_cannot_create_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $unrelated = User::factory()->create();

        $response = $this
            ->authenticatedRequest($unrelated)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
            ]);

        $this->assertErrorResponse($response, 403);
    }

    public function test_create_invitation_requires_email(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), []);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_create_invitation_rejects_invalid_email(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'not-an-email',
            ]);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_create_invitation_rejects_invalid_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
                'role' => 'owner',
            ]);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_create_invitation_accepts_admin_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
                'role' => 'admin',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('household_invitations', [
            'household_id' => $household->id,
            'email' => 'friend@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_create_invitation_normalizes_email_case_and_whitespace(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => '  Friend@Example.COM  ',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('household_invitations', [
            'household_id' => $household->id,
            'email' => 'friend@example.com',
        ]);
    }

    public function test_duplicate_pending_invitation_returns_existing_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $existing = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
        ]);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.id', $existing->id);

        $this->assertDatabaseCount('household_invitations', 1);
    }

    public function test_new_invitation_can_be_created_after_previous_one_was_declined(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'declined',
        ]);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
            ]);

        $response->assertCreated();

        $this->assertDatabaseCount('household_invitations', 2);
    }

    public function test_create_invitation_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->invitationsUrl($household), [
                'email' => 'friend@example.com',
                'role' => 'admin',
            ]);

        $response->assertCreated();

        $invitationId = $response->json('data.id');

        Http::assertSent(function ($request) use ($invitationId, $owner, $household): bool {
            $payload = $request->data();

            return $request->url() === self::NOTIFICATION_URL
                && ($payload['eventType'] ?? null) === 'HOUSEHOLD_INVITATION_CREATED'
                && ($payload['userId'] ?? null) === (string) $owner->id
                && ($payload['data']['invitationId'] ?? null) === $invitationId
                && ($payload['data']['householdId'] ?? null) === $household->id
                && ($payload['data']['invitedBy'] ?? null) === $owner->id
                && ($payload['data']['email'] ?? null) === 'friend@example.com'
                && ($payload['data']['role'] ?? null) === 'admin'
                && isset($payload['data']['expiresAt']);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->getJson($this->invitationUrl($household, $invitation));

        $this->assertSuccessResponse($response, 'Household invitation retrieved successfully.');
        $response->assertJsonPath('data.id', $invitation->id);
    }

    public function test_member_cannot_view_invitation(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $invitation = $this->createInvitation($household, $owner);

        $response = $this
            ->authenticatedRequest($memberUser)
            ->getJson($this->invitationUrl($household, $invitation));

        $this->assertErrorResponse($response, 403);
    }

    public function test_invitation_from_different_household_returns_not_found(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $invitation = $this->createInvitation($householdTwo, $ownerTwo);

        $response = $this
            ->authenticatedRequest($ownerOne)
            ->getJson($this->invitationUrl($householdOne, $invitation));

        $this->assertErrorResponse($response, 404);
    }

    public function test_unknown_invitation_returns_not_found(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->getJson($this->invitationsUrl($household).'/00000000-0000-0000-0000-000000000000');

        $this->assertErrorResponse($response, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Destroy (Cancel)
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_cancel_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner, ['status' => 'pending']);

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->invitationUrl($household, $invitation));

        $this->assertSuccessResponse($response, 'Household invitation cancelled successfully.');

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'declined',
        ]);
    }

    public function test_admin_can_cancel_invitation(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $admin, 'admin');

        $invitation = $this->createInvitation($household, $owner, ['status' => 'pending']);

        $response = $this
            ->authenticatedRequest($admin)
            ->deleteJson($this->invitationUrl($household, $invitation));

        $response->assertOk();
    }

    public function test_member_cannot_cancel_invitation(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $invitation = $this->createInvitation($household, $owner, ['status' => 'pending']);

        $response = $this
            ->authenticatedRequest($memberUser)
            ->deleteJson($this->invitationUrl($household, $invitation));

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'pending',
        ]);
    }

    public function test_cancelling_already_declined_invitation_does_not_change_status(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner, ['status' => 'accepted']);

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->invitationUrl($household, $invitation));

        $response->assertOk();

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_cancel_invitation_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitation = $this->createInvitation($household, $owner, ['status' => 'pending']);

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->invitationUrl($household, $invitation));

        $response->assertOk();

        $this->assertNotificationEvent('HOUSEHOLD_INVITATION_CANCELLED', (string) $owner->id, [
            'invitationId' => $invitation->id,
            'householdId' => $household->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Accept
    |--------------------------------------------------------------------------
    */

    public function test_invited_user_can_accept_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'role' => 'admin',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertSuccessResponse($response, 'Household invitation accepted successfully.');

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $invitee->id,
            'role' => 'admin',
        ]);

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_accept_rejects_unknown_token(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->authenticatedRequest($user)
            ->postJson('/api/v1/households/invitations/does-not-exist/accept');

        $this->assertErrorResponse($response, 404);
    }

    public function test_accept_rejects_email_mismatch(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($wrongUser)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $wrongUser->id,
        ]);
    }

    public function test_accept_rejects_already_accepted_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'accepted',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 409);
    }

    public function test_accept_rejects_declined_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'declined',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 409);
    }

    public function test_accept_rejects_expired_invitation_and_marks_it_expired(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 400);

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'expired',
        ]);

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_expiring_invitation_via_accept_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->subDay(),
        ]);

        $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertNotificationEvent('HOUSEHOLD_INVITATION_EXPIRED', (string) $owner->id, [
            'invitationId' => $invitation->id,
            'householdId' => $household->id,
        ]);
    }

    public function test_accept_rejects_already_declined_invitation_even_if_also_past_expiry(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'declined',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 409);

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'declined',
        ]);
    }

    public function test_household_owner_cannot_accept_invitation(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $household = $this->createHousehold($owner);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'owner@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 409);
    }

    public function test_accept_rejects_when_user_is_already_a_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $this->addMember($household, $invitee, 'member');

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $this->assertErrorResponse($response, 409);

        $this->assertDatabaseCount('household_members', 1);
    }

    public function test_accept_invitation_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'role' => 'member',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/accept');

        $response->assertOk();

        $this->assertNotificationEvent('HOUSEHOLD_INVITATION_ACCEPTED', (string) $invitee->id, [
            'invitationId' => $invitation->id,
            'householdId' => $household->id,
            'userId' => $invitee->id,
            'role' => 'member',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Decline
    |--------------------------------------------------------------------------
    */

    public function test_invited_user_can_decline_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertSuccessResponse($response, 'Household invitation declined successfully.');

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'declined',
        ]);

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_decline_rejects_unknown_token(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->authenticatedRequest($user)
            ->postJson('/api/v1/households/invitations/does-not-exist/decline');

        $this->assertErrorResponse($response, 404);
    }

    public function test_decline_rejects_email_mismatch(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($wrongUser)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertErrorResponse($response, 403);
    }

    public function test_decline_rejects_already_declined_invitation(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'declined',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertErrorResponse($response, 409);
    }

    public function test_decline_rejects_expired_invitation_and_marks_it_expired(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertErrorResponse($response, 400);

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'expired',
        ]);
    }

    public function test_expiring_invitation_via_decline_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->subDay(),
        ]);

        $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertNotificationEvent('HOUSEHOLD_INVITATION_EXPIRED', (string) $owner->id, [
            'invitationId' => $invitation->id,
            'householdId' => $household->id,
        ]);
    }

    public function test_decline_rejects_already_accepted_invitation_even_if_also_past_expiry(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'accepted',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $this->assertErrorResponse($response, 409);

        $this->assertDatabaseHas('household_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_decline_invitation_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $response->assertOk();

        $this->assertNotificationEvent('HOUSEHOLD_INVITATION_DECLINED', (string) $invitee->id, [
            'invitationId' => $invitation->id,
            'householdId' => $household->id,
            'userId' => $invitee->id,
        ]);
    }

    public function test_declining_invitation_does_not_require_household_membership(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $invitee = User::factory()->create(['email' => 'friend@example.com']);

        $invitation = $this->createInvitation($household, $owner, [
            'email' => 'friend@example.com',
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);

        $response = $this
            ->authenticatedRequest($invitee)
            ->postJson('/api/v1/households/invitations/'.$invitation->token.'/decline');

        $response->assertOk();
    }
}
