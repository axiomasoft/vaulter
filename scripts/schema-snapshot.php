#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Schema snapshot CLI (PLAN3.P1.10). Boots a bare Testbench application with the core, media, drive and
 * documents providers, migrates the packaged migrations of every module onto an empty database and writes (or compares) the raw
 * catalog snapshot of one id/host-key/prefix/tenancy preset on one engine. Schema migrations only — no
 * behavioural test runs here.
 *
 *   php scripts/schema-snapshot.php --list-presets
 *   php scripts/schema-snapshot.php --driver=sqlite --preset=bigint.string.v.off [--output=FILE] [--compare=DIR|FILE]
 *                                   [--rollback]
 *
 * --compare=DIR reads DIR/<driver>/<preset>.json. A mismatch prints every table/part/name that differs and
 * exits 1; nothing is rewritten. The server engines come from DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/...
 * (scripts/schema-snapshot.sh provisions a disposable one); DB_DATABASE must end in _test.
 * --rollback additionally rolls the chain back and requires an empty schema.
 * --mode=builder builds the schema straight from the schema classes of the offered modules (ModuleSchemas::createAll()) instead of the migration chain. It exists
 * for one purpose: the immutable "before" of a preset whose 0.x chain cannot even migrate an empty database.
 */

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Tests\Support\Schema\SchemaPresets;
use Vaulter\Tests\Support\Schema\SchemaSnapshot;
use Vaulter\VaulterServiceProvider;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) !== 1) {
        fwrite(STDERR, "schema-snapshot: unknown argument [$argument]\n");
        exit(2);
    }
    $options[$match[1]] = $match[2] ?? true;
}

if (isset($options['list-presets'])) {
    echo implode("\n", array_keys(SchemaPresets::all())), "\n";
    exit(0);
}

$driver = (string) ($options['driver'] ?? '');
$presetId = (string) ($options['preset'] ?? '');
if (!in_array($driver, ['sqlite', 'pgsql', 'mysql'], true) || $presetId === '') {
    fwrite(STDERR, "usage: schema-snapshot.php --driver=<sqlite|pgsql|mysql> --preset=<id> [--output=FILE] [--compare=DIR|FILE] [--rollback]\n       schema-snapshot.php --list-presets\n");
    exit(2);
}

// The driver of the process is the one asked for; a stray DB_CONNECTION must not silently win.
putenv('DB_CONNECTION='.$driver);
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = $driver;
if ($driver === 'sqlite') {
    $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
}
// The suite guards that apply to every test database apply here: a server schema is dropped and rebuilt.
if ($driver !== 'sqlite' && !str_ends_with((string) getenv('DB_DATABASE'), '_test')) {
    fwrite(STDERR, "schema-snapshot: DB_DATABASE must end with _test for a server engine\n");
    exit(2);
}

try {
    $extra = [
        'providers' => [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
        ],
        'env' => [
            'APP_KEY=base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
            'APP_ENV=testing',
            ...SchemaPresets::environment($presetId),
        ],
    ];

    // Config that must exist before the providers boot is set between configuration loading and provider registration.
    $builder = new class(null) extends Testbench
    {
        protected function resolveApplicationConfiguration($app): void
        {
            parent::resolveApplicationConfiguration($app);
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', SuiteDatabaseConnection::resolve());
            $app['config']->set('cache.default', 'array');
            $app['config']->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
        }
    };
    $app = $builder->configure(['extra' => $extra])->createApplication();

    $resolved = SchemaPresets::current($app['config']);
    if ($resolved !== $presetId) {
        fwrite(STDERR, "schema-snapshot: preset [$presetId] resolved to [$resolved]\n");
        exit(2);
    }

    // The migrations of every offered module, from the schema paths its manifest declares.
    $migrations = array_values(array_unique($app->make(Vaulter\Kernel::class)->compiled()->schemaPaths));
    $kernel = $app->make(Kernel::class);
    $migrate = static function (string $command) use ($kernel, $migrations): void {
        $status = $kernel->call($command, ['--path' => $migrations, '--realpath' => true, '--force' => true, '--database' => 'testing']);
        if ($status !== 0) {
            fwrite(STDERR, $kernel->output());
            exit(3);
        }
    };

    $mode = (string) ($options['mode'] ?? 'chain');
    if ($mode === 'builder') {
        $app['db']->connection('testing')->getSchemaBuilder()->dropAllTables();
        $app->make(ModuleSchemas::class)->createAll();
    } elseif ($mode === 'chain') {
        $migrate('migrate:fresh');
    } else {
        fwrite(STDERR, "schema-snapshot: unknown --mode [$mode]\n");
        exit(2);
    }
    $connection = $app['db']->connection('testing');
    $snapshot = SchemaSnapshot::capture($connection) + ['preset' => $presetId, 'driver' => $driver, 'source' => $mode];

    if (isset($options['rollback'])) {
        if ($mode === 'builder') {
            $app->make(ModuleSchemas::class)->dropAll();
        } else {
            $migrate('migrate:rollback');
        }
        $left = array_keys(SchemaSnapshot::capture($connection)['tables']);
        if ($left !== []) {
            fwrite(STDERR, 'schema-snapshot: rollback left tables: '.implode(', ', $left)."\n");
            exit(1);
        }
    }

    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

    if (is_string($options['output'] ?? null)) {
        if (!is_dir(dirname($options['output']))) {
            mkdir(dirname($options['output']), 0o775, true);
        }
        file_put_contents($options['output'], $json);
    }

    if (is_string($options['compare'] ?? null)) {
        $path = is_dir($options['compare']) ? sprintf('%s/%s/%s.json', rtrim($options['compare'], '/'), $driver, $presetId) : $options['compare'];
        if (!is_file($path)) {
            fwrite(STDERR, "schema-snapshot: no snapshot to compare with at $path\n");
            exit(2);
        }
        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $differences = SchemaSnapshot::differences($expected, $snapshot);
        if ($differences !== []) {
            fwrite(STDERR, "schema-snapshot: $driver/$presetId differs from $path (".count($differences)." differences)\n  ".implode("\n  ", array_slice($differences, 0, 40))."\n");
            exit(1);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'schema-snapshot: '.$exception::class.': '.$exception->getMessage()."\n");
    exit(3);
}

exit(0);
