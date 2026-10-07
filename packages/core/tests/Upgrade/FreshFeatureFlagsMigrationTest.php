<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;

beforeEach(function (): void {
    app(VaulterDatabase::class)->connection()->getSchemaBuilder()->dropAllTables();
    RefreshDatabaseState::$migrated = false;
});

afterEach(function (): void {
    app(VaulterDatabase::class)->connection()->getSchemaBuilder()->dropAllTables();
    RefreshDatabaseState::$migrated = false;
});

it('migrates and rolls back a fresh installation with all module features disabled', function (): void {
    foreach (array_keys(config('vaulter.features')) as $feature) {
        config()->set('vaulter.features.'.$feature, false);
    }

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
    $entities = ['drives', 'nodes', 'blobs', 'files', 'file_revisions', 'file_revision_blobs', 'node_grants', 'node_shares', 'node_comments', 'node_user_states', 'documents', 'document_revisions', 'upload_sessions', 'upload_parts', 'activity', 'outbox', 'webhook_endpoints', 'webhook_deliveries'];
    foreach ($entities as $entity) {
        expect($schema->hasTable(app(DatabaseConfig::class)->table($entity)))->toBeTrue($entity);
    }

    // extra_data is gone from every table (PLAN2.D41).
    expect($schema->hasColumn(app(DatabaseConfig::class)->table('nodes'), 'extra_data'))->toBeFalse()
        ->and($schema->hasColumn(app(DatabaseConfig::class)->table('blobs'), 'extra_data'))->toBeFalse();
    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    foreach ($entities as $entity) {
        expect($schema->hasTable(app(DatabaseConfig::class)->table($entity)))->toBeFalse($entity);
    }

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($schema->hasTable(app(DatabaseConfig::class)->table('blobs')))->toBeTrue();
});
