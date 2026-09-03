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

/**
 * Reflect into the private per-driver SQL fragment directly, since the test
 * suite only opens a real connection to SQLite — this exercises the
 * MySQL/MariaDB and PostgreSQL branches even where they cannot be run.
 */
function retentionCutoffSqlFor(string $driver): string
{
    $method = new ReflectionMethod(Record::class, 'retentionCutoffSql');
    $method->setAccessible(true);

    return $method->invoke(new Record, $driver);
}

test('the retention cutoff uses DATE_SUB on mysql and mariadb', function (string $driver) {
    expect(retentionCutoffSqlFor($driver))
        ->toBe('records.created_at < DATE_SUB(NOW(), INTERVAL projects.retention_days DAY)');
})->with(['mysql', 'mariadb']);

test('the retention cutoff multiplies an interval by retention_days on postgres', function () {
    expect(retentionCutoffSqlFor('pgsql'))
        ->toBe("records.created_at < NOW() - (INTERVAL '1 day' * projects.retention_days)");
});

test('the retention cutoff builds a datetime modifier on sqlite', function () {
    expect(retentionCutoffSqlFor('sqlite'))
        ->toBe("records.created_at < datetime('now', '-' || projects.retention_days || ' days')");
});

test('an unsupported driver is rejected instead of silently reusing another driver\'s syntax', function () {
    retentionCutoffSqlFor('sqlsrv');
})->throws(RuntimeException::class, 'Unsupported database driver for retention pruning: sqlsrv');
