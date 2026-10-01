<?php

declare(strict_types=1);

/*
 * Builds the v0.2.0 data fixture for one configuration (PLAN2 P0.3).
 *
 *   php scripts/fixtures/make-v0.2-dump.php <pgsql|mysql> <bigint|ulid> [main|preflight-red] [--migrate-only]
 *
 * Boots a Testbench application from the `v0.2.0` worktree (FIXTURE_WORKTREE,
 * default build/fixtures/v0.2.0) — never the current main code — runs the tag's
 * own core migrations on an EMPTY schema and seeds it through seed-v0.2.php.
 * The caller (make-v0.2-dump.sh) recreates the schema before and dumps after.
 */

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$migrateOnly = in_array('--migrate-only', $argv, true);
[$driver, $strategy, $variant] = $args + [null, null, 'main'];

if (!in_array($driver, ['pgsql', 'mysql'], true) || !in_array($strategy, ['bigint', 'ulid'], true)
    || !in_array($variant, ['main', 'preflight-red'], true)) {
    fwrite(STDERR, "Usage: make-v0.2-dump.php <pgsql|mysql> <bigint|ulid> [main|preflight-red] [--migrate-only]\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$worktree = getenv('FIXTURE_WORKTREE') ?: $root.'/build/fixtures/v0.2.0';
$database = getenv('DB_DATABASE') ?: 'vaulter_test';

if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "make-v0.2-dump: database must end with _test: {$database}\n");
    exit(1);
}

if (!is_file($worktree.'/vendor/autoload.php')) {
    fwrite(STDERR, "make-v0.2-dump: no installed v0.2.0 worktree at {$worktree}\n");
    exit(1);
}

require $worktree.'/vendor/autoload.php';

$env = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'DB_CONNECTION' => $driver,
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
    'DB_DATABASE' => $database,
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'vaulter',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'secret',
    // The tag's config reads these at boot: the id strategy fixes the schema key
    // types, so it must be in place before the migrations run.
    'VAULTER_ID_STRATEGY' => $strategy,
    'VAULTER_DEDUP_SCOPE' => 'storage',
];

$app = Application::create(
    basePath: null,
    resolvingCallback: static function ($app): void {
        $app->booting(static function ($app): void {
            $app['config']->set('cache.default', 'array');
            $app['config']->set('vaulter.middleware', []);
            $app['config']->set('vaulter.drivers.permission', 'null');
            // Opt-in surfaces of 0.2 whose data the 0.3 upgrade must carry: the
            // document version history table only exists with document_versions.
            $app['config']->set('vaulter.features.document_versions', true);
            $app['config']->set('vaulter.features.share_links', true);
            $app['config']->set('vaulter.features.comments', true);
        });
    },
    options: [
        'extra' => [
            'env' => array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($env), $env),
            'dont-discover' => ['*'],
            'providers' => [
                VaulterServiceProvider::class,
                VaulterMediaServiceProvider::class,
                VaulterDriveServiceProvider::class,
                VaulterDocumentsServiceProvider::class,
            ],
        ],
    ],
);

if (config('vaulter.ids.default') !== $strategy || DB::connection()->getDriverName() !== $driver
    || config('vaulter.features.document_versions') !== true) {
    fwrite(STDERR, 'make-v0.2-dump: config did not take: ids='.config('vaulter.ids.default').' driver='.DB::connection()->getDriverName()."\n");
    exit(1);
}

// The migrator directly (not `artisan migrate`): a failing tag migration must
// abort this script, and the console call reports success after rendering it.
$migrations = $worktree.'/packages/core/database/migrations';
$migrator = $app->make('migrator');

if (!$migrator->repositoryExists()) {
    $migrator->getRepository()->createRepository();
}

try {
    $migrator->run([$migrations]);
} catch (Throwable $e) {
    fwrite(STDERR, 'make-v0.2-dump: v0.2.0 migration failed: '.$e->getMessage()."\n");
    exit(1);
}

$expected = count(glob($migrations.'/*.php') ?: []);
$ran = DB::table('migrations')->count();

if ($ran !== $expected) {
    fwrite(STDERR, "make-v0.2-dump: {$ran} of {$expected} v0.2.0 migrations ran\n");
    exit(1);
}

if ($migrateOnly) {
    exit(0);
}

$seed = require __DIR__.'/seed-v0.2.php';

try {
    $seed($app, $strategy, $variant);
} catch (Throwable $e) {
    fwrite(STDERR, 'make-v0.2-dump: seeding failed: '.$e::class.': '.$e->getMessage()."\n");
    exit(1);
}

exit(0);
