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

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function token(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function auth(User $user): static
    {
        return $this->withToken($this->token($user));
    }

    private function household(User $owner): Household
    {
        return Household::factory()->create([
            'owner_id' => $owner->id,
        ]);
    }

    private function budgetData(Household $household, array $overrides = []): array
    {
        return array_merge([
            'household_id' => $household->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 250000,
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/budgets')
            ->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/v1/budgets', [])
            ->assertUnauthorized();
    }

    public function test_show_requires_authentication(): void
    {
        $budget = Budget::factory()->create();

        $this->getJson("/api/v1/budgets/{$budget->id}")
            ->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $budget = Budget::factory()->create();

        $this->patchJson("/api/v1/budgets/{$budget->id}", [
            'total_planned' => 100000,
        ])->assertUnauthorized();
    }

    public function test_delete_requires_authentication(): void
    {
        $budget = Budget::factory()->create();

        $this->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function test_user_can_list_own_budgets(): void
    {
        $user = User::factory()->create();

        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets');

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($budget->id));
    }

    public function test_user_cannot_see_another_users_budget_in_index(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);

        $budget = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
        ]);

        $response = $this->auth($attacker)
            ->getJson('/api/v1/budgets');

        $response->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id');

        $this->assertFalse($ids->contains($budget->id));
    }

    public function test_index_returns_empty_collection_when_user_has_no_budgets(): void
    {
        $user = User::factory()->create();

        $response = $this->auth($user)
            ->getJson('/api/v1/budgets');

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    public function test_index_does_not_leak_other_users_data(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $ownerHousehold = $this->household($owner);
        $attackerHousehold = $this->household($attacker);

        $ownerBudget = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $ownerHousehold->id,
        ]);

        $attackerBudget = Budget::factory()->create([
            'user_id' => $attacker->id,
            'household_id' => $attackerHousehold->id,
        ]);

        $response = $this->auth($attacker)
            ->getJson('/api/v1/budgets');

        $ids = collect($response->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($attackerBudget->id));
        $this->assertFalse($ids->contains($ownerBudget->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function test_user_can_create_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $response = $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household));

        $response->assertCreated();

        $this->assertDatabaseHas('budgets', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'household_id' => $household->id,
            'currency_code' => 'XAF',
        ]);
    }

    public function test_created_budget_belongs_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $household = $this->household($user);

        $response = $this->auth($user)
            ->postJson('/api/v1/budgets', array_merge($this->budgetData($household), [
                'user_id' => $other->id,
            ]));

        $response->assertCreated();

        $this->assertDatabaseHas('budgets', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
        ]);
    }

    public function test_user_cannot_create_budget_for_inaccessible_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);

        $this->auth($attacker)
            ->postJson('/api/v1/budgets', $this->budgetData($household))
            ->assertForbidden();

        $this->assertDatabaseMissing('budgets', [
            'user_id' => $attacker->id,
            'household_id' => $household->id,
        ]);
    }

    public function test_budget_requires_household_id(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budgets', [
                'month' => '2026-08-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_budget_requires_valid_household_uuid(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budgets', [
                'household_id' => 'not-a-uuid',
                'month' => '2026-08-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_budget_requires_existing_household(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->postJson('/api/v1/budgets', [
                'household_id' => fake()->uuid(),
                'month' => '2026-08-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['household_id']);
    }

    public function test_budget_requires_month(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', [
                'household_id' => $household->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_budget_requires_y_m_d_month_format(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', [
                'household_id' => $household->id,
                'month' => '08/2026',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_budget_rejects_invalid_month_value(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', [
                'household_id' => $household->id,
                'month' => 'not-a-date',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_budget_rejects_negative_total_planned(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'total_planned' => -1,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total_planned']);
    }

    public function test_budget_accepts_zero_total_planned(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'total_planned' => 0,
            ]))
            ->assertCreated();
    }

    public function test_budget_accepts_decimal_total_planned(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $response = $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'total_planned' => 123456.78,
            ]));

        $response->assertCreated();

        $this->assertDatabaseHas('budgets', [
            'id' => $response->json('data.id'),
            'total_planned' => '123456.78',
        ]);
    }

    public function test_budget_currency_must_have_three_characters(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'currency_code' => 'XA',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency_code']);
    }

    public function test_budget_currency_must_be_alphabetic(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'currency_code' => 'X1F',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency_code']);
    }

    public function test_duplicate_budget_for_same_user_household_and_month_is_rejected(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'month' => '2026-08-01',
        ]);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household))
            ->assertConflict();

        $this->assertDatabaseCount('budgets', 1);
    }

    public function test_same_month_can_exist_for_different_households(): void
    {
        $user = User::factory()->create();

        $householdA = $this->household($user);
        $householdB = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($householdA))
            ->assertCreated();

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($householdB))
            ->assertCreated();

        $this->assertDatabaseCount('budgets', 2);
    }

    public function test_different_months_can_exist_for_same_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'month' => '2026-08-01',
            ]))
            ->assertCreated();

        $this->auth($user)
            ->postJson('/api/v1/budgets', $this->budgetData($household, [
                'month' => '2026-09-01',
            ]))
            ->assertCreated();

        $this->assertDatabaseCount('budgets', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_user_can_view_own_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $this->auth($user)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $budget->id);
    }

    public function test_user_cannot_view_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);

        $budget = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
        ]);

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();
    }

    public function test_unknown_budget_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->getJson('/api/v1/budgets/'.fake()->uuid())
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_user_can_update_own_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'total_planned' => 100000,
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 200000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'total_planned' => '200000.00',
        ]);
    }

    public function test_user_cannot_update_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);

        $budget = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
            'total_planned' => 100000,
        ]);

        $this->auth($attacker)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 999999,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'total_planned' => '100000.00',
        ]);
    }

    public function test_budget_update_is_partial(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'currency_code' => 'XAF',
            'total_planned' => 100000,
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 150000,
            ])
            ->assertOk();

        $budget->refresh();

        $this->assertSame('XAF', $budget->currency_code);
        $this->assertSame('150000.00', $budget->total_planned);
    }

    public function test_budget_update_rejects_negative_amount(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => -100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total_planned']);
    }

    public function test_budget_update_rejects_duplicate_month(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'month' => '2026-08-01',
        ]);

        $second = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'month' => '2026-09-01',
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budgets/{$second->id}", [
                'month' => '2026-08-01',
            ])
            ->assertConflict();

        $this->assertDatabaseHas('budgets', [
            'id' => $second->id,
            'month' => '2026-09-01',
        ]);
    }

    public function test_budget_update_can_change_month_when_no_conflict_exists(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'month' => '2026-08-01',
        ]);

        $this->auth($user)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'month' => '2026-09-01',
            ])
            ->assertOk();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'month' => '2026-09-01',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function test_user_can_delete_own_budget(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $this->auth($user)
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertOk();

        $this->assertDatabaseMissing('budgets', [
            'id' => $budget->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);

        $budget = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
        ]);

        $this->auth($attacker)
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
        ]);
    }

    public function test_delete_unknown_budget_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->deleteJson('/api/v1/budgets/'.fake()->uuid())
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships / calculations
    |--------------------------------------------------------------------------
    */

    public function test_budget_response_includes_household(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertOk();

        $this->assertNotNull($response->json('data.household'));
    }

    public function test_budget_response_includes_items(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
        ]);

        $response = $this->auth($user)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertOk();

        $this->assertIsArray($response->json('data.items'));
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_budget_calculates_total_actual_from_items(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $categoryA = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $categoryB = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryA->id,
            'planned_amount' => 100000,
            'actual_amount' => 60000,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryB->id,
            'planned_amount' => 50000,
            'actual_amount' => 30000,
        ]);

        $budget->load('items');

        $this->assertSame(90000.0, $budget->totalActual());
    }

    public function test_budget_calculates_total_planned_from_items(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $categoryA = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $categoryB = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryA->id,
            'planned_amount' => 100000,
            'actual_amount' => 0,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $categoryB->id,
            'planned_amount' => 50000,
            'actual_amount' => 0,
        ]);

        $budget->load('items');

        $this->assertSame(150000.0, $budget->totalPlanned());
    }

    public function test_budget_remaining_is_planned_minus_actual(): void
    {
        $user = User::factory()->create();
        $household = $this->household($user);

        $budget = Budget::factory()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
        ]);

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        BudgetItem::factory()->create([
            'budget_id' => $budget->id,
            'budget_category_id' => $category->id,
            'planned_amount' => 100000,
            'actual_amount' => 65000,
        ]);

        $budget->load('items');

        $this->assertSame(35000.0, $budget->remaining());
    }
}
