<?php

declare(strict_types=1);

use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Schema\DocumentsSchema;
use Vaulter\Schema\KeyColumnInspector;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

it('declares the host-key columns of its own tables to the key-type check of the kernel', function (): void {
    $schema = app(DocumentsSchema::class);
    $columns = array_map(static fn (KeyColumnExpectation $column): string => $column->table.'.'.$column->column.':'.$column->kind, $schema->keyColumns());

    expect($columns)->toBe([
        'node_comments.tenant_id:HK', 'node_comments.author_id:HK', 'node_comments.resolved_by:HK',
        'documents.tenant_id:HK', 'documents.created_by:HK', 'documents.updated_by:HK',
        'document_revisions.tenant_id:HK', 'document_revisions.created_by:HK',
        'document_payloads.document_id:REF', 'document_sync_states.document_id:REF',
        'document_revision_payloads.revision_id:REF', 'document_revision_sync_states.revision_id:REF',
    ]);
    foreach ($schema->keyColumns() as $column) {
        expect($schema->entities())->toContain($column->table);
    }
});

it('adds the documents columns to the key columns the inspector expects of the whole installation', function (): void {
    $inspector = new KeyColumnInspector(
        app(VaulterDatabase::class),
        app(DatabaseOptions::class),
        app(IdsOptions::class),
        app(ModuleSchemas::class),
    );
    $tables = array_unique(array_map(static fn (KeyColumnExpectation $column): string => $column->table, $inspector->expected()));

    expect($tables)->toContain('outbox', 'blobs', 'drives', 'nodes', 'activity', 'node_comments', 'documents', 'document_revisions')
        ->and($inspector->expected())->toHaveCount(48);
});
