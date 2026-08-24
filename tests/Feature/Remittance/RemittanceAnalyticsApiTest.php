<?php

namespace Tests\Feature\Remittance;

use App\Models\Household;
use App\Models\Remittance;
use App\Models\TransferProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemittanceAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/remittances/analytics';

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

    private function provider(array $overrides = []): TransferProvider
    {
        return TransferProvider::factory()->create($overrides);
    }

    private function remittance(User $user, Household $household, TransferProvider $provider, array $overrides = []): Remittance
    {
        return Remittance::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'transfer_provider_id' => $provider->id,
            'amount_sent' => 100,
            'sent_currency_code' => 'USD',
            'amount_received' => 60000,
            'received_currency_code' => 'XAF',
            'exchange_rate' => 600,
            'rate_source' => 'market-service-official',
            'sent_at' => '2026-08-20',
        ], $overrides));
    }

    private function analytics(array $filters = [])
    {
        return $this->getJson(self::ENDPOINT.($filters === []
                ? ''
                : '?'.http_build_query($filters)));
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Basic response
    |--------------------------------------------------------------------------
    */

    public function test_authenticated_user_can_retrieve_analytics(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'count',
                        'total_sent',
                        'total_received',
                        'average_sent',
                        'average_received',
                        'average_exchange_rate',
                    ],
                    'monthly_trend',
                    'providers',
                ],
            ]);
    }

    public function test_analytics_returns_zero_summary_when_user_has_no_remittances(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.count', 0)
            ->assertJsonPath('data.summary.total_sent', 0)
            ->assertJsonPath('data.summary.total_received', 0)
            ->assertJsonPath('data.summary.average_sent', 0)
            ->assertJsonPath('data.summary.average_received', 0)
            ->assertJsonPath('data.summary.average_exchange_rate', 0)
            ->assertJsonPath('data.monthly_trend', [])
            ->assertJsonPath('data.providers', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Summary
    |--------------------------------------------------------------------------
    */

    public function test_analytics_returns_correct_summary(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'exchange_rate' => 600,
            'sent_at' => '2026-08-01',
        ]);

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 200,
            'amount_received' => 124000,
            'exchange_rate' => 620,
            'sent_at' => '2026-08-10',
        ]);

        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.count', 2)
            ->assertJsonPath('data.summary.total_sent', 300)
            ->assertJsonPath('data.summary.total_received', 184000)
            ->assertJsonPath('data.summary.average_sent', 150)
            ->assertJsonPath('data.summary.average_received', 92000)
            ->assertJsonPath('data.summary.average_exchange_rate', 610);
    }

    public function test_analytics_does_not_include_other_users_remittances(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $userHousehold = $this->household($user);
        $otherHousehold = $this->household($otherUser);

        $provider = $this->provider();

        $this->remittance($user, $userHousehold, $provider, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'sent_at' => '2026-08-01',
        ]);

        $this->remittance($otherUser, $otherHousehold, $provider, [
            'amount_sent' => 9999,
            'amount_received' => 999999,
            'sent_at' => '2026-08-01',
        ]);

        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100)
            ->assertJsonPath('data.summary.total_received', 60000);
    }

    /*
    |--------------------------------------------------------------------------
    | Monthly trend
    |--------------------------------------------------------------------------
    */

    public function test_analytics_groups_remittances_by_month(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'exchange_rate' => 600,
            'sent_at' => '2026-01-15',
        ]);

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 200,
            'amount_received' => 124000,
            'exchange_rate' => 620,
            'sent_at' => '2026-01-20',
        ]);

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 300,
            'amount_received' => 195000,
            'exchange_rate' => 650,
            'sent_at' => '2026-02-10',
        ]);

        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.monthly_trend')
            ->assertJsonPath('data.monthly_trend.0.month', '2026-01')
            ->assertJsonPath('data.monthly_trend.0.count', 2)
            ->assertJsonPath('data.monthly_trend.0.total_sent', 300)
            ->assertJsonPath('data.monthly_trend.0.total_received', 184000)
            ->assertJsonPath('data.monthly_trend.0.average_exchange_rate', 610)
            ->assertJsonPath('data.monthly_trend.1.month', '2026-02')
            ->assertJsonPath('data.monthly_trend.1.count', 1)
            ->assertJsonPath('data.monthly_trend.1.total_sent', 300)
            ->assertJsonPath('data.monthly_trend.1.total_received', 195000)
            ->assertJsonPath('data.monthly_trend.1.average_exchange_rate', 650);
    }

    public function test_monthly_trend_is_ordered_chronologically(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'sent_at' => '2026-03-01',
        ]);

        $this->remittance($user, $household, $provider, [
            'sent_at' => '2026-01-01',
        ]);

        $this->remittance($user, $household, $provider, [
            'sent_at' => '2026-02-01',
        ]);

        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonPath('data.monthly_trend.0.month', '2026-01')
            ->assertJsonPath('data.monthly_trend.1.month', '2026-02')
            ->assertJsonPath('data.monthly_trend.2.month', '2026-03');
    }

    /*
    |--------------------------------------------------------------------------
    | Provider aggregation
    |--------------------------------------------------------------------------
    */

    public function test_analytics_groups_remittances_by_provider(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $providerOne = $this->provider();
        $providerTwo = $this->provider();

        $this->remittance($user, $household, $providerOne, [
            'amount_sent' => 100,
            'amount_received' => 60000,
        ]);

        $this->remittance($user, $household, $providerOne, [
            'amount_sent' => 200,
            'amount_received' => 120000,
        ]);

        $this->remittance($user, $household, $providerTwo, [
            'amount_sent' => 300,
            'amount_received' => 180000,
        ]);

        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.providers');

        $providers = $response->json('data.providers');

        $first = collect($providers)
            ->firstWhere('provider_id', $providerOne->id);

        $second = collect($providers)
            ->firstWhere('provider_id', $providerTwo->id);

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->assertSame(2, $first['count']);
        $this->assertSame(300.0, (float) $first['total_sent']);
        $this->assertSame(180000.0, (float) $first['total_received']);

        $this->assertSame(1, $second['count']);
        $this->assertSame(300.0, (float) $second['total_sent']);
        $this->assertSame(180000.0, (float) $second['total_received']);
    }

    public function test_analytics_includes_provider_relationship_data(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider);

        $this->authenticate($user);

        $response = $this->analytics();

        $response
            ->assertOk()
            ->assertJsonPath('data.providers.0.provider.id', $provider->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Household filter
    |--------------------------------------------------------------------------
    */

    public function test_analytics_filters_by_accessible_household(): void
    {
        $user = User::factory()->create();

        $householdOne = $this->household($user);
        $householdTwo = $this->household($user);

        $provider = $this->provider();

        $this->remittance($user, $householdOne, $provider, [
            'amount_sent' => 100,
        ]);

        $this->remittance($user, $householdTwo, $provider, [
            'amount_sent' => 500,
        ]);

        $this->authenticate($user);

        $response = $this->analytics([
            'household_id' => $householdOne->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100);
    }

    public function test_analytics_rejects_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();

        $household = $this->household($owner);
        $provider = $this->provider();

        $this->remittance($owner, $household, $provider);

        $this->authenticate($user);

        $this->analytics([
            'household_id' => $household->id,
        ])->assertForbidden();
    }

    public function test_analytics_rejects_unknown_household(): void
    {
        $user = User::factory()->create();

        $this->authenticate($user);

        $this->analytics([
            'household_id' => fake()->uuid(),
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Provider filter
    |--------------------------------------------------------------------------
    */

    public function test_analytics_filters_by_transfer_provider(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $providerOne = $this->provider();
        $providerTwo = $this->provider();

        $this->remittance($user, $household, $providerOne, [
            'amount_sent' => 100,
        ]);

        $this->remittance($user, $household, $providerTwo, [
            'amount_sent' => 500,
        ]);

        $this->authenticate($user);

        $response = $this->analytics([
            'transfer_provider_id' => $providerOne->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100);
    }

    public function test_analytics_rejects_unknown_transfer_provider(): void
    {
        $user = User::factory()->create();

        $this->authenticate($user);

        $this->analytics([
            'transfer_provider_id' => fake()->uuid(),
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Date filters
    |--------------------------------------------------------------------------
    */

    public function test_analytics_filters_by_from_date(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 100,
            'sent_at' => '2026-08-01',
        ]);

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 200,
            'sent_at' => '2026-08-15',
        ]);

        $this->authenticate($user);

        $this->analytics([
            'from' => '2026-08-10',
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 200);
    }

    public function test_analytics_filters_by_to_date(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 100,
            'sent_at' => '2026-08-01',
        ]);

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 200,
            'sent_at' => '2026-08-15',
        ]);

        $this->authenticate($user);

        $this->analytics([
            'to' => '2026-08-10',
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100);
    }

    public function test_analytics_includes_boundary_dates(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 100,
            'sent_at' => '2026-08-10',
        ]);

        $this->authenticate($user);

        $this->analytics([
            'from' => '2026-08-10',
            'to' => '2026-08-10',
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100);
    }

    /*
    |--------------------------------------------------------------------------
    | Combined filters
    |--------------------------------------------------------------------------
    */

    public function test_analytics_supports_combined_filters(): void
    {
        $user = User::factory()->create();

        $householdOne = $this->household($user);
        $householdTwo = $this->household($user);

        $providerOne = $this->provider();
        $providerTwo = $this->provider();

        $this->remittance($user, $householdOne, $providerOne, [
            'amount_sent' => 100,
            'sent_at' => '2026-08-10',
        ]);

        $this->remittance($user, $householdOne, $providerTwo, [
            'amount_sent' => 200,
            'sent_at' => '2026-08-15',
        ]);

        $this->remittance($user, $householdTwo, $providerOne, [
            'amount_sent' => 300,
            'sent_at' => '2026-08-15',
        ]);

        $this->authenticate($user);

        $this->analytics([
            'household_id' => $householdOne->id,
            'transfer_provider_id' => $providerOne->id,
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_analytics_rejects_invalid_household_uuid(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $this->analytics([
            'household_id' => 'not-a-uuid',
        ])->assertStatus(422);
    }

    public function test_analytics_rejects_invalid_provider_uuid(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $this->analytics([
            'transfer_provider_id' => 'not-a-uuid',
        ])->assertStatus(422);
    }

    public function test_analytics_rejects_invalid_from_date(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $this->analytics([
            'from' => '08/01/2026',
        ])->assertStatus(422);
    }

    public function test_analytics_rejects_invalid_to_date(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $this->analytics([
            'to' => '08/31/2026',
        ])->assertStatus(422);
    }

    public function test_analytics_rejects_to_date_before_from_date(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);

        $this->analytics([
            'from' => '2026-08-31',
            'to' => '2026-08-01',
        ])->assertStatus(422);
    }

    public function test_analytics_accepts_equal_from_and_to_dates(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $this->remittance($user, $household, $provider, [
            'amount_sent' => 150,
            'sent_at' => '2026-08-15',
        ]);

        $this->authenticate($user);

        $this->analytics([
            'from' => '2026-08-15',
            'to' => '2026-08-15',
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 150);
    }

    /*
    |--------------------------------------------------------------------------
    | Data isolation
    |--------------------------------------------------------------------------
    */

    public function test_household_filter_cannot_be_used_to_access_another_users_data(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);
        $provider = $this->provider();

        $this->remittance($owner, $household, $provider, [
            'amount_sent' => 9999,
        ]);

        $this->authenticate($attacker);

        $this->analytics([
            'household_id' => $household->id,
        ])->assertForbidden();
    }

    public function test_provider_filter_only_returns_authenticated_users_data(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $userHousehold = $this->household($user);
        $otherHousehold = $this->household($otherUser);

        $provider = $this->provider();

        $this->remittance($user, $userHousehold, $provider, [
            'amount_sent' => 100,
        ]);

        $this->remittance($otherUser, $otherHousehold, $provider, [
            'amount_sent' => 9000,
        ]);

        $this->authenticate($user);

        $this->analytics([
            'transfer_provider_id' => $provider->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.total_sent', 100);
    }

    /*
    |--------------------------------------------------------------------------
    | Response contract
    |--------------------------------------------------------------------------
    */

    public function test_analytics_response_contains_expected_top_level_fields(): void
    {
        $user = User::factory()->create();

        $this->authenticate($user);

        $this->analytics()
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'summary',
                    'monthly_trend',
                    'providers',
                ],
            ]);
    }

    public function test_analytics_preserves_zero_values_for_empty_results(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->authenticate($user);

        $this->analytics([
            'household_id' => $household->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.count', 0)
            ->assertJsonPath('data.summary.total_sent', 0)
            ->assertJsonPath('data.summary.total_received', 0);
    }
}
