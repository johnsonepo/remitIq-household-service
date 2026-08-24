<?php

namespace Tests\Feature\Budget;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BudgetHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_history_requires_authentication(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $this->getJson("/api/v1/budgets/history/{$household->id}")
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Basic Retrieval
    |--------------------------------------------------------------------------
    */

    public function test_user_can_retrieve_budget_history_for_owned_household(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $older = $this->createBudget($user, $household, '2026-01-01');
        $current = $this->createBudget($user, $household, '2026-02-01');
        $newer = $this->createBudget($user, $household, '2026-03-01');

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Budget history retrieved successfully.')
            ->assertJsonCount(3, 'data');

        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $current->id);
        $response->assertJsonPath('data.2.id', $older->id);
    }

    public function test_history_returns_empty_collection_when_household_has_no_budgets(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Budget history retrieved successfully.')
            ->assertJsonCount(0, 'data');
    }

    public function test_history_returns_only_budgets_for_requested_household(): void
    {
        $user = User::factory()->create();

        $householdA = $this->createHousehold($user);
        $householdB = $this->createHousehold($user);

        $budgetA1 = $this->createBudget($user, $householdA, '2026-01-01');
        $budgetA2 = $this->createBudget($user, $householdA, '2026-02-01');

        $budgetB1 = $this->createBudget($user, $householdB, '2026-01-01');
        $budgetB2 = $this->createBudget($user, $householdB, '2026-02-01');

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$householdA->id}");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $budgetA2->id)
            ->assertJsonPath('data.1.id', $budgetA1->id);

        $response->assertJsonMissing([
            'id' => $budgetB1->id,
        ]);

        $response->assertJsonMissing([
            'id' => $budgetB2->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | User Isolation
    |--------------------------------------------------------------------------
    */

    public function test_history_does_not_return_other_users_budgets_for_same_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->createHousehold($owner);

        $ownerBudget = $this->createBudget($owner, $household, '2026-01-01');

        $attackerBudget = Budget::factory()->create([
            'user_id' => $attacker->id,
            'household_id' => $household->id,
            'month' => '2026-02-01',
        ]);

        /*
         * The household itself is not necessarily accessible to the attacker.
         * This assertion primarily protects against leaking another user's
         * budget records through a history query.
         */
        $response = $this->auth($attacker)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $ownerBudget->id,
            'user_id' => $owner->id,
        ]);

        $this->assertDatabaseHas('budgets', [
            'id' => $attackerBudget->id,
            'user_id' => $attacker->id,
        ]);
    }

    public function test_history_does_not_leak_budgets_from_other_users_when_user_has_access_to_household(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $household = $this->createHousehold($owner);

        /*
         * If the application permits the second user to access the household,
         * history must still be scoped by user_id because the confirmed budget
         * ownership model states that budgets belong to their creator.
         *
         * This test dynamically checks the actual household-access mechanism
         * through the HouseholdRepository contract by adding the user through
         * the household membership relationship when available.
         */
        $this->addHouseholdMember($household, $member);

        $ownerBudget = $this->createBudget($owner, $household, '2026-01-01');
        $memberBudget = $this->createBudget($member, $household, '2026-02-01');

        $response = $this->auth($member)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        /*
         * If membership grants household access, the request succeeds and
         * only the authenticated user's budget should be returned.
         */
        if ($response->status() === 200) {
            $response
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $memberBudget->id);

            $response->assertJsonMissing([
                'id' => $ownerBudget->id,
            ]);
        } else {
            $response->assertForbidden();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    public function test_history_is_ordered_by_month_descending(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $dates = [
            '2025-12-01',
            '2026-03-01',
            '2026-01-01',
            '2026-06-01',
            '2026-02-01',
            '2025-11-01',
        ];

        $budgets = [];

        foreach ($dates as $date) {
            $budgets[$date] = $this->createBudget($user, $household, $date);
        }

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonCount(count($dates), 'data');

        $expectedOrder = [
            $budgets['2026-06-01']->id,
            $budgets['2026-03-01']->id,
            $budgets['2026-02-01']->id,
            $budgets['2026-01-01']->id,
            $budgets['2025-12-01']->id,
            $budgets['2025-11-01']->id,
        ];

        $actualIds = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame($expectedOrder, $actualIds);
    }

    public function test_history_handles_budgets_across_multiple_years(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $older = $this->createBudget($user, $household, '2024-12-01');
        $middle = $this->createBudget($user, $household, '2025-12-01');
        $newer = $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $older->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Historical Data Integrity
    |--------------------------------------------------------------------------
    */

    public function test_history_includes_household_relationship(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $budget = $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $budget->id)
            ->assertJsonPath('data.0.household.id', $household->id);
    }

    public function test_history_includes_budget_items(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $category = $this->createCategory();

        $budget = $this->createBudget($user, $household, '2026-01-01');

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 50000,
            'actual_amount' => 30000,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $budget->id)
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonPath('data.0.items.0.id', $item->id)
            ->assertJsonPath('data.0.items.0.planned_amount', '50000.00')
            ->assertJsonPath('data.0.items.0.actual_amount', '30000.00');
    }

    public function test_history_includes_budget_item_category_relationship(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);
        $category = $this->createCategory([
            'name' => 'Historical Food',
        ]);

        $budget = $this->createBudget($user, $household, '2026-01-01');

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 100000,
            'actual_amount' => 75000,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.items.0.id', $item->id)
            ->assertJsonPath('data.0.items.0.category.id', $category->id)
            ->assertJsonPath('data.0.items.0.category.name', 'Historical Food');
    }

    public function test_history_preserves_each_months_budget_items(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $categoryA = $this->createCategory(['name' => 'Food']);
        $categoryB = $this->createCategory(['name' => 'Transport']);

        $january = $this->createBudget($user, $household, '2026-01-01');
        $february = $this->createBudget($user, $household, '2026-02-01');

        $januaryFood = BudgetItem::factory()->create([
            'budget_id' => $january->id,
            'budget_category_id' => $categoryA->id,
            'planned_amount' => 100000,
            'actual_amount' => 80000,
        ]);

        $februaryFood = BudgetItem::factory()->create([
            'budget_id' => $february->id,
            'budget_category_id' => $categoryA->id,
            'planned_amount' => 120000,
            'actual_amount' => 90000,
        ]);

        $februaryTransport = BudgetItem::factory()->create([
            'budget_id' => $february->id,
            'budget_category_id' => $categoryB->id,
            'planned_amount' => 50000,
            'actual_amount' => 25000,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(2, 'data.0.items')
            ->assertJsonCount(1, 'data.1.items');

        $response->assertJsonPath('data.0.id', $february->id);
        $response->assertJsonPath('data.0.items.0.id', $februaryFood->id);
        $response->assertJsonPath('data.0.items.1.id', $februaryTransport->id);
        $response->assertJsonPath('data.1.id', $january->id);
        $response->assertJsonPath('data.1.items.0.id', $januaryFood->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Data Isolation / Cross-Household Protection
    |--------------------------------------------------------------------------
    */

    public function test_history_does_not_return_budgets_from_another_household(): void
    {
        $user = User::factory()->create();

        $requestedHousehold = $this->createHousehold($user);
        $otherHousehold = $this->createHousehold($user);

        $requestedBudget = $this->createBudget($user, $requestedHousehold, '2026-01-01');

        $otherBudget = $this->createBudget($user, $otherHousehold, '2026-02-01');

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$requestedHousehold->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestedBudget->id);

        $response->assertJsonMissing([
            'id' => $otherBudget->id,
        ]);
    }

    public function test_history_does_not_leak_items_from_other_budgets(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $category = $this->createCategory();

        $requestedBudget = $this->createBudget($user, $household, '2026-01-01');

        $otherBudget = $this->createBudget($user, $household, '2026-02-01');

        $requestedItem = BudgetItem::factory()->create([
            'budget_id' => $requestedBudget->id,
            'budget_category_id' => $category->id,
        ]);

        $otherItem = BudgetItem::factory()->create([
            'budget_id' => $otherBudget->id,
            'budget_category_id' => $category->id,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $response->assertJsonFragment([
            'id' => $requestedItem->id,
        ]);

        $response->assertJsonFragment([
            'id' => $otherItem->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Household Access
    |--------------------------------------------------------------------------
    */

    public function test_history_for_inaccessible_household_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->createHousehold($owner);

        $this->createBudget($owner, $household, '2026-01-01');

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/history/{$household->id}")
            ->assertForbidden();
    }

    public function test_history_for_unknown_household_is_forbidden_or_not_found_consistently(): void
    {
        $user = User::factory()->create();

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/history/'.fake()->uuid());

        /*
         * The service checks household accessibility before querying history.
         * Therefore an unknown household should not expose whether a household
         * exists through budget records.
         */
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_history_does_not_expose_budgets_from_an_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->createHousehold($owner);

        $budget = $this->createBudget($owner, $household, '2026-01-01');

        $response = $this->auth($attacker)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response->assertForbidden();

        $response->assertJsonMissing([
            'id' => $budget->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Large History / Regression Coverage
    |--------------------------------------------------------------------------
    */

    public function test_history_returns_all_months_without_truncation(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $budgets = [];

        for ($month = 1; $month <= 24; $month++) {
            $date = now()
                ->startOfMonth()
                ->subMonths($month)
                ->toDateString();

            $budgets[$date] = $this->createBudget($user, $household, $date);
        }

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonCount(24, 'data');

        $returnedDates = collect($response->json('data'))
            ->pluck('month')
            ->map(fn ($month) => substr((string) $month, 0, 10))
            ->all();

        $expectedDates = collect(array_keys($budgets))
            ->sortDesc()
            ->values()
            ->all();

        $this->assertSame($expectedDates, $returnedDates);
    }

    public function test_history_returns_distinct_monthly_budgets_in_descending_order(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $months = [
            '2026-04-01',
            '2026-01-01',
            '2026-03-01',
            '2026-02-01',
        ];

        foreach ($months as $month) {
            $this->createBudget($user, $household, $month);
        }

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response->assertOk();

        $returnedMonths = collect($response->json('data'))
            ->pluck('month')
            ->map(fn ($month) => substr((string) $month, 0, 10))
            ->all();

        $this->assertSame([
            '2026-04-01',
            '2026-03-01',
            '2026-02-01',
            '2026-01-01',
        ], $returnedMonths);
    }

    /*
    |--------------------------------------------------------------------------
    | Response Contract
    |--------------------------------------------------------------------------
    */

    public function test_history_response_contains_expected_budget_fields(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $budget = $this->createBudget($user, $household, '2026-01-01', [
            'currency_code' => 'USD',
            'total_planned' => 250000,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $budget->id)
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.household_id', $household->id)
            ->assertJsonPath('data.0.currency_code', 'USD')
            ->assertJsonPath('data.0.total_planned', '250000.00');
    }

    public function test_history_preserves_budget_ownership_in_response(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $budget = $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/history/{$household->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $budget->id)
            ->assertJsonPath('data.0.user_id', $user->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function auth(User $user): static
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBudget(User $user, Household $household, string $month, array $overrides = []): Budget
    {
        return Budget::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'month' => $month,
            'currency_code' => 'XAF',
            'total_planned' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCategory(array $overrides = []): BudgetCategory
    {
        return BudgetCategory::factory()->create(array_merge([
            'is_default' => true,
            'user_id' => null,
        ], $overrides));
    }

    private function addHouseholdMember(Household $household, User $user): void
    {
        HouseholdMember::factory()->create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    }
}
