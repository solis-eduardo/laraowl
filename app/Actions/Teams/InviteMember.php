<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

class InviteMember
{
    /**
     * Create a team invitation and notify the invitee by email.
     */
    public function handle(Team $team, User $invitedBy, string $email, TeamRole $role, bool $notify = true): TeamInvitation
    {
        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $invitedBy->id,
            'expires_at' => now()->addDays(3),
        ]);

        if ($notify) {
            Notification::route('mail', $invitation->email)
                ->notify(new TeamInvitationNotification($invitation));
        }

        return $invitation;
    }
}
