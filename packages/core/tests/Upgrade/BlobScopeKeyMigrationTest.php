<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Support\Config;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }
    RefreshDatabaseState::$migrated = false;
});

it('backfills historical blob scopes without moving their objects', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);
    $db = app(VaulterDatabase::class)->connection();
    $table = Config::table('blobs');
    $before = $db->table($table)->orderBy('id')->get()->keyBy('id');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    foreach ($db->table($table)->get() as $blob) {
        $original = $before[$blob->id];
        $expected = match ($original->dedup_scope) {
            'storage' => $original->storage_id === null ? 'legacy:snull' : 'drive:'.$original->storage_id,
            'tenant' => $original->tenant_id === null ? 'legacy:tnull' : 'tenant:'.$original->tenant_id,
            default => 'global',
        };
        expect($blob->scope_key)->toBe($expected)->and($blob->storage_key)->toBe($original->storage_key);
    }
    $schema = $db->getSchemaBuilder();
    expect($schema->hasIndex($table, $table.'_scope_sha_uniq'))->toBeTrue()
        ->and($schema->hasIndex($table, $table.'_dedup_uniq'))->toBeFalse();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000007_blob_scope_key.php';
    $migration->up();
    expect($db->table($table)->whereNull('scope_key')->count())->toBe(0);
    $migration->down();
    expect($schema->hasColumn($table, 'scope_key'))->toBeFalse()
        ->and($schema->hasIndex($table, $table.'_dedup_uniq'))->toBeTrue();
    $migration->up();
})->with(['bigint', 'ulid']);
