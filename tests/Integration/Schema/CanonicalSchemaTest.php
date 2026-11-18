<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Schema\DocumentsSchema;
use Vaulter\Schema\ModuleSchemas;

it('creates the canonical schema independently of runtime features and legacy table overrides', function (): void {
    $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
    // The documents module is not offered by this harness, so its migrations did not run: the canonical schema of an
    // installation that has it is built by the schema class those migrations call.
    $documents = app(DocumentsSchema::class);
    foreach ($documents->entities() as $entity) {
        if (!$schema->hasTable(app(DatabaseOptions::class)->table($entity))) {
            $documents->create($entity);
        }
    }
    expect(fn () => VaulterOptions::fromArray(['table_names' => ['drives' => 'ignored_drives']]))
        ->toThrow(InvalidConfiguration::class, 'vaulter.table_names');
    expect(app(DatabaseOptions::class)->table('drives'))->toBe('v_drives');
    foreach (['drives', 'file_revisions', 'file_revision_blobs', 'documents', 'document_revisions', 'node_comments'] as $entity) {
        expect($schema->hasTable(app(DatabaseOptions::class)->table($entity)))->toBeTrue();
    }

    foreach (['drives' => ['profile'], 'nodes' => ['drive_id', 'profile'], 'blobs' => ['storage_id'],
        'files' => ['current_revision_id', 'revision_count'], 'file_revisions' => ['number', 'sha256', 'declared_mime_type', 'scan_status', 'restored_from_revision_id'],
        'documents' => ['node_id', 'type', 'schema_version', 'current_revision_id', 'revision_count', 'collab_epoch', 'state_version', 'checkpoint_state_version', 'content', 'content_hash', 'content_size', 'collab_state', 'collab_state_size', 'last_checkpoint_at'],
        'document_revisions' => ['document_id', 'number', 'schema_version', 'state_version', 'label', 'restored_from_revision_id'],
        'file_revision_blobs' => ['file_revision_id'], 'upload_sessions' => ['drive_id', 'target_node_id', 'result_node_id', 'result_revision_id'],
        'activity' => ['drive_id', 'event_id'], 'outbox' => ['scope_kind', 'scope_id', 'aggregate_type', 'aggregate_id', 'sequence']] as $entity => $columns) {
        expect($schema->hasColumns(app(DatabaseOptions::class)->table($entity), $columns))->toBeTrue();
    }

    foreach (['drives' => ['storage_type', 'slug', 'default_node_permissions', 'deleted_at'], 'files' => ['deleted_at'],
        'nodes' => ['current_version_id', 'file_id', 'deleted_at', 'owner_id'], 'document_revisions' => ['node_id', 'version_no', 'snapshot_label', 'format'],
        'node_grants' => ['deleted_at', 'inherited', 'inherited_from_node_id', 'grant_active', 'level', 'permissions', 'effect'], 'node_shares' => ['deleted_at']] as $entity => $columns) {
        foreach ($columns as $column) {
            expect($schema->hasColumn(app(DatabaseOptions::class)->table($entity), $column))->toBeFalse();
        }
    }

    expect($schema->hasTable('v_storages'))->toBeFalse()
        ->and($schema->hasTable('v_node_document_versions'))->toBeFalse();
    $indexes = array_column($schema->getIndexes('v_node_grants'), null, 'name');
    expect($indexes['v_node_grants_grant_uniq']['columns'])->toBe(['node_id', 'subject_type', 'subject_id', 'scope']);
    config()->set('vaulter-drive.features', array_fill_keys(['grants', 'share_links', 'activity', 'webhooks', 'public_listing', 'spreadsheets'], false));
    config()->set('vaulter.database.table_prefix', 'features_off_');
    app(ModuleSchemas::class)->createAll();
    $documents = app(DocumentsSchema::class);
    array_map($documents->create(...), $documents->entities());
    expect($schema->hasTable('features_off_file_revisions'))->toBeTrue()
        ->and($schema->hasTable('features_off_documents'))->toBeTrue()
        ->and($schema->hasTable('features_off_document_revisions'))->toBeTrue()
        ->and($schema->hasTable('features_off_node_comments'))->toBeTrue()
        ->and($schema->hasColumn('features_off_nodes', 'extra_data'))->toBeFalse();
    array_map($documents->drop(...), array_reverse($documents->entities()));
    app(ModuleSchemas::class)->dropAll();
});
