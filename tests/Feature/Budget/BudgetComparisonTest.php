<?php

declare(strict_types=1);

namespace Tests\Feature\Budget;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BudgetComparisonTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_comparison_requires_authentication(): void
    {
        $household = Household::factory()->create();

        $response = $this->getJson('/api/v1/budgets/comparison?' . http_build_query([
            'household_id' => $household->id,
            'month' => '2026-02-01',
            'compare_month' => '2026-01-01',
        ]));

        $response->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_household_id_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_household_id_must_be_valid_uuid(): void
    {
        $user = User::factory()->create();

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => 'not-a-uuid',
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_month_is_required(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_month_must_be_a_valid_date(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => 'not-a-date',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_compare_month_is_required(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['compare_month']);
    }

    public function test_compare_month_must_be_a_valid_date(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => 'not-a-date',
            ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['compare_month']);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_user_cannot_compare_budgets_for_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->createHousehold($owner);

        $this->createBudget($owner, $household, '2026-02-01');
        $this->createBudget($owner, $household, '2026-01-01');

        $response = $this->auth($attacker)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response->assertForbidden();
    }

    public function test_user_cannot_compare_another_users_budgets_in_same_household(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $household = $this->createHousehold($owner);

        HouseholdMember::factory()->create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->createBudget($owner, $household, '2026-02-01');
        $this->createBudget($owner, $household, '2026-01-01');

        $response = $this->auth($member)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        /*
         * The household may be accessible, but BudgetService still scopes
         * both budgets by authenticated user_id.
         */
        $response->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Missing Budgets
    |--------------------------------------------------------------------------
    */

    public function test_current_month_budget_must_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Current month budget not found.');
    }

    public function test_comparison_month_budget_must_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $this->createBudget($user, $household, '2026-02-01');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Comparison month budget not found.');
    }

    public function test_budget_from_another_household_is_not_used(): void
    {
        $user = User::factory()->create();

        $householdA = $this->createHousehold($user);
        $householdB = Household::factory()->create();

        $this->createBudget($user, $householdB, '2026-02-01');
        $this->createBudget($user, $householdB, '2026-01-01');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $householdA->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Basic Comparison
    |--------------------------------------------------------------------------
    */

    public function test_user_can_compare_two_monthly_budgets(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Budgets compared successfully.')
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.current.budget_id', $current->id)
            ->assertJsonPath('data.comparison.budget_id', $comparison->id);
    }

    public function test_comparison_returns_expected_top_level_structure(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $this->createBudget($user, $household, '2026-02-01');
        $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'household_id',
                    'current' => [
                        'month',
                        'budget_id',
                        'planned',
                        'actual',
                        'remaining',
                    ],
                    'comparison' => [
                        'month',
                        'budget_id',
                        'planned',
                        'actual',
                        'remaining',
                    ],
                    'change' => [
                        'planned',
                        'actual',
                        'remaining',
                    ],
                    'categories',
                ],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Aggregate Calculations
    |--------------------------------------------------------------------------
    */

    public function test_current_budget_totals_are_calculated_from_items(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $categoryA = $this->createCategory();
        $categoryB = $this->createCategory();

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $categoryA, 100000, 40000);
        $this->createItem($current, $categoryB, 50000, 10000);

        $this->createItem($comparison, $categoryA, 80000, 30000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.current.planned', 150000)
            ->assertJsonPath('data.current.actual', 50000)
            ->assertJsonPath('data.current.remaining', 100000)
            ->assertJsonPath('data.comparison.planned', 80000)
            ->assertJsonPath('data.comparison.actual', 30000)
            ->assertJsonPath('data.comparison.remaining', 50000)
            ->assertJsonPath('data.change.planned', 70000)
            ->assertJsonPath('data.change.actual', 20000)
            ->assertJsonPath('data.change.remaining', 50000);
    }

    public function test_remaining_amount_is_planned_minus_actual(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $category = $this->createCategory();

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $category, 100000, 120000);
        $this->createItem($comparison, $category, 100000, 90000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.current.remaining', -20000)
            ->assertJsonPath('data.comparison.remaining', 10000)
            ->assertJsonPath('data.change.remaining', -30000);
    }

    public function test_budgets_with_no_items_return_zero_totals(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $this->createBudget($user, $household, '2026-02-01');
        $this->createBudget($user, $household, '2026-01-01');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.current.planned', 0)
            ->assertJsonPath('data.current.actual', 0)
            ->assertJsonPath('data.current.remaining', 0)
            ->assertJsonPath('data.comparison.planned', 0)
            ->assertJsonPath('data.comparison.actual', 0)
            ->assertJsonPath('data.comparison.remaining', 0)
            ->assertJsonPath('data.change.planned', 0)
            ->assertJsonPath('data.change.actual', 0)
            ->assertJsonPath('data.change.remaining', 0)
            ->assertJsonCount(0, 'data.categories');
    }

    /*
    |--------------------------------------------------------------------------
    | Category Comparison
    |--------------------------------------------------------------------------
    */

    public function test_category_comparison_contains_categories_present_in_current_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $category = $this->createCategory([
            'name' => 'Food',
        ]);

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $category, 100000, 40000);
        $this->createItem($comparison, $category, 80000, 30000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.category_id', $category->id)
            ->assertJsonPath('data.categories.0.current.planned', 100000)
            ->assertJsonPath('data.categories.0.current.actual', 40000)
            ->assertJsonPath('data.categories.0.current.remaining', 60000)
            ->assertJsonPath('data.categories.0.comparison.planned', 80000)
            ->assertJsonPath('data.categories.0.comparison.actual', 30000)
            ->assertJsonPath('data.categories.0.comparison.remaining', 50000)
            ->assertJsonPath('data.categories.0.change.planned', 20000)
            ->assertJsonPath('data.categories.0.change.actual', 10000)
            ->assertJsonPath('data.categories.0.change.remaining', 10000);
    }

    public function test_category_present_only_in_current_budget_has_zero_comparison_values(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $currentCategory = $this->createCategory([
            'name' => 'Transport',
        ]);

        $comparisonCategory = $this->createCategory([
            'name' => 'Food',
        ]);

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $currentCategory, 75000, 25000);
        $this->createItem($comparison, $comparisonCategory, 50000, 20000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.categories');

        $response->assertJsonFragment([
            'category_id' => $currentCategory->id,
            'current' => [
                'planned' => 75000,
                'actual' => 25000,
                'remaining' => 50000,
            ],
            'comparison' => [
                'planned' => 0,
                'actual' => 0,
                'remaining' => 0,
            ],
        ]);
    }

    public function test_category_present_only_in_comparison_budget_has_zero_current_values(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $currentCategory = $this->createCategory([
            'name' => 'Transport',
        ]);

        $comparisonCategory = $this->createCategory([
            'name' => 'Food',
        ]);

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $currentCategory, 75000, 25000);
        $this->createItem($comparison, $comparisonCategory, 50000, 20000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonFragment([
                'category_id' => $comparisonCategory->id,
                'current' => [
                    'planned' => 0,
                    'actual' => 0,
                    'remaining' => 0,
                ],
                'comparison' => [
                    'planned' => 50000,
                    'actual' => 20000,
                    'remaining' => 30000,
                ],
            ]);
    }

    public function test_category_response_includes_category_relationship(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $category = $this->createCategory([
            'name' => 'Housing',
        ]);

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $category, 100000, 50000);
        $this->createItem($comparison, $category, 90000, 45000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.categories.0.category.id', $category->id)
            ->assertJsonPath('data.categories.0.category.name', 'Housing');
    }

    public function test_categories_from_both_months_are_returned_without_duplicates(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $shared = $this->createCategory(['name' => 'Food']);
        $currentOnly = $this->createCategory(['name' => 'Transport']);
        $comparisonOnly = $this->createCategory(['name' => 'Utilities']);

        $current = $this->createBudget($user, $household, '2026-02-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $shared, 100000, 40000);
        $this->createItem($current, $currentOnly, 50000, 20000);

        $this->createItem($comparison, $shared, 80000, 30000);
        $this->createItem($comparison, $comparisonOnly, 30000, 10000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data.categories');

        $categoryIds = collect($response->json('data.categories'))
            ->pluck('category_id')
            ->all();

        $this->assertCount(3, array_unique($categoryIds));
        $this->assertContains($shared->id, $categoryIds);
        $this->assertContains($currentOnly->id, $categoryIds);
        $this->assertContains($comparisonOnly->id, $categoryIds);
    }

    /*
    |--------------------------------------------------------------------------
    | Date Handling
    |--------------------------------------------------------------------------
    */

    public function test_response_normalizes_current_month_to_date_string(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $this->createBudget($user, $household, '2026-02-15');
        $this->createBudget($user, $household, '2026-01-15');

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-15',
                'compare_month' => '2026-01-15',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.current.month', '2026-02-15')
            ->assertJsonPath('data.comparison.month', '2026-01-15');
    }

    public function test_comparison_works_across_different_years(): void
    {
        $user = User::factory()->create();
        $household = $this->createHousehold($user);

        $category = $this->createCategory();

        $current = $this->createBudget($user, $household, '2027-01-01');
        $comparison = $this->createBudget($user, $household, '2026-01-01');

        $this->createItem($current, $category, 200000, 100000);
        $this->createItem($comparison, $category, 150000, 50000);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2027-01-01',
                'compare_month' => '2026-01-01',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.current.month', '2027-01-01')
            ->assertJsonPath('data.comparison.month', '2026-01-01')
            ->assertJsonPath('data.change.planned', 50000)
            ->assertJsonPath('data.change.actual', 50000)
            ->assertJsonPath('data.change.remaining', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Data Isolation
    |--------------------------------------------------------------------------
    */

    public function test_other_users_budgets_are_not_included_in_category_totals(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->createHousehold($owner);

        $category = $this->createCategory();

        $attackerHousehold = $this->createHousehold($attacker);

        $current = $this->createBudget($attacker, $attackerHousehold, '2026-02-01');
        $comparison = $this->createBudget($attacker, $attackerHousehold, '2026-01-01');

        $this->createItem($current, $category, 999999, 999999);
        $this->createItem($comparison, $category, 999999, 999999);

        $response = $this->auth($owner)
            ->getJson('/api/v1/budgets/comparison?' . http_build_query([
                'household_id' => $household->id,
                'month' => '2026-02-01',
                'compare_month' => '2026-01-01',
            ]));

        $response->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
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
     * @param array<string, mixed> $overrides
     */
    private function createBudget(
        User $user,
        Household $household,
        string $month,
        array $overrides = []
    ): Budget {
        return Budget::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'month' => $month,
            'currency_code' => 'XAF',
            'total_planned' => 0,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCategory(array $overrides = []): BudgetCategory
    {
        return BudgetCategory::factory()->create(array_merge([
            'is_default' => true,
            'user_id' => null,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createItem(
        Budget $budget,
        BudgetCategory $category,
        float|int $planned,
        float|int $actual,
        array $overrides = []
    ): BudgetItem {
        return BudgetItem::factory()->create(array_merge([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => $planned,
            'actual_amount' => $actual,
        ], $overrides));
    }
}
