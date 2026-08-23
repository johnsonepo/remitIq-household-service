<?php

namespace Tests\Unit\Budget;

use App\Exceptions\ApiException;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\User;
use App\Services\Budget\BudgetItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class BudgetItemServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetItemService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->service = app(BudgetItemService::class);
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

    private function defaultCategory(array $overrides = []): BudgetCategory
    {
        return BudgetCategory::factory()->create(array_merge([
            'is_default' => true,
            'user_id' => null,
        ], $overrides));
    }

    private function customCategory(
        User $user,
        array $overrides = []
    ): BudgetCategory {
        return BudgetCategory::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_default' => false,
        ], $overrides));
    }

    private function item(
        Budget $budget,
        BudgetCategory $category,
        array $overrides = []
    ): BudgetItem {
        return BudgetItem::factory()->create(array_merge([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ], $overrides));
    }

    /**
     * Build valid budget item data for the service.
     */
    private function itemData(
        string $categoryId,
        float $plannedAmount = 50000
    ): array {
        return [
            'budget_category_id' => $categoryId,
            'planned_amount' => $plannedAmount,
            'actual_amount' => 0,
            'notes' => 'Test budget item',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | forBudget
    |--------------------------------------------------------------------------
    */

    public function test_for_budget_returns_items_for_owned_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->defaultCategory();
        $item = $this->item($budget, $category);

        $result = $this->service->forBudget($budget, $user->id);

        $this->assertCount(1, $result);
        $this->assertSame($item->id, $result->first()->id);
    }

    public function test_for_budget_returns_empty_collection_when_budget_has_no_items(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $result = $this->service->forBudget($budget, $user->id);

        $this->assertCount(0, $result);
    }

    public function test_for_budget_rejects_budget_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $budget = $this->budget($owner, $household);

        $this->expectException(ApiException::class);

        $this->service->forBudget($budget, $otherUser->id);
    }

    /*
    |--------------------------------------------------------------------------
    | create
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_create_item_with_default_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->defaultCategory();

        $item = $this->service->create(
            $budget,
            $user->id,
            $this->itemData($category->id, 50000)
        );

        $this->assertNotNull($item);
        $this->assertSame($budget->id, $item->budget_id);
        $this->assertSame($category->id, $item->budget_category_id);
        $this->assertSame(50000, (int) $item->planned_amount);
        $this->assertTrue($item->relationLoaded('category'));
    }

    public function test_owner_can_create_item_with_own_custom_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->customCategory($user);

        $item = $this->service->create(
            $budget,
            $user->id,
            $this->itemData($category->id, 75000)
        );

        $this->assertSame($category->id, $item->budget_category_id);
        $this->assertSame(75000, (int) $item->planned_amount);
    }

    public function test_create_rejects_item_for_budget_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $budget = $this->budget($owner, $household);

        $category = $this->defaultCategory();

        $this->expectException(ApiException::class);

        $this->service->create(
            $budget,
            $otherUser->id,
            $this->itemData($category->id)
        );
    }

    public function test_create_rejects_inaccessible_custom_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->customCategory($otherUser);

        $this->expectException(ApiException::class);

        $this->service->create(
            $budget,
            $user->id,
            $this->itemData($category->id)
        );
    }

    public function test_create_rejects_unknown_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $this->expectException(ApiException::class);

        $this->service->create(
            $budget,
            $user->id,
            $this->itemData(
                '00000000-0000-0000-0000-000000000000'
            )
        );
    }

    public function test_create_rejects_duplicate_category_in_same_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->defaultCategory();

        $this->item($budget, $category);

        $this->expectException(ApiException::class);

        $this->service->create(
            $budget,
            $user->id,
            $this->itemData($category->id)
        );
    }

    public function test_same_category_can_be_used_in_different_budgets(): void
{
    $user = User::factory()->create();
    $household = $this->household($user);

    $budgetOne = $this->budget($user, $household, [
        'month' => '2026-06-01',
    ]);

    $budgetTwo = $this->budget($user, $household, [
        'month' => '2026-07-01',
    ]);

    $category = $this->defaultCategory();

    $first = $this->service->create(
        $budgetOne,
        $user->id,
        $this->itemData($category->id, 50000)
    );

    $second = $this->service->create(
        $budgetTwo,
        $user->id,
        $this->itemData($category->id, 75000)
    );

    $this->assertNotSame($first->id, $second->id);
    $this->assertSame($budgetOne->id, $first->budget_id);
    $this->assertSame($budgetTwo->id, $second->budget_id);
    $this->assertSame($category->id, $first->budget_category_id);
    $this->assertSame($category->id, $second->budget_category_id);
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

        $category = $this->defaultCategory();
        $item = $this->item($budget, $category);

        $result = $this->service->findForBudget(
            $budget,
            $user->id,
            $item->id
        );

        $this->assertSame($item->id, $result->id);
        $this->assertTrue($result->relationLoaded('category'));
    }

    public function test_find_for_budget_rejects_unknown_item(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $this->expectException(ApiException::class);

        $this->service->findForBudget(
            $budget,
            $user->id,
            '00000000-0000-0000-0000-000000000000'
        );
    }

    public function test_find_for_budget_does_not_return_item_from_another_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budgetOne = $this->budget($user, $household);
        $budgetTwo = $this->budget($user, $household);

        $category = $this->defaultCategory();
        $item = $this->item($budgetTwo, $category);

        $this->expectException(ApiException::class);

        $this->service->findForBudget(
            $budgetOne,
            $user->id,
            $item->id
        );
    }

    public function test_find_for_budget_rejects_budget_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $budget = $this->budget($owner, $household);

        $this->expectException(ApiException::class);

        $this->service->findForBudget(
            $budget,
            $otherUser->id,
            '00000000-0000-0000-0000-000000000000'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_item(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->defaultCategory();

        $item = $this->item($budget, $category, [
            'planned_amount' => 50000,
        ]);

        $result = $this->service->update(
            $item,
            $budget,
            $user->id,
            [
                'planned_amount' => 100000,
            ]
        );

        $this->assertSame(100000, (int) $result->planned_amount);
        $this->assertSame($item->id, $result->id);
    }

    public function test_owner_can_change_item_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $oldCategory = $this->defaultCategory();
        $newCategory = $this->defaultCategory();

        $item = $this->item($budget, $oldCategory);

        $result = $this->service->update(
            $item,
            $budget,
            $user->id,
            [
                'budget_category_id' => $newCategory->id,
            ]
        );

        $this->assertSame($newCategory->id, $result->budget_category_id);
    }

    public function test_update_rejects_duplicate_category(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $firstCategory = $this->defaultCategory();
        $secondCategory = $this->defaultCategory();

        $itemOne = $this->item($budget, $firstCategory);
        $itemTwo = $this->item($budget, $secondCategory);

        $this->expectException(ApiException::class);

        $this->service->update(
            $itemTwo,
            $budget,
            $user->id,
            [
                'budget_category_id' => $firstCategory->id,
            ]
        );
    }

    public function test_update_rejects_inaccessible_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $currentCategory = $this->defaultCategory();
        $otherCategory = $this->customCategory($otherUser);

        $item = $this->item($budget, $currentCategory);

        $this->expectException(ApiException::class);

        $this->service->update(
            $item,
            $budget,
            $user->id,
            [
                'budget_category_id' => $otherCategory->id,
            ]
        );
    }

    public function test_update_rejects_item_from_another_budget(): void
{
    $user = User::factory()->create();
    $household = $this->household($user);

    $budgetOne = $this->budget($user, $household, [
        'month' => '2026-08-01',
    ]);

    $budgetTwo = $this->budget($user, $household, [
        'month' => '2026-09-01',
    ]);

    $category = $this->defaultCategory();
    $item = $this->item($budgetTwo, $category);

    $this->expectException(ApiException::class);

    $this->service->update(
        $item,
        $budgetOne,
        $user->id,
        [
            'planned_amount' => 100000,
        ]
    );
}

    public function test_update_rejects_budget_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $budget = $this->budget($owner, $household);

        $category = $this->defaultCategory();
        $item = $this->item($budget, $category);

        $this->expectException(ApiException::class);

        $this->service->update(
            $item,
            $budget,
            $otherUser->id,
            [
                'planned_amount' => 100000,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_item(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);
        $budget = $this->budget($user, $household);

        $category = $this->defaultCategory();
        $item = $this->item($budget, $category);

        $result = $this->service->delete(
            $item,
            $budget,
            $user->id
        );

        $this->assertTrue($result);

        $this->assertDatabaseMissing('budget_items', [
            'id' => $item->id,
        ]);
    }

    public function test_delete_rejects_item_from_another_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budgetOne = $this->budget($user, $household);
        $budgetTwo = $this->budget($user, $household);

        $category = $this->defaultCategory();
        $item = $this->item($budgetTwo, $category);

        $this->expectException(ApiException::class);

        $this->service->delete(
            $item,
            $budgetOne,
            $user->id
        );
    }

    public function test_delete_rejects_budget_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);
        $budget = $this->budget($owner, $household);

        $category = $this->defaultCategory();
        $item = $this->item($budget, $category);

        $this->expectException(ApiException::class);

        $this->service->delete(
            $item,
            $budget,
            $otherUser->id
        );
    }
}
