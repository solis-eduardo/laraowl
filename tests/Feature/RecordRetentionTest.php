<?php

use App\Models\Project;
use App\Models\Record;

test('records past the retention window are pruned', function () {
    $project = Project::factory()->create(['retention_days' => 7]);

    $stale = Record::factory()->create([
        'project_id' => $project->id,
        'created_at' => now()->subDays(10),
    ]);

    $fresh = Record::factory()->create([
        'project_id' => $project->id,
        'created_at' => now()->subDays(1),
    ]);

    $this->artisan('model:prune', ['--model' => [Record::class]])->assertExitCode(0);

    expect(Record::find($stale->id))->toBeNull()
        ->and(Record::find($fresh->id))->not->toBeNull();
});

test('a project with retention disabled keeps its records', function () {
    $project = Project::factory()->create(['retention_days' => 0]);

    $ancient = Record::factory()->create([
        'project_id' => $project->id,
        'created_at' => now()->subYears(2),
    ]);

    $this->artisan('model:prune', ['--model' => [Record::class]])->assertExitCode(0);

    expect(Record::find($ancient->id))->not->toBeNull();
});

test('retention windows are scoped per project', function () {
    $short = Project::factory()->create(['retention_days' => 1]);
    $long = Project::factory()->create(['retention_days' => 30]);

    $prunedByShortWindow = Record::factory()->create([
        'project_id' => $short->id,
        'created_at' => now()->subDays(5),
    ]);

    $keptByLongWindow = Record::factory()->create([
        'project_id' => $long->id,
        'created_at' => now()->subDays(5),
    ]);

    $this->artisan('model:prune', ['--model' => [Record::class]])->assertExitCode(0);

    expect(Record::find($prunedByShortWindow->id))->toBeNull()
        ->and(Record::find($keptByLongWindow->id))->not->toBeNull();
});
