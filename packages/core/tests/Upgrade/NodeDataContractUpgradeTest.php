<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Log;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Support\NodeName;

/**
 * 2026_10_07_000004: name_key with collision renames, extra_data → properties['legacy'],
 * content times, user states, and the removal of is_pinned/position/extra_data. The
 * pre-P4.11 schema is rebuilt with the migration's own down() over a fresh install, so
 * the path runs on every driver; the 0.2 PostgreSQL fixtures cover the full chain.
 */
beforeEach(function (): void {
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->transactionLevel() === 0 && ($db->getDriverName() === 'sqlite' || str_ends_with($db->getDatabaseName(), '_test'))) {
        $db->getSchemaBuilder()->dropAllTables();
    }
    RefreshDatabaseState::$migrated = false;
});

function nodeDataContractMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_07_000004_node_data_contract.php';
}

/** Back to the schema before this migration, as an upgraded 0.3 install has it. */
function nodeDataContractBefore(Connection $db): void
{
    $db->table('migrations')->where('migration', '2026_10_07_000004_node_data_contract')->update(['batch' => 99]);
    nodeDataContractMigration()->down();
    $db->table('migrations')->where('migration', '2026_10_07_000004_node_data_contract')->delete();
}

/**
 * @param array<string, mixed> $extra
 */
function nodeDataContractNode(Connection $db, int|string $driveId, int|string|null $parentId, string $name, string $kind = 'folder', array $extra = []): int|string
{
    return $db->table(app(DatabaseConfig::class)->table('nodes'))->insertGetId([
        'drive_id' => $driveId, 'parent_id' => $parentId, 'kind' => $kind, 'name' => $name,
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

/** @return array{drive: int|string, root: int|string} */
function nodeDataContractDrive(Connection $db): array
{
    $drives = app(DatabaseConfig::class)->table('drives');
    $drive = $db->table($drives)->insertGetId(['owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk', 'created_at' => now(), 'updated_at' => now()]);
    $root = nodeDataContractNode($db, $drive, null, 'root');
    $db->table($drives)->where('id', $drive)->update(['root_node_id' => $root]);

    return ['drive' => $drive, 'root' => $root];
}

it('upgrades names, legacy data, times and columns, keeping the earliest of colliding siblings', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $nodes = app(DatabaseConfig::class)->table('nodes');
    nodeDataContractBefore($db);
    $schema = $db->getSchemaBuilder();
    expect($schema->hasColumn($nodes, 'name_key'))->toBeFalse()
        ->and($schema->hasColumn($nodes, 'is_pinned'))->toBeTrue();

    ['drive' => $drive, 'root' => $root] = nodeDataContractDrive($db);
    // LOWER() tells these apart; NFC + full case folding does not.
    $nfc = nodeDataContractNode($db, $drive, $root, 'Résumé.pdf', 'file');
    $nfd = nodeDataContractNode($db, $drive, $root, "Re\u{0301}sume\u{0301}.pdf", 'file');
    $sharp = nodeDataContractNode($db, $drive, $root, 'straße');
    $upper = nodeDataContractNode($db, $drive, $root, 'STRASSE');
    $trashed = nodeDataContractNode($db, $drive, $root, 'STRASSE ', extra: ['trashed_at' => now()]);
    $legacy = nodeDataContractNode($db, $drive, $root, 'legacy', extra: ['extra_data' => json_encode(['color' => 'red', 'n' => 5, 'bad key' => ['x' => 1]])]);
    $files = app(DatabaseConfig::class)->table('files');
    $revisions = app(DatabaseConfig::class)->table('file_revisions');
    $file = $db->table($files)->insertGetId(['node_id' => $nfc, 'size_bytes' => 3, 'created_at' => now(), 'updated_at' => now()]);
    $committed = '2026-01-02 03:04:05';
    $revision = $db->table($revisions)->insertGetId(['file_id' => $file, 'number' => 1, 'sha256' => str_repeat('a', 64), 'size_bytes' => 3,
        'original_name' => 'Résumé.pdf', 'status' => 'committed', 'created_by' => '7', 'committed_at' => $committed, 'created_at' => $committed]);
    $db->table($files)->where('id', $file)->update(['current_revision_id' => $revision]);
    Log::spy();

    nodeDataContractMigration()->up();

    $row = static fn (int|string $id): stdClass => $db->table($nodes)->where('id', $id)->first();
    expect($row($nfc)->name)->toBe('Résumé.pdf')
        ->and($row($nfd)->name)->toBe("Re\u{0301}sume\u{0301} (conflict 1).pdf")
        ->and($row($sharp)->name)->toBe('straße')
        ->and($row($upper)->name)->toBe('STRASSE (conflict 1)')
        ->and($row($trashed)->name)->toBe('STRASSE ')
        ->and($row($upper)->name_key)->toBe(NodeName::key('STRASSE (conflict 1)'))
        ->and($db->table($nodes)->where('name_key', '')->count())->toBe(0)
        ->and(json_decode((string) $row($legacy)->properties, true))->toBe(['legacy' => ['bad_key' => '{"x":1}', 'color' => 'red', 'n' => '5']])
        ->and((string) $row($nfc)->content_modified_at)->toBe($committed)
        ->and((string) $row($nfc)->content_modified_by)->toBe('7');

    $activity = $db->table(app(DatabaseConfig::class)->table('activity'))->where('verb', 'node.renamed')->orderBy('target_id')->get();
    expect($activity)->toHaveCount(2)
        ->and($activity->pluck('actor_type')->unique()->all())->toBe(['vaulter:system'])
        ->and(json_decode((string) $activity[0]->context, true))->toBe(['from' => "Re\u{0301}sume\u{0301}.pdf", 'to' => "Re\u{0301}sume\u{0301} (conflict 1).pdf", 'reason' => 'name_key_upgrade']);
    Log::shouldHaveReceived('warning')->twice();

    foreach (['drives', 'nodes', 'blobs', 'files', 'file_revisions', 'node_grants', 'node_shares', 'node_comments', 'documents', 'document_revisions', 'upload_sessions', 'activity'] as $entity) {
        expect($schema->hasColumn(app(DatabaseConfig::class)->table($entity), 'extra_data'))->toBeFalse($entity);
    }
    expect($schema->hasColumn($nodes, 'is_pinned'))->toBeFalse()
        ->and($schema->hasColumn($nodes, 'position'))->toBeFalse()
        ->and($schema->hasColumn($revisions, 'client_modified_at'))->toBeTrue()
        ->and($schema->hasTable(app(DatabaseConfig::class)->table('node_user_states')))->toBeTrue();
    $sibling = collect($schema->getIndexes($nodes))->firstWhere('name', $nodes.'_sibling_uniq');
    expect(array_intersect(['name_key', 'name_key_hash'], $sibling['columns']))->not->toBeEmpty();

    // A second run finds everything in place and changes nothing.
    nodeDataContractMigration()->up();
    expect($db->table(app(DatabaseConfig::class)->table('activity'))->where('verb', 'node.renamed')->count())->toBe(2);
});

it('rolls back to the 0.3 shape and upgrades again to the same state', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $nodes = app(DatabaseConfig::class)->table('nodes');
    nodeDataContractBefore($db);
    ['drive' => $drive, 'root' => $root] = nodeDataContractDrive($db);
    $legacy = nodeDataContractNode($db, $drive, $root, 'legacy', extra: ['extra_data' => json_encode(['color' => 'red'])]);
    nodeDataContractMigration()->up();

    $db->table('migrations')->insert(['migration' => '2026_10_07_000004_node_data_contract', 'batch' => 99]);
    nodeDataContractBefore($db);
    expect(json_decode((string) $db->table($nodes)->where('id', $legacy)->value('extra_data'), true))->toBe(['color' => 'red'])
        ->and($db->getSchemaBuilder()->hasColumn($nodes, 'properties'))->toBeFalse();

    nodeDataContractMigration()->up();
    expect(json_decode((string) $db->table($nodes)->where('id', $legacy)->value('properties'), true))->toBe(['legacy' => ['color' => 'red']]);
});

it('refuses legacy data that cannot fit the properties limits before changing anything', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $nodes = app(DatabaseConfig::class)->table('nodes');
    nodeDataContractBefore($db);
    ['drive' => $drive, 'root' => $root] = nodeDataContractDrive($db);
    $big = nodeDataContractNode($db, $drive, $root, 'big', extra: ['extra_data' => json_encode(['note' => str_repeat('x', 1025)])]);

    expect(fn () => nodeDataContractMigration()->up())->toThrow(UpgradePreflightException::class, 'nodes ['.$big.']');
    expect($db->getSchemaBuilder()->hasColumn($nodes, 'name_key'))->toBeFalse()
        ->and($db->table($nodes)->where('id', $big)->value('extra_data'))->not->toBeNull();
});

it('refuses a name_key column it did not create', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $nodes = app(DatabaseConfig::class)->table('nodes');
    nodeDataContractBefore($db);
    $db->getSchemaBuilder()->table($nodes, static fn ($table) => $table->integer('name_key')->nullable());

    expect(fn () => nodeDataContractMigration()->up())->toThrow(UpgradePreflightException::class, 'name_key');
});

it('creates only the user-state table on a fresh install and drops only it on rollback', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $states = app(DatabaseConfig::class)->table('node_user_states');
    expect($schema->hasTable($states))->toBeTrue();

    nodeDataContractMigration()->down();

    expect($schema->hasTable($states))->toBeFalse()
        ->and($schema->hasColumn(app(DatabaseConfig::class)->table('nodes'), 'name_key'))->toBeTrue();
});
