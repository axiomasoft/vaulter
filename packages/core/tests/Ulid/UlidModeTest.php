<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('reads the canonical ulid strategy', function (): void {
    expect(config('vaulter.ids.strategy'))->toBe('ulid')
        ->and(app(IdsConfig::class)->strategy())->toBe('ulid')
        ->and(app(IdsConfig::class)->usesUlids())->toBeTrue();
});

it('uses ULID keys when configured', function (): void {
    $storage = Vaulter::drive(DriveTestHelpers::host())->model();

    expect($storage->getKey())->toBeString()
        ->and(strlen((string) $storage->getKey()))->toBe(26);

    $this->assertDatabaseHas('v_drives', ['id' => $storage->getKey()]);
});

it('adds the tenant column when tenancy is enabled', function (): void {
    expect(Schema::hasColumn('v_nodes', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('v_files', 'tenant_id'))->toBeTrue();
});
