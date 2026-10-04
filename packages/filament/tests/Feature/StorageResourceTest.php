<?php

declare(strict_types=1);

use Vaulter\Filament\Resources\StorageResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Drive\Drive;

it('resolves the configured storage model (swappable)', function (): void {
    expect(StorageResource::getModel())->toBe(Drive::class);
});

it('is read-mostly: storages are provisioned by the core, not the panel', function (): void {
    expect(StorageResource::canCreate())->toBeFalse();
});

it('lists the storages the core StorageManager provisioned', function (): void {
    $this->actingAs(DriveBuilder::user());

    $storage = DriveBuilder::storage();

    $found = StorageResource::getModel()::query()->whereKey($storage->getKey())->first();

    expect($found)->toBeInstanceOf(Drive::class)
        ->and($found->used_bytes)->toBeInt()
        ->and($found->disk)->not->toBe('');
});
