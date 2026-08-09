<?php

use App\Models\Project;
use App\Models\RecordRollup;
use App\Services\IngestService;
use App\Services\RollupWriter;

function rollupFor(Project $project, string $type): ?RecordRollup
{
    return RecordRollup::where('project_id', $project->id)->where('type', $type)->first();
}

function ingestRecords(Project $project, array $records): void
{
    app(IngestService::class)->ingest($project, $records);
}

test('a request contributes its status, duration and user to the bucket', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [
        ['t' => 'request', 'status_code' => 200, 'duration' => 30, 'user' => '7'],
        ['t' => 'request', 'status_code' => 404, 'duration' => 10, 'user' => '7'],
        ['t' => 'request', 'status_code' => 500, 'duration' => 90],
    ]);

    $rollup = rollupFor($project, 'request');

    expect($rollup->count)->toBe(3)
        ->and($rollup->ok_count)->toBe(1)
        ->and($rollup->client_error_count)->toBe(1)
        ->and($rollup->server_error_count)->toBe(1)
        ->and($rollup->authed_count)->toBe(2)
        ->and((float) $rollup->sum_duration)->toBe(130.0)
        ->and($rollup->count_duration)->toBe(3)
        ->and((float) $rollup->max_duration)->toBe(90.0)
        ->and((float) $rollup->min_duration)->toBe(10.0);
});

test('counters accumulate when a second batch lands in the same bucket', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [['t' => 'request', 'status_code' => 200, 'duration' => 30]]);
    ingestRecords($project, [['t' => 'request', 'status_code' => 200, 'duration' => 70]]);

    $rollup = rollupFor($project, 'request');

    // Laravel's upsert() would have overwritten rather than added.
    expect($rollup->count)->toBe(2)
        ->and($rollup->ok_count)->toBe(2)
        ->and((float) $rollup->sum_duration)->toBe(100.0)
        ->and((float) $rollup->max_duration)->toBe(70.0)
        ->and((float) $rollup->min_duration)->toBe(30.0);
});

test('a batch carrying no durations does not erase the recorded extremes', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [['t' => 'log', 'level' => 'info', 'duration' => 42]]);
    ingestRecords($project, [['t' => 'log', 'level' => 'info']]);

    $rollup = rollupFor($project, 'log');

    // GREATEST(x, NULL) is NULL on MySQL and SQLite, so this is a real trap.
    expect($rollup->count)->toBe(2)
        ->and($rollup->count_duration)->toBe(1)
        ->and((float) $rollup->max_duration)->toBe(42.0)
        ->and((float) $rollup->min_duration)->toBe(42.0);
});

test('exceptions always count as server errors', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [
        ['t' => 'exception', 'class' => 'RuntimeException', 'message' => 'boom'],
    ]);

    expect(rollupFor($project, 'exception')->server_error_count)->toBe(1);
});

test('job statuses map onto ok, failed and neutral counters', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [
        ['t' => 'job-attempt', 'name' => 'A', 'status' => 'processed', 'duration' => 5],
        ['t' => 'job-attempt', 'name' => 'A', 'status' => 'failed', 'duration' => 5],
        ['t' => 'job-attempt', 'name' => 'A', 'status' => 'released', 'duration' => 5],
    ]);

    $rollup = rollupFor($project, 'job-attempt');

    expect($rollup->ok_count)->toBe(1)
        ->and($rollup->server_error_count)->toBe(1)
        ->and($rollup->neutral_count)->toBe(1);
});

test('scheduled task statuses map onto ok, failed and neutral counters', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [
        ['t' => 'scheduled-task', 'command' => 'backup:run', 'status' => 'processed'],
        ['t' => 'scheduled-task', 'command' => 'backup:run', 'status' => 'failed'],
        ['t' => 'scheduled-task', 'command' => 'backup:run', 'status' => 'skipped'],
    ]);

    $rollup = rollupFor($project, 'scheduled-task');

    expect($rollup->ok_count)->toBe(1)
        ->and($rollup->server_error_count)->toBe(1)
        ->and($rollup->neutral_count)->toBe(1);
});

test('cache events split into hits, misses and writes', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [
        ['t' => 'cache-event', 'key' => 'a', 'type' => 'hit'],
        ['t' => 'cache-event', 'key' => 'a', 'type' => 'hit'],
        ['t' => 'cache-event', 'key' => 'b', 'type' => 'miss'],
        ['t' => 'cache-event', 'key' => 'b', 'type' => 'write'],
    ]);

    $rollup = rollupFor($project, 'cache-event');

    expect($rollup->hits)->toBe(2)
        ->and($rollup->misses)->toBe(1)
        ->and($rollup->writes)->toBe(1)
        ->and($rollup->ok_count)->toBe(2)
        ->and($rollup->server_error_count)->toBe(1);
});

test('a non numeric outgoing status is not read as a status code', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [
        ['t' => 'outgoing-request', 'host' => 'a.test', 'status' => 'failed', 'duration' => 10],
        ['t' => 'outgoing-request', 'host' => 'a.test', 'status_code' => 200, 'duration' => 10],
        ['t' => 'outgoing-request', 'host' => 'a.test', 'status' => 503, 'duration' => 10],
    ]);

    $rollup = rollupFor($project, 'outgoing-request');

    expect($rollup->count)->toBe(3)
        ->and($rollup->ok_count)->toBe(1)
        ->and($rollup->server_error_count)->toBe(1)
        ->and($rollup->client_error_count)->toBe(0);
});

// Durations arrive in microseconds; the client's StageSensor multiplies
// elapsed seconds by 1_000_000.
test('durations land in the right latency histogram bucket', function (float $microseconds, string $column) {
    $project = Project::factory()->create();

    ingestRecords($project, [['t' => 'query', 'sql' => 'select 1', 'duration' => $microseconds]]);

    expect(rollupFor($project, 'query')->{$column})->toBe(1);
})->with([
    'half a millisecond' => [500.0, 'lat_le_1000'],
    'exactly 1ms' => [1_000.0, 'lat_le_1000'],
    'just over 1ms' => [1_001.0, 'lat_le_5000'],
    'a real 325ms request' => [325_225.0, 'lat_le_500000'],
    'one second' => [1_000_000.0, 'lat_le_1000000'],
    'slower than ten seconds' => [10_000_001.0, 'lat_le_inf'],
]);

test('the histogram merges additively across batches', function () {
    $project = Project::factory()->create();

    ingestRecords($project, [['t' => 'query', 'sql' => 'a', 'duration' => 900]]);
    ingestRecords($project, [['t' => 'query', 'sql' => 'b', 'duration' => 900]]);

    expect(rollupFor($project, 'query')->lat_le_1000)->toBe(2);
});

test('every additive column is a real column on the rollup table', function () {
    $project = Project::factory()->create();
    ingestRecords($project, [['t' => 'request', 'status_code' => 200]]);

    $rollup = rollupFor($project, 'request');

    foreach (RollupWriter::additiveColumns() as $column) {
        expect($rollup->getAttributes())->toHaveKey($column);
    }
});
