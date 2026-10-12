<?php

declare(strict_types=1);

use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Drive\Drive;

it('resolves the configured drive model (swappable)', function (): void {
    expect(DriveResource::getModel())->toBe(Drive::class);
});

it('is read-mostly: drives are provisioned through the API, not the panel', function (): void {
    expect(DriveResource::canCreate())->toBeFalse();
});

it('lists the provisioned drives', function (): void {
    $this->actingAs(DriveBuilder::user());

    $storage = DriveBuilder::storage();

    $found = DriveResource::getModel()::query()->whereKey($storage->getKey())->first();

    expect($found)->toBeInstanceOf(Drive::class)
        ->and($found->used_bytes)->toBeInt()
        ->and($found->disk)->not->toBe('');
});

it('uses drive labels and the drive route slug', function (): void {
    expect(DriveResource::getModelLabel())->toBe('drive')
        ->and(DriveResource::getPluralModelLabel())->toBe('drives')
        ->and(DriveResource::getSlug())->toBe('drives');
});
