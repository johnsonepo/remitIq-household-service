<?php

namespace App\Services\Household;

use App\Exceptions\ApiException;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\HouseholdInvitationRepository;
use App\Services\Notification\NotificationEventBuilder;
use App\Services\Notification\NotificationEventEmitter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HouseholdInvitationService
{
    public function __construct(private readonly HouseholdInvitationRepository $repository, private readonly NotificationEventBuilder $notificationEventBuilder, private readonly NotificationEventEmitter $notificationEventEmitter) {}

    /**
     * Get all invitations for a household.
     *
     * @return Collection<int, HouseholdInvitation>
     */
    public function list(Household $household): Collection
    {
        return $this->repository->forHousehold($household);
    }

    /**
     * Find an invitation within a household.
     */
    public function find(Household $household, string $invitationId): HouseholdInvitation
    {
        $invitation = $this->repository->findInHousehold($household, $invitationId);

        if (! $invitation) {
            throw (new ModelNotFoundException)
                ->setModel(HouseholdInvitation::class, [$invitationId]);
        }

        return $invitation;
    }

    /**
     * Create an invitation.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Household $household, User $inviter, array $data): HouseholdInvitation
    {
        $email = strtolower(trim($data['email']));

        $existing = $this->repository->findPendingByEmail($household, $email);

        if ($existing) {
            return $existing->load('inviter');
        }

        return DB::transaction(function () use ($household, $inviter, $email, $data): HouseholdInvitation {
            /** @var HouseholdInvitation $invitation */
            $invitation = $this->repository->create([
                'household_id' => $household->id,
                'invited_by' => $inviter->id,
                'email' => $email,
                'role' => $data['role'] ?? 'member',
                'token' => Str::random(64),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            $invitation = $invitation->load('inviter', 'household');

            $event = $this->notificationEventBuilder->build(eventType: 'HOUSEHOLD_INVITATION_CREATED', userId: (string) $inviter->id, data: [
                'invitationId' => $invitation->id,
                'householdId' => $household->id,
                'invitedBy' => $inviter->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expiresAt' => Carbon::parse($invitation->expires_at)->toISOString(),
            ], );

            $this->notificationEventEmitter->emit($event);

            return $invitation;
        });
    }

    /**
     * Accept a household invitation.
     */
    public function accept(string $token, User $user): HouseholdMember
    {
        /** @var HouseholdInvitation|null $invitation */
        $invitation = $this->repository->findByToken($token);

        if (! $invitation) {
            throw new ModelNotFoundException;
        }

        if ($invitation->status === 'pending' && $invitation->isExpired()) {
            $this->expire($invitation);

            throw ApiException::badRequest('This invitation has expired.');
        }

        if (! $invitation->isPending()) {
            throw ApiException::conflict('This invitation is no longer pending.');
        }

        if (
            strtolower($invitation->email)
            !== strtolower($user->email)
        ) {
            throw ApiException::forbidden('This invitation does not belong to the authenticated user.');
        }

        /** @var Household $household */
        $household = $invitation->household;

        if ($household->owner_id === $user->id) {
            throw ApiException::conflict('The household owner cannot accept an invitation.');
        }

        /** @var HouseholdMember|null $existingMember */
        $existingMember = $household->memberships()
            ->where('user_id', $user->id)
            ->first();

        if ($existingMember) {
            throw ApiException::conflict('You are already a member of this household.');
        }

        return DB::transaction(function () use ($invitation, $household, $user): HouseholdMember {
            /** @var HouseholdMember $member */
            $member = $household->memberships()->create([
                'user_id' => $user->id,
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);

            $invitation->status = 'accepted';
            $invitation->save();

            $member = $member->load('user', 'household');

            $event = $this->notificationEventBuilder->build(eventType: 'HOUSEHOLD_INVITATION_ACCEPTED', userId: (string) $user->id, data: [
                'invitationId' => $invitation->id,
                'householdId' => $household->id,
                'userId' => $user->id,
                'role' => $member->role,
            ], );

            $this->notificationEventEmitter->emit($event);

            return $member;
        });
    }

    /**
     * Decline a household invitation.
     */
    public function decline(string $token, User $user): HouseholdInvitation
    {
        /** @var HouseholdInvitation|null $invitation */
        $invitation = $this->repository->findByToken($token);

        if (! $invitation) {
            throw new ModelNotFoundException;
        }

        if ($invitation->status === 'pending' && $invitation->isExpired()) {
            $this->expire($invitation);

            throw ApiException::badRequest('This invitation has expired.');
        }

        if (! $invitation->isPending()) {
            throw ApiException::conflict('This invitation is no longer pending.');
        }

        if (
            strtolower($invitation->email)
            !== strtolower($user->email)
        ) {
            throw ApiException::forbidden('This invitation does not belong to the authenticated user.');
        }

        return DB::transaction(function () use ($invitation, $user): HouseholdInvitation {
            $invitation->status = 'declined';
            $invitation->save();

            $invitation = $invitation->load('inviter', 'household');

            $event = $this->notificationEventBuilder->build(eventType: 'HOUSEHOLD_INVITATION_DECLINED', userId: (string) $user->id, data: [
                'invitationId' => $invitation->id,
                'householdId' => $invitation->household_id,
                'userId' => $user->id,
            ], );

            $this->notificationEventEmitter->emit($event);

            return $invitation;
        });
    }

    /**
     * Cancel a household invitation.
     */
    public function cancel(HouseholdInvitation $invitation): bool
    {
        if ($invitation->status !== 'pending') {
            return false;
        }

        $invitation->status = 'declined';

        $saved = $invitation->save();

        if ($saved) {
            $event = $this->notificationEventBuilder->build(eventType: 'HOUSEHOLD_INVITATION_CANCELLED', userId: (string) $invitation->invited_by, data: [
                'invitationId' => $invitation->id,
                'householdId' => $invitation->household_id,
            ], );

            $this->notificationEventEmitter->emit($event);
        }

        return $saved;
    }

    /**
     * Mark an invitation as expired when necessary.
     */
    public function expire(HouseholdInvitation $invitation): bool
    {
        if ($invitation->status !== 'pending') {
            return false;
        }

        $invitation->status = 'expired';

        $saved = $invitation->save();

        if ($saved) {
            $event = $this->notificationEventBuilder->build(eventType: 'HOUSEHOLD_INVITATION_EXPIRED', userId: (string) $invitation->invited_by, data: [
                'invitationId' => $invitation->id,
                'householdId' => $invitation->household_id,
            ], );

            $this->notificationEventEmitter->emit($event);
        }

        return $saved;
    }
}
