<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Concerns\ResolvesConsoleIdentifiers;
use App\Http\Requests\Teams\SaveTeamRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateTeamCommand extends Command
{
    use ResolvesConsoleIdentifiers;

    protected $signature = 'laraowl:teams:create
                            {name : The team name}
                            {owner : Owner user ID or email}
                            {--personal : Mark the team as the owner\'s personal team}';

    protected $description = 'Create a team and assign an owner, mirroring the "New team" web flow';

    public function handle(CreateTeam $createTeam): int
    {
        $owner = $this->findUserByIdOrEmail((string) $this->argument('owner'));

        if (! $owner) {
            $this->error("User [{$this->argument('owner')}] not found.");

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $this->argument('name')],
            (new SaveTeamRequest)->rules(),
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $team = $createTeam->handle($owner, $validator->validated()['name'], (bool) $this->option('personal'));

        $this->info("Team [{$team->name}] created (slug: {$team->slug}).");
        $this->line("Owner: {$owner->email}");

        return self::SUCCESS;
    }
}
