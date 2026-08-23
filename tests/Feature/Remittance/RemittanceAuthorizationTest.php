<?php

namespace Tests\Feature\Remittance;

use App\Models\Household;
use App\Models\Remittance;
use App\Models\TransferProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemittanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/v1/remittances';

    private function authenticate(User $user): void
    {
        $this->actingAs($user, 'api');
    }

    private function household(User $owner): Household
    {
        return Household::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    private function remittance(
        User $user,
        Household $household,
        array $overrides = []
    ): Remittance {
        return Remittance::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ], $overrides));
    }

    private function createPayload(Household $household): array
    {
        return [
            'household_id' => $household->id,
            'transfer_provider_id' => TransferProvider::factory()->create()->id,
            'amount_sent' => 100,
            'sent_currency_code' => 'USD',
            'amount_received' => 60000,
            'received_currency_code' => 'XAF',
            'exchange_rate' => 600,
            'rate_source' => 'market-service-official',
            'sent_at' => '2026-08-20',
            'notes' => 'Authorization test',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_show_own_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $remittance = $this->remittance($user, $household);

        $this->authenticate($user);

        $response = $this->getJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $remittance->id);
    }

    public function test_non_owner_cannot_show_remittance(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $this->authenticate($otherUser);

        $response = $this->getJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response->assertForbidden();
    }

    public function test_household_membership_does_not_grant_show_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $household = $this->household($owner);

        $remittance = $this->remittance($owner, $household);

        // Deliberately do not rely on household membership for remittance
        // authorization: RemittancePolicy is based on user ownership.
        $this->authenticate($member);

        $response = $this->getJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_own_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $remittance = $this->remittance($user, $household);

        $this->authenticate($user);

        $response = $this->patchJson(
            self::BASE_URL.'/'.$remittance->id,
            [
                'amount_sent' => 250,
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $remittance->id);

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'user_id' => $user->id,
            'amount_sent' => 250,
        ]);
    }

    public function test_non_owner_cannot_update_remittance(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $this->authenticate($otherUser);

        $response = $this->patchJson(
            self::BASE_URL.'/'.$remittance->id,
            [
                'amount_sent' => 999,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'user_id' => $owner->id,
            'amount_sent' => $remittance->amount_sent,
        ]);
    }

    public function test_household_membership_does_not_grant_update_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $this->authenticate($member);

        $response = $this->patchJson(
            self::BASE_URL.'/'.$remittance->id,
            [
                'amount_sent' => 999,
            ]
        );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_own_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $remittance = $this->remittance($user, $household);

        $this->authenticate($user);

        $response = $this->deleteJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('remittances', [
            'id' => $remittance->id,
        ]);
    }

    public function test_non_owner_cannot_delete_remittance(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $this->authenticate($otherUser);

        $response = $this->deleteJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'deleted_at' => null,
        ]);
    }

    public function test_household_membership_does_not_grant_delete_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $this->authenticate($member);

        $response = $this->deleteJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'deleted_at' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-household ownership
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_access_remittances_across_multiple_households(): void
    {
        $user = User::factory()->create();

        $householdOne = $this->household($user);
        $householdTwo = $this->household($user);

        $remittanceOne = $this->remittance($user, $householdOne);
        $remittanceTwo = $this->remittance($user, $householdTwo);

        $this->authenticate($user);

        $this->getJson(self::BASE_URL.'/'.$remittanceOne->id)
            ->assertOk()
            ->assertJsonPath('data.id', $remittanceOne->id);

        $this->getJson(self::BASE_URL.'/'.$remittanceTwo->id)
            ->assertOk()
            ->assertJsonPath('data.id', $remittanceTwo->id);
    }

    public function test_user_cannot_access_another_users_remittance_in_same_household(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $remittance = $this->remittance($owner, $household);

        $this->authenticate($otherUser);

        $this->getJson(self::BASE_URL.'/'.$remittance->id)
            ->assertForbidden();

        $this->patchJson(
            self::BASE_URL.'/'.$remittance->id,
            ['amount_sent' => 999]
        )->assertForbidden();

        $this->deleteJson(
            self::BASE_URL.'/'.$remittance->id
        )->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Create authorization boundary
    |--------------------------------------------------------------------------
    */

    public function test_authenticated_user_cannot_create_remittance_for_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $this->authenticate($otherUser);

        $payload = $this->createPayload($household);

        $response = $this->postJson(
            self::BASE_URL,
            $payload
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('remittances', [
            'user_id' => $otherUser->id,
            'household_id' => $household->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization must not be bypassed by route/model manipulation
    |--------------------------------------------------------------------------
    */

    public function test_non_owner_cannot_update_owner_id_through_remittance_update(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $this->authenticate($attacker);

        $response = $this->patchJson(
            self::BASE_URL.'/'.$remittance->id,
            [
                'user_id' => $attacker->id,
                'amount_sent' => 999,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_non_owner_cannot_delete_soft_deleted_remittance_as_a_way_to_bypass_authorization(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);
        $remittance = $this->remittance($owner, $household);

        $remittance->delete();

        $this->authenticate($attacker);

        $response = $this->deleteJson(
            self::BASE_URL.'/'.$remittance->id
        );

        $response->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Unknown resources
    |--------------------------------------------------------------------------
    */

    public function test_unknown_remittance_cannot_be_authorized(): void
    {
        $user = User::factory()->create();

        $this->authenticate($user);

        $response = $this->getJson(
            self::BASE_URL.'/'.fake()->uuid()
        );

        $response->assertNotFound();
    }
}
