<?php

namespace Tests\Unit\Budget;

use App\Models\Budget;
use App\Models\User;
use App\Policies\BudgetPolicy;
use PHPUnit\Framework\TestCase;

class BudgetPolicyTest extends TestCase
{
    private BudgetPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new BudgetPolicy;
    }

    public function test_owner_can_view_budget(): void
    {
        $user = new User;
        $user->id = 1;

        $budget = new Budget;
        $budget->user_id = 1;

        $this->assertTrue($this->policy->view($user, $budget));
    }

    public function test_non_owner_cannot_view_budget(): void
    {
        $user = new User;
        $user->id = 2;

        $budget = new Budget;
        $budget->user_id = 1;

        $this->assertFalse($this->policy->view($user, $budget));
    }

    public function test_owner_can_update_budget(): void
    {
        $user = new User;
        $user->id = 1;

        $budget = new Budget;
        $budget->user_id = 1;

        $this->assertTrue($this->policy->update($user, $budget));
    }

    public function test_non_owner_cannot_update_budget(): void
    {
        $user = new User;
        $user->id = 2;

        $budget = new Budget;
        $budget->user_id = 1;

        $this->assertFalse($this->policy->update($user, $budget));
    }

    public function test_owner_can_delete_budget(): void
    {
        $user = new User;
        $user->id = 1;

        $budget = new Budget;
        $budget->user_id = 1;

        $this->assertTrue($this->policy->delete($user, $budget));
    }

    public function test_non_owner_cannot_delete_budget(): void
    {
        $user = new User;
        $user->id = 2;

        $budget = new Budget;
        $budget->user_id = 1;

        $this->assertFalse($this->policy->delete($user, $budget));
    }
}
