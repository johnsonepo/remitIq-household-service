<?php

namespace Tests\Unit\Budget;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\User;
use App\Repositories\BudgetRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class BudgetRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->repository = app(BudgetRepository::class);
    }

    private function household(User $owner): Household
    {
        return Household::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    private function budget(
        User $user,
        Household $household,
        array $overrides = []
    ): Budget {
        return Budget::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | forUser
    |--------------------------------------------------------------------------
    */

    public function test_for_user_returns_only_users_budgets(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($otherUser);

        $ownBudget = $this->budget($user, $household);
        $otherBudget = $this->budget($otherUser, $otherHousehold);

        $budgets = $this->repository->forUser($user->id);

        $ids = $budgets->pluck('id');

        $this->assertTrue($ids->contains($ownBudget->id));
        $this->assertFalse($ids->contains($otherBudget->id));
    }

    public function test_for_user_returns_empty_collection_when_user_has_no_budgets(): void
    {
        $user = User::factory()->create();

        $budgets = $this->repository->forUser($user->id);

        $this->assertCount(0, $budgets);
    }

    public function test_for_user_orders_budgets_by_month_descending(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $oldest = $this->budget($user, $household, [
            'month' => '2026-01-01',
        ]);

        $newest = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $middle = $this->budget($user, $household, [
            'month' => '2026-04-01',
        ]);

        $budgets = $this->repository->forUser($user->id);

        $this->assertSame([
            $newest->id,
            $middle->id,
            $oldest->id,
        ], $budgets->pluck('id')->all());
    }

    public function test_for_user_eager_loads_household_and_items_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household);

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $result = $this->repository->forUser($user->id)->first();

        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('household'));
        $this->assertTrue($result->relationLoaded('items'));
        $this->assertTrue($result->items->first()->relationLoaded('category'));
    }

    /*
    |--------------------------------------------------------------------------
    | historyForHousehold
    |--------------------------------------------------------------------------
    */

    public function test_history_returns_only_user_budgets_for_household(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($otherUser);

        $ownBudget = $this->budget($user, $household);

        $otherUserSameHousehold = $this->budget($otherUser, $household);

        $otherHouseholdBudget = $this->budget($user, $otherHousehold);

        $budgets = $this->repository->historyForHousehold(
            $user->id,
            $household->id
        );

        $ids = $budgets->pluck('id');

        $this->assertTrue($ids->contains($ownBudget->id));
        $this->assertFalse($ids->contains($otherUserSameHousehold->id));
        $this->assertFalse($ids->contains($otherHouseholdBudget->id));
    }

    public function test_history_returns_empty_collection_when_no_matching_budgets_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budgets = $this->repository->historyForHousehold(
            $user->id,
            $household->id
        );

        $this->assertCount(0, $budgets);
    }

    public function test_history_orders_budgets_by_month_descending(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $oldest = $this->budget($user, $household, [
            'month' => '2025-12-01',
        ]);

        $newest = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $middle = $this->budget($user, $household, [
            'month' => '2026-03-01',
        ]);

        $budgets = $this->repository->historyForHousehold(
            $user->id,
            $household->id
        );

        $this->assertSame([
            $newest->id,
            $middle->id,
            $oldest->id,
        ], $budgets->pluck('id')->all());
    }

    public function test_history_eager_loads_relationships(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household);

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $result = $this->repository
            ->historyForHousehold($user->id, $household->id)
            ->first();

        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('household'));
        $this->assertTrue($result->relationLoaded('items'));
        $this->assertTrue($result->items->first()->relationLoaded('category'));
    }

    /*
    |--------------------------------------------------------------------------
    | forUserHouseholdMonth
    |--------------------------------------------------------------------------
    */

    public function test_for_user_household_month_returns_matching_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->repository->forUserHouseholdMonth(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNotNull($result);
        $this->assertSame($budget->id, $result->id);
    }

    public function test_for_user_household_month_returns_null_when_budget_does_not_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $result = $this->repository->forUserHouseholdMonth(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNull($result);
    }

    public function test_for_user_household_month_does_not_return_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);

        $this->budget($otherUser, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->repository->forUserHouseholdMonth(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNull($result);
    }

    public function test_for_user_household_month_does_not_return_budget_from_another_household(): void
    {
        $user = User::factory()->create();

        $household = $this->household($user);
        $otherHousehold = $this->household($user);

        $this->budget($user, $otherHousehold, [
            'month' => '2026-08-01',
        ]);

        $result = $this->repository->forUserHouseholdMonth(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNull($result);
    }

    public function test_for_user_household_month_eager_loads_relationships(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $result = $this->repository->forUserHouseholdMonth(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('household'));
        $this->assertTrue($result->relationLoaded('items'));
        $this->assertTrue($result->items->first()->relationLoaded('category'));
    }

    /*
    |--------------------------------------------------------------------------
    | forUserHouseholdMonthWithItems
    |--------------------------------------------------------------------------
    */

    public function test_for_user_household_month_with_items_returns_matching_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->repository->forUserHouseholdMonthWithItems(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNotNull($result);
        $this->assertSame($budget->id, $result->id);
        $this->assertTrue($result->relationLoaded('items'));
    }

    public function test_for_user_household_month_with_items_returns_null_when_budget_does_not_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $result = $this->repository->forUserHouseholdMonthWithItems(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNull($result);
    }

    public function test_for_user_household_month_with_items_does_not_leak_another_users_budget(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);

        $this->budget($otherUser, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->repository->forUserHouseholdMonthWithItems(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNull($result);
    }

    public function test_for_user_household_month_with_items_loads_items_and_categories(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $result = $this->repository->forUserHouseholdMonthWithItems(
            $user->id,
            $household->id,
            '2026-08-01'
        );

        $this->assertNotNull($result);
        $this->assertCount(1, $result->items);
        $this->assertTrue(
            $result->items->first()->relationLoaded('category')
        );
        $this->assertSame(
            $category->id,
            $result->items->first()->category->id
        );
    }
}
