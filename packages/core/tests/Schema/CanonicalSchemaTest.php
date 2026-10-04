<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

it('creates the canonical schema independently of runtime features and legacy table overrides', function (): void {
    $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
    config()->set('vaulter.table_names.drives', 'ignored_drives');
    expect(Config::table('drives'))->toBe('v_drives');
    foreach (['drives', 'file_revisions', 'file_revision_blobs', 'node_document_versions', 'node_comments'] as $entity) {
        expect($schema->hasTable(Config::table($entity)))->toBeTrue();
    }

    foreach (['drives' => ['profile'], 'nodes' => ['drive_id', 'profile'], 'blobs' => ['drive_id'],
        'files' => ['current_revision_id', 'revision_count'], 'file_revisions' => ['number', 'sha256', 'declared_mime_type', 'scan_status'],
        'file_revision_blobs' => ['file_revision_id'], 'upload_sessions' => ['drive_id', 'target_node_id', 'result_node_id', 'result_revision_id'],
        'activity' => ['drive_id', 'event_id'], 'outbox' => ['drive_id', 'aggregate_type', 'aggregate_id', 'sequence']] as $entity => $columns) {
        expect($schema->hasColumns(Config::table($entity), $columns))->toBeTrue();
    }

    foreach (['drives' => ['storage_type', 'slug', 'default_node_permissions', 'deleted_at'], 'files' => ['deleted_at'],
        'node_grants' => ['deleted_at', 'inherited', 'inherited_from_node_id', 'grant_active'], 'node_shares' => ['deleted_at']] as $entity => $columns) {
        foreach ($columns as $column) {
            expect($schema->hasColumn(Config::table($entity), $column))->toBeFalse();
        }
    }

    expect($schema->hasTable('v_storages'))->toBeFalse();
    $indexes = array_column($schema->getIndexes('v_node_grants'), null, 'name');
    expect($indexes['v_node_grants_grant_uniq']['columns'])->toBe(['node_id', 'subject_type', 'subject_id', 'scope', 'effect']);
    config()->set('vaulter.features', array_fill_keys(['blobs', 'versions', 'grants', 'share_links', 'comments', 'document_versions', 'uploads', 'activity', 'reliability'], false));
    config()->set('vaulter.extra_data.enabled', false);
    config()->set('vaulter.table_prefix', 'features_off_');
    (new SchemaBuilder)->createAll();
    expect($schema->hasTable('features_off_file_revisions'))->toBeTrue()
        ->and($schema->hasTable('features_off_node_comments'))->toBeTrue()
        ->and($schema->hasColumn('features_off_nodes', 'extra_data'))->toBeFalse();
    (new SchemaBuilder)->dropAll();
});
