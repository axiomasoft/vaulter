<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Support\Config;
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

it('normalizes legacy overrides and their constraint names without losing rows', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $db->getSchemaBuilder()->rename('v_nodes', 'v_custom_nodes');
    config()->set('vaulter.table_names.nodes', 'custom_nodes');
    $db->getSchemaBuilder()->rename('v_file_versions', 'v_custom_versions');
    config()->set('vaulter.table_names.file_versions', 'custom_versions');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($db->getSchemaBuilder()->hasTable('v_nodes'))->toBeTrue()
        ->and($db->getSchemaBuilder()->hasTable('v_custom_nodes'))->toBeFalse()
        ->and($db->table('v_nodes')->count())->toBe(40)
        ->and(Config::table('nodes'))->toBe('v_nodes')
        ->and(Config::legacyTable('nodes'))->toBe('v_custom_nodes');
    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    expect($db->getSchemaBuilder()->hasTable('v_custom_nodes'))->toBeTrue();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($db->table('v_nodes')->count())->toBe(40);
});

it('creates historical feature-disabled tables on upgrade', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $schema->drop('v_node_comments');

    config()->set('vaulter.features.comments', false);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($schema->hasTable('v_node_comments'))->toBeTrue();
});
