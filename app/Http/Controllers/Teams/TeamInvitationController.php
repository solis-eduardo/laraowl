<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation as AcceptTeamInvitationAction;
use App\Actions\Teams\InviteMember;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\AcceptTeamInvitationRequest;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, InviteMember $inviteMember): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $inviteMember->handle(
            $team,
            $request->user(),
            $request->validated('email'),
            TeamRole::from($request->validated('role')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(AcceptTeamInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitationAction $acceptTeamInvitation): RedirectResponse
    {
        $acceptTeamInvitation->handle($request->user(), $invitation);

        return redirect('/dashboard');
    }
}
