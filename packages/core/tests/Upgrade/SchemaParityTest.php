<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

function vaulterSchemaSnapshot(): array
{
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $result = [];
    foreach ($schema->getTableListing() as $table) {
        $table = str_replace('public.', '', $table);
        if (!str_starts_with($table, 'v_')) {
            continue;
        }

        foreach ($schema->getColumns($table) as $column) {
            unset($column['comment']);
            $result[$table]['columns'][$column['name']] = $column;
        }

        foreach ($schema->getIndexes($table) as $index) {
            $result[$table]['indexes'][$index['name']] = $index;
        }

        foreach ($schema->getForeignKeys($table) as $key) {
            $result[$table]['foreign_keys'][$key['name']] = $key;
        }

        // Includes expressions and partial predicates omitted by getIndexes().
        foreach ($db->select('select indexname, indexdef from pg_indexes where schemaname = ? and tablename = ?', ['public', $table]) as $index) {
            $result[$table]['index_sql'][$index->indexname] = $index->indexdef;
        }

        foreach ($result[$table] as &$part) {
            ksort($part);
        }

        unset($part);
    }

    ksort($result);

    return $result;
}

function vaulterSchemaDifferences(array $upgrade, array $fresh): array
{
    $differences = [];
    foreach (array_unique([...array_keys($upgrade), ...array_keys($fresh)]) as $table) {
        foreach (array_unique([...array_keys($upgrade[$table] ?? []), ...array_keys($fresh[$table] ?? [])]) as $part) {
            foreach (array_unique([...array_keys($upgrade[$table][$part] ?? []), ...array_keys($fresh[$table][$part] ?? [])]) as $name) {
                $before = $upgrade[$table][$part][$name] ?? null;
                $after = $fresh[$table][$part][$name] ?? null;
                if ($before !== $after) {
                    $differences[sprintf('%s.%s.%s', $table, $part, $name)] = ['upgrade' => $before, 'fresh' => $after];
                }
            }
        }
    }

    ksort($differences);

    return $differences;
}

it('matches the fresh schema after upgrading each historical strategy', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Schema parity requires PostgreSQL.');
    }

    $this->loadV02Fixture($strategy);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $upgrade = vaulterSchemaSnapshot();
    config()->set('vaulter.features.document_versions', true);
    config()->set('vaulter.features.comments', true);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $differences = vaulterSchemaDifferences($upgrade, vaulterSchemaSnapshot());
    $path = dirname(__DIR__, 4).'/plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.7';
    if (getenv('VAULTER_CAPTURE_PARITY_BASELINE') === '1') {
        file_put_contents(sprintf('%s/parity-baseline-%s.json', $path, $strategy), json_encode($differences, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        expect(true)->toBeTrue();

        return;
    }

    expect($differences)->toBe([]);
})->with(['bigint', 'ulid']);
