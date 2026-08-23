<?php

namespace Tests\Feature\Budget;

use App\Models\Budget;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BudgetAuthorizationTest extends TestCase
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

    private function budget(User $owner, ?Household $household = null): Budget
    {
        $household ??= $this->household($owner);

        return Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
            'month' => '2026-08-01',
            'currency_code' => 'XAF',
            'total_planned' => 250000,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_show_requires_authentication(): void
    {
        $budget = $this->budget(User::factory()->create());

        $this->getJson("/api/v1/budgets/{$budget->id}")
            ->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $budget = $this->budget(User::factory()->create());

        $this->patchJson("/api/v1/budgets/{$budget->id}", [
            'total_planned' => 300000,
        ])->assertUnauthorized();
    }

    public function test_delete_requires_authentication(): void
    {
        $budget = $this->budget(User::factory()->create());

        $this->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | View Authorization
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_own_budget(): void
    {
        $owner = User::factory()->create();
        $budget = $this->budget($owner);

        $response = $this->auth($owner)
            ->getJson("/api/v1/budgets/{$budget->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $budget->id)
            ->assertJsonPath('data.user_id', $owner->id);
    }

    public function test_user_cannot_view_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();
    }

    public function test_household_owner_cannot_view_another_users_budget(): void
    {
        $householdOwner = User::factory()->create();
        $budgetOwner = User::factory()->create();

        $household = $this->household($householdOwner);

        $budget = $this->budget($budgetOwner, $household);

        $this->auth($householdOwner)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();
    }

    public function test_same_household_does_not_grant_budget_access(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $budget = $this->budget($owner, $household);

        $this->auth($otherUser)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();
    }

    public function test_user_cannot_view_budget_from_another_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $ownerHousehold = $this->household($owner);
        $attackerHousehold = $this->household($attacker);

        $budget = $this->budget($owner, $ownerHousehold);

        $this->assertNotSame(
            $ownerHousehold->id,
            $attackerHousehold->id
        );

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();
    }

    public function test_unknown_budget_returns_not_found(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->getJson('/api/v1/budgets/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Update Authorization
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_own_budget(): void
    {
        $owner = User::factory()->create();
        $budget = $this->budget($owner);

        $this->auth($owner)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 300000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
            'total_planned' => '300000.00',
        ]);
    }

    public function test_user_cannot_update_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $this->auth($attacker)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 999999,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
            'total_planned' => '250000.00',
        ]);
    }

    public function test_household_access_does_not_grant_update_permission(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $budget = $this->budget($owner, $household);

        $this->auth($otherUser)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 999999,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
            'total_planned' => '250000.00',
        ]);
    }

    public function test_attacker_cannot_change_budget_owner(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $this->auth($attacker)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'user_id' => $attacker->id,
                'total_planned' => 999999,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_update_does_not_change_budget_owner_for_authorized_user(): void
    {
        $owner = User::factory()->create();
        $budget = $this->budget($owner);

        $this->auth($owner)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'user_id' => User::factory()->create()->id,
                'total_planned' => 350000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
            'total_planned' => '350000.00',
        ]);
    }

    public function test_user_cannot_update_budget_from_another_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $this->household($attacker);

        $this->auth($attacker)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'total_planned' => 999999,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
            'total_planned' => '250000.00',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Authorization
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_own_budget(): void
    {
        $owner = User::factory()->create();
        $budget = $this->budget($owner);

        $this->auth($owner)
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

        $budget = $this->budget($owner);

        $this->auth($attacker)
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_household_access_does_not_grant_delete_permission(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = $this->household($owner);

        $budget = $this->budget($owner, $household);

        $this->auth($otherUser)
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
        ]);
    }

    public function test_user_cannot_delete_budget_from_another_household(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $ownerHousehold = $this->household($owner);
        $this->household($attacker);

        $budget = $this->budget($owner, $ownerHousehold);

        $this->auth($attacker)
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
        ]);
    }

    public function test_unknown_budget_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->auth($user)
            ->deleteJson('/api/v1/budgets/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Data Isolation / IDOR Regression Tests
    |--------------------------------------------------------------------------
    */

    public function test_budget_id_cannot_be_used_to_access_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $response = $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$budget->id}");

        $response->assertForbidden();

        $response->assertJsonMissing([
            'id' => $budget->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_failed_update_does_not_modify_any_budget_fields(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $original = $budget->fresh()->toArray();

        $this->auth($attacker)
            ->patchJson("/api/v1/budgets/{$budget->id}", [
                'month' => '2030-01-01',
                'currency_code' => 'USD',
                'total_planned' => 999999,
            ])
            ->assertForbidden();

        $updated = $budget->fresh()->toArray();

        $this->assertSame($original['user_id'], $updated['user_id']);
        $this->assertSame($original['household_id'], $updated['household_id']);
        $this->assertSame($original['month'], $updated['month']);
        $this->assertSame($original['currency_code'], $updated['currency_code']);
        $this->assertSame($original['total_planned'], $updated['total_planned']);
    }

    public function test_failed_delete_does_not_remove_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $budget = $this->budget($owner);

        $this->auth($attacker)
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
        ]);
    }

    public function test_same_user_can_access_multiple_own_budgets(): void
    {
        $owner = User::factory()->create();

        $household = $this->household($owner);

        $first = $this->budget($owner, $household);

        $second = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
            'month' => '2026-09-01',
            'currency_code' => 'XAF',
            'total_planned' => 400000,
        ]);

        $this->auth($owner)
            ->getJson("/api/v1/budgets/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);

        $this->auth($owner)
            ->getJson("/api/v1/budgets/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id);
    }

    public function test_one_user_cannot_access_any_of_another_users_budgets(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $household = $this->household($owner);

        $first = $this->budget($owner, $household);

        $second = Budget::factory()->create([
            'user_id' => $owner->id,
            'household_id' => $household->id,
            'month' => '2026-09-01',
            'currency_code' => 'XAF',
            'total_planned' => 400000,
        ]);

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$first->id}")
            ->assertForbidden();

        $this->auth($attacker)
            ->getJson("/api/v1/budgets/{$second->id}")
            ->assertForbidden();
    }
}
