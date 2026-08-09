<?php

namespace App\Console\Commands;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\InviteMember;
use App\Concerns\ResolvesConsoleIdentifiers;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InviteTeamMemberCommand extends Command
{
    use ResolvesConsoleIdentifiers;

    protected $signature = 'laraowl:teams:invite
                            {team : Team ID or slug}
                            {email : Email address to invite}
                            {--role=member : Role to assign (member or admin)}
                            {--by= : Inviter user ID or email (defaults to the team owner)}
                            {--accept : Immediately join an existing user to the team instead of waiting for the invite email to be accepted}
                            {--no-notify : Skip sending the invitation email}';

    protected $description = 'Invite a user to a team, mirroring the team settings "invite" web flow';

    public function handle(InviteMember $inviteMember, AcceptTeamInvitation $acceptTeamInvitation): int
    {
        $team = $this->findTeamByIdOrSlug((string) $this->argument('team'));

        if (! $team) {
            $this->error("Team [{$this->argument('team')}] not found.");

            return self::FAILURE;
        }

        $invitedBy = $this->resolveInviter($team);

        if (! $invitedBy) {
            $this->error("Could not determine an inviter: team [{$team->slug}] has no owner and --by was not given.");

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $this->argument('email'), 'role' => $this->option('role')],
            [
                'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($team)],
                'role' => ['required', 'string', Rule::enum(TeamRole::class)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $invitation = $inviteMember->handle(
            $team,
            $invitedBy,
            $data['email'],
            TeamRole::from($data['role']),
            notify: ! $this->option('no-notify'),
        );

        $this->info("Invitation sent to [{$invitation->email}] for team [{$team->slug}] as {$invitation->role->label()}.");

        if ($this->option('accept')) {
            $this->acceptImmediately($invitation, $acceptTeamInvitation);
        }

        return self::SUCCESS;
    }

    protected function resolveInviter(Team $team): ?User
    {
        if ($identifier = $this->option('by')) {
            return $this->findUserByIdOrEmail((string) $identifier);
        }

        return $team->owner();
    }

    protected function acceptImmediately(TeamInvitation $invitation, AcceptTeamInvitation $acceptTeamInvitation): void
    {
        $user = User::where('email', $invitation->email)->first();

        if (! $user) {
            $this->warn("No existing user with email [{$invitation->email}]; invitation left pending until they register and accept it.");

            return;
        }

        $acceptTeamInvitation->handle($user, $invitation);

        $this->info("User [{$user->email}] joined the team immediately (--accept).");
    }
}
