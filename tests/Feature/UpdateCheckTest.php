<?php

use App\Console\Commands\UpdateLaraOwl;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\UpdateService;
use App\Support\Release;
use App\Support\Version;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Build a GitHub releases API payload for the given tag.
 */
function githubRelease(string $tag): array
{
    return [
        'tag_name' => $tag,
        'name' => "LaraOwl {$tag}",
        'html_url' => "https://github.com/laraowl/laraowl/releases/tag/{$tag}",
        'body' => 'Fixed the integrations URL bug.',
        'published_at' => '2026-07-09T12:00:00Z',
    ];
}

/**
 * Seed the update cache as though a check had already run.
 */
function cacheRelease(?Release $release): void
{
    Cache::put(UpdateService::CACHE_KEY, ['release' => $release?->toArray()], 3600);
}

/**
 * Round-trip a value the way a serializing cache store configured with
 * `serializable_classes => false` would. Objects come back as incomplete
 * classes; plain arrays and scalars survive untouched.
 */
function throughCacheSerialization(mixed $value): mixed
{
    return unserialize(serialize($value), ['allowed_classes' => false]);
}

/**
 * The first user to register administers the instance.
 */
function instanceOperator(): User
{
    return User::factory()->create();
}

beforeEach(function () {
    config(['laraowl.update_check.enabled' => true]);
});

test('the current version is a hardcoded, well-formed semver constant', function () {
    expect(Version::current())->toBe(Version::CURRENT)
        ->and(Version::current())->toMatch('/^\d+\.\d+\.\d+$/');
});

test('a release is newer only when it sorts above the running version', function () {
    $current = Version::current();

    expect(Version::isNewerThanCurrent('99.0.0'))->toBeTrue()
        ->and(Version::isNewerThanCurrent('v99.0.0'))->toBeTrue()
        ->and(Version::isNewerThanCurrent($current))->toBeFalse()
        ->and(Version::isNewerThanCurrent('v'.$current))->toBeFalse()
        ->and(Version::isNewerThanCurrent('0.0.1'))->toBeFalse();
});

test('refreshing caches the latest release from github', function () {
    Http::fake([
        'api.github.com/repos/laraowl/laraowl/releases/latest' => Http::response(githubRelease('v99.0.0')),
    ]);

    $release = app(UpdateService::class)->refresh();

    expect($release)->toBeInstanceOf(Release::class)
        ->and($release->version)->toBe('99.0.0')
        ->and($release->url)->toContain('releases/tag/v99.0.0')
        ->and(Cache::get(UpdateService::CACHE_KEY)['release']['version'])->toBe('99.0.0');
});

test('the release is cached as a plain array, never as an object', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);

    app(UpdateService::class)->refresh();

    // config/cache.php sets serializable_classes => false, so a cached object
    // would come back as __PHP_Incomplete_Class and never match `instanceof`.
    expect(Cache::get(UpdateService::CACHE_KEY)['release'])->toBeArray();
});

test('a pending update survives a cache store that forbids unserializing classes', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);

    app(UpdateService::class)->refresh();

    Cache::put(
        UpdateService::CACHE_KEY,
        throughCacheSerialization(Cache::get(UpdateService::CACHE_KEY)),
        3600,
    );

    expect(app(UpdateService::class)->pendingUpdate()?->version)->toBe('99.0.0');
});

test('a failed github request does not cache anything', function () {
    Http::fake([
        'api.github.com/*' => Http::response(status: 503),
    ]);

    expect(app(UpdateService::class)->refresh())->toBeNull()
        ->and(Cache::has(UpdateService::CACHE_KEY))->toBeFalse();
});

test('a pending update is reported only when the cached release is newer', function () {
    cacheRelease(new Release('99.0.0', 'LaraOwl 99', 'https://example.com'));
    expect(app(UpdateService::class)->pendingUpdate()?->version)->toBe('99.0.0');

    cacheRelease(new Release('0.0.1', 'LaraOwl 0.0.1', 'https://example.com'));
    expect(app(UpdateService::class)->pendingUpdate())->toBeNull();
});

test('checking for updates can be disabled entirely', function () {
    config(['laraowl.update_check.enabled' => false]);
    Http::fake();

    cacheRelease(new Release('99.0.0', 'LaraOwl 99', 'https://example.com'));

    expect(app(UpdateService::class)->pendingUpdate())->toBeNull()
        ->and(app(UpdateService::class)->refresh())->toBeNull();

    Http::assertNothingSent();
});

test('a cold cache defers the check instead of blocking the request', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);

    // The response is produced without ever calling GitHub inline; the deferred
    // callback runs after the response is sent.
    $this->actingAs(instanceOperator())->get(route('teams.index'))->assertOk();

    expect(Cache::get(UpdateService::CACHE_KEY)['release']['version'])->toBe('99.0.0');
});

test('a payload cached by an older version is discarded and re-fetched', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);

    // What an older LaraOwl left behind: a serialized Release, which this cache
    // hands back as an incomplete class rather than a usable object.
    $legacy = throughCacheSerialization(new Release('1.0.7', 'v1.0.7', 'https://example.com/107'));
    Cache::put(UpdateService::CACHE_KEY, ['release' => $legacy], 3600);

    // The stale payload yields no banner, and is replaced by the deferred check.
    $this->actingAs(instanceOperator())
        ->get(route('teams.index'))
        ->assertInertia(fn ($page) => $page->where('update', null));

    expect(Cache::get(UpdateService::CACHE_KEY)['release']['version'])->toBe('99.0.0');
});

test('the instance operator is shown a pending update', function () {
    cacheRelease(new Release('99.0.0', 'LaraOwl 99', 'https://example.com/99'));

    $this->actingAs(instanceOperator())
        ->get(route('teams.index'))
        ->assertInertia(fn ($page) => $page
            ->where('update.version', '99.0.0')
            ->where('update.url', 'https://example.com/99')
            ->where('version', Version::current())
        );
});

test('every user owns a personal team, so team ownership cannot gate the banner', function () {
    $operator = instanceOperator();
    $other = User::factory()->create();

    // Both own a team; only the first user administers the instance.
    expect($operator->ownedTeams()->exists())->toBeTrue()
        ->and($other->ownedTeams()->exists())->toBeTrue()
        ->and($operator->isInstanceOperator())->toBeTrue()
        ->and($other->isInstanceOperator())->toBeFalse();
});

test('users other than the operator are not shown a pending update', function () {
    instanceOperator();
    $other = User::factory()->create();
    Team::factory()->create()->members()->attach($other, ['role' => TeamRole::Owner->value]);

    cacheRelease(new Release('99.0.0', 'LaraOwl 99', 'https://example.com/99'));

    $this->actingAs($other)
        ->get(route('teams.index'))
        ->assertInertia(fn ($page) => $page->where('update', null));
});

test('the operator is not shown a banner when the instance is up to date', function () {
    cacheRelease(new Release(Version::current(), 'Current', 'https://example.com'));

    $this->actingAs(instanceOperator())
        ->get(route('teams.index'))
        ->assertInertia(fn ($page) => $page->where('update', null));
});

test('the update command reports when the instance is up to date', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v'.Version::current())),
    ]);
    Process::fake();

    $this->artisan('laraowl:update')
        ->expectsOutputToContain('LaraOwl is up to date')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

test('the update command with --check reports an available release without installing it', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);
    Process::fake();

    $this->artisan('laraowl:update --check')
        ->expectsOutputToContain('LaraOwl v99.0.0 is available')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

test('the update command with --dry-run prints the steps without running them', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);
    Process::fake();

    $this->artisan('laraowl:update --dry-run')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('git pull --ff-only')
        ->expectsOutputToContain('migrate --force')
        // Without this an upgraded instance renders an empty dashboard: the new
        // rollup tables exist but hold nothing.
        ->expectsOutputToContain('laraowl:rollups:backfill --missing')
        ->assertExitCode(0);

    Process::assertNothingRan();
    expect(app()->isDownForMaintenance())->toBeFalse();
});

test('the update command installs the release in maintenance mode', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);
    Process::fake();

    $this->artisan('laraowl:update --force')
        ->expectsOutputToContain('LaraOwl has been updated to v99.0.0')
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => $process->command === ['git', 'pull', '--ff-only']);
    Process::assertRan(fn ($process) => in_array('migrate', $process->command, true));
    Process::assertRan(fn ($process) => in_array('laraowl:rollups:backfill', $process->command, true));
    Process::assertRan(fn ($process) => in_array('queue:restart', $process->command, true));

    expect(app()->isDownForMaintenance())->toBeFalse();
})->skip(fn () => ! is_dir(base_path('.git')), 'Requires a git checkout.');

test('a failed step aborts the update and lifts maintenance mode', function () {
    Http::fake([
        'api.github.com/*' => Http::response(githubRelease('v99.0.0')),
    ]);
    // Matched on the command array rather than a string pattern: Symfony
    // resolves the executable to an absolute path before the fake sees it.
    Process::fake([
        '*' => fn ($process) => $process->command === ['npm', 'ci']
            ? Process::result(output: '', errorOutput: 'npm exploded', exitCode: 1)
            : Process::result(''),
    ]);

    $this->artisan('laraowl:update --force')
        ->expectsOutputToContain('Step failed: Installing JS dependencies')
        ->assertExitCode(1);

    // Later steps never ran, and the app was brought back up regardless.
    Process::assertNotRan(fn ($process) => in_array('migrate', $process->command, true));
    expect(app()->isDownForMaintenance())->toBeFalse();
})->skip(fn () => ! is_dir(base_path('.git')), 'Requires a git checkout.');

test('the backfill runs after the migrations that create its tables', function () {
    $command = new UpdateLaraOwl;
    $steps = (new ReflectionMethod($command, 'steps'))->invoke($command);
    $labels = array_keys($steps);

    expect(array_search('Running migrations', $labels, true))
        ->toBeLessThan(array_search('Backfilling dashboard rollups', $labels, true));
});

test('the update command fails when github cannot be reached', function () {
    Http::fake([
        'api.github.com/*' => Http::response(status: 500),
    ]);
    Process::fake();

    $this->artisan('laraowl:update --check')
        ->expectsOutputToContain('Could not reach the GitHub releases API')
        ->assertExitCode(1);
});

test('the update command refuses to run when update checks are disabled', function () {
    config(['laraowl.update_check.enabled' => false]);
    Http::fake();

    $this->artisan('laraowl:update')
        ->expectsOutputToContain('Update checks are disabled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
