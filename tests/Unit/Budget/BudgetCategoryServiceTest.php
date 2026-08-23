<?php

namespace Tests\Unit\Budget;

use App\Exceptions\ApiException;
use App\Models\BudgetCategory;
use App\Models\User;
use App\Services\Budget\BudgetCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetCategoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BudgetCategoryService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | availableForUser
    |--------------------------------------------------------------------------
    */

    public function test_available_for_user_returns_available_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $defaultFood = BudgetCategory::factory()->create([
            'name' => 'Food',
            'is_default' => true,
        ]);

        $defaultTransport = BudgetCategory::factory()->create([
            'name' => 'Transport',
            'is_default' => true,
        ]);

        $ownCategory = BudgetCategory::factory()->create([
            'name' => 'School',
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $otherCategory = BudgetCategory::factory()->create([
            'name' => 'Other User Category',
            'user_id' => $otherUser->id,
            'is_default' => false,
        ]);

        $categories = $this->service->availableForUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertCount(3, $categories);
        $this->assertTrue($ids->contains($defaultFood->id));
        $this->assertTrue($ids->contains($defaultTransport->id));
        $this->assertTrue($ids->contains($ownCategory->id));
        $this->assertFalse($ids->contains($otherCategory->id));
    }

    /*
    |--------------------------------------------------------------------------
    | create
    |--------------------------------------------------------------------------
    */

    public function test_create_assigns_user_id_and_custom_category_flag(): void
    {
        $user = User::factory()->create();

        $category = $this->service->create($user->id, [
            'name' => 'Monthly Savings',
            'icon' => 'wallet',
            'color' => '#123456',
            'is_default' => true,
            'user_id' => 999999,
        ]);

        $this->assertSame($user->id, $category->user_id);
        $this->assertFalse($category->is_default);

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
            'user_id' => $user->id,
            'name' => 'Monthly Savings',
            'is_default' => false,
        ]);
    }

    public function test_create_does_not_allow_caller_to_create_default_category(): void
    {
        $user = User::factory()->create();

        $category = $this->service->create($user->id, [
            'name' => 'Custom Category',
            'is_default' => true,
        ]);

        $this->assertSame($user->id, $category->user_id);
        $this->assertFalse($category->is_default);
    }

    /*
    |--------------------------------------------------------------------------
    | findForUser
    |--------------------------------------------------------------------------
    */

    public function test_find_for_user_returns_default_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $result = $this->service->findForUser(
            $user->id,
            $category->id
        );

        $this->assertSame($category->id, $result->id);
    }

    public function test_find_for_user_returns_users_own_custom_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $result = $this->service->findForUser(
            $user->id,
            $category->id
        );

        $this->assertSame($category->id, $result->id);
    }

    public function test_find_for_user_rejects_another_users_custom_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $otherUser->id,
            'is_default' => false,
        ]);

        $this->expectException(ApiException::class);

        $this->service->findForUser(
            $user->id,
            $category->id
        );
    }

    public function test_find_for_user_rejects_unknown_category(): void
    {
        $user = User::factory()->create();

        $this->expectException(ApiException::class);

        $this->service->findForUser(
            $user->id,
            '00000000-0000-0000-0000-000000000000'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_custom_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
            'name' => 'Old Name',
        ]);

        $result = $this->service->update(
            $category,
            $user->id,
            [
                'name' => 'New Name',
            ]
        );

        $this->assertSame($category->id, $result->id);
        $this->assertSame('New Name', $result->name);

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
            'name' => 'New Name',
        ]);
    }

    public function test_update_rejects_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $otherUser->id,
            'is_default' => false,
            'name' => 'Original',
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->service->update(
                $category,
                $user->id,
                [
                    'name' => 'Changed',
                ]
            );
        } finally {
            $this->assertDatabaseHas('budget_categories', [
                'id' => $category->id,
                'name' => 'Original',
            ]);
        }
    }

    public function test_update_rejects_default_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
            'name' => 'Default Category',
        ]);

        $this->expectException(ApiException::class);

        $this->service->update(
            $category,
            $user->id,
            [
                'name' => 'Changed',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_custom_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $user->id,
            'is_default' => false,
        ]);

        $result = $this->service->delete(
            $category,
            $user->id
        );

        $this->assertTrue($result);

        $this->assertDatabaseMissing('budget_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_delete_rejects_another_users_category(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'user_id' => $otherUser->id,
            'is_default' => false,
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->service->delete(
                $category,
                $user->id
            );
        } finally {
            $this->assertDatabaseHas('budget_categories', [
                'id' => $category->id,
            ]);
        }
    }

    public function test_delete_rejects_default_category(): void
    {
        $user = User::factory()->create();

        $category = BudgetCategory::factory()->create([
            'is_default' => true,
        ]);

        $this->expectException(ApiException::class);

        $this->service->delete(
            $category,
            $user->id
        );

        $this->assertDatabaseHas('budget_categories', [
            'id' => $category->id,
        ]);
    }
}
