<?php

use App\Models\Project;
use App\Models\User;

test('uptime monitoring is enabled by default for new projects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['team_id' => $user->currentTeam->id]);

    expect($project->fresh()->uptime_monitoring_enabled)->toBeTrue();
});

test('uptime monitoring can be disabled from the project settings', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'url' => 'https://example.com',
        'uptime_monitoring_enabled' => true,
        'last_uptime_status' => 'down',
        'last_uptime_check_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('projects.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'name' => $project->name,
            'url' => $project->url,
            'uptime_monitoring_enabled' => false,
            'uptime_check_interval' => 60,
            'retention_days' => 7,
        ])
        ->assertRedirect();

    $project->refresh();
    expect($project->uptime_monitoring_enabled)->toBeFalse();
    expect($project->last_uptime_status)->toBeNull();
    expect($project->last_uptime_check_at)->toBeNull();
});

test('uptime monitoring can be re-enabled from the project settings', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'url' => 'https://example.com',
        'uptime_monitoring_enabled' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('projects.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'name' => $project->name,
            'url' => $project->url,
            'uptime_monitoring_enabled' => true,
            'uptime_check_interval' => 60,
            'retention_days' => 7,
        ])
        ->assertRedirect();

    expect($project->fresh()->uptime_monitoring_enabled)->toBeTrue();
});

test('omitting the uptime monitoring field preserves the stored value', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    foreach ([true, false] as $enabled) {
        $project = Project::factory()->create([
            'team_id' => $team->id,
            'url' => 'https://example.com',
            'uptime_monitoring_enabled' => $enabled,
        ]);

        $this->actingAs($user)
            ->patch(route('projects.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
                'name' => 'Renamed Project',
                'url' => $project->url,
                'uptime_check_interval' => 60,
                'retention_days' => 7,
            ])
            ->assertRedirect();

        expect($project->fresh()->uptime_monitoring_enabled)->toBe($enabled);
    }
});

test('the uptime monitoring scope only returns monitorable projects', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $monitored = Project::factory()->create([
        'team_id' => $team->id,
        'url' => 'https://example.com',
        'uptime_monitoring_enabled' => true,
    ]);

    Project::factory()->create([
        'team_id' => $team->id,
        'url' => 'https://example.com',
        'uptime_monitoring_enabled' => false,
    ]);

    Project::factory()->create([
        'team_id' => $team->id,
        'url' => null,
        'uptime_monitoring_enabled' => true,
    ]);

    expect(Project::withUptimeMonitoring()->pluck('id')->all())->toBe([$monitored->id]);
});
