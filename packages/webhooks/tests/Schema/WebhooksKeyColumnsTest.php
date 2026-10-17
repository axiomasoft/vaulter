<?php

declare(strict_types=1);

use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\KeyColumnInspector;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Webhooks\Schema\WebhooksSchema;

it('declares the key columns of its own tables to the key-type check of the kernel', function (): void {
    $schema = app(WebhooksSchema::class);
    $columns = array_map(static fn ($column): string => $column->table.'.'.$column->column.':'.$column->kind, $schema->keyColumns());

    expect($columns)->toBe([
        'webhook_endpoints.tenant_id:HK',
        'webhook_endpoints.drive_id:REF',
        'webhook_deliveries.endpoint_id:REF',
    ]);
    foreach ($schema->keyColumns() as $column) {
        expect($schema->entities())->toContain($column->table);
    }
});

it('adds the webhooks columns to the key columns the inspector expects of the whole installation', function (): void {
    $inspector = new KeyColumnInspector(
        app(VaulterDatabase::class),
        app(DatabaseConfig::class),
        app(IdsConfig::class),
        app(ModuleSchemas::class),
    );
    $expected = array_map(static fn ($column): string => $column->table.'.'.$column->column, $inspector->expected());

    expect($expected)->toContain('webhook_endpoints.tenant_id', 'webhook_endpoints.drive_id', 'webhook_deliveries.endpoint_id');
});
