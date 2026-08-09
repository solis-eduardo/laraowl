<?php

namespace App\Console\Commands;

use App\Actions\Projects\CreateProject;
use App\Concerns\ResolvesConsoleIdentifiers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateProjectCommand extends Command
{
    use ResolvesConsoleIdentifiers;

    protected $signature = 'laraowl:projects:create
                            {team : Team ID or slug}
                            {name : The project name}
                            {--url= : The project URL, used for uptime checks}
                            {--email= : Default alert email (defaults to the team owner\'s email)}';

    protected $description = 'Create a project (application) with its default alert rules, mirroring the "New project" web flow';

    public function handle(CreateProject $createProject): int
    {
        $team = $this->findTeamByIdOrSlug((string) $this->argument('team'));

        if (! $team) {
            $this->error("Team [{$this->argument('team')}] not found.");

            return self::FAILURE;
        }

        $alertEmail = $this->option('email') ?? $team->owner()?->email;

        if (! $alertEmail) {
            $this->error("Team [{$team->slug}] has no owner; pass --email to set the default alert address.");

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $this->argument('name'), 'url' => $this->option('url'), 'email' => $alertEmail],
            [
                'name' => ['required', 'string', 'max:255'],
                'url' => ['nullable', 'url', 'max:255'],
                'email' => ['required', 'email'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $project = $createProject->handle($team, $data['name'], $data['url'] ?? null, $data['email']);

        $this->info("Project [{$project->name}] created (slug: {$project->slug}) in team [{$team->slug}].");
        $this->line("Default alert email: {$data['email']}");

        return self::SUCCESS;
    }
}
