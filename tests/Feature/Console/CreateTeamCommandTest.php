<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('a team can be created for an existing owner by email', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->artisan('laraowl:teams:create', ['name' => 'Acme Inc', 'owner' => 'owner@example.com'])
        ->assertExitCode(0);

    $team = Team::where('name', 'Acme Inc')->firstOrFail();

    expect($team->is_personal)->toBeFalse()
        ->and($owner->fresh()->ownsTeam($team))->toBeTrue()
        ->and($owner->fresh()->current_team_id)->toBe($team->id);
});

test('a team can be created for an existing owner by id', function () {
    $owner = User::factory()->create();

    $this->artisan('laraowl:teams:create', ['name' => 'Acme Inc', 'owner' => (string) $owner->id])
        ->assertExitCode(0);

    $this->assertDatabaseHas('teams', ['name' => 'Acme Inc']);
});

test('a team can be marked personal', function () {
    $owner = User::factory()->create();

    $this->artisan('laraowl:teams:create', [
        'name' => 'Solo Team',
        'owner' => $owner->email,
        '--personal' => true,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('teams', ['name' => 'Solo Team', 'is_personal' => true]);
});

test('team creation fails for an unknown owner', function () {
    $this->artisan('laraowl:teams:create', ['name' => 'Acme Inc', 'owner' => 'missing@example.com'])
        ->assertExitCode(1);

    $this->assertDatabaseMissing('teams', ['name' => 'Acme Inc']);
});

test('team creation fails for a reserved name', function () {
    $owner = User::factory()->create();

    $this->artisan('laraowl:teams:create', ['name' => 'Settings', 'owner' => $owner->email])
        ->assertExitCode(1);

    $this->assertDatabaseMissing('teams', ['name' => 'Settings']);
});
