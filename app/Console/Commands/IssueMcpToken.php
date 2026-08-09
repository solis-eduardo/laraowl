<?php

namespace App\Console\Commands;

use App\Concerns\ResolvesConsoleIdentifiers;
use Illuminate\Console\Command;

class IssueMcpToken extends Command
{
    use ResolvesConsoleIdentifiers;

    protected $signature = 'laraowl:mcp-token {user : User id or email} {--name=mcp : Token name}';

    protected $description = 'Issue a Sanctum personal access token for MCP access';

    public function handle(): int
    {
        $identifier = (string) $this->argument('user');

        $user = $this->findUserByIdOrEmail($identifier);

        if (! $user) {
            $this->error("User [{$identifier}] not found.");

            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->option('name'));

        $this->info("Token for {$user->email}:");
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->comment('Store it now — it will not be shown again.');

        return self::SUCCESS;
    }
}
