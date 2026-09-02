<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use Illuminate\Database\Eloquent\Collection;

class HouseholdInvitationRepository extends BaseRepository
{
    public function __construct(HouseholdInvitation $model)
    {
        parent::__construct($model);
    }

    /**
     * Get all invitations for a household.
     *
     * @return Collection<int, HouseholdInvitation>
     */
    public function forHousehold(Household $household): Collection
    {
        /** @var Collection<int, HouseholdInvitation> $invitations */
        $invitations = $this->model
            ->newQuery()
            ->with('inviter')
            ->where('household_id', $household->id)
            ->latest()
            ->get();

        return $invitations;
    }

    /**
     * Find an invitation within a household.
     */
    public function findInHousehold(Household $household, string $invitationId): ?HouseholdInvitation
    {
        /** @var HouseholdInvitation|null $invitation */
        $invitation = $this->model
            ->newQuery()
            ->whereKey($invitationId)
            ->where('household_id', $household->id)
            ->with('inviter')
            ->first();

        return $invitation;
    }

    /**
     * Find an invitation by token.
     */
    public function findByToken(string $token): ?HouseholdInvitation
    {
        /** @var HouseholdInvitation|null $invitation */
        $invitation = $this->model
            ->newQuery()
            ->where('token', $token)
            ->first();

        return $invitation;
    }

    /**
     * Find a pending invitation for an email address.
     */
    public function findPendingByEmail(Household $household, string $email): ?HouseholdInvitation
    {
        /** @var HouseholdInvitation|null $invitation */
        $invitation = $this->model
            ->newQuery()
            ->where('household_id', $household->id)
            ->where('email', $email)
            ->where('status', 'pending')
            ->first();

        return $invitation;
    }

    /**
     * Get pending invitations for a user's email address.
     *
     * @return Collection<int, HouseholdInvitation>
     */
    public function forEmail(string $email): Collection
    {
        /** @var Collection<int, HouseholdInvitation> $invitations */
        $invitations = $this->model
            ->newQuery()
            ->with(['inviter', 'household'])
            ->where('email', strtolower(trim($email)))
            ->where('status', 'pending')
            ->latest()
            ->get();

        return $invitations;
    }
}
