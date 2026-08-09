<?php

namespace App\Concerns;

use App\Models\Team;
use App\Models\User;

/**
 * Look up models from a CLI-friendly identifier (id, email or slug).
 */
trait ResolvesConsoleIdentifiers
{
    protected function findUserByIdOrEmail(string $identifier): ?User
    {
        return User::where(function ($query) use ($identifier) {
            if (ctype_digit($identifier)) {
                $query->orWhere('id', $identifier);
            }

            $query->orWhere('email', $identifier);
        })->first();
    }

    protected function findTeamByIdOrSlug(string $identifier): ?Team
    {
        return Team::where(function ($query) use ($identifier) {
            if (ctype_digit($identifier)) {
                $query->orWhere('id', $identifier);
            }

            $query->orWhere('slug', $identifier);
        })->first();
    }
}
