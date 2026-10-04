<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

beforeEach(function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql' || (new ExecutableFinder)->find('psql') === null) {
        $this->markTestSkipped('Upgrade fixtures require PostgreSQL and psql.');
    }
});

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

it('refuses all red fixture conditions before renaming tables', function (): void {
    $this->loadV02Fixture('bigint', preflightRed: true);
    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000001_vaulter_preflight.php';
    try {
        $migration->up();
        test()->fail('Preflight accepted an unsafe fixture.');
    } catch (UpgradePreflightException $upgradePreflightException) {
        expect($upgradePreflightException->problemCode())->toBe('upgrade_preflight_failed');
        foreach (['gone.txt', 'Headless', 'vlegacy_storages', '1 soft-deleted', 'vaulter:upgrade:inspect'] as $needle) {
            expect($upgradePreflightException->getMessage())->toContain($needle);
        }
    }

    expect(fn () => $this->artisan('migrate', ['--force' => true])->run())->toThrow(UpgradePreflightException::class);
    $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
    expect($schema->hasTable('v_storages'))->toBeTrue()->and($schema->hasTable('v_drives'))->toBeFalse();
});

it('refuses soft-deleted drive and file rows', function (string $table): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $id = $db->table($table)->value('id');
    $db->table($table)->where('id', $id)->update(['deleted_at' => '2026-09-29 12:00:00']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000001_vaulter_preflight.php';
    expect(fn () => $migration->up())->toThrow(UpgradePreflightException::class, $table.': 1 soft-deleted');
    expect($db->getSchemaBuilder()->hasTable('v_storages'))->toBeTrue();
})->with(['v_storages', 'v_files']);

it('refuses document nodes when the revisions table is missing', function (): void {
    $this->loadV02Fixture('bigint');
    app(VaulterDatabase::class)->connection()->getSchemaBuilder()->drop('v_node_document_versions');
    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000001_vaulter_preflight.php';
    expect(fn () => $migration->up())->toThrow(UpgradePreflightException::class, 'documents without revisions');
});
