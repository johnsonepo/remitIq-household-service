<?php

namespace Tests\Unit\Remittance;

use App\Models\Remittance;
use App\Models\User;
use App\Policies\RemittancePolicy;
use Tests\TestCase;

class RemittancePolicyTest extends TestCase
{
    protected RemittancePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new RemittancePolicy();
    }

    /*
    |--------------------------------------------------------------------------
    | view
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_remittance(): void
    {
        $user = new User();
        $user->id = 1;

        $remittance = new Remittance();
        $remittance->user_id = 1;

        $this->assertTrue(
            $this->policy->view($user, $remittance)
        );
    }

    public function test_non_owner_cannot_view_remittance(): void
    {
        $user = new User();
        $user->id = 1;

        $remittance = new Remittance();
        $remittance->user_id = 2;

        $this->assertFalse(
            $this->policy->view($user, $remittance)
        );
    }

    public function test_household_membership_does_not_grant_view_access(): void
    {
        $owner = new User();
        $owner->id = 1;

        $householdMember = new User();
        $householdMember->id = 2;

        $remittance = new Remittance();
        $remittance->user_id = $owner->id;
        $remittance->household_id = 'household-123';

        $this->assertFalse(
            $this->policy->view($householdMember, $remittance)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_remittance(): void
    {
        $user = new User();
        $user->id = 1;

        $remittance = new Remittance();
        $remittance->user_id = 1;

        $this->assertTrue(
            $this->policy->update($user, $remittance)
        );
    }

    public function test_non_owner_cannot_update_remittance(): void
    {
        $user = new User();
        $user->id = 1;

        $remittance = new Remittance();
        $remittance->user_id = 2;

        $this->assertFalse(
            $this->policy->update($user, $remittance)
        );
    }

    public function test_household_membership_does_not_grant_update_access(): void
    {
        $owner = new User();
        $owner->id = 1;

        $householdMember = new User();
        $householdMember->id = 2;

        $remittance = new Remittance();
        $remittance->user_id = $owner->id;
        $remittance->household_id = 'household-123';

        $this->assertFalse(
            $this->policy->update($householdMember, $remittance)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | delete
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_delete_remittance(): void
    {
        $user = new User();
        $user->id = 1;

        $remittance = new Remittance();
        $remittance->user_id = 1;

        $this->assertTrue(
            $this->policy->delete($user, $remittance)
        );
    }

    public function test_non_owner_cannot_delete_remittance(): void
    {
        $user = new User();
        $user->id = 1;

        $remittance = new Remittance();
        $remittance->user_id = 2;

        $this->assertFalse(
            $this->policy->delete($user, $remittance)
        );
    }

    public function test_household_membership_does_not_grant_delete_access(): void
    {
        $owner = new User();
        $owner->id = 1;

        $householdMember = new User();
        $householdMember->id = 2;

        $remittance = new Remittance();
        $remittance->user_id = $owner->id;
        $remittance->household_id = 'household-123';

        $this->assertFalse(
            $this->policy->delete($householdMember, $remittance)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Ownership boundaries
    |--------------------------------------------------------------------------
    */

    public function test_same_user_can_access_remittances_for_different_households(): void
    {
        $user = new User();
        $user->id = 1;

        $firstRemittance = new Remittance();
        $firstRemittance->user_id = 1;
        $firstRemittance->household_id = 'household-1';

        $secondRemittance = new Remittance();
        $secondRemittance->user_id = 1;
        $secondRemittance->household_id = 'household-2';

        $this->assertTrue(
            $this->policy->view($user, $firstRemittance)
        );

        $this->assertTrue(
            $this->policy->view($user, $secondRemittance)
        );

        $this->assertTrue(
            $this->policy->update($user, $firstRemittance)
        );

        $this->assertTrue(
            $this->policy->update($user, $secondRemittance)
        );

        $this->assertTrue(
            $this->policy->delete($user, $firstRemittance)
        );

        $this->assertTrue(
            $this->policy->delete($user, $secondRemittance)
        );
    }

    public function test_different_user_cannot_access_remittance_even_when_household_is_the_same(): void
    {
        $owner = new User();
        $owner->id = 10;

        $otherUser = new User();
        $otherUser->id = 20;

        $remittance = new Remittance();
        $remittance->user_id = $owner->id;
        $remittance->household_id = 'same-household';

        $this->assertFalse(
            $this->policy->view($otherUser, $remittance)
        );

        $this->assertFalse(
            $this->policy->update($otherUser, $remittance)
        );

        $this->assertFalse(
            $this->policy->delete($otherUser, $remittance)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Policy consistency
    |--------------------------------------------------------------------------
    */

    public function test_all_policy_actions_use_the_same_ownership_rule(): void
    {
        $owner = new User();
        $owner->id = 100;

        $otherUser = new User();
        $otherUser->id = 200;

        $remittance = new Remittance();
        $remittance->user_id = $owner->id;

        $this->assertTrue(
            $this->policy->view($owner, $remittance)
        );

        $this->assertTrue(
            $this->policy->update($owner, $remittance)
        );

        $this->assertTrue(
            $this->policy->delete($owner, $remittance)
        );

        $this->assertFalse(
            $this->policy->view($otherUser, $remittance)
        );

        $this->assertFalse(
            $this->policy->update($otherUser, $remittance)
        );

        $this->assertFalse(
            $this->policy->delete($otherUser, $remittance)
        );
    }

    public function test_zero_and_non_matching_user_ids_are_denied(): void
    {
        $user = new User();
        $user->id = 0;

        $remittance = new Remittance();
        $remittance->user_id = 1;

        $this->assertFalse(
            $this->policy->view($user, $remittance)
        );

        $this->assertFalse(
            $this->policy->update($user, $remittance)
        );

        $this->assertFalse(
            $this->policy->delete($user, $remittance)
        );
    }
}
