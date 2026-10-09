<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;

/**
 * 2026_10_07_000003_node_trash_roots (PLAN2.D37): trash_root_id is backfilled
 * deterministically — a node trashed on its own (remembers its parent, has none or
 * hangs under a live one) is its own root, a node trashed with a folder takes that
 * folder's root. The previous shape is rebuilt with the migration's own down().
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

const TRASH_ROOTS_MIGRATION = '2026_10_07_000003_node_trash_roots';

function trashRootsMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_07_000003_node_trash_roots.php';
}

function trashRootsTable(string $entity): string
{
    return app(DatabaseConfig::class)->table($entity);
}

/** Back to the schema before this migration: no trash_root_id column. */
function trashRootsBefore(Connection $db): void
{
    $db->table('migrations')->where('migration', TRASH_ROOTS_MIGRATION)->update(['batch' => 99]);
    trashRootsMigration()->down();
    $db->table('migrations')->where('migration', TRASH_ROOTS_MIGRATION)->delete();
}

/** @param array<string, mixed> $extra */
function trashRootsNode(Connection $db, int|string $drive, int|string|null $parent, string $name, array $extra = []): int|string
{
    return $db->table(trashRootsTable('nodes'))->insertGetId([
        'drive_id' => $drive, 'parent_id' => $parent, 'kind' => 'folder', 'name' => $name,
        'name_key' => $name, 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

function trashRootsOf(Connection $db, int|string $id): mixed
{
    return $db->table(trashRootsTable('nodes'))->where('id', $id)->value('trash_root_id');
}

it('backfills explicit roots, members and leaves live nodes null', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $drive = $db->table(trashRootsTable('drives'))->insertGetId(['owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk', 'created_at' => now(), 'updated_at' => now()]);
    $root = trashRootsNode($db, $drive, null, 'root');
    $db->table(trashRootsTable('drives'))->where('id', $drive)->update(['root_node_id' => $root]);

    $at = now()->subDay();
    $live = trashRootsNode($db, $drive, $root, 'live');
    // an earlier independently trashed child remembers its parent and stays its own root
    $folder = trashRootsNode($db, $drive, $root, 'folder', ['trashed_at' => $at, 'trashed_from_parent_id' => $root]);
    $early = trashRootsNode($db, $drive, $folder, 'early', ['trashed_at' => $at->copy()->subDay(), 'trashed_from_parent_id' => $folder]);
    $member = trashRootsNode($db, $drive, $folder, 'member', ['trashed_at' => $at]);
    $deep = trashRootsNode($db, $drive, $member, 'deep', ['trashed_at' => $at]);
    $underEarly = trashRootsNode($db, $drive, $early, 'under-early', ['trashed_at' => $at]);

    trashRootsBefore($db);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(trashRootsOf($db, $live))->toBeNull()
        ->and(trashRootsOf($db, $root))->toBeNull()
        ->and((string) trashRootsOf($db, $folder))->toBe((string) $folder)
        ->and((string) trashRootsOf($db, $early))->toBe((string) $early)
        ->and((string) trashRootsOf($db, $member))->toBe((string) $folder)
        ->and((string) trashRootsOf($db, $deep))->toBe((string) $folder)
        ->and((string) trashRootsOf($db, $underEarly))->toBe((string) $early);
});

it('is idempotent and deterministic on a second run', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $drive = $db->table(trashRootsTable('drives'))->insertGetId(['owner_type' => 'team', 'owner_id' => 2, 'name' => 'Disk', 'created_at' => now(), 'updated_at' => now()]);
    $root = trashRootsNode($db, $drive, null, 'root');
    $folder = trashRootsNode($db, $drive, $root, 'folder', ['trashed_at' => now(), 'trashed_from_parent_id' => $root]);
    $child = trashRootsNode($db, $drive, $folder, 'child', ['trashed_at' => now()]);

    trashRootsBefore($db);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $first = [trashRootsOf($db, $folder), trashRootsOf($db, $child)];
    trashRootsMigration()->up();

    expect([trashRootsOf($db, $folder), trashRootsOf($db, $child)])->toEqual($first)
        ->and((string) $first[1])->toBe((string) $folder);
});
