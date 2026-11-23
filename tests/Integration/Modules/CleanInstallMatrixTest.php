<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\AppSet;
use Vaulter\Integration\Tests\Modules\Fixtures\Apps\AppSets;
use Vaulter\Integration\Tests\Support\Schema\SchemaPresets;
use Vaulter\Integration\Tests\Support\Schema\SchemaSnapshot;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

uses(TestCase::class, DatabaseMigrations::class);

// The clean-install matrix: every downward-closed application set of the package graph (Fixtures/Apps) on an
// empty database — `migrate` creates exactly the tables its modules own, `migrate:rollback` leaves the database empty and no
// reference of the set's owners behind (the pins of the media), `migrate` installs it again. Each set runs on a spread of the
// schema presets of snapshot-configs.json (ids, host keys, table prefix, tenancy; every value of every axis at least once).
//
// The engine is the one the suite runs on (DB_CONNECTION; group `database`): the file is the same on sqlite, pgsql and mysql,
// and the schema of the whole installation is compared with the raw catalog snapshot of THAT engine. A set's application has its own database on
// sqlite; on a server engine it wipes the suite database first (the name ends in _test) and leaves it empty.

afterEach(function (): void {
    // A server engine's sets ran on the suite database and left it empty: the suite's DatabaseMigrations rolls its own
    // schema back after the test, so it gets that schema again.
    if (SuiteDatabaseConnection::engine() !== 'sqlite') {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->assertExitCode(0);
    }
});

/** The presets the matrix installs every set with: together all ids strategies, host key types, both prefixes, both tenancy modes. */
const CLEAN_INSTALL_PRESETS = ['bigint.string.v.off', 'ulid.bigint.custom.on', 'uuid7.uuid.v.on', 'bigint.ulid.custom.off'];

/**
 * Runs `$callback` with `$app` as the container and facade root.
 *
 * @template T
 *
 * @param  Closure(): T $callback
 * @return T
 */
function cleanIn(Application $app, Closure $callback): mixed
{
    $current = Container::getInstance();
    Container::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    try {
        return $callback();
    } finally {
        Container::setInstance($current);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($current instanceof Application ? $current : null);
    }
}

/** @return list<string> the tables of the connection but the migration ledger, sorted */
function cleanTables(): array
{
    $tables = array_values(array_filter(
        array_map(static fn (array $table): string => (string) $table['name'], Schema::getTables(Schema::getCurrentSchemaName())),
        static fn (string $name): bool => $name !== 'migrations',
    ));
    sort($tables);

    return $tables;
}

/** @return list<string> the tables the modules of the set own, as their schema descriptors name them, sorted */
function cleanExpectedTables(Application $app): array
{
    $helpers = $app->make(SchemaHelpers::class);
    $tables = [];
    foreach ($app->make(ModuleSchemas::class)->all() as $schema) {
        foreach ($schema->entities() as $entity) {
            $tables[] = $helpers->table($entity);
        }
    }
    sort($tables);

    return $tables;
}

/** @param array<string, mixed> $options */
function cleanArtisan(Application $app, string $command, array $options = []): void
{
    $console = $app->make(ConsoleKernel::class);
    expect($console->call($command, $options + ['--force' => true]))->toBe(0, $command.': '.$console->output());
}

/** The owner a set pins a blob for: the drive's own, or a host's when there is no drive. */
function cleanOwner(AppSet $set): string
{
    return in_array('drive', $set->modules, true) ? 'drive.revision_blob' : 'host.attachment';
}

/** @return array<string, int> the pins per owner type */
function cleanPins(): array
{
    return app(VaulterDatabase::class)->transaction(static fn (): array => app(BlobPins::class)->countByOwner());
}

function cleanPin(string $owner): void
{
    Storage::fake('local');
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'clean-install '.$owner);
    rewind($stream);
    $blob = app(BlobStore::class)->commit($stream, BlobScope::unshared());
    app(VaulterDatabase::class)->transaction(static function () use ($blob, $owner): void {
        app(BlobPins::class)->pin($blob, $owner, 'ref-1');
    });
}

/**
 * The foreign keys MySQL enforces through an index with a nullable or functional part after the key's own columns, as
 * `table.constraint via index`. InnoDB uses the first index that starts with the key's columns (unique ones sort first),
 * and MySQL 9.7's SQL-layer checks skip a row whose entry in that index holds a NULL, so such a key lets rows through that
 * name a missing parent.
 *
 * @return list<string>
 */
function cleanUnenforcedForeignKeys(): array
{
    $schema = DB::connection()->getDatabaseName();
    $nullable = [];
    foreach (DB::select("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ? AND is_nullable = 'YES'", [$schema]) as $column) {
        $nullable[$column->t.'.'.$column->c] = true;
    }
    $indexes = DB::select(
        'SELECT SUBSTRING_INDEX(t.name, \'/\', -1) AS t, i.name AS i, GROUP_CONCAT(f.name ORDER BY f.pos) AS cols'
        .' FROM information_schema.innodb_indexes i JOIN information_schema.innodb_tables t ON t.table_id = i.table_id'
        .' JOIN information_schema.innodb_fields f ON f.index_id = i.index_id WHERE t.name LIKE ? GROUP BY t.name, i.index_id, i.name ORDER BY i.index_id',
        [$schema.'/%'],
    );
    $keys = DB::select(
        'SELECT table_name AS t, constraint_name AS c, GROUP_CONCAT(column_name ORDER BY ordinal_position) AS cols FROM information_schema.key_column_usage'
        .' WHERE table_schema = ? AND referenced_table_name IS NOT NULL GROUP BY table_name, constraint_name',
        [$schema],
    );
    $unenforced = [];
    foreach ($keys as $key) {
        $columns = explode(',', (string) $key->cols);
        foreach ($indexes as $index) {
            $parts = explode(',', (string) $index->cols);
            if ($index->t !== $key->t || array_slice($parts, 0, count($columns)) !== $columns) {
                continue;
            }
            foreach (array_slice($parts, count($columns)) as $part) {
                if (isset($nullable[$key->t.'.'.$part]) || str_starts_with($part, '!hidden!')) {
                    $unenforced[] = "{$key->t}.{$key->c} via {$index->i}";
                    break;
                }
            }
            break;
        }
    }
    sort($unenforced);

    return $unenforced;
}

/** The snapshot file of the engine the suite runs on for `$preset`. @return array{tables: array<string, array<string, mixed>>} */
function cleanExpectedSnapshot(string $preset): array
{
    $driver = SuiteDatabaseConnection::engine();
    $driver = $driver === 'mariadb' ? 'mysql' : $driver;

    return json_decode((string) file_get_contents(SchemaPresets::FIXTURES.'/expected/'.$driver.'/'.$preset.'.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> the config that makes an application resolve to the preset */
function cleanPresetConfig(string $preset): array
{
    $values = SchemaPresets::get($preset);

    return [
        'vaulter.ids.strategy' => $values['ids'],
        'vaulter.ids.host_keys' => $values['host_keys'],
        'vaulter.database.table_prefix' => $values['table_prefix'],
        'vaulter.tenancy.enabled' => $values['tenancy'],
        'vaulter.tenancy.resolver' => $values['tenancy'] ? MutableTenantResolver::class : null,
    ];
}

it('installs a set from nothing, rolls it back to an empty database without a pin left, and installs it again', function (AppSet $set): void {
    foreach (CLEAN_INSTALL_PRESETS as $preset) {
        $app = FreshApplication::boot([...$set->on, ...cleanPresetConfig($preset)], case: $set->testCase());

        cleanIn($app, function () use ($app, $set, $preset): void {
            expect(SchemaPresets::current($app['config']))->toBe($preset);
            $expected = cleanExpectedTables($app);
            $hasMedia = in_array('media', $set->modules, true);

            try {
                // From nothing: a server database is wiped first, a sqlite one is a new in-memory database.
                cleanArtisan($app, 'db:wipe');
                expect(cleanTables())->toBe([], "$preset: the database is empty before the install");

                cleanArtisan($app, 'migrate');
                expect(cleanTables())->toBe($expected, "{$set->id} $preset: the tables of the set");
                if ($set->id === 'full') {
                    // The whole installation is the schema of the raw catalog oracle of this engine.
                    expect(SchemaSnapshot::differences(cleanExpectedSnapshot($preset), SchemaSnapshot::capture(DB::connection())))->toBe([], "$preset: the schema differs from its snapshot");
                    if (SuiteDatabaseConnection::engine() === 'mysql') {
                        expect(cleanUnenforcedForeignKeys())->toBe([], "$preset: foreign keys MySQL does not enforce");
                    }
                }
                if ($hasMedia) {
                    cleanPin(cleanOwner($set));
                    expect(cleanPins())->toBe([cleanOwner($set) => 1]);
                }

                // CLI rollback: every table of the set goes in reverse order (head foreign keys included), the ledger empties,
                // and the references the set's owners held go with their table.
                cleanArtisan($app, 'migrate:rollback');
                expect(cleanTables())->toBe([], "{$set->id} $preset: tables left after the rollback")
                    ->and(DB::table('migrations')->count())->toBe(0, "{$set->id} $preset: migrations left in the ledger");

                // And again: nothing of the first install is left to collide with or to resurrect.
                cleanArtisan($app, 'migrate');
                expect(cleanTables())->toBe($expected, "{$set->id} $preset: the tables of the second install");
                if ($hasMedia) {
                    expect(cleanPins())->toBe([], "{$set->id} $preset: a pin survived the rollback");
                }
            } finally {
                // A server database is shared with the suite: leave it empty.
                $app->make(ConsoleKernel::class)->call('db:wipe', ['--force' => true]);
            }
        });
    }
})->with(fn (): array => [...AppSets::dataset(), 'full' => [AppSets::full()]]);
