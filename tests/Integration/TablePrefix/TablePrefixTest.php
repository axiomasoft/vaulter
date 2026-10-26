<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Drive\Models\Node;

it('creates every table with the configured prefix', function (): void {
    expect(Schema::hasTable('custom_nodes'))->toBeTrue()
        ->and(Schema::hasTable('custom_drives'))->toBeTrue()
        ->and(Schema::hasTable('custom_blobs'))->toBeTrue()
        ->and(Schema::hasTable('custom_files'))->toBeTrue()
        ->and(Schema::hasTable('custom_node_grants'))->toBeTrue()
        ->and(Schema::hasTable('custom_node_shares'))->toBeTrue()
        ->and(Schema::hasTable('v_nodes'))->toBeFalse();
});

it('resolves config and model tables through the prefix', function (): void {
    expect(app(DatabaseConfig::class)->table('nodes'))->toBe('custom_nodes')
        ->and((new Node)->getTable())->toBe('custom_nodes');
});
