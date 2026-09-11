<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Guards the work a dashboard page view is allowed to cost: props nobody
 * renders are props nobody should pay a query for.
 */
function dashboardActor(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->create(['team_id' => $team->id]);

    return [$user, $team, $project];
}

test('the cross-type record counts are only computed for the screen that renders them', function () {
    [$user, $team, $project] = dashboardActor();

    $this->actingAs($user);

    $route = fn (string $name) => route($name, ['current_team' => $team->slug, 'project' => $project->slug]);

    $this->get($route('requests'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats'));

    foreach (['dashboard', 'exceptions', 'queries', 'jobs', 'logs'] as $name) {
        $this->get($route($name))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('stats'));
    }
});

test('the dashboard does not carry an issue list it never renders', function () {
    [$user, $team, $project] = dashboardActor();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('recent_issues'));
});

test('the shared team props cost one query regardless of how many teams a user has', function () {
    [$user] = dashboardActor();

    foreach (range(1, 4) as $ignored) {
        Team::factory()->create()->members()->attach($user, ['role' => TeamRole::Member->value]);
    }

    $user->refresh();
    $teamCount = $user->teams()->count();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $teams = $user->toUserTeams(includeCurrent: true);

    // The teams themselves, and nothing per team on top of that.
    expect($teams)->toHaveCount($teamCount)
        ->and($queries)->toBe(1)
        ->and($teams->first()->role)->not->toBeNull();
});
