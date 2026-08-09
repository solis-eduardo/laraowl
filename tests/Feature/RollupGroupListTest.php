<?php

use App\Models\Project;
use App\Models\RecordGroupRollup;
use App\Services\IngestService;
use App\Services\RecordService;

function ingestGroups(Project $project, array $records): void
{
    app(IngestService::class)->ingest($project, $records);
}

function service(): RecordService
{
    return app(RecordService::class);
}

test('the requests list is grouped by fingerprint with its labels', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/users', 'status_code' => 200, 'duration' => 10_000],
        ['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/users', 'status_code' => 500, 'duration' => 30_000],
        ['t' => 'request', '_group' => 'b', 'method' => 'POST', 'route_path' => '/orders', 'status_code' => 404, 'duration' => 5_000],
    ]);

    $rows = service()->getRequestStats($project, '1h')['requests'];

    expect($rows->total())->toBe(2);

    $users = collect($rows->items())->firstWhere('hash', 'a');

    expect($users->method)->toBe('GET')
        ->and($users->path)->toBe('/users')
        ->and((int) $users->total)->toBe(2)
        ->and((int) $users->ok_count)->toBe(1)
        ->and((int) $users->server_error_count)->toBe(1)
        ->and($users->avg_duration)->toBe(20000.0)
        ->and((float) $users->max_duration)->toBe(30000.0);

    $orders = collect($rows->items())->firstWhere('hash', 'b');
    expect((int) $orders->client_error_count)->toBe(1);
});

test('group rollups are hourly, so a whole day of one route is 24 rows at most', function () {
    $project = Project::factory()->create();

    $this->travelTo(now()->startOfHour()->addMinutes(2));
    ingestGroups($project, [['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/', 'status_code' => 200]]);

    $this->travelTo(now()->addMinutes(30));
    ingestGroups($project, [['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/', 'status_code' => 200]]);

    $this->travelBack();

    $rows = RecordGroupRollup::where('project_id', $project->id)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->count)->toBe(2)
        ->and($rows->first()->bucket->format('i:s'))->toBe('00:00');
});

test('a group list row reports a real p95 rather than a maximum', function () {
    $project = Project::factory()->create();

    $batch = [];
    for ($i = 0; $i < 95; $i++) {
        $batch[] = ['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/', 'status_code' => 200, 'duration' => 10_000];
    }
    for ($i = 0; $i < 5; $i++) {
        $batch[] = ['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/', 'status_code' => 200, 'duration' => 5_000_000];
    }

    ingestGroups($project, $batch);

    $row = service()->getRequestStats($project, '1h')['requests']->items()[0];

    expect($row->p95_duration)->toBe(10000.0)
        ->and((float) $row->max_duration)->toBe(5000000.0);
});

test('the exceptions list counts distinct affected users', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'exception', '_group' => 'boom', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => '1'],
        ['t' => 'exception', '_group' => 'boom', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => '1'],
        ['t' => 'exception', '_group' => 'boom', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => '2'],
        ['t' => 'exception', '_group' => 'boom', 'class' => 'RuntimeException', 'message' => 'boom', 'user' => 'guest_abc'],
        ['t' => 'exception', '_group' => 'other', 'class' => 'LogicException', 'message' => 'nope', 'user' => '1'],
    ]);

    $rows = collect(service()->getExceptionStats($project, '1h')['exceptions']->items());

    $boom = $rows->firstWhere('hash', 'boom');

    expect($boom->class)->toBe('RuntimeException')
        ->and($boom->message)->toBe('boom')
        ->and((int) $boom->total_count)->toBe(4)
        // Three of those four carry a user, but only two are real people.
        ->and($boom->user_count)->toBe(2);

    expect($rows->firstWhere('hash', 'other')->user_count)->toBe(1);
});

test('the jobs list maps statuses onto its columns', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'job-attempt', '_group' => 'j', 'name' => 'SendMail', 'status' => 'processed', 'duration' => 1_000],
        ['t' => 'job-attempt', '_group' => 'j', 'name' => 'SendMail', 'status' => 'failed', 'duration' => 2_000],
        ['t' => 'job-attempt', '_group' => 'j', 'name' => 'SendMail', 'status' => 'released', 'duration' => 3_000],
    ]);

    $row = service()->getJobStats($project, '1h')['jobs']->items()[0];

    expect($row->job_class)->toBe('SendMail')
        ->and((int) $row->total)->toBe(3)
        ->and($row->processed_count)->toBe(1)
        ->and($row->failed_count)->toBe(1);
});

test('the cache list keeps its per key breakdown and hit rate', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'cache-event', '_group' => 'k1', 'key' => 'users:1', 'type' => 'hit'],
        ['t' => 'cache-event', '_group' => 'k1', 'key' => 'users:1', 'type' => 'hit'],
        ['t' => 'cache-event', '_group' => 'k1', 'key' => 'users:1', 'type' => 'miss'],
        ['t' => 'cache-event', '_group' => 'k1', 'key' => 'users:1', 'type' => 'write'],
        ['t' => 'cache-event', '_group' => 'k1', 'key' => 'users:1', 'type' => 'delete'],
    ]);

    $row = service()->getCacheStats($project, '1h')['keys']->items()[0];

    expect($row->cache_key)->toBe('users:1')
        ->and((int) $row->hits)->toBe(2)
        ->and((int) $row->misses)->toBe(1)
        ->and((int) $row->writes)->toBe(1)
        ->and((int) $row->deletes)->toBe(1)
        ->and($row->hit_rate)->toBe(40.0);
});

test('the queries list carries the statement and total duration', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'query', '_group' => 'q', 'sql' => 'select * from users', 'connection' => 'pgsql', 'duration' => 1_500],
        ['t' => 'query', '_group' => 'q', 'sql' => 'select * from users', 'connection' => 'pgsql', 'duration' => 2_500],
    ]);

    $row = service()->getQueryStats($project, '1h')['queries']->items()[0];

    expect($row->sql_query)->toBe('select * from users')
        ->and($row->db_connection)->toBe('pgsql')
        ->and($row->total_calls)->toBe(2)
        ->and($row->total_duration)->toBe(4000.0)
        ->and($row->avg_duration)->toBe(2000.0);
});

test('the mail list counts what actually went out', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'mail', '_group' => 'm', 'class' => 'WelcomeMail', 'mailer' => 'smtp'],
        ['t' => 'mail', '_group' => 'm', 'class' => 'WelcomeMail', 'mailer' => 'log'],
    ]);

    $row = service()->getMailStats($project, '1h')['mailables']->items()[0];

    expect($row->mailable_class)->toBe('WelcomeMail')
        ->and((int) $row->total)->toBe(2)
        ->and($row->queued_count)->toBe(1);
});

test('the notifications list splits sent from failed', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'notification', '_group' => 'n', 'class' => 'OrderShipped', 'channel' => 'mail'],
        ['t' => 'notification', '_group' => 'n', 'class' => 'OrderShipped', 'channel' => 'mail', 'status' => 'failed'],
    ]);

    $row = service()->getNotificationStats($project, '1h')['notifications']->items()[0];

    expect($row->notification_class)->toBe('OrderShipped')
        ->and($row->channel)->toBe('mail')
        ->and($row->sent_count)->toBe(1)
        ->and($row->failed_count)->toBe(1);
});

test('the outgoing requests list groups by host', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'outgoing-request', '_group' => 'h', 'host' => 'api.stripe.com', 'status_code' => 200, 'duration' => 100],
        ['t' => 'outgoing-request', '_group' => 'h', 'host' => 'api.stripe.com', 'status' => 'failed', 'duration' => 100],
    ]);

    $row = service()->getOutgoingRequestStats($project, '1h')['hosts']->items()[0];

    expect($row->host)->toBe('api.stripe.com')
        ->and((int) $row->total)->toBe(2)
        ->and((int) $row->ok_count)->toBe(1);
});

test('the scheduled tasks list keeps the cron expression and next run', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'scheduled-task', '_group' => 's', 'command' => 'backup:run', 'cron' => '0 3 * * *', 'status' => 'processed'],
        ['t' => 'scheduled-task', '_group' => 's', 'command' => 'backup:run', 'cron' => '0 3 * * *', 'status' => 'skipped'],
    ]);

    $row = service()->getScheduledTaskStats($project, '1h')['tasks']->items()[0];

    expect($row->command)->toBe('backup:run')
        ->and($row->schedule)->toBe('0 3 * * *')
        ->and($row->processed_count)->toBe(1)
        ->and($row->skipped_count)->toBe(1)
        ->and($row->next_run)->not->toBe('Invalid Schedule');
});

test('the users list aggregates requests, errors and exceptions per user', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'request', '_group' => 'a', 'user' => '5', 'status_code' => 200],
        ['t' => 'request', '_group' => 'a', 'user' => '5', 'status_code' => 500],
        ['t' => 'request', '_group' => 'a', 'user' => '5', 'status_code' => 404],
        ['t' => 'exception', '_group' => 'e', 'class' => 'E', 'message' => 'm', 'user' => '5'],
        ['t' => 'request', '_group' => 'a', 'user' => 'guest_zz', 'status_code' => 200],
    ]);

    $rows = service()->getUserStats($project, '1h')['users'];

    // The guest never becomes a row.
    expect($rows->total())->toBe(1);

    $row = $rows->items()[0];

    expect((string) $row->user_id)->toBe('5')
        ->and($row->hash)->toBe(md5('5'))
        ->and((int) $row->total_requests)->toBe(3)
        ->and((int) $row->error_count)->toBe(2)
        ->and((int) $row->exception_count)->toBe(1);
});

test('records without a fingerprint produce no group row', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [
        ['t' => 'log', 'level' => 'info', 'message' => 'hello'],
    ]);

    expect(RecordGroupRollup::where('project_id', $project->id)->count())->toBe(0);
});

test('group counters accumulate across batches in the same hour', function () {
    $project = Project::factory()->create();

    ingestGroups($project, [['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/', 'status_code' => 200, 'duration' => 10]]);
    ingestGroups($project, [['t' => 'request', '_group' => 'a', 'method' => 'GET', 'route_path' => '/', 'status_code' => 200, 'duration' => 30]]);

    $row = RecordGroupRollup::where('project_id', $project->id)->first();

    expect((int) $row->count)->toBe(2)
        ->and((float) $row->sum_duration)->toBe(40.0)
        ->and((float) $row->max_duration)->toBe(30.0)
        ->and((float) $row->min_duration)->toBe(10.0);
});
