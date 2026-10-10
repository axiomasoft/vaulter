<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

/**
 * 2026_10_10_000004_drive_changes on a 0.2 database: the journal tables appear, every
 * existing drive gets its head at seq 0 and epoch 1, and the journal starts empty.
 * down() drops both tables; running up() again adds only what is missing.
 */
uses(LoadsV02Fixture::class);

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

function driveChangesMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_10_000004_drive_changes.php';
}

it('backfills one head per drive and starts the journal empty, reversibly and idempotently', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $db = app(VaulterDatabase::class)->connection();
    $drives = app(DatabaseConfig::class)->table('drives');
    $heads = app(DatabaseConfig::class)->table('drive_change_heads');
    $changes = app(DatabaseConfig::class)->table('drive_changes');
    $driveIds = $db->table($drives)->orderBy('id')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

    expect($driveIds)->not->toBeEmpty()
        ->and($db->table($heads)->orderBy('drive_id')->pluck('drive_id')->map(static fn (mixed $id): string => (string) $id)->all())->toBe($driveIds)
        ->and($db->table($heads)->where(static fn ($query) => $query->where('last_seq', '!=', 0)->orWhere('pruned_through', '!=', 0)->orWhere('epoch', '!=', 1))->count())->toBe(0)
        ->and($db->table($heads)->whereColumn("{$heads}.tenant_id", '!=', $db->raw("(select tenant_id from {$drives} where {$drives}.id = {$heads}.drive_id)"))->count())->toBe(0)
        ->and($db->table($changes)->count())->toBe(0);

    driveChangesMigration()->up();
    expect($db->table($heads)->count())->toBe(count($driveIds));

    driveChangesMigration()->down();
    expect($db->getSchemaBuilder()->hasTable($heads))->toBeFalse()
        ->and($db->getSchemaBuilder()->hasTable($changes))->toBeFalse();

    driveChangesMigration()->up();
    expect($db->table($heads)->count())->toBe(count($driveIds));
})->with(['bigint', 'ulid']);

it('journals the first mutation of an upgraded drive as seq 1', function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture('bigint');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $db = app(VaulterDatabase::class)->connection();
    $drive = $db->table(app(DatabaseConfig::class)->table('drives'))->where('status', 'active')->orderBy('id')->first();
    $node = $db->table(app(DatabaseConfig::class)->table('nodes'))->where('drive_id', $drive->id)->whereNotNull('parent_id')->whereNull('trashed_at')->orderBy('id')->first();
    $handle = Vaulter::drive(Drive::query()->findOrFail($drive->id))->asSystem('upgrade check');
    $handle->nodes()->rename($node->id, 'renamed-after-upgrade');

    expect($db->table(app(DatabaseConfig::class)->table('drive_changes'))->where('drive_id', $drive->id)->pluck('seq')->map(static fn (mixed $seq): int => (int) $seq)->all())->toBe([1]);
});
