<?php

namespace Tests\Unit\Budget;

use App\Models\BudgetCategory;
use App\Models\User;
use App\Repositories\BudgetCategoryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCategoryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetCategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(BudgetCategoryRepository::class);
    }

    private function category(array $overrides = []): BudgetCategory
    {
        return BudgetCategory::factory()->create($overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | availableForUser
    |--------------------------------------------------------------------------
    */

    public function test_available_for_user_returns_default_categories(): void
    {
        $user = User::factory()->create();

        $defaultCategory = $this->category([
            'is_default' => true,
            'user_id' => null,
        ]);

        $categories = $this->repository->availableForUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertTrue($ids->contains($defaultCategory->id));
    }

    public function test_available_for_user_returns_users_own_custom_categories(): void
    {
        $user = User::factory()->create();

        $customCategory = $this->category([
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $categories = $this->repository->availableForUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertTrue($ids->contains($customCategory->id));
    }

    public function test_available_for_user_does_not_return_another_users_custom_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $otherCategory = $this->category([
            'is_default' => false,
            'user_id' => $otherUser->id,
        ]);

        $categories = $this->repository->availableForUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertFalse($ids->contains($otherCategory->id));
    }

    public function test_available_for_user_returns_default_and_own_custom_categories_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $defaultCategory = $this->category([
            'is_default' => true,
            'user_id' => null,
        ]);

        $ownCategory = $this->category([
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $otherCategory = $this->category([
            'is_default' => false,
            'user_id' => $otherUser->id,
        ]);

        $categories = $this->repository->availableForUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertTrue($ids->contains($defaultCategory->id));
        $this->assertTrue($ids->contains($ownCategory->id));
        $this->assertFalse($ids->contains($otherCategory->id));
    }

    public function test_available_for_user_orders_categories_by_name(): void
    {
        $user = User::factory()->create();

        $zulu = $this->category([
            'name' => 'Zulu',
            'is_default' => true,
            'user_id' => null,
        ]);

        $alpha = $this->category([
            'name' => 'Alpha',
            'is_default' => true,
            'user_id' => null,
        ]);

        $middle = $this->category([
            'name' => 'Food',
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $categories = $this->repository->availableForUser($user->id);

        $this->assertSame([
            $alpha->id,
            $middle->id,
            $zulu->id,
        ], $categories->pluck('id')->all());
    }

    public function test_available_for_user_returns_empty_collection_when_no_categories_exist(): void
    {
        $user = User::factory()->create();

        $categories = $this->repository->availableForUser($user->id);

        $this->assertCount(0, $categories);
    }

    /*
    |--------------------------------------------------------------------------
    | forUser
    |--------------------------------------------------------------------------
    */

    public function test_for_user_returns_only_users_custom_categories(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownCategory = $this->category([
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $otherCategory = $this->category([
            'is_default' => false,
            'user_id' => $otherUser->id,
        ]);

        $categories = $this->repository->forUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertTrue($ids->contains($ownCategory->id));
        $this->assertFalse($ids->contains($otherCategory->id));
    }

    public function test_for_user_does_not_return_default_categories(): void
    {
        $user = User::factory()->create();

        $defaultCategory = $this->category([
            'is_default' => true,
            'user_id' => null,
        ]);

        $categories = $this->repository->forUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertFalse($ids->contains($defaultCategory->id));
    }

    public function test_for_user_returns_empty_collection_when_user_has_no_custom_categories(): void
    {
        $user = User::factory()->create();

        $categories = $this->repository->forUser($user->id);

        $this->assertCount(0, $categories);
    }

    public function test_for_user_orders_categories_by_name(): void
    {
        $user = User::factory()->create();

        $zulu = $this->category([
            'name' => 'Zulu',
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $alpha = $this->category([
            'name' => 'Alpha',
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $middle = $this->category([
            'name' => 'Food',
            'is_default' => false,
            'user_id' => $user->id,
        ]);

        $categories = $this->repository->forUser($user->id);

        $this->assertSame([
            $alpha->id,
            $middle->id,
            $zulu->id,
        ], $categories->pluck('id')->all());
    }

    public function test_for_user_does_not_return_categories_from_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $otherCategory = $this->category([
            'is_default' => false,
            'user_id' => $otherUser->id,
        ]);

        $categories = $this->repository->forUser($user->id);

        $ids = $categories->pluck('id');

        $this->assertFalse($ids->contains($otherCategory->id));
    }
}
