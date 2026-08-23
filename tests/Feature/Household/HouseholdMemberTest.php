<?php

namespace Tests\Feature\Household;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class HouseholdMemberTest extends TestCase
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

    private function membersUrl(Household $household): string
    {
        return '/api/v1/households/'.$household->id.'/members';
    }

    private function memberUrl(Household $household, HouseholdMember $member): string
    {
        return '/api/v1/households/'.$household->id.'/members/'.$member->id;
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

        $response = $this->getJson($this->membersUrl($household));

        $this->assertErrorResponse($response, 401);
    }

    public function test_store_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this->postJson($this->membersUrl($household), [
            'user_id' => $target->id,
        ]);

        $this->assertErrorResponse($response, 401);
    }

    public function test_show_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());

        $response = $this->getJson($this->memberUrl($household, $member));

        $this->assertErrorResponse($response, 401);
    }

    public function test_update_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());

        $response = $this->patchJson($this->memberUrl($household, $member), [
            'role' => 'admin',
        ]);

        $this->assertErrorResponse($response, 401);
    }

    public function test_delete_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());

        $response = $this->deleteJson($this->memberUrl($household, $member));

        $this->assertErrorResponse($response, 401);
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_list_household_members(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, User::factory()->create());
        $this->addMember($household, User::factory()->create(), 'admin');

        $response = $this
            ->authenticatedRequest($owner)
            ->getJson($this->membersUrl($household));

        $this->assertSuccessResponse($response, 'Household members retrieved successfully.');

        $this->assertCount(2, $response->json('data'));
    }

    public function test_member_can_list_household_members(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser);

        $response = $this
            ->authenticatedRequest($memberUser)
            ->getJson($this->membersUrl($household));

        $this->assertSuccessResponse($response, 'Household members retrieved successfully.');
    }

    public function test_unrelated_user_cannot_list_household_members(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $unrelated = User::factory()->create();

        $response = $this
            ->authenticatedRequest($unrelated)
            ->getJson($this->membersUrl($household));

        $this->assertErrorResponse($response, 403);
    }

    public function test_index_does_not_return_members_from_another_household(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $this->addMember($householdTwo, User::factory()->create());

        $response = $this
            ->authenticatedRequest($ownerOne)
            ->getJson($this->membersUrl($householdOne));

        $this->assertSuccessResponse($response, 'Household members retrieved successfully.');
        $this->assertCount(0, $response->json('data'));
    }

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_add_household_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
            ]);

        $this->assertSuccessResponse($response, 'Household member added successfully.', 201);

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $target->id,
            'role' => 'member',
        ]);
    }

    public function test_admin_can_add_household_member(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $admin, 'admin');

        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($admin)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_member_cannot_add_household_member(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($memberUser)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
            ]);

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_unrelated_user_cannot_add_household_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $unrelated = User::factory()->create();
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($unrelated)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
            ]);

        $this->assertErrorResponse($response, 403);
    }

    public function test_add_member_can_specify_admin_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
                'role' => 'admin',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $target->id,
            'role' => 'admin',
        ]);
    }

    public function test_add_member_rejects_owner_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
                'role' => 'owner',
            ]);

        $this->assertErrorResponse($response, 422);
    }

    public function test_add_member_requires_user_id(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), []);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    public function test_add_member_requires_existing_user(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => 999999,
            ]);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    public function test_add_member_rejects_invalid_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
                'role' => 'superadmin',
            ]);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_cannot_add_household_owner_as_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $owner->id,
            ]);

        $this->assertErrorResponse($response, 409);
    }

    public function test_cannot_add_same_user_twice(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $this->addMember($household, $target);

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
            ]);

        $this->assertErrorResponse($response, 409);

        $this->assertDatabaseCount('household_members', 1);
    }

    public function test_same_user_can_be_added_to_different_households(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();
        $target = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $this->addMember($householdOne, $target);

        $response = $this
            ->authenticatedRequest($ownerTwo)
            ->postJson($this->membersUrl($householdTwo), [
                'user_id' => $target->id,
            ]);

        $response->assertCreated();
    }

    public function test_add_member_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
                'role' => 'admin',
            ]);

        $response->assertCreated();

        $memberId = $response->json('data.id');

        $this->assertNotificationEvent('HOUSEHOLD_MEMBER_ADDED', (string) $target->id, [
            'memberId' => $memberId,
            'householdId' => $household->id,
            'userId' => $target->id,
            'role' => 'admin',
        ]);
    }

    public function test_notification_failure_does_not_break_add_member(): void
    {
        Http::fake([
            self::NOTIFICATION_URL => Http::response(null, 500),
        ]);

        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();

        $response = $this
            ->authenticatedRequest($owner)
            ->postJson($this->membersUrl($household), [
                'user_id' => $target->id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $target->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_household_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();
        $member = $this->addMember($household, $target);

        $response = $this
            ->authenticatedRequest($owner)
            ->getJson($this->memberUrl($household, $member));

        $this->assertSuccessResponse($response, 'Household member retrieved successfully.');
        $response->assertJsonPath('data.id', $member->id);
    }

    public function test_member_can_view_household_member(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser);

        $another = User::factory()->create();
        $anotherMember = $this->addMember($household, $another);

        $response = $this
            ->authenticatedRequest($memberUser)
            ->getJson($this->memberUrl($household, $anotherMember));

        $this->assertSuccessResponse($response, 'Household member retrieved successfully.');
    }

    public function test_unrelated_user_cannot_view_household_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());
        $unrelated = User::factory()->create();

        $response = $this
            ->authenticatedRequest($unrelated)
            ->getJson($this->memberUrl($household, $member));

        $this->assertErrorResponse($response, 403);
    }

    public function test_member_from_different_household_returns_not_found(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $member = $this->addMember($householdTwo, User::factory()->create());

        $response = $this
            ->authenticatedRequest($ownerOne)
            ->getJson($this->memberUrl($householdOne, $member));

        $this->assertErrorResponse($response, 404);
    }

    public function test_unknown_member_returns_not_found(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $response = $this
            ->authenticatedRequest($owner)
            ->getJson($this->membersUrl($household).'/00000000-0000-0000-0000-000000000000');

        $this->assertErrorResponse($response, 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Role
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_member_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();
        $member = $this->addMember($household, $target, 'member');

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'admin',
            ]);

        $this->assertSuccessResponse($response, 'Household member updated successfully.');

        $this->assertDatabaseHas('household_members', [
            'id' => $member->id,
            'role' => 'admin',
        ]);
    }

    public function test_admin_cannot_update_member_role(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $admin, 'admin');

        $target = User::factory()->create();
        $member = $this->addMember($household, $target, 'member');

        $response = $this
            ->authenticatedRequest($admin)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'admin',
            ]);

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseHas('household_members', [
            'id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_member_cannot_update_member_role(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $target = User::factory()->create();
        $member = $this->addMember($household, $target, 'member');

        $response = $this
            ->authenticatedRequest($memberUser)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'admin',
            ]);

        $this->assertErrorResponse($response, 403);
    }

    public function test_update_rejects_owner_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create(), 'member');

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'owner',
            ]);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_update_requires_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $member), []);

        $this->assertErrorResponse($response, 422);
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_update_rejects_invalid_role(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'superadmin',
            ]);

        $this->assertErrorResponse($response, 422);
    }

    public function test_update_can_demote_admin_to_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create(), 'admin');

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'member',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('household_members', [
            'id' => $member->id,
            'role' => 'member',
        ]);
    }

    public function test_household_owner_role_cannot_be_changed(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $ownerMembership = HouseholdMember::factory()->owner()->create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
        ]);

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $ownerMembership), [
                'role' => 'admin',
            ]);

        $this->assertErrorResponse($response, 400);

        $this->assertDatabaseHas('household_members', [
            'id' => $ownerMembership->id,
            'role' => 'owner',
        ]);
    }

    public function test_update_member_from_different_household_returns_not_found(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $member = $this->addMember($householdTwo, User::factory()->create());

        $response = $this
            ->authenticatedRequest($ownerOne)
            ->patchJson($this->memberUrl($householdOne, $member), [
                'role' => 'admin',
            ]);

        $this->assertErrorResponse($response, 403);
    }

    public function test_update_member_role_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();
        $member = $this->addMember($household, $target, 'member');

        $response = $this
            ->authenticatedRequest($owner)
            ->patchJson($this->memberUrl($household, $member), [
                'role' => 'admin',
            ]);

        $response->assertOk();

        $this->assertNotificationEvent('HOUSEHOLD_MEMBER_ROLE_UPDATED', (string) $target->id, [
            'memberId' => $member->id,
            'householdId' => $household->id,
            'userId' => $target->id,
            'role' => 'admin',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_remove_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create(), 'member');

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->memberUrl($household, $member));

        $this->assertSuccessResponse($response, 'Household member removed successfully.');

        $this->assertDatabaseMissing('household_members', [
            'id' => $member->id,
        ]);
    }

    public function test_owner_can_remove_admin(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create(), 'admin');

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->memberUrl($household, $member));

        $response->assertOk();

        $this->assertDatabaseMissing('household_members', [
            'id' => $member->id,
        ]);
    }

    public function test_admin_can_remove_regular_member(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $admin, 'admin');

        $member = $this->addMember($household, User::factory()->create(), 'member');

        $response = $this
            ->authenticatedRequest($admin)
            ->deleteJson($this->memberUrl($household, $member));

        $response->assertOk();

        $this->assertDatabaseMissing('household_members', [
            'id' => $member->id,
        ]);
    }

    public function test_admin_cannot_remove_another_admin(): void
    {
        $owner = User::factory()->create();
        $adminOne = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $adminOne, 'admin');

        $adminTwoMembership = $this->addMember($household, User::factory()->create(), 'admin');

        $response = $this
            ->authenticatedRequest($adminOne)
            ->deleteJson($this->memberUrl($household, $adminTwoMembership));

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseHas('household_members', [
            'id' => $adminTwoMembership->id,
        ]);
    }

    public function test_member_cannot_remove_member(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $household = $this->createHousehold($owner);
        $this->addMember($household, $memberUser, 'member');

        $target = $this->addMember($household, User::factory()->create(), 'member');

        $response = $this
            ->authenticatedRequest($memberUser)
            ->deleteJson($this->memberUrl($household, $target));

        $this->assertErrorResponse($response, 403);
    }

    public function test_unrelated_user_cannot_remove_member(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());
        $unrelated = User::factory()->create();

        $response = $this
            ->authenticatedRequest($unrelated)
            ->deleteJson($this->memberUrl($household, $member));

        $this->assertErrorResponse($response, 403);
    }

    public function test_household_owner_cannot_be_removed(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);

        $ownerMembership = HouseholdMember::factory()->owner()->create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
        ]);

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->memberUrl($household, $ownerMembership));

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseHas('household_members', [
            'id' => $ownerMembership->id,
        ]);
    }

    public function test_member_from_different_household_cannot_be_removed(): void
    {
        $ownerOne = User::factory()->create();
        $ownerTwo = User::factory()->create();

        $householdOne = $this->createHousehold($ownerOne);
        $householdTwo = $this->createHousehold($ownerTwo);

        $member = $this->addMember($householdTwo, User::factory()->create());

        $response = $this
            ->authenticatedRequest($ownerOne)
            ->deleteJson($this->memberUrl($householdOne, $member));

        $this->assertErrorResponse($response, 403);

        $this->assertDatabaseHas('household_members', [
            'id' => $member->id,
        ]);
    }

    public function test_remove_member_emits_notification_event(): void
    {
        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $target = User::factory()->create();
        $member = $this->addMember($household, $target, 'member');

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->memberUrl($household, $member));

        $response->assertOk();

        $this->assertNotificationEvent('HOUSEHOLD_MEMBER_REMOVED', (string) $target->id, [
            'memberId' => $member->id,
            'householdId' => $household->id,
            'userId' => $target->id,
        ]);
    }

    public function test_notification_failure_does_not_break_member_removal(): void
    {
        Http::fake([
            self::NOTIFICATION_URL => Http::response(null, 500),
        ]);

        $owner = User::factory()->create();
        $household = $this->createHousehold($owner);
        $member = $this->addMember($household, User::factory()->create());

        $response = $this
            ->authenticatedRequest($owner)
            ->deleteJson($this->memberUrl($household, $member));

        $response->assertOk();

        $this->assertDatabaseMissing('household_members', [
            'id' => $member->id,
        ]);
    }
}
