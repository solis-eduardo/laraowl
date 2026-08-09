<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

test('a member can be invited and defaults to the team owner as inviter', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->artisan('laraowl:teams:invite', [
        'team' => $team->slug,
        'email' => 'invited@example.com',
    ])->assertExitCode(0);

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
        'invited_by' => $owner->id,
    ]);

    Notification::assertSentOnDemand(\App\Notifications\Teams\TeamInvitation::class);
});

test('invitation email can be skipped with --no-notify', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->artisan('laraowl:teams:invite', [
        'team' => $team->slug,
        'email' => 'invited@example.com',
        '--no-notify' => true,
    ])->assertExitCode(0);

    Notification::assertNothingSent();
});

test('an explicit inviter can be given via --by', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->artisan('laraowl:teams:invite', [
        'team' => $team->slug,
        'email' => 'invited@example.com',
        '--by' => $admin->email,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'invited_by' => $admin->id,
    ]);
});

test('existing members cannot be invited again', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->artisan('laraowl:teams:invite', [
        'team' => $team->slug,
        'email' => 'member@example.com',
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('team_invitations', ['email' => 'member@example.com']);
});

test('--accept immediately joins an existing user to the team', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->artisan('laraowl:teams:invite', [
        'team' => $team->slug,
        'email' => 'invited@example.com',
        '--accept' => true,
    ])->assertExitCode(0);

    expect($invitee->fresh()->belongsToTeam($team))->toBeTrue();

    $invitation = TeamInvitation::where('email', 'invited@example.com')->firstOrFail();
    expect($invitation->accepted_at)->not->toBeNull();
});

test('--accept leaves the invitation pending when no matching user exists yet', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->artisan('laraowl:teams:invite', [
        'team' => $team->slug,
        'email' => 'not-yet-registered@example.com',
        '--accept' => true,
    ])->assertExitCode(0);

    $invitation = TeamInvitation::where('email', 'not-yet-registered@example.com')->firstOrFail();
    expect($invitation->accepted_at)->toBeNull();
});
