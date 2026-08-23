<?php

namespace Tests\Feature\Budget;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BudgetItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function auth(User $user): static
    {
        return $this->withToken(JWTAuth::fromUser($user));
    }

    private function household(User $user): Household
    {
        return Household::factory()->create([
            'owner_id' => $user->id,
        ]);
    }

    private function budget(User $user): Budget
    {
        return Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $this->household($user)->id,
        ]);
    }

    private function category(User $user): BudgetCategory
    {
        return BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);
    }

    private function defaultCategory(): BudgetCategory
    {
        return BudgetCategory::factory()->create([
            'is_default' => true,
        ]);
    }

    private function itemData(BudgetCategory $category, array $overrides = []): array
    {
        return array_merge([
            'budget_category_id' => $category->id,
            'planned_amount' => 100000,
            'actual_amount' => 50000,
            'notes' => 'Monthly expense',
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_index_requires_authentication(): void
    {
        $budget = Budget::factory()->create();

        $this->getJson("/api/v1/budgets/{$budget->id}/items")
            ->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $budget = Budget::factory()->create();

        $this->postJson(
            "/api/v1/budgets/{$budget->id}/items",
            []
        )->assertUnauthorized();
    }

    public function test_show_requires_authentication(): void
    {
        $budget = Budget::factory()->create();
        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
        ]);

        $this->getJson(
            "/api/v1/budgets/{$budget->id}/items/{$item->id}"
        )->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $budget = Budget::factory()->create();
        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
        ]);

        $this->patchJson(
            "/api/v1/budgets/{$budget->id}/items/{$item->id}",
            ['planned_amount' => 100]
        )->assertUnauthorized();
    }

    public function test_delete_requires_authentication(): void
    {
        $budget = Budget::factory()->create();
        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
        ]);

        $this->deleteJson(
            "/api/v1/budgets/{$budget->id}/items/{$item->id}"
        )->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_list_budget_items(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/{$budget->id}/items");

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($item->id));
    }

    public function test_non_owner_cannot_list_budget_items(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$budget->id}/items")
            ->assertForbidden();
    }

    public function test_items_from_other_budgets_are_not_returned(): void
    {
        $user = User::factory()->create();

        $budgetA = $this->budget($user);
        $budgetB = $this->budget($user);

        $categoryA = $this->defaultCategory();
        $categoryB = $this->defaultCategory();

        $itemA = BudgetItem::factory()->create([
            'budget_id' => $budgetA->id,
            'budget_category_id' => $categoryA->id,
        ]);

        $itemB = BudgetItem::factory()->create([
            'budget_id' => $budgetB->id,
            'budget_category_id' => $categoryB->id,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/{$budgetA->id}/items");

        $ids = collect($response->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($itemA->id));
        $this->assertFalse($ids->contains($itemB->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_create_budget_item(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $response = $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category)
            );

        $response->assertCreated();

        $this->assertDatabaseHas('budget_items', [
            'id' => $response->json('data.id'),
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);
    }

    public function test_non_owner_cannot_create_budget_item(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);
        $category = $this->defaultCategory();

        $this->auth($attacker)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category)
            )
            ->assertForbidden();

        $this->assertDatabaseCount('budget_items', 0);
    }

    public function test_budget_item_requires_category(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                [
                    'planned_amount' => 100000,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['budget_category_id']);
    }

    public function test_budget_item_requires_uuid_category(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                [
                    'budget_category_id' => 'invalid',
                    'planned_amount' => 100000,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['budget_category_id']);
    }

    public function test_budget_item_requires_planned_amount(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                [
                    'budget_category_id' => $category->id,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['planned_amount']);
    }

    public function test_planned_amount_cannot_be_negative(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category, [
                    'planned_amount' => -1,
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['planned_amount']);
    }

    public function test_actual_amount_cannot_be_negative(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category, [
                    'actual_amount' => -1,
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actual_amount']);
    }

    public function test_actual_amount_is_optional(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                [
                    'budget_category_id' => $category->id,
                    'planned_amount' => 100000,
                ]
            )
            ->assertCreated();
    }

    public function test_notes_are_optional(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                [
                    'budget_category_id' => $category->id,
                    'planned_amount' => 100000,
                ]
            )
            ->assertCreated();
    }

    public function test_notes_may_be_null(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                [
                    'budget_category_id' => $category->id,
                    'planned_amount' => 100000,
                    'notes' => null,
                ]
            )
            ->assertCreated();
    }

    public function test_user_can_use_default_category(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category)
            )
            ->assertCreated();
    }

    public function test_user_can_use_own_custom_category(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->category($user);

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category)
            )
            ->assertCreated();
    }

    public function test_user_cannot_use_another_users_custom_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $budget = $this->budget($user);
        $category = $this->category($other);

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category)
            )
            ->assertNotFound();

        $this->assertDatabaseCount('budget_items', 0);
    }

    public function test_same_category_cannot_be_added_twice_to_budget(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budget->id}/items",
                $this->itemData($category)
            )
            ->assertConflict();

        $this->assertDatabaseCount('budget_items', 1);
    }

    public function test_same_category_can_be_used_in_different_budgets(): void
    {
        $user = User::factory()->create();

        $budgetA = $this->budget($user);
        $budgetB = $this->budget($user);
        $category = $this->defaultCategory();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budgetA->id}/items",
                $this->itemData($category)
            )
            ->assertCreated();

        $this->auth($user)
            ->postJson(
                "/api/v1/budgets/{$budgetB->id}/items",
                $this->itemData($category)
            )
            ->assertCreated();

        $this->assertDatabaseCount('budget_items', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_budget_item(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($user)
            ->getJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}"
            )
            ->assertOk()
            ->assertJsonPath('data.id', $item->id);
    }

    public function test_non_owner_cannot_view_budget_item(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($attacker)
            ->getJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}"
            )
            ->assertForbidden();
    }

    public function test_item_from_different_budget_returns_not_found(): void
    {
        $user = User::factory()->create();

        $budgetA = $this->budget($user);
        $budgetB = $this->budget($user);

        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budgetB->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($user)
            ->getJson(
                "/api/v1/budgets/{$budgetA->id}/items/{$item->id}"
            )
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_budget_item(): void
    {
        $user = User::factory()->create();

        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 100000,
        ]);

        $this->auth($user)
            ->patchJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}",
                [
                    'planned_amount' => 200000,
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('budget_items', [
            'id' => $item->id,
            'planned_amount' => '200000.00',
        ]);
    }

    public function test_item_update_is_partial(): void
    {
        $user = User::factory()->create();

        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 100000,
            'actual_amount' => 50000,
            'notes' => 'Original',
        ]);

        $this->auth($user)
            ->patchJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}",
                [
                    'actual_amount' => 60000,
                ]
            )
            ->assertOk();

        $item->refresh();

        $this->assertSame('100000.00', $item->planned_amount);
        $this->assertSame('60000.00', $item->actual_amount);
        $this->assertSame('Original', $item->notes);
    }

    public function test_non_owner_cannot_update_item(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 100000,
        ]);

        $this->auth($attacker)
            ->patchJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}",
                [
                    'planned_amount' => 999999,
                ]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('budget_items', [
            'id' => $item->id,
            'planned_amount' => '100000.00',
        ]);
    }

    public function test_item_update_rejects_duplicate_category(): void
    {
        $user = User::factory()->create();

        $budget = $this->budget($user);

        $categoryA = $this->defaultCategory();
        $categoryB = $this->defaultCategory();

        $itemA = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryA->id,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryB->id,
        ]);

        $this->auth($user)
            ->patchJson(
                "/api/v1/budgets/{$budget->id}/items/{$itemA->id}",
                [
                    'budget_category_id' => $categoryB->id,
                ]
            )
            ->assertConflict();
    }

    public function test_item_can_change_to_unused_category(): void
    {
        $user = User::factory()->create();

        $budget = $this->budget($user);

        $categoryA = $this->defaultCategory();
        $categoryB = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryA->id,
        ]);

        $this->auth($user)
            ->patchJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}",
                [
                    'budget_category_id' => $categoryB->id,
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('budget_items', [
            'id' => $item->id,
            'budget_category_id' => $categoryB->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_budget_item(): void
    {
        $user = User::factory()->create();

        $budget = $this->budget($user);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($user)
            ->deleteJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}"
            )
            ->assertOk();

        $this->assertDatabaseMissing('budget_items', [
            'id' => $item->id,
        ]);
    }

    public function test_non_owner_cannot_delete_budget_item(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);
        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($attacker)
            ->deleteJson(
                "/api/v1/budgets/{$budget->id}/items/{$item->id}"
            )
            ->assertForbidden();

        $this->assertDatabaseHas('budget_items', [
            'id' => $item->id,
        ]);
    }

    public function test_item_from_different_budget_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $budgetA = $this->budget($user);
        $budgetB = $this->budget($user);

        $category = $this->defaultCategory();

        $item = BudgetItem::factory()->create([
            'budget_id' => $budgetB->id,
            'budget_category_id' => $category->id,
        ]);

        $this->auth($user)
            ->deleteJson(
                "/api/v1/budgets/{$budgetA->id}/items/{$item->id}"
            )
            ->assertNotFound();

        $this->assertDatabaseHas('budget_items', [
            'id' => $item->id,
        ]);
    }

    public function test_unknown_item_returns_not_found(): void
    {
        $user = User::factory()->create();
        $budget = $this->budget($user);

        $this->auth($user)
            ->getJson(
                "/api/v1/budgets/{$budget->id}/items/".fake()->uuid()
            )
            ->assertNotFound();
    }
}
