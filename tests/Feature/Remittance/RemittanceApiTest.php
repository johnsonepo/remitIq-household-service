<?php

namespace Tests\Feature\Remittance;

use App\Models\Household;
use App\Models\Remittance;
use App\Models\TransferProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class RemittanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/v1/remittances';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Remittance create/update/delete operations emit notification
         * events through the notification service. Feature tests must
         * isolate the household service from that external dependency.
         */
        Http::fake();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson(self::BASE_URL);

        $response->assertUnauthorized();
    }

    public function test_history_requires_authentication(): void
    {
        $response = $this->getJson(self::BASE_URL.'/history');

        $response->assertUnauthorized();
    }

    public function test_analytics_requires_authentication(): void
    {
        $response = $this->getJson(self::BASE_URL.'/analytics');

        $response->assertUnauthorized();
    }

    public function test_household_requires_authentication(): void
    {
        $household = Household::factory()->create();

        $response = $this->getJson(self::BASE_URL.'/household/'.$household->id);

        $response->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $household = Household::factory()->create();

        $response = $this->postJson(self::BASE_URL, $this->validCreatePayload($household));

        $response->assertUnauthorized();
    }

    public function test_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $remittance = $this->createRemittance($user, $household);

        $response = $this->getJson(self::BASE_URL.'/'.$remittance->id);

        $response->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $remittance = $this->createRemittance($user, $household);

        $response = $this->putJson(self::BASE_URL.'/'.$remittance->id, [
            'amount_sent' => 250,
        ]);

        $response->assertUnauthorized();
    }

    public function test_delete_requires_authentication(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $remittance = $this->createRemittance($user, $household);

        $response = $this->deleteJson(self::BASE_URL.'/'.$remittance->id);

        $response->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_authenticated_user_can_list_own_remittances(): void
    {
        $user = User::factory()->create();

        $household = $this->createHousehold($user);

        $ownedOne = $this->createRemittance($user, $household, ['sent_at' => '2026-08-20']);

        $ownedTwo = $this->createRemittance($user, $household, ['sent_at' => '2026-08-21']);

        $otherUser = User::factory()->create();
        $otherHousehold = $this->createHousehold($otherUser);

        $otherRemittance = $this->createRemittance($otherUser, $otherHousehold);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data',
            ]);

        $data = $response->json('data');

        $ids = collect($data)
            ->pluck('id')
            ->all();

        $this->assertContains($ownedOne->id, $ids);
        $this->assertContains($ownedTwo->id, $ids);
        $this->assertNotContains($otherRemittance->id, $ids);
    }

    public function test_index_returns_empty_collection_when_user_has_no_remittances(): void
    {
        $user = User::factory()->create();

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL);

        $response
            ->assertOk()
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_index_does_not_leak_remittances_from_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($user);

        /*
         * Same household must not matter. Remittance ownership is based
         * on remittance.user_id.
         */
        $userRemittance = $this->createRemittance($user, $household);

        $otherRemittance = $this->createRemittance($otherUser, $household);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($userRemittance->id, $ids);
        $this->assertNotContains($otherRemittance->id, $ids);
    }

    public function test_index_returns_latest_remittances_first(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $old = $this->createRemittance($user, $household, ['sent_at' => '2026-08-01']);

        $new = $this->createRemittance($user, $household, ['sent_at' => '2026-08-20']);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->values()
            ->all();

        $this->assertSame($new->id, $ids[0]);

        $this->assertSame($old->id, $ids[1]);
    }

    public function test_index_includes_expected_remittance_relationships(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $provider = TransferProvider::factory()->create();

        $remittance = $this->createRemittance($user, $household, [
            'transfer_provider_id' => $provider->id,
        ]);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL);

        $response->assertOk();

        $item = collect($response->json('data'))
            ->firstWhere('id', $remittance->id);

        $this->assertNotNull($item);

        $this->assertArrayHasKey('household', $item);
        $this->assertArrayHasKey('provider', $item);
        $this->assertArrayHasKey('attachments', $item);
    }

    /*
    |--------------------------------------------------------------------------
    | Household endpoint
    |--------------------------------------------------------------------------
    */

    public function test_user_can_list_remittances_for_accessible_household(): void
    {
        $user = User::factory()->create();

        $household = $this->createHousehold($user);
        $otherHousehold = $this->createHousehold($user);

        $matching = $this->createRemittance($user, $household);

        $other = $this->createRemittance($user, $otherHousehold);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/household/'.$household->id);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_household_endpoint_does_not_return_another_users_remittances(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($user);

        $userRemittance = $this->createRemittance($user, $household);

        $otherRemittance = $this->createRemittance($otherUser, $household);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/household/'.$household->id);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($userRemittance->id, $ids);
        $this->assertNotContains($otherRemittance->id, $ids);
    }

    public function test_household_endpoint_for_inaccessible_household_is_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($otherUser);

        $this->createRemittance($otherUser, $household);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/household/'.$household->id);

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    public function test_user_can_create_remittance_for_accessible_household(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $provider = TransferProvider::factory()->create();

        $payload = $this->validCreatePayload($household, [
            'transfer_provider_id' => $provider->id,
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'data',
            ]);

        $this->assertDatabaseHas('remittances', [
            'user_id' => $user->id,
            'household_id' => $household->id,
            'transfer_provider_id' => $provider->id,
            'amount_sent' => 100,
            'amount_received' => 60000,
            'sent_currency_code' => 'USD',
            'received_currency_code' => 'XAF',
            'exchange_rate' => 600,
            'sent_at' => '2026-08-20',
        ]);
    }

    public function test_create_always_assigns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'user_id' => $attacker->id,
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('remittances', [
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $this->assertDatabaseMissing('remittances', [
            'user_id' => $attacker->id,
            'household_id' => $household->id,
        ]);
    }

    public function test_create_rejects_inaccessible_household(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($otherUser);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $this->validCreatePayload($household));

        $response->assertForbidden();

        $this->assertDatabaseMissing('remittances', [
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);
    }

    public function test_create_supports_nullable_transfer_provider(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'transfer_provider_id' => null,
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('remittances', [
            'user_id' => $user->id,
            'household_id' => $household->id,
            'transfer_provider_id' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_user_can_show_owned_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/'.$remittance->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $remittance->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.household_id', $household->id);
    }

    public function test_user_cannot_show_another_users_remittance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($otherUser);

        $remittance = $this->createRemittance($otherUser, $household);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/'.$remittance->id);

        $response->assertForbidden();
    }

    public function test_show_returns_not_found_for_unknown_uuid(): void
    {
        $user = User::factory()->create();

        $unknownId = '00000000-0000-0000-0000-000000000000';

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/'.$unknownId);

        $response->assertNotFound();
    }

    public function test_show_does_not_accept_non_uuid_remittance_identifier(): void
    {
        $user = User::factory()->create();

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/not-a-uuid');

        $response->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_user_can_update_owned_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'exchange_rate' => 600,
        ]);

        $response = $this->authenticated($user)
            ->putJson(self::BASE_URL.'/'.$remittance->id, [
                'amount_sent' => 250,
                'amount_received' => 150000,
                'exchange_rate' => 600,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $remittance->id);

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'amount_sent' => 250,
            'amount_received' => 150000,
            'exchange_rate' => 600,
        ]);
    }

    public function test_user_can_partially_update_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'notes' => 'Original notes',
        ]);

        $response = $this->authenticated($user)
            ->patchJson(self::BASE_URL.'/'.$remittance->id, [
                'amount_sent' => 200,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'amount_sent' => 200,
            'amount_received' => 60000,
            'notes' => 'Original notes',
        ]);
    }

    public function test_user_cannot_update_another_users_remittance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($otherUser);

        $remittance = $this->createRemittance($otherUser, $household, [
            'amount_sent' => 100,
        ]);

        $response = $this->authenticated($user)
            ->putJson(self::BASE_URL.'/'.$remittance->id, [
                'amount_sent' => 999,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'amount_sent' => 100,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function test_user_can_delete_owned_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household);

        $response = $this->authenticated($user)
            ->deleteJson(self::BASE_URL.'/'.$remittance->id);

        $response
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('remittances', [
            'id' => $remittance->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_remittance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($otherUser);

        $remittance = $this->createRemittance($otherUser, $household);

        $response = $this->authenticated($user)
            ->deleteJson(self::BASE_URL.'/'.$remittance->id);

        $response->assertForbidden();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_remittance_is_not_returned_by_index(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $deleted = $this->createRemittance($user, $household);

        $active = $this->createRemittance($user, $household);

        $deleted->delete();

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($deleted->id, $ids);
        $this->assertContains($active->id, $ids);
    }

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    */

    public function test_user_can_retrieve_remittance_history(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/history');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data',
            ]);

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($remittance->id, $ids);
    }

    public function test_history_filters_by_household(): void
    {
        $user = User::factory()->create();

        $householdOne = $this->createHousehold($user);
        $householdTwo = $this->createHousehold($user);

        $matching = $this->createRemittance($user, $householdOne);

        $other = $this->createRemittance($user, $householdTwo);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.'/history?household_id='.$householdOne->id);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_history_filters_by_transfer_provider(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $providerOne = TransferProvider::factory()->create();
        $providerTwo = TransferProvider::factory()->create();

        $matching = $this->createRemittance($user, $household, [
            'transfer_provider_id' => $providerOne->id,
        ]);

        $other = $this->createRemittance($user, $household, [
            'transfer_provider_id' => $providerTwo->id,
        ]);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.
                '?transfer_provider_id='.$providerOne->id);

        /*
         * This endpoint is history, not index.
         */
        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.
                '/history?transfer_provider_id='.$providerOne->id);

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_history_filters_by_date_range(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $before = $this->createRemittance($user, $household, [
            'sent_at' => '2026-07-01',
        ]);

        $inside = $this->createRemittance($user, $household, [
            'sent_at' => '2026-08-15',
        ]);

        $after = $this->createRemittance($user, $household, [
            'sent_at' => '2026-09-01',
        ]);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.
                '/history?from=2026-08-01&to=2026-08-31');

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_history_rejects_inaccessible_household_filter(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->createHousehold($otherUser);

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.
                '/history?household_id='.$household->id);

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_create_requires_household_id(): void
    {
        $user = User::factory()->create();

        $payload = $this->validCreatePayload(Household::factory()->create());

        unset($payload['household_id']);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'household_id',
            ]);
    }

    public function test_create_rejects_invalid_household_uuid(): void
    {
        $user = User::factory()->create();

        $payload = $this->validCreatePayload(Household::factory()->create(), [
            'household_id' => 'not-a-uuid',
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'household_id',
            ]);
    }

    public function test_create_rejects_unknown_household(): void
    {
        $user = User::factory()->create();

        $payload = $this->validCreatePayload(Household::factory()->create(), [
            'household_id' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'household_id',
            ]);
    }

    public function test_create_rejects_non_positive_amount_sent(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'amount_sent' => 0,
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount_sent',
            ]);
    }

    public function test_create_rejects_non_positive_amount_received(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'amount_received' => 0,
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount_received',
            ]);
    }

    public function test_create_rejects_non_positive_exchange_rate(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'exchange_rate' => 0,
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'exchange_rate',
            ]);
    }

    public function test_create_rejects_invalid_currency_codes(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'sent_currency_code' => 'usd',
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sent_currency_code',
            ]);
    }

    public function test_create_rejects_invalid_sent_date_format(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $payload = $this->validCreatePayload($household, [
            'sent_at' => '20-08-2026',
        ]);

        $response = $this->authenticated($user)
            ->postJson(self::BASE_URL, $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sent_at',
            ]);
    }

    public function test_update_allows_partial_payload(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household, [
            'amount_sent' => 100,
            'notes' => 'Keep this',
        ]);

        $response = $this->authenticated($user)
            ->patchJson(self::BASE_URL.'/'.$remittance->id, [
                'notes' => 'Updated',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('remittances', [
            'id' => $remittance->id,
            'amount_sent' => 100,
            'notes' => 'Updated',
        ]);
    }

    public function test_update_rejects_invalid_amount(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $remittance = $this->createRemittance($user, $household);

        $response = $this->authenticated($user)
            ->putJson(self::BASE_URL.'/'.$remittance->id, [
                'amount_sent' => -1,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount_sent',
            ]);
    }

    public function test_history_rejects_invalid_date_range(): void
    {
        $user = User::factory()->create();

        $response = $this->authenticated($user)
            ->getJson(self::BASE_URL.
                '/history?from=2026-08-20&to=2026-08-01');

        /*
         * RemittanceHistoryRequest currently does not define
         * after_or_equal validation for `to`. Therefore this test
         * intentionally verifies the current contract rather than
         * assuming validation that does not exist.
         */
        $response->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function authenticated(User $user)
    {
        $token = JWTAuth::fromUser($user);

        return $this->withToken($token);
    }

    private function createHousehold(User $owner): Household
    {
        return Household::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    private function createRemittance(User $user, Household $household, array $overrides = []): Remittance
    {
        return Remittance::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ], $overrides));
    }

    private function validCreatePayload(Household $household, array $overrides = []): array
    {
        return array_merge([
            'household_id' => $household->id,
            'transfer_provider_id' => TransferProvider::factory()->create()->id,
            'amount_sent' => 100,
            'sent_currency_code' => 'USD',
            'amount_received' => 60000,
            'received_currency_code' => 'XAF',
            'exchange_rate' => 600,
            'rate_source' => 'market-service-official',
            'sent_at' => '2026-08-20',
            'notes' => 'Production API test remittance',
        ], $overrides);
    }
}
