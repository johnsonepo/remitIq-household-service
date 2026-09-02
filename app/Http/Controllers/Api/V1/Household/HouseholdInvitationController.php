<?php

namespace App\Http\Controllers\Api\V1\Household;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Api\Household\CreateHouseholdInvitationRequest;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Services\Household\HouseholdInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseholdInvitationController extends BaseController
{
    public function __construct(private readonly HouseholdInvitationService $service) {}

    /**
     * List household invitations.
     */
    public function index(Household $household): JsonResponse
    {
        $this->authorize('inviteMembers', $household);

        $invitations = $this->service->list($household);

        return $this->success($invitations, 'Household invitations retrieved successfully.');
    }

    /**
     * Create a household invitation.
     */
    public function store(CreateHouseholdInvitationRequest $request, Household $household): JsonResponse
    {
        $this->authorize('inviteMembers', $household);

        $invitation = $this->service->create($household, $request->user(), $request->validated());

        return $this->created($invitation, 'Household invitation created successfully.');
    }

    /**
     * Show a household invitation.
     */
    public function show(Household $household, HouseholdInvitation $invitation): JsonResponse
    {
        $this->authorize('inviteMembers', $household);

        $invitation = $this->service->find($household, $invitation->id);

        return $this->success($invitation, 'Household invitation retrieved successfully.');
    }

    /**
     * Cancel a household invitation.
     */
    public function destroy(Household $household, HouseholdInvitation $invitation): JsonResponse
    {
        $this->authorize('inviteMembers', $household);

        $invitation = $this->service->find($household, $invitation->id);

        $this->service->cancel($invitation);

        return $this->success(null, 'Household invitation cancelled successfully.');
    }

    /**
     * Accept a household invitation.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $member = $this->service->accept($token, $request->user());

        return $this->success($member, 'Household invitation accepted successfully.');
    }

    /**
     * Decline a household invitation.
     */
    public function decline(Request $request, string $token): JsonResponse
    {
        $invitation = $this->service->decline($token, $request->user());

        return $this->success($invitation, 'Household invitation declined successfully.');
    }

    /**
     * List invitations received by the authenticated user.
     */
    public function myInvitations(Request $request): JsonResponse
    {
        $invitations = $this->service->forUser($request->user());

        return $this->success(
            $invitations,
            'Your household invitations retrieved successfully.'
        );
    }
}
