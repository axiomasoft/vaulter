<?php

declare(strict_types=1);

use Vaulter\Collaboration\Schema\CollaborationSchema;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Schema\DocumentsSchema;
use Vaulter\Integration\Tests\Support\Schema\SchemaPresets;
use Vaulter\Integration\Tests\Support\Schema\SchemaSnapshot;

/**
 * The installed schema is pinned as raw catalog text per engine and per id/host-key/prefix/tenancy preset
 * (fixtures/Schema/expected). The 0.x schema it replaced is pinned in fixtures/Schema/original and is read only by
 * `scripts/schema-snapshot.sh --compare`. A deliberate schema change updates `expected` together with its decision;
 * `original` is never rewritten.
 */
function schemaSnapshotFixture(string $kind, string $driver, string $preset): array
{
    $path = sprintf('%s/%s/%s/%s.json', SchemaPresets::FIXTURES, $kind, $driver, $preset);
    expect($path)->toBeFile();

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('installs exactly the expected schema of the current preset', function (): void {
    $connection = app(VaulterDatabase::class)->connection();
    $preset = SchemaPresets::current(config());
    // The documents and collaboration modules are not offered by this harness, so their migrations did not run; the
    // snapshot is the schema of an installation that has them, built by the schema classes their migrations call (the
    // collaboration tables reference v_documents, so documents first).
    foreach ([app(DocumentsSchema::class), app(CollaborationSchema::class)] as $schema) {
        foreach ($schema->entities() as $entity) {
            if (!$connection->getSchemaBuilder()->hasTable(app(DatabaseOptions::class)->table($entity))) {
                $schema->create($entity);
            }
        }
    }

    $differences = SchemaSnapshot::differences(
        schemaSnapshotFixture('expected', $connection->getDriverName(), $preset),
        SchemaSnapshot::capture($connection),
    );

    expect($differences)->toBe([]);
})->group('database');

it('keeps one expected and one original snapshot for every preset on every engine', function (string $driver): void {
    $presets = array_keys(SchemaPresets::all());
    sort($presets);

    foreach (['expected', 'original'] as $kind) {
        $files = array_map(
            static fn (string $path): string => basename($path, '.json'),
            glob(sprintf('%s/%s/%s/*.json', SchemaPresets::FIXTURES, $kind, $driver)) ?: [],
        );
        sort($files);

        expect($files)->toBe($presets, $kind.'/'.$driver);
    }
})->with(['sqlite', 'pgsql', 'mysql']);

it('keeps the original tables and adds the declared tables in every preset', function (string $driver): void {
    $metadata = json_decode((string) file_get_contents(SchemaPresets::FIXTURES.'/original/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($metadata['product_tree_clean_vs'])->toBe('c64bba25')
        ->and($metadata['engines'])->toHaveKey($driver);

    $added = [
        'blob_references',
        'collaboration_heads',
        'collaboration_permission_versions',
        'collaboration_receipts',
        'collaboration_updates',
        'document_payloads',
        'document_revision_payloads',
        'document_revision_sync_states',
        'document_sync_states',
        'idempotency_keys',
        'outbox_consumers',
        'outbox_deliveries',
    ];
    foreach (SchemaPresets::all() as $preset => $definition) {
        $original = schemaSnapshotFixture('original', $driver, $preset);
        $expected = schemaSnapshotFixture('expected', $driver, $preset);
        $tables = [...array_keys($original['tables']), ...array_map(
            static fn (string $table): string => $definition['table_prefix'].$table,
            $added,
        )];
        sort($tables, SORT_STRING);
        expect(array_keys($expected['tables']))->toBe($tables, $preset);
    }
})->with(['sqlite', 'pgsql', 'mysql']);

it('names the table, part and constraint that differ, and rewrites nothing', function (): void {
    $connection = app(VaulterDatabase::class)->connection();
    $actual = SchemaSnapshot::capture($connection);
    $table = array_key_first($actual['tables']);
    $changed = $actual;
    unset($changed['tables'][$table]['indexes'][array_key_first($actual['tables'][$table]['indexes'])]);
    $changed['tables']['v_phantom'] = ['columns' => []];

    $differences = SchemaSnapshot::differences($actual, $changed);

    expect($differences)->toHaveCount(2)
        ->and($differences[0])->toStartWith($table.'.indexes.')->toContain('missing')
        ->and($differences[1])->toStartWith('v_phantom')->toContain('unexpected');
})->group('database');
