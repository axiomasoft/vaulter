<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

beforeEach(function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql' || (new ExecutableFinder)->find('psql') === null) {
        $this->markTestSkipped('Upgrade fixture requires PostgreSQL and psql.');
    }
});
afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }
    RefreshDatabaseState::$migrated = false;
});

function hostKeyFixtureColumns(): array
{
    return [
        'drives' => ['tenant_id', 'owner_id', 'created_by'],
        'nodes' => ['tenant_id', 'owner_id', 'trashed_by', 'created_by', 'updated_by'],
        'blobs' => ['tenant_id'],
        'files' => ['tenant_id', 'created_by'],
        'file_revisions' => ['tenant_id', 'created_by'],
        'node_grants' => ['tenant_id', 'subject_id', 'granted_by'],
        'node_shares' => ['tenant_id', 'granted_by'],
        'node_comments' => ['tenant_id', 'author_id', 'resolved_by'],
        'node_document_versions' => ['tenant_id', 'created_by'],
        'upload_sessions' => ['tenant_id', 'created_by'],
        'activity' => ['tenant_id', 'actor_id'],
    ];
}

it('widens all 27 fixture host columns without losing values and rolls back', function (string $strategy): void {
    $this->loadV02Fixture($strategy);
    $db = app(VaulterDatabase::class)->connection();
    $before = [];
    foreach (hostKeyFixtureColumns() as $entity => $columns) {
        // Only the host-key columns: later upgrade steps in the same batch legitimately
        // change other columns (the N04 repair re-parents nodes) and add activity rows.
        $legacy = app(DatabaseConfig::class)->legacyTable(match ($entity) {
            'drives' => 'storages', 'file_revisions' => 'file_versions', default => $entity,
        });
        $query = $db->table($legacy);
        if ($entity === 'node_grants') {
            $query->whereNull('deleted_at');
        }
        $before[$entity] = $query->orderBy('id')->get(['id', ...$columns])->map(static function ($row) use ($columns): array {
            $values = (array) $row;
            foreach ($columns as $column) {
                $values[$column] = $values[$column] === null ? null : (string) $values[$column];
            }

            return $values;
        })->all();
    }

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $count = 0;
    foreach (hostKeyFixtureColumns() as $entity => $columns) {
        expect($db->table(app(DatabaseConfig::class)->table($entity))->whereIn('id', array_column($before[$entity], 'id'))->orderBy('id')
            ->get(['id', ...$columns])->map(static fn ($row): array => (array) $row)->all())->toBe($before[$entity]);
        foreach ($columns as $column) {
            $metadata = $db->table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', app(DatabaseConfig::class)->table($entity))->where('column_name', $column)->first();
            expect($metadata->data_type)->toBe('character varying')
                ->and($metadata->character_maximum_length)->toBe(64);
            $count++;
        }
    }
    expect($count)->toBe(27);
    $owners = $db->table(app(DatabaseConfig::class)->table('drives'))->orderBy('owner_id')->pluck('owner_id')->all();
    expect($owners)->toBe($strategy === 'bigint' ? ['1', '2', '3'] : [
        '01ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FAW', '01ARZ3NDEKTSV4RRFFQ69G5FAX',
    ]);
    $queries = [];
    $db->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000003_host_key_columns.php';
    $migration->up();
    expect(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')))->toBe([]);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    // The upgrade migrations run as one batch; rolling the batch back reaches this one
    // whatever later upgrade steps were added after it.
    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    foreach (hostKeyFixtureColumns() as $entity => $columns) {
        foreach ($columns as $column) {
            $metadata = $db->table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', app(DatabaseConfig::class)->legacyTable(match ($entity) {
                    'drives' => 'storages', 'file_revisions' => 'file_versions', default => $entity,
                }))->where('column_name', $column)->first();
            expect($metadata->data_type)->toBe($strategy === 'bigint' ? 'bigint' : 'character');
        }
    }
})->with(['bigint', 'ulid']);

it('refuses a nonnumeric host-key conversion without changing fixture rows', function (): void {
    $this->loadV02Fixture('ulid');
    $db = app(VaulterDatabase::class)->connection();
    (require __DIR__.'/../../database/migrations/2026_10_01_000002_rename_storages_to_drives.php')->up();
    $owners = $db->table(app(DatabaseConfig::class)->table('drives'))->orderBy('id')->pluck('owner_id')->all();
    config()->set('vaulter.ids.host_keys', 'bigint');
    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000003_host_key_columns.php';
    expect(fn () => $db->transaction(fn () => $migration->up()))->toThrow(QueryException::class);
    expect($db->table(app(DatabaseConfig::class)->table('drives'))->orderBy('id')->pluck('owner_id')->all())->toBe($owners);
});
