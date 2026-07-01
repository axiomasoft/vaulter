<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Facades\Vaulter;
use Vaulter\Support\Config;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('derives the ulid strategy from the legacy id_type (BC)', function (): void {
    // ids.default is cleared in UlidTestCase; the strategy must fall back to the
    // legacy id_type=ulid knob so existing installs keep their ulid keys.
    expect(config('vaulter.ids.default'))->toBeNull()
        ->and(config('vaulter.id_type'))->toBe('ulid')
        ->and(Config::idStrategy())->toBe('ulid')
        ->and(Config::usesUlids())->toBeTrue();
});

it('uses ULID keys when configured', function (): void {
    $storage = Vaulter::for(DriveTestHelpers::host())->storage();

    expect($storage->getKey())->toBeString()
        ->and(strlen((string) $storage->getKey()))->toBe(26);

    $this->assertDatabaseHas('v_storages', ['id' => $storage->getKey()]);
});

it('adds the tenant column when tenancy is enabled', function (): void {
    expect(Schema::hasColumn('v_nodes', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('v_files', 'tenant_id'))->toBeTrue();
});
