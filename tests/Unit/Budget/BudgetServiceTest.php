<?php

namespace Tests\Unit\Budget;

use App\Exceptions\ApiException;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\Budget\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class BudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->service = app(BudgetService::class);
    }

    private function household(User $owner): Household
    {
        return Household::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    private function budget(User $user, Household $household, array $overrides = []): Budget
    {
        return Budget::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ], $overrides));
    }

    private function category(array $overrides = []): BudgetCategory
    {
        return BudgetCategory::factory()->create(array_merge([
            'is_default' => true,
            'user_id' => null,
        ], $overrides));
    }

    private function item(Budget $budget, BudgetCategory $category, array $overrides = []): BudgetItem
    {
        return BudgetItem::factory()->create(array_merge([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 50000,
            'actual_amount' => 30000,
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | forUser
    |--------------------------------------------------------------------------
    */

    public function test_for_user_returns_users_budgets(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $olderBudget = $this->budget($user, $household, [
            'month' => '2026-01-01',
        ]);

        $newerBudget = $this->budget($user, $household, [
            'month' => '2026-02-01',
        ]);

        $otherUser = User::factory()->create();
        $otherHousehold = $this->household($otherUser);

        $this->budget($otherUser, $otherHousehold, [
            'month' => '2026-03-01',
        ]);

        $result = $this->service->forUser($user->id);

        $this->assertCount(2, $result);
        $this->assertSame($newerBudget->id, $result->first()->id);
        $this->assertSame($olderBudget->id, $result->last()->id);
    }

    public function test_for_user_returns_empty_collection_when_user_has_no_budgets(): void
    {
        $user = User::factory()->create();

        $result = $this->service->forUser($user->id);

        $this->assertCount(0, $result);
    }

    public function test_for_user_loads_household_and_items_with_categories(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household, [
            'month' => '2026-01-01',
        ]);

        $category = $this->category();
        $item = $this->item($budget, $category);

        $result = $this->service->forUser($user->id);

        $found = $result->firstWhere('id', $budget->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('household'));
        $this->assertTrue($found->relationLoaded('items'));
        $this->assertTrue($found->items->first()->relationLoaded('category'));
        $this->assertSame($item->id, $found->items->first()->id);
    }

    /*
    |--------------------------------------------------------------------------
    | create
    |--------------------------------------------------------------------------
    */

    public function test_user_can_create_budget_for_accessible_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->service->create($user->id, [
            'household_id' => $household->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 250000,
        ]);

        $this->assertSame($user->id, $budget->user_id);
        $this->assertSame($household->id, $budget->household_id);
        $this->assertSame('2026-08-01', $budget->month->toDateString());
        $this->assertSame('XAF', $budget->currency_code);
        $this->assertEquals(250000, (float) $budget->total_planned);

        $this->assertTrue($budget->relationLoaded('household'));
        $this->assertTrue($budget->relationLoaded('items'));
    }

    public function test_create_allows_household_member_to_create_budget(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $household = $this->household($owner);

        HouseholdMember::factory()->create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $budget = $this->service->create($member->id, [
            'household_id' => $household->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 150000,
        ]);

        $this->assertSame($member->id, $budget->user_id);
        $this->assertSame($household->id, $budget->household_id);
    }

    public function test_create_rejects_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $this->expectException(ApiException::class);

        $this->service->create($otherUser->id, [
            'household_id' => $household->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 100000,
        ]);
    }

    public function test_create_rejects_duplicate_budget_for_same_household_and_month(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $this->expectException(ApiException::class);

        $this->service->create($user->id, [
            'household_id' => $household->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 100000,
        ]);
    }

    public function test_create_allows_same_month_for_different_households(): void
    {
        $user = User::factory()->create();

        $householdOne = $this->household($user);
        $householdTwo = $this->household($user);

        $first = $this->service->create($user->id, [
            'household_id' => $householdOne->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 100000,
        ]);

        $second = $this->service->create($user->id, [
            'household_id' => $householdTwo->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 200000,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($householdOne->id, $first->household_id);
        $this->assertSame($householdTwo->id, $second->household_id);
    }

    /*
    |--------------------------------------------------------------------------
    | history
    |--------------------------------------------------------------------------
    */

    public function test_history_returns_budgets_for_user_and_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $older = $this->budget($user, $household, [
            'month' => '2026-01-01',
        ]);

        $newer = $this->budget($user, $household, [
            'month' => '2026-02-01',
        ]);

        $otherHousehold = $this->household($user);

        $this->budget($user, $otherHousehold, [
            'month' => '2026-03-01',
        ]);

        $result = $this->service->history($user->id, $household->id);

        $this->assertCount(2, $result);
        $this->assertSame($newer->id, $result->first()->id);
        $this->assertSame($older->id, $result->last()->id);
    }

    public function test_history_rejects_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $this->expectException(ApiException::class);

        $this->service->history($otherUser->id, $household->id);
    }

    /*
    |--------------------------------------------------------------------------
    | findForUser
    |--------------------------------------------------------------------------
    */

    public function test_find_for_user_returns_owned_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->service->findForUser($user->id, $budget->id);

        $this->assertSame($budget->id, $result->id);
        $this->assertSame($user->id, $result->user_id);
    }

    public function test_find_for_user_loads_relationships(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $category = $this->category();
        $this->item($budget, $category);

        $result = $this->service->findForUser($user->id, $budget->id);

        $this->assertTrue($result->relationLoaded('household'));
        $this->assertTrue($result->relationLoaded('items'));
        $this->assertTrue($result->items->first()->relationLoaded('category'));
    }

    public function test_find_for_user_rejects_budget_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $budget = $this->budget($owner, $household, [
            'month' => '2026-08-01',
        ]);

        $this->expectException(ApiException::class);

        $this->service->findForUser($otherUser->id, $budget->id);
    }

    public function test_find_for_user_rejects_unknown_budget(): void
    {
        $user = User::factory()->create();

        $this->expectException(ApiException::class);

        $this->service->findForUser($user->id, '00000000-0000-0000-0000-000000000000');
    }

    /*
    |--------------------------------------------------------------------------
    | update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 100000,
        ]);

        $result = $this->service->update($budget, [
            'currency_code' => 'EUR',
            'total_planned' => 200000,
        ]);

        $this->assertSame($budget->id, $result->id);
        $this->assertSame('EUR', $result->currency_code);
        $this->assertEquals(200000, (float) $result->total_planned);
    }

    public function test_update_allows_same_month_for_current_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->service->update($budget, [
            'month' => '2026-08-01',
        ]);

        $this->assertSame($budget->id, $result->id);
        $this->assertSame('2026-08-01', $result->month->toDateString());
    }

    public function test_update_rejects_duplicate_month(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $first = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $second = $this->budget($user, $household, [
            'month' => '2026-09-01',
        ]);

        $this->expectException(ApiException::class);

        $this->service->update($second, [
            'month' => '2026-08-01',
        ]);

        $this->assertSame('2026-09-01', $second->fresh()->month->toDateString());
        $this->assertNotNull($first->fresh());
    }

    public function test_update_refreshes_relationships(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $category = $this->category();
        $this->item($budget, $category);

        $result = $this->service->update($budget, [
            'total_planned' => 300000,
        ]);

        $this->assertTrue($result->relationLoaded('household'));
        $this->assertTrue($result->relationLoaded('items'));
        $this->assertTrue($result->items->first()->relationLoaded('category'));
    }

    /*
    |--------------------------------------------------------------------------
    | delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $result = $this->service->delete($budget);

        $this->assertTrue($result);

        $this->assertDatabaseMissing('budgets', [
            'id' => $budget->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | compare
    |--------------------------------------------------------------------------
    */

    public function test_compare_returns_budget_totals_and_changes(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $current = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $comparison = $this->budget($user, $household, [
            'month' => '2026-07-01',
        ]);

        $currentCategory = $this->category();
        $comparisonCategory = $this->category();

        $this->item($current, $currentCategory, [
            'planned_amount' => 100000,
            'actual_amount' => 60000,
        ]);

        $this->item($current, $comparisonCategory, [
            'planned_amount' => 50000,
            'actual_amount' => 30000,
        ]);

        $this->item($comparison, $currentCategory, [
            'planned_amount' => 80000,
            'actual_amount' => 50000,
        ]);

        $this->item($comparison, $comparisonCategory, [
            'planned_amount' => 40000,
            'actual_amount' => 20000,
        ]);

        $result = $this->service->compare($user->id, $household->id, '2026-08-01', '2026-07-01');

        $this->assertSame($household->id, $result['household_id']);

        $this->assertSame('2026-08-01', $result['current']['month']);
        $this->assertSame($current->id, $result['current']['budget_id']);
        $this->assertEquals(150000, $result['current']['planned']);
        $this->assertEquals(90000, $result['current']['actual']);
        $this->assertEquals(60000, $result['current']['remaining']);

        $this->assertSame('2026-07-01', $result['comparison']['month']);
        $this->assertSame($comparison->id, $result['comparison']['budget_id']);
        $this->assertEquals(120000, $result['comparison']['planned']);
        $this->assertEquals(70000, $result['comparison']['actual']);
        $this->assertEquals(50000, $result['comparison']['remaining']);

        $this->assertEquals(30000, $result['change']['planned']);
        $this->assertEquals(20000, $result['change']['actual']);
        $this->assertEquals(10000, $result['change']['remaining']);
    }

    public function test_compare_returns_category_level_comparison(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $current = $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $comparison = $this->budget($user, $household, [
            'month' => '2026-07-01',
        ]);

        $sharedCategory = $this->category();
        $currentOnlyCategory = $this->category();
        $comparisonOnlyCategory = $this->category();

        $this->item($current, $sharedCategory, [
            'planned_amount' => 100000,
            'actual_amount' => 60000,
        ]);

        $this->item($current, $currentOnlyCategory, [
            'planned_amount' => 50000,
            'actual_amount' => 20000,
        ]);

        $this->item($comparison, $sharedCategory, [
            'planned_amount' => 80000,
            'actual_amount' => 50000,
        ]);

        $this->item($comparison, $comparisonOnlyCategory, [
            'planned_amount' => 40000,
            'actual_amount' => 10000,
        ]);

        $result = $this->service->compare($user->id, $household->id, '2026-08-01', '2026-07-01');

        $this->assertCount(3, $result['categories']);

        $categories = collect($result['categories'])
            ->keyBy('category_id');

        $shared = $categories->get($sharedCategory->id);

        $this->assertNotNull($shared);
        $this->assertSame($sharedCategory->id, $shared['category_id']);
        $this->assertSame($sharedCategory->id, $shared['category']->id);

        $this->assertEquals(100000, $shared['current']['planned']);
        $this->assertEquals(60000, $shared['current']['actual']);
        $this->assertEquals(40000, $shared['current']['remaining']);

        $this->assertEquals(80000, $shared['comparison']['planned']);
        $this->assertEquals(50000, $shared['comparison']['actual']);
        $this->assertEquals(30000, $shared['comparison']['remaining']);

        $this->assertEquals(20000, $shared['change']['planned']);
        $this->assertEquals(10000, $shared['change']['actual']);
        $this->assertEquals(10000, $shared['change']['remaining']);

        $currentOnly = $categories->get($currentOnlyCategory->id);

        $this->assertNotNull($currentOnly);
        $this->assertEquals(50000, $currentOnly['current']['planned']);
        $this->assertEquals(20000, $currentOnly['current']['actual']);
        $this->assertEquals(30000, $currentOnly['current']['remaining']);
        $this->assertEquals(0, $currentOnly['comparison']['planned']);
        $this->assertEquals(0, $currentOnly['comparison']['actual']);
        $this->assertEquals(0, $currentOnly['comparison']['remaining']);

        $comparisonOnly = $categories->get($comparisonOnlyCategory->id);

        $this->assertNotNull($comparisonOnly);
        $this->assertEquals(0, $comparisonOnly['current']['planned']);
        $this->assertEquals(0, $comparisonOnly['current']['actual']);
        $this->assertEquals(0, $comparisonOnly['current']['remaining']);
        $this->assertEquals(40000, $comparisonOnly['comparison']['planned']);
        $this->assertEquals(10000, $comparisonOnly['comparison']['actual']);
        $this->assertEquals(30000, $comparisonOnly['comparison']['remaining']);
    }

    public function test_compare_rejects_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $this->expectException(ApiException::class);

        $this->service->compare($otherUser->id, $household->id, '2026-08-01', '2026-07-01');
    }

    public function test_compare_rejects_missing_current_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->budget($user, $household, [
            'month' => '2026-07-01',
        ]);

        $this->expectException(ApiException::class);

        $this->service->compare($user->id, $household->id, '2026-08-01', '2026-07-01');
    }

    public function test_compare_rejects_missing_comparison_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->budget($user, $household, [
            'month' => '2026-08-01',
        ]);

        $this->expectException(ApiException::class);

        $this->service->compare($user->id, $household->id, '2026-08-01', '2026-07-01');
    }

    public function test_compare_uses_item_amounts_as_source_of_truth(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $current = $this->budget($user, $household, [
            'month' => '2026-08-01',
            'total_planned' => 999999,
        ]);

        $comparison = $this->budget($user, $household, [
            'month' => '2026-07-01',
            'total_planned' => 888888,
        ]);

        $category = $this->category();

        $this->item($current, $category, [
            'planned_amount' => 100000,
            'actual_amount' => 70000,
        ]);

        $this->item($comparison, $category, [
            'planned_amount' => 80000,
            'actual_amount' => 50000,
        ]);

        $result = $this->service->compare($user->id, $household->id, '2026-08-01', '2026-07-01');

        $this->assertEquals(100000, $result['current']['planned']);
        $this->assertEquals(70000, $result['current']['actual']);

        $this->assertEquals(80000, $result['comparison']['planned']);
        $this->assertEquals(50000, $result['comparison']['actual']);
    }
}
