<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;

/**
 * PLAN2.F09 / EV290: rolling blob_scope_key back over equal-byte blobs (dedup scope
 * "off") cannot be done without losing data, so the newer guard migration refuses
 * before the frozen down() runs any DDL, and says which blobs are in the way. A
 * rollback that fits the old index keeps every blob, CAS edge, payload key and tenant.
 */
beforeEach(function (): void {
    if (!in_array(app(VaulterDatabase::class)->driver(), ['pgsql', 'mysql'], true)) {
        $this->markTestSkipped('The frozen blob scope rollback changes column types and runs on PostgreSQL and MySQL.');
    }
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->transactionLevel() === 0 && str_ends_with($db->getDatabaseName(), '_test')) {
        $db->getSchemaBuilder()->dropAllTables();
    }
    RefreshDatabaseState::$migrated = false;
});

function offDedupGuard(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_07_000008_blob_scope_rollback_guard.php';
}

function offDedupFrozen(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_01_000007_blob_scope_key.php';
}

function offDedupTable(string $entity): string
{
    return app(DatabaseConfig::class)->table($entity);
}

function offDedupBlob(Connection $db, string $sha, string $scopeKey, ?string $tenant = 'A', string $dedup = 'tenant'): int|string
{
    return $db->table(offDedupTable('blobs'))->insertGetId([
        'tenant_id' => $tenant, 'sha256' => $sha, 'size_bytes' => 10, 'disk' => 'local', 'storage_key' => $scopeKey.'/'.$sha,
        'status' => 'committed', 'ref_count' => 1, 'dedup_scope' => $dedup, 'scope_key' => $scopeKey, 'first_seen_at' => now(), 'committed_at' => now(),
    ]);
}

/** A file revision holding an edge to the blob, the CAS truth. */
function offDedupEdge(Connection $db, int|string $blobId, string $sha, string $name): void
{
    $drive = $db->table(offDedupTable('drives'))->insertGetId(['owner_type' => 'team', 'owner_id' => random_int(1, 1_000_000), 'name' => 'Disk', 'created_at' => now(), 'updated_at' => now()]);
    $node = $db->table(offDedupTable('nodes'))->insertGetId(['drive_id' => $drive, 'parent_id' => null, 'kind' => 'file', 'name' => $name, 'name_key' => $name, 'created_at' => now(), 'updated_at' => now()]);
    $file = $db->table(offDedupTable('files'))->insertGetId(['node_id' => $node, 'size_bytes' => 10, 'revision_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $revision = $db->table(offDedupTable('file_revisions'))->insertGetId(['file_id' => $file, 'number' => 1, 'sha256' => $sha, 'size_bytes' => 10, 'original_name' => $name,
        'status' => 'committed', 'created_at' => now(), 'committed_at' => now()]);
    $db->table(offDedupTable('file_revision_blobs'))->insert(['file_revision_id' => $revision, 'blob_id' => $blobId, 'role' => 'data', 'ordinal' => 0]);
}

it('refuses before any DDL when equal-byte off blobs cannot fit the old unique index, naming them', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $sha = str_repeat('c', 64);
    $first = offDedupBlob($db, $sha, 'off:01ARZ3NDEKTSV4RRFFQ69G5FAV', dedup: 'off');
    $second = offDedupBlob($db, $sha, 'off:01ARZ3NDEKTSV4RRFFQ69G5FAW', dedup: 'off');
    offDedupEdge($db, $first, $sha, 'one.bin');
    offDedupEdge($db, $second, $sha, 'two.bin');
    $blobs = offDedupTable('blobs');
    $before = $db->table($blobs)->orderBy('id')->get()->toArray();

    try {
        offDedupGuard()->down();
        $this->fail('The guard must refuse equal-byte blobs.');
    } catch (UpgradePreflightException $exception) {
        expect($exception->getMessage())->toContain(substr($sha, 0, 12))->toContain('held by 2 blobs')->toContain('no table was changed');
    }

    $schema = $db->getSchemaBuilder();
    expect($schema->hasColumn($blobs, 'scope_key'))->toBeTrue()
        ->and($schema->hasIndex($blobs, $blobs.'_scope_sha_uniq'))->toBeTrue()
        ->and($schema->hasIndex($blobs, $blobs.'_dedup_uniq'))->toBeFalse()
        ->and($db->table($blobs)->orderBy('id')->get()->toArray())->toEqual($before)
        ->and($db->table(offDedupTable('file_revision_blobs'))->count())->toBe(2);
});

it('also refuses through a real migrate:rollback that reaches the frozen migration', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $sha = str_repeat('d', 64);
    offDedupBlob($db, $sha, 'off:01ARZ3NDEKTSV4RRFFQ69G5FAX', dedup: 'off');
    offDedupBlob($db, $sha, 'off:01ARZ3NDEKTSV4RRFFQ69G5FAY', dedup: 'off');
    $steps = $db->table('migrations')->where('migration', '>=', '2026_10_01_000007_blob_scope_key')->count();
    $applied = $db->table('migrations')->count();
    $blobs = offDedupTable('blobs');

    expect(fn () => $this->artisan('migrate:rollback', ['--force' => true, '--step' => $steps])->run())->toThrow(UpgradePreflightException::class);

    expect($db->table('migrations')->count())->toBe($applied)
        ->and($db->getSchemaBuilder()->hasColumn($blobs, 'scope_key'))->toBeTrue()
        ->and($db->table($blobs)->count())->toBe(2);
});

it('rolls back and forward again without losing blobs, edges, payload keys or tenant scope when the index fits', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $blobs = offDedupTable('blobs');
    $a = offDedupBlob($db, str_repeat('a', 64), 'tenant:A', 'A');
    $b = offDedupBlob($db, str_repeat('b', 64), 'tenant:B', 'B');
    // Same bytes in two tenants are two blobs and fit (tenant_id, sha256).
    $c = offDedupBlob($db, str_repeat('a', 64), 'tenant:B', 'B');
    offDedupEdge($db, $a, str_repeat('a', 64), 'a.bin');
    offDedupEdge($db, $b, str_repeat('b', 64), 'b.bin');
    offDedupEdge($db, $c, str_repeat('a', 64), 'c.bin');
    $snapshot = static fn (): array => $db->table($blobs)->orderBy('id')->get(['id', 'tenant_id', 'sha256', 'storage_key', 'scope_key', 'dedup_scope', 'status', 'ref_count'])->toArray();
    $before = $snapshot();
    $edges = $db->table(offDedupTable('file_revision_blobs'))->orderBy('blob_id')->get()->toArray();

    offDedupGuard()->down(); // passes: nothing in the way
    offDedupFrozen()->down();
    expect($db->getSchemaBuilder()->hasColumn($blobs, 'scope_key'))->toBeFalse();
    offDedupFrozen()->up();

    expect($snapshot())->toEqual($before)
        ->and($db->table(offDedupTable('file_revision_blobs'))->orderBy('blob_id')->get()->toArray())->toEqual($edges);
});

it('ignores rows whose key part is null, which never collide in a unique index', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $sha = str_repeat('e', 64);
    offDedupBlob($db, $sha, 'legacy:tnull-1', null);
    offDedupBlob($db, $sha, 'legacy:tnull-2', null);

    offDedupGuard()->down();

    expect($db->table(offDedupTable('blobs'))->count())->toBe(2);
});
