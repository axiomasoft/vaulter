<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Quota\QuotaCalculator;

/**
 * 2026_10_07_000007_quota_all_revisions (PLAN2.D48, P4-I18): superseded_at is
 * backfilled from the next revision's commit time and used_bytes is recounted as the
 * sum of every retained revision, trash included. The previous shape is rebuilt with
 * the migration's own down() over a fresh install, so the test runs on every driver.
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

const QUOTA_ALL_REVISIONS_MIGRATION = '2026_10_07_000007_quota_all_revisions';

function quotaAllRevisionsMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_07_000007_quota_all_revisions.php';
}

/** Back to the schema before this migration: no superseded_at, used_bytes over the heads. */
function quotaAllRevisionsBefore(Connection $db): void
{
    $db->table('migrations')->where('migration', QUOTA_ALL_REVISIONS_MIGRATION)->update(['batch' => 99]);
    quotaAllRevisionsMigration()->down();
    $db->table('migrations')->where('migration', QUOTA_ALL_REVISIONS_MIGRATION)->delete();
}

function quotaAllRevisionsTable(string $entity): string
{
    return app(DatabaseConfig::class)->table($entity);
}

function quotaAllRevisionsDrive(Connection $db, int $usedBytes = 0): int|string
{
    static $owner = 0;
    $drive = $db->table(quotaAllRevisionsTable('drives'))->insertGetId(['owner_type' => 'team', 'owner_id' => ++$owner, 'name' => 'Disk', 'used_bytes' => $usedBytes, 'created_at' => now(), 'updated_at' => now()]);
    $root = $db->table(quotaAllRevisionsTable('nodes'))->insertGetId(['drive_id' => $drive, 'parent_id' => null, 'kind' => 'folder', 'name' => 'root', 'name_key' => 'root', 'created_at' => now(), 'updated_at' => now()]);
    $db->table(quotaAllRevisionsTable('drives'))->where('id', $drive)->update(['root_node_id' => $root]);

    return $drive;
}

/**
 * A file whose revisions have these sizes and commit times (oldest first); the last one is the head.
 *
 * @param  list<array{int, CarbonImmutable}> $revisions
 * @return list<int|string>                  revision ids, oldest first
 */
function quotaAllRevisionsFile(Connection $db, int|string $driveId, string $name, array $revisions, bool $trashed = false): array
{
    $root = $db->table(quotaAllRevisionsTable('drives'))->where('id', $driveId)->value('root_node_id');
    $head = end($revisions)[0];
    $node = $db->table(quotaAllRevisionsTable('nodes'))->insertGetId(['drive_id' => $driveId, 'parent_id' => $root, 'kind' => 'file', 'name' => $name, 'name_key' => $name,
        'size_bytes' => $head, 'trashed_at' => $trashed ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    $file = $db->table(quotaAllRevisionsTable('files'))->insertGetId(['node_id' => $node, 'size_bytes' => $head, 'revision_count' => count($revisions), 'created_at' => now(), 'updated_at' => now()]);
    $ids = [];
    foreach ($revisions as $index => [$size, $committed]) {
        $ids[] = $db->table(quotaAllRevisionsTable('file_revisions'))->insertGetId([
            'file_id' => $file, 'number' => $index + 1, 'sha256' => str_repeat('a', 64), 'size_bytes' => $size, 'original_name' => $name,
            'status' => $index === count($revisions) - 1 ? 'committed' : 'superseded', 'created_at' => $committed, 'committed_at' => $committed,
        ]);
    }
    $db->table(quotaAllRevisionsTable('files'))->where('id', $file)->update(['current_revision_id' => end($ids)]);

    return $ids;
}

it('backfills superseded_at and recounts used_bytes over every retained revision', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $revisions = quotaAllRevisionsTable('file_revisions');
    $drives = quotaAllRevisionsTable('drives');
    quotaAllRevisionsBefore($db);
    expect($db->getSchemaBuilder()->hasColumn($revisions, 'superseded_at'))->toBeFalse();

    $t1 = CarbonImmutable::parse('2026-01-01 10:00:00');
    $t2 = CarbonImmutable::parse('2026-02-01 10:00:00');
    $t3 = CarbonImmutable::parse('2026-03-01 10:00:00');
    $drive = quotaAllRevisionsDrive($db, usedBytes: 999);
    $a = quotaAllRevisionsFile($db, $drive, 'a.bin', [[100, $t1], [120, $t2], [100, $t3]]);
    $b = quotaAllRevisionsFile($db, $drive, 'b.bin', [[7, $t1], [5, $t2]], trashed: true);
    $empty = quotaAllRevisionsDrive($db, usedBytes: 50);
    $other = quotaAllRevisionsDrive($db);
    quotaAllRevisionsFile($db, $other, 'c.bin', [[9, $t1]]);

    quotaAllRevisionsMigration()->up();

    $superseded = static fn (int|string $id): ?string => ($value = $db->table($revisions)->where('id', $id)->value('superseded_at')) === null
        ? null : CarbonImmutable::parse($value)->toDateTimeString();
    expect($superseded($a[0]))->toBe($t2->toDateTimeString())
        ->and($superseded($a[1]))->toBe($t3->toDateTimeString())
        ->and($superseded($a[2]))->toBeNull()
        ->and($superseded($b[0]))->toBe($t2->toDateTimeString())
        ->and($superseded($b[1]))->toBeNull()
        ->and((int) $db->table($drives)->where('id', $drive)->value('used_bytes'))->toBe(100 + 120 + 100 + 7 + 5)
        ->and((int) $db->table($drives)->where('id', $empty)->value('used_bytes'))->toBe(0)
        ->and((int) $db->table($drives)->where('id', $other)->value('used_bytes'))->toBe(9)
        ->and(app(QuotaCalculator::class)->usedBytes($drive))->toBe(332)
        ->and(app(QuotaCalculator::class)->trashedBytes($drive))->toBe(12);

    // A second run changes nothing.
    $before = [$db->table($revisions)->orderBy('id')->get()->all(), $db->table($drives)->orderBy('id')->get()->all()];
    quotaAllRevisionsMigration()->up();
    expect([$db->table($revisions)->orderBy('id')->get()->all(), $db->table($drives)->orderBy('id')->get()->all()])->toEqual($before);
});

it('rolls back to the head formula and the previous shape', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $drive = quotaAllRevisionsDrive($db);
    quotaAllRevisionsFile($db, $drive, 'a.bin', [[100, now()->toImmutable()], [30, now()->toImmutable()]]);
    quotaAllRevisionsFile($db, $drive, 'b.bin', [[4, now()->toImmutable()]], trashed: true);
    quotaAllRevisionsMigration()->up();
    expect((int) $db->table(quotaAllRevisionsTable('drives'))->where('id', $drive)->value('used_bytes'))->toBe(134);

    quotaAllRevisionsBefore($db);

    expect($db->getSchemaBuilder()->hasColumn(quotaAllRevisionsTable('file_revisions'), 'superseded_at'))->toBeFalse()
        ->and((int) $db->table(quotaAllRevisionsTable('drives'))->where('id', $drive)->value('used_bytes'))->toBe(34);
});

it('stops on a superseded_at column of another shape', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $revisions = quotaAllRevisionsTable('file_revisions');
    quotaAllRevisionsBefore($db);
    $db->getSchemaBuilder()->table($revisions, static fn (Blueprint $table) => $table->string('superseded_at', 32)->nullable());

    expect(fn () => quotaAllRevisionsMigration()->up())->toThrow(UpgradePreflightException::class, 'superseded_at');
});

it('keeps the column on a fresh install rollback', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
    $db->table('migrations')->where('migration', QUOTA_ALL_REVISIONS_MIGRATION)->update(['batch' => $base]);

    quotaAllRevisionsMigration()->down();

    expect($db->getSchemaBuilder()->hasColumn(quotaAllRevisionsTable('file_revisions'), 'superseded_at'))->toBeTrue();
});
