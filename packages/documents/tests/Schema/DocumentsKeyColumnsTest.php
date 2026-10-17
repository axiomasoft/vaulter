<?php

declare(strict_types=1);

use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Schema\DocumentsSchema;
use Vaulter\Schema\KeyColumnInspector;
use Vaulter\Schema\ModuleSchemas;

it('declares the host-key columns of its own tables to the key-type check of the kernel', function (): void {
    $schema = app(DocumentsSchema::class);
    $columns = array_map(static fn ($column): string => $column->table.'.'.$column->column.':'.$column->kind, $schema->keyColumns());

    expect($columns)->toBe([
        'node_comments.tenant_id:HK', 'node_comments.author_id:HK', 'node_comments.resolved_by:HK',
        'documents.tenant_id:HK', 'documents.created_by:HK', 'documents.updated_by:HK',
        'document_revisions.tenant_id:HK', 'document_revisions.created_by:HK',
    ]);
    foreach ($schema->keyColumns() as $column) {
        expect($schema->entities())->toContain($column->table);
    }
});

it('adds the documents columns to the key columns the inspector expects of the whole installation', function (): void {
    $inspector = new KeyColumnInspector(
        app(VaulterDatabase::class),
        app(DatabaseConfig::class),
        app(IdsConfig::class),
        app(ModuleSchemas::class),
    );
    $tables = array_unique(array_map(static fn ($column): string => $column->table, $inspector->expected()));

    expect($tables)->toContain('outbox', 'blobs', 'drives', 'nodes', 'activity', 'node_comments', 'documents', 'document_revisions')
        ->and($inspector->expected())->toHaveCount(44);
});
