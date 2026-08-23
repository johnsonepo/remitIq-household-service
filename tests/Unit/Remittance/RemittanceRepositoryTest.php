<?php

namespace Tests\Unit\Remittance;

use App\Models\Household;
use App\Models\Remittance;
use App\Models\TransferProvider;
use App\Models\User;
use App\Repositories\RemittanceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemittanceRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected RemittanceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(RemittanceRepository::class);
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

    /*
    |--------------------------------------------------------------------------
    | forUser
    |--------------------------------------------------------------------------
    */

    public function test_for_user_returns_only_users_remittances(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($otherUser);

        $owned = $this->remittance($user, $household);
        $this->remittance($otherUser, $otherHousehold);

        $result = $this->repository->forUser($user->id);

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $owned->id));
        $this->assertFalse(
            $result->contains('user_id', $otherUser->id)
        );
    }

    public function test_for_user_returns_latest_sent_remittances_first(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $older = $this->remittance($user, $household, [
            'sent_at' => '2026-01-10',
        ]);

        $newer = $this->remittance($user, $household, [
            'sent_at' => '2026-08-20',
        ]);

        $result = $this->repository->forUser($user->id);

        $this->assertCount(2, $result);
        $this->assertSame($newer->id, $result->first()->id);
        $this->assertSame($older->id, $result->last()->id);
    }

    public function test_for_user_eager_loads_household_provider_and_attachments(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $remittance = $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
        ]);

        $result = $this->repository->forUser($user->id);

        $found = $result->firstWhere('id', $remittance->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('household'));
        $this->assertTrue($found->relationLoaded('provider'));
        $this->assertTrue($found->relationLoaded('attachments'));

        $this->assertSame($household->id, $found->household->id);
        $this->assertSame($provider->id, $found->provider->id);
    }

    /*
    |--------------------------------------------------------------------------
    | forUserHousehold
    |--------------------------------------------------------------------------
    */

    public function test_for_user_household_returns_only_matching_user_and_household(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($user);
        $otherUsersHousehold = $this->household($otherUser);

        $matching = $this->remittance($user, $household);
        $this->remittance($user, $otherHousehold);
        $this->remittance($otherUser, $otherUsersHousehold);

        $result = $this->repository->forUserHousehold(
            $user->id,
            $household->id
        );

        $this->assertCount(1, $result);
        $this->assertSame($matching->id, $result->first()->id);
    }

    public function test_for_user_household_returns_latest_sent_first(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $older = $this->remittance($user, $household, [
            'sent_at' => '2026-02-01',
        ]);

        $newer = $this->remittance($user, $household, [
            'sent_at' => '2026-08-01',
        ]);

        $result = $this->repository->forUserHousehold(
            $user->id,
            $household->id
        );

        $this->assertCount(2, $result);
        $this->assertSame($newer->id, $result->first()->id);
        $this->assertSame($older->id, $result->last()->id);
    }

    /*
    |--------------------------------------------------------------------------
    | findForUser
    |--------------------------------------------------------------------------
    */

    public function test_find_for_user_returns_owned_remittance(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $remittance = $this->remittance($user, $household);

        $result = $this->repository->findForUser(
            $user->id,
            $remittance->id
        );

        $this->assertNotNull($result);
        $this->assertSame($remittance->id, $result->id);
    }

    public function test_find_for_user_does_not_return_another_users_remittance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($otherUser);

        $remittance = $this->remittance(
            $otherUser,
            $household
        );

        $result = $this->repository->findForUser(
            $user->id,
            $remittance->id
        );

        $this->assertNull($result);
    }

    public function test_find_for_user_returns_null_for_missing_remittance(): void
    {
        $user = User::factory()->create();

        $result = $this->repository->findForUser(
            $user->id,
            '00000000-0000-0000-0000-000000000000'
        );

        $this->assertNull($result);
    }

    public function test_find_for_user_eager_loads_relationships(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $provider = $this->provider();

        $remittance = $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
        ]);

        $result = $this->repository->findForUser(
            $user->id,
            $remittance->id
        );

        $this->assertNotNull($result);

        $this->assertTrue($result->relationLoaded('household'));
        $this->assertTrue($result->relationLoaded('provider'));
        $this->assertTrue($result->relationLoaded('attachments'));

        $this->assertSame($household->id, $result->household->id);
        $this->assertSame($provider->id, $result->provider->id);
    }

    /*
    |--------------------------------------------------------------------------
    | historyForUser
    |--------------------------------------------------------------------------
    */

    public function test_history_for_user_returns_all_user_remittances_without_filters(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($otherUser);

        $first = $this->remittance($user, $household);
        $second = $this->remittance($user, $household);
        $this->remittance($otherUser, $otherHousehold);

        $result = $this->repository->historyForUser($user->id);

        $this->assertCount(2, $result);
        $this->assertTrue($result->contains('id', $first->id));
        $this->assertTrue($result->contains('id', $second->id));
    }

    public function test_history_for_user_filters_by_household(): void
    {
        $user = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($user);

        $matching = $this->remittance($user, $household);
        $this->remittance($user, $otherHousehold);

        $result = $this->repository->historyForUser(
            $user->id,
            [
                'household_id' => $household->id,
            ]
        );

        $this->assertCount(1, $result);
        $this->assertSame($matching->id, $result->first()->id);
    }

    public function test_history_for_user_filters_by_transfer_provider(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $provider = $this->provider();
        $otherProvider = $this->provider();

        $matching = $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $otherProvider->id,
        ]);

        $result = $this->repository->historyForUser(
            $user->id,
            [
                'transfer_provider_id' => $provider->id,
            ]
        );

        $this->assertCount(1, $result);
        $this->assertSame($matching->id, $result->first()->id);
    }

    public function test_history_for_user_filters_by_date_range(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $before = $this->remittance($user, $household, [
            'sent_at' => '2026-01-01',
        ]);

        $insideStart = $this->remittance($user, $household, [
            'sent_at' => '2026-03-01',
        ]);

        $insideEnd = $this->remittance($user, $household, [
            'sent_at' => '2026-06-30',
        ]);

        $after = $this->remittance($user, $household, [
            'sent_at' => '2026-09-01',
        ]);

        $result = $this->repository->historyForUser(
            $user->id,
            [
                'from' => '2026-03-01',
                'to' => '2026-06-30',
            ]
        );

        $this->assertCount(2, $result);

        $this->assertTrue($result->contains('id', $insideStart->id));
        $this->assertTrue($result->contains('id', $insideEnd->id));

        $this->assertFalse($result->contains('id', $before->id));
        $this->assertFalse($result->contains('id', $after->id));
    }

    public function test_history_for_user_applies_multiple_filters(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $otherHousehold = $this->household($user);

        $provider = $this->provider();
        $otherProvider = $this->provider();

        $matching = $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
            'sent_at' => '2026-05-15',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $otherProvider->id,
            'sent_at' => '2026-05-15',
        ]);

        $this->remittance($user, $otherHousehold, [
            'transfer_provider_id' => $provider->id,
            'sent_at' => '2026-05-15',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
            'sent_at' => '2026-07-01',
        ]);

        $result = $this->repository->historyForUser(
            $user->id,
            [
                'household_id' => $household->id,
                'transfer_provider_id' => $provider->id,
                'from' => '2026-05-01',
                'to' => '2026-05-31',
            ]
        );

        $this->assertCount(1, $result);
        $this->assertSame($matching->id, $result->first()->id);
    }

    public function test_history_for_user_returns_latest_sent_first(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $older = $this->remittance($user, $household, [
            'sent_at' => '2026-01-01',
        ]);

        $newer = $this->remittance($user, $household, [
            'sent_at' => '2026-08-01',
        ]);

        $result = $this->repository->historyForUser($user->id);

        $this->assertSame($newer->id, $result->first()->id);
        $this->assertSame($older->id, $result->last()->id);
    }

    /*
    |--------------------------------------------------------------------------
    | analyticsForUser
    |--------------------------------------------------------------------------
    */

    public function test_analytics_for_user_returns_correct_summary(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->remittance($user, $household, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'exchange_rate' => 600,
            'sent_at' => '2026-01-10',
        ]);

        $this->remittance($user, $household, [
            'amount_sent' => 200,
            'amount_received' => 122000,
            'exchange_rate' => 610,
            'sent_at' => '2026-02-10',
        ]);

        $result = $this->repository->analyticsForUser($user->id);

        $this->assertSame(2, $result['summary']['count']);
        $this->assertSame(300.0, $result['summary']['total_sent']);
        $this->assertSame(182000.0, $result['summary']['total_received']);
        $this->assertSame(150.0, $result['summary']['average_sent']);
        $this->assertSame(91000.0, $result['summary']['average_received']);
        $this->assertSame(605.0, $result['summary']['average_exchange_rate']);
    }

    public function test_analytics_for_user_returns_monthly_trend(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->remittance($user, $household, [
            'amount_sent' => 100,
            'amount_received' => 60000,
            'exchange_rate' => 600,
            'sent_at' => '2026-01-10',
        ]);

        $this->remittance($user, $household, [
            'amount_sent' => 200,
            'amount_received' => 120000,
            'exchange_rate' => 600,
            'sent_at' => '2026-01-20',
        ]);

        $this->remittance($user, $household, [
            'amount_sent' => 300,
            'amount_received' => 183000,
            'exchange_rate' => 610,
            'sent_at' => '2026-02-10',
        ]);

        $result = $this->repository->analyticsForUser($user->id);

        $this->assertCount(2, $result['monthly_trend']);

        $this->assertSame([
            'month' => '2026-01',
            'count' => 2,
            'total_sent' => 300.0,
            'total_received' => 180000.0,
            'average_exchange_rate' => 600.0,
        ], $result['monthly_trend'][0]);

        $this->assertSame([
            'month' => '2026-02',
            'count' => 1,
            'total_sent' => 300.0,
            'total_received' => 183000.0,
            'average_exchange_rate' => 610.0,
        ], $result['monthly_trend'][1]);
    }

    public function test_analytics_for_user_groups_results_by_provider(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $providerA = $this->provider([
            'name' => 'Provider A',
        ]);

        $providerB = $this->provider([
            'name' => 'Provider B',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $providerA->id,
            'amount_sent' => 100,
            'amount_received' => 60000,
            'sent_at' => '2026-01-10',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $providerA->id,
            'amount_sent' => 200,
            'amount_received' => 122000,
            'sent_at' => '2026-01-15',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $providerB->id,
            'amount_sent' => 300,
            'amount_received' => 183000,
            'sent_at' => '2026-02-10',
        ]);

        $result = $this->repository->analyticsForUser($user->id);

        $this->assertCount(2, $result['providers']);

        $providerAResult = collect($result['providers'])
            ->firstWhere('provider_id', $providerA->id);

        $providerBResult = collect($result['providers'])
            ->firstWhere('provider_id', $providerB->id);

        $this->assertNotNull($providerAResult);
        $this->assertNotNull($providerBResult);

        $this->assertSame(2, $providerAResult['count']);
        $this->assertSame(300.0, $providerAResult['total_sent']);
        $this->assertSame(182000.0, $providerAResult['total_received']);
        $this->assertSame($providerA->id, $providerAResult['provider_id']);
        $this->assertSame($providerA->id, $providerAResult['provider']->id);

        $this->assertSame(1, $providerBResult['count']);
        $this->assertSame(300.0, $providerBResult['total_sent']);
        $this->assertSame(183000.0, $providerBResult['total_received']);
        $this->assertSame($providerB->id, $providerBResult['provider_id']);
        $this->assertSame($providerB->id, $providerBResult['provider']->id);
    }

    public function test_analytics_for_user_applies_filters(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $otherHousehold = $this->household($user);

        $provider = $this->provider();
        $otherProvider = $this->provider();

        $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
            'amount_sent' => 100,
            'amount_received' => 60000,
            'sent_at' => '2026-05-10',
        ]);

        $this->remittance($user, $otherHousehold, [
            'transfer_provider_id' => $provider->id,
            'amount_sent' => 200,
            'amount_received' => 120000,
            'sent_at' => '2026-05-10',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $otherProvider->id,
            'amount_sent' => 300,
            'amount_received' => 180000,
            'sent_at' => '2026-05-10',
        ]);

        $this->remittance($user, $household, [
            'transfer_provider_id' => $provider->id,
            'amount_sent' => 400,
            'amount_received' => 240000,
            'sent_at' => '2026-07-10',
        ]);

        $result = $this->repository->analyticsForUser(
            $user->id,
            [
                'household_id' => $household->id,
                'transfer_provider_id' => $provider->id,
                'from' => '2026-05-01',
                'to' => '2026-05-31',
            ]
        );

        $this->assertSame(1, $result['summary']['count']);
        $this->assertSame(100.0, $result['summary']['total_sent']);
        $this->assertSame(60000.0, $result['summary']['total_received']);
        $this->assertSame(100.0, $result['summary']['average_sent']);
        $this->assertSame(60000.0, $result['summary']['average_received']);
    }

    public function test_analytics_for_user_returns_zero_values_when_no_remittances_exist(): void
    {
        $user = User::factory()->create();

        $result = $this->repository->analyticsForUser($user->id);

        $this->assertSame(0, $result['summary']['count']);
        $this->assertSame(0.0, $result['summary']['total_sent']);
        $this->assertSame(0.0, $result['summary']['total_received']);
        $this->assertSame(0.0, $result['summary']['average_sent']);
        $this->assertSame(0.0, $result['summary']['average_received']);
        $this->assertSame(0.0, $result['summary']['average_exchange_rate']);

        $this->assertSame([], $result['monthly_trend']);
        $this->assertSame([], $result['providers']);
    }
}
