<?php

namespace Tests\Unit\Budget;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\User;
use App\Repositories\BudgetItemRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetItemRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetItemRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(BudgetItemRepository::class);
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
        ], $overrides));
    }

    private function item(Budget $budget, BudgetCategory $category, array $overrides = []): BudgetItem
    {
        return BudgetItem::factory()->create(array_merge([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | forBudget
    |--------------------------------------------------------------------------
    */

    public function test_for_budget_returns_only_items_for_requested_budget(): void
    {
        $user = User::factory()->create();

        $household = $this->household($user);

        $budget = $this->budget($user, $household);
        $otherBudget = $this->budget($user, $household, [
            'month' => '2026-09-01',
        ]);

        $category = $this->category();

        $ownItem = $this->item($budget, $category);
        $otherItem = $this->item($otherBudget, $category);

        $items = $this->repository->forBudget($budget->id);

        $ids = $items->pluck('id');

        $this->assertTrue($ids->contains($ownItem->id));
        $this->assertFalse($ids->contains($otherItem->id));
    }

    public function test_for_budget_returns_empty_collection_when_budget_has_no_items(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $items = $this->repository->forBudget($budget->id);

        $this->assertCount(0, $items);
    }

    public function test_for_budget_orders_items_by_created_at_ascending(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $firstCategory = $this->category([
            'name' => 'First',
        ]);

        $secondCategory = $this->category([
            'name' => 'Second',
        ]);

        $thirdCategory = $this->category([
            'name' => 'Third',
        ]);

        $first = $this->item($budget, $firstCategory);
        $second = $this->item($budget, $secondCategory);
        $third = $this->item($budget, $thirdCategory);

        $first->created_at = now()->subDays(3);
        $first->save();

        $second->created_at = now()->subDays(2);
        $second->save();

        $third->created_at = now()->subDay();
        $third->save();

        $items = $this->repository->forBudget($budget->id);

        $this->assertSame([
            $first->id,
            $second->id,
            $third->id,
        ], $items->pluck('id')->all());
    }

    public function test_for_budget_eager_loads_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->category();

        $this->item($budget, $category);

        $result = $this->repository
            ->forBudget($budget->id)
            ->first();

        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('category'));
        $this->assertSame($category->id, $result->category->id);
    }

    /*
    |--------------------------------------------------------------------------
    | forBudgetCategory
    |--------------------------------------------------------------------------
    */

    public function test_for_budget_category_returns_matching_item(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->category();

        $item = $this->item($budget, $category);

        $result = $this->repository->forBudgetCategory($budget->id, $category->id);

        $this->assertNotNull($result);
        $this->assertSame($item->id, $result->id);
    }

    public function test_for_budget_category_returns_null_when_item_does_not_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->category();

        $result = $this->repository->forBudgetCategory($budget->id, $category->id);

        $this->assertNull($result);
    }

    public function test_for_budget_category_does_not_return_item_from_another_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household);

        $otherBudget = $this->budget($user, $household, [
            'month' => '2026-09-01',
        ]);

        $category = $this->category();

        $this->item($otherBudget, $category);

        $result = $this->repository->forBudgetCategory($budget->id, $category->id);

        $this->assertNull($result);
    }

    public function test_for_budget_category_eager_loads_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->category();

        $this->item($budget, $category);

        $result = $this->repository->forBudgetCategory($budget->id, $category->id);

        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('category'));
        $this->assertSame($category->id, $result->category->id);
    }

    /*
    |--------------------------------------------------------------------------
    | findForBudget
    |--------------------------------------------------------------------------
    */

    public function test_find_for_budget_returns_matching_item(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->category();

        $item = $this->item($budget, $category);

        $result = $this->repository->findForBudget($budget->id, $item->id);

        $this->assertNotNull($result);
        $this->assertSame($item->id, $result->id);
    }

    public function test_find_for_budget_returns_null_when_item_does_not_exist(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $result = $this->repository->findForBudget($budget->id, '00000000-0000-0000-0000-000000000000');

        $this->assertNull($result);
    }

    public function test_find_for_budget_does_not_return_item_from_another_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = $this->budget($user, $household);

        $otherBudget = $this->budget($user, $household, [
            'month' => '2026-09-01',
        ]);

        $category = $this->category();

        $item = $this->item($otherBudget, $category);

        $result = $this->repository->findForBudget($budget->id, $item->id);

        $this->assertNull($result);
    }

    public function test_find_for_budget_eager_loads_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->category();

        $item = $this->item($budget, $category);

        $result = $this->repository->findForBudget($budget->id, $item->id);

        $this->assertNotNull($result);
        $this->assertTrue($result->relationLoaded('category'));
        $this->assertSame($category->id, $result->category->id);
    }
}
