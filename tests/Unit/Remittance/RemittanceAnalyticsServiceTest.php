<?php

namespace Tests\Unit\Remittance;

use App\Exceptions\ApiException;
use App\Repositories\HouseholdRepository;
use App\Repositories\RemittanceRepository;
use App\Services\Remittance\RemittanceAnalyticsService;
use Mockery;
use Tests\TestCase;

class RemittanceAnalyticsServiceTest extends TestCase
{
    protected RemittanceAnalyticsService $service;

    protected RemittanceRepository $repository;

    protected HouseholdRepository $householdRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(RemittanceRepository::class);
        $this->householdRepository = Mockery::mock(HouseholdRepository::class);

        $this->app->instance(RemittanceRepository::class, $this->repository);

        $this->app->instance(HouseholdRepository::class, $this->householdRepository);

        $this->service = app(RemittanceAnalyticsService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | forUser
    |--------------------------------------------------------------------------
    */

    public function test_for_user_returns_repository_analytics_without_household_filter(): void
    {
        $userId = 1;

        $analytics = [
            'summary' => [
                'count' => 5,
                'total_sent' => 500.00,
                'total_received' => 300000.00,
                'average_sent' => 100.00,
                'average_received' => 60000.00,
                'average_exchange_rate' => 600.00,
            ],
            'monthly_trend' => [],
            'providers' => [],
        ];

        $this->repository
            ->shouldReceive('analyticsForUser')
            ->once()
            ->with($userId, [])
            ->andReturn($analytics);

        $this->householdRepository
            ->shouldNotReceive('isAccessibleByUser');

        $result = $this->service->forUser($userId);

        $this->assertSame($analytics, $result);
    }

    public function test_for_user_passes_filters_to_repository_without_household_filter(): void
    {
        $userId = 1;

        $filters = [
            'transfer_provider_id' => 'provider-123',
            'from' => '2026-01-01',
            'to' => '2026-08-31',
        ];

        $analytics = [
            'summary' => [
                'count' => 2,
                'total_sent' => 200.00,
                'total_received' => 120000.00,
                'average_sent' => 100.00,
                'average_received' => 60000.00,
                'average_exchange_rate' => 600.00,
            ],
            'monthly_trend' => [],
            'providers' => [],
        ];

        $this->repository
            ->shouldReceive('analyticsForUser')
            ->once()
            ->with($userId, $filters)
            ->andReturn($analytics);

        $this->householdRepository
            ->shouldNotReceive('isAccessibleByUser');

        $result = $this->service->forUser($userId, $filters);

        $this->assertSame($analytics, $result);
    }

    public function test_for_user_checks_household_access_when_household_filter_is_present(): void
    {
        $userId = 1;
        $householdId = 'household-123';

        $filters = [
            'household_id' => $householdId,
        ];

        $analytics = [
            'summary' => [
                'count' => 3,
                'total_sent' => 300.00,
                'total_received' => 180000.00,
                'average_sent' => 100.00,
                'average_received' => 60000.00,
                'average_exchange_rate' => 600.00,
            ],
            'monthly_trend' => [],
            'providers' => [],
        ];

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($householdId, $userId)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('analyticsForUser')
            ->once()
            ->with($userId, $filters)
            ->andReturn($analytics);

        $result = $this->service->forUser($userId, $filters);

        $this->assertSame($analytics, $result);
    }

    public function test_for_user_casts_household_id_to_string_for_access_check(): void
    {
        $userId = 1;

        $filters = [
            'household_id' => 123,
        ];

        $analytics = [
            'summary' => [
                'count' => 1,
                'total_sent' => 100.00,
                'total_received' => 60000.00,
                'average_sent' => 100.00,
                'average_received' => 60000.00,
                'average_exchange_rate' => 600.00,
            ],
            'monthly_trend' => [],
            'providers' => [],
        ];

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with('123', $userId)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('analyticsForUser')
            ->once()
            ->with($userId, $filters)
            ->andReturn($analytics);

        $result = $this->service->forUser($userId, $filters);

        $this->assertSame($analytics, $result);
    }

    public function test_for_user_rejects_inaccessible_household(): void
    {
        $userId = 1;
        $householdId = 'household-123';

        $filters = [
            'household_id' => $householdId,
        ];

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($householdId, $userId)
            ->andReturnFalse();

        $this->repository
            ->shouldNotReceive('analyticsForUser');

        $this->expectException(ApiException::class);

        $this->service->forUser($userId, $filters);
    }

    public function test_for_user_preserves_all_filters_when_household_is_accessible(): void
    {
        $userId = 1;
        $householdId = 'household-123';

        $filters = [
            'household_id' => $householdId,
            'transfer_provider_id' => 'provider-123',
            'from' => '2026-01-01',
            'to' => '2026-08-31',
        ];

        $analytics = [
            'summary' => [
                'count' => 4,
                'total_sent' => 400.00,
                'total_received' => 240000.00,
                'average_sent' => 100.00,
                'average_received' => 60000.00,
                'average_exchange_rate' => 600.00,
            ],
            'monthly_trend' => [],
            'providers' => [],
        ];

        $this->householdRepository
            ->shouldReceive('isAccessibleByUser')
            ->once()
            ->with($householdId, $userId)
            ->andReturnTrue();

        $this->repository
            ->shouldReceive('analyticsForUser')
            ->once()
            ->with($userId, $filters)
            ->andReturn($analytics);

        $result = $this->service->forUser($userId, $filters);

        $this->assertSame($analytics, $result);
    }
}
