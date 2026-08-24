<?php

namespace Tests\Unit\Remittance;

use App\Exceptions\ApiException;
use App\Models\Household;
use App\Models\Remittance;
use App\Models\TransferProvider;
use App\Models\User;
use App\Repositories\HouseholdRepository;
use App\Repositories\RemittanceRepository;
use App\Services\Remittance\RemittanceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RemittanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RemittanceService $service;

    protected RemittanceRepository $repository;

    protected HouseholdRepository $householdRepository;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * NotificationServiceClient is a real dependency and the
         * NotificationEventEmitter is final, so we do not mock the emitter.
         *
         * Instead, fake Laravel's HTTP client and verify notification
         * requests at the HTTP boundary.
         */
        Http::fake();

        $this->repository = Mockery::mock(RemittanceRepository::class);
        $this->householdRepository = Mockery::mock(HouseholdRepository::class);

        $this->app->instance(RemittanceRepository::class, $this->repository);
        $this->app->instance(HouseholdRepository::class, $this->householdRepository);

        $this->service = app(RemittanceService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function household(User $owner): Household
    {
        return Household::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    private function remittance(User $user, Household $household, array $overrides = []): Remittance
    {
        return Remittance::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ], $overrides));
    }

    private function remittanceData(Household $household, array $overrides = []): array
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
            'notes' => 'Test remittance',
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | forUser
    |--------------------------------------------------------------------------
    */

    public function test_for_user_returns_repository_results(): void
    {
        $user = User::factory()->create();

        $remittances = new Collection([
            $this->remittance($user, $this->household($user)),
        ]);

        $this->repository
            ->shouldReceive('forUser')
            ->once()
            ->with($user->id)
            ->andReturn($remittances);

        $result = $this->service->forUser($user->id);

        $this->assertSame($remittances, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | forHousehold
    |--------------------------------------------------------------------------
    */

    public function test_for_household_returns_remittances_when_user_has_access(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $remittances = new Collection([
            $this->remittance($user, $household),
        ]);

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('forUserHousehold')
            ->once()
            ->with($user->id, $household->id)
            ->andReturn($remittances);

        $result = $this->service->forHousehold($user->id, $household->id);

        $this->assertSame($remittances, $result);
    }

    public function test_for_household_rejects_inaccessible_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnFalse();

        $this->repository
            ->shouldNotReceive('forUserHousehold');

        $this->expectException(ApiException::class);

        $this->service->forHousehold($user->id, $household->id);
    }

    /*
    |--------------------------------------------------------------------------
    | create
    |--------------------------------------------------------------------------
    */

    public function test_create_creates_remittance_for_accessible_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $data = $this->remittanceData($household);

        $remittance = $this->remittance($user, $household, $data);

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($user, $household): bool {
                return $payload['user_id'] === $user->id
                    && $payload['household_id'] === $household->id;
            }))
            ->andReturn($remittance);

        $result = $this->service->create($user->id, $data);

        $this->assertSame($remittance->id, $result->id);

        /*
         * The notification client should have attempted to send
         * a REMITTANCE_CREATED event.
         *
         * If notification URL is not configured, no HTTP request is
         * expected because NotificationServiceClient intentionally
         * returns early.
         */
    }

    public function test_create_forces_authenticated_user_id(): void
    {
        $user = User::factory()->create();
        $attacker = User::factory()->create();
        $household = $this->household($user);

        $data = $this->remittanceData($household, [
            'user_id' => $attacker->id,
        ]);

        $remittance = $this->remittance($user, $household);

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($user): bool {
                return $payload['user_id'] === $user->id;
            }))
            ->andReturn($remittance);

        $this->service->create($user->id, $data);

        $this->assertSame($user->id, $remittance->user_id);
    }

    public function test_create_rejects_inaccessible_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $data = $this->remittanceData($household);

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnFalse();

        $this->repository
            ->shouldNotReceive('create');

        $this->expectException(ApiException::class);

        $this->service->create($user->id, $data);
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

        $this->repository
            ->shouldReceive('findForUser')
            ->once()
            ->with($user->id, $remittance->id)
            ->andReturn($remittance);

        $result = $this->service->findForUser($user->id, $remittance->id);

        $this->assertSame($remittance->id, $result->id);
    }

    public function test_find_for_user_rejects_missing_remittance(): void
    {
        $user = User::factory()->create();

        $this->repository
            ->shouldReceive('findForUser')
            ->once()
            ->with($user->id, 'missing-id')
            ->andReturnNull();

        $this->expectException(ApiException::class);

        $this->service->findForUser($user->id, 'missing-id');
    }

    /*
    |--------------------------------------------------------------------------
    | update
    |--------------------------------------------------------------------------
    */

    public function test_update_updates_remittance_and_emits_event(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $remittance = $this->remittance($user, $household);

        $result = $this->service->update($remittance, [
            'amount_sent' => 250,
            'amount_received' => 150000,
            'exchange_rate' => 600,
        ]);

        $this->assertSame($remittance->id, $result->id);

        $this->assertSame(250.0, (float) $result->amount_sent);

        $this->assertSame(150000.0, (float) $result->amount_received);

        $this->assertSame(600.0, (float) $result->exchange_rate);
    }

    /*
    |--------------------------------------------------------------------------
    | delete
    |--------------------------------------------------------------------------
    */

    public function test_delete_soft_deletes_remittance_and_emits_event(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $remittance = $this->remittance($user, $household);

        $result = $this->service->delete($remittance);

        $this->assertTrue($result);

        $this->assertSoftDeleted('remittances', [
            'id' => $remittance->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | historyForUser
    |--------------------------------------------------------------------------
    */

    public function test_history_for_user_returns_repository_results_without_household_filter(): void
    {
        $user = User::factory()->create();

        $remittances = new Collection;

        $this->repository
            ->shouldReceive('historyForUser')
            ->once()
            ->with($user->id, [])
            ->andReturn($remittances);

        $result = $this->service->historyForUser($user->id);

        $this->assertSame($remittances, $result);
    }

    public function test_history_for_user_allows_accessible_household_filter(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $filters = [
            'household_id' => $household->id,
            'transfer_provider_id' => TransferProvider::factory()->create()->id,
            'from' => '2026-01-01',
            'to' => '2026-08-31',
        ];

        $remittances = new Collection;

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('historyForUser')
            ->once()
            ->with($user->id, $filters)
            ->andReturn($remittances);

        $result = $this->service->historyForUser($user->id, $filters);

        $this->assertSame($remittances, $result);
    }

    public function test_history_for_user_rejects_inaccessible_household_filter(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $filters = [
            'household_id' => $household->id,
        ];

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($household->id, $user->id)
            ->andReturnFalse();

        $this->repository
            ->shouldNotReceive('historyForUser');

        $this->expectException(ApiException::class);

        $this->service->historyForUser($user->id, $filters);
    }
}
