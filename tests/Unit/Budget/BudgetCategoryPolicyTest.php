<?php

namespace Tests\Unit\Budget;

use App\Models\BudgetCategory;
use App\Models\User;
use App\Policies\BudgetCategoryPolicy;
use PHPUnit\Framework\TestCase;

class BudgetCategoryPolicyTest extends TestCase
{
    private BudgetCategoryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new BudgetCategoryPolicy();
    }

    public function test_any_user_can_view_default_category(): void
    {
        $user = new User();
        $user->id = 1;

        $category = new BudgetCategory();
        $category->is_default = true;
        $category->user_id = null;

        $this->assertTrue($this->policy->view($user, $category));
    }

    public function test_user_can_view_own_custom_category(): void
    {
        $user = new User();
        $user->id = 1;

        $category = new BudgetCategory();
        $category->is_default = false;
        $category->user_id = 1;

        $this->assertTrue($this->policy->view($user, $category));
    }

    public function test_user_cannot_view_another_users_custom_category(): void
    {
        $user = new User();
        $user->id = 2;

        $category = new BudgetCategory();
        $category->is_default = false;
        $category->user_id = 1;

        $this->assertFalse($this->policy->view($user, $category));
    }

    public function test_active_user_can_create_category(): void
    {
        $user = new User();
        $user->is_active = true;

        $this->assertTrue($this->policy->create($user));
    }

    public function test_inactive_user_cannot_create_category(): void
    {
        $user = new User();
        $user->is_active = false;

        $this->assertFalse($this->policy->create($user));
    }

    public function test_owner_can_update_own_custom_category(): void
    {
        $user = new User();
        $user->id = 1;

        $category = new BudgetCategory();
        $category->is_default = false;
        $category->user_id = 1;

        $this->assertTrue($this->policy->update($user, $category));
    }

    public function test_user_cannot_update_another_users_category(): void
    {
        $user = new User();
        $user->id = 2;

        $category = new BudgetCategory();
        $category->is_default = false;
        $category->user_id = 1;

        $this->assertFalse($this->policy->update($user, $category));
    }

    public function test_default_category_cannot_be_updated(): void
    {
        $user = new User();
        $user->id = 1;

        $category = new BudgetCategory();
        $category->is_default = true;
        $category->user_id = 1;

        $this->assertFalse($this->policy->update($user, $category));
    }

    public function test_owner_can_delete_own_custom_category(): void
    {
        $user = new User();
        $user->id = 1;

        $category = new BudgetCategory();
        $category->is_default = false;
        $category->user_id = 1;

        $this->assertTrue($this->policy->delete($user, $category));
    }

    public function test_user_cannot_delete_another_users_category(): void
    {
        $user = new User();
        $user->id = 2;

        $category = new BudgetCategory();
        $category->is_default = false;
        $category->user_id = 1;

        $this->assertFalse($this->policy->delete($user, $category));
    }

    public function test_default_category_cannot_be_deleted(): void
    {
        $user = new User();
        $user->id = 1;

        $category = new BudgetCategory();
        $category->is_default = true;
        $category->user_id = 1;

        $this->assertFalse($this->policy->delete($user, $category));
    }
}
