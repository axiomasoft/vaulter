<?php

declare(strict_types=1);

use Vaulter\Kernel;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Modules\Cardinality;

// The media module owns two config-selected capabilities (knowledge/p3-modules#slots): the storage engine the
// host picks in `vaulter-media.storage_driver` and the optional content scanner of `vaulter-drive.scan.scanner`.

it('defines the storage driver as a required single capability resolved from config, Flysystem by default', function (): void {
    $kernel = app(Kernel::class);
    $definition = $kernel->compiled()->capabilities[StorageDriver::class];

    expect($definition->cardinality)->toBe(Cardinality::RequiredSingle)
        ->and($kernel->capabilities()->has(StorageDriver::class))->toBeTrue()
        ->and($kernel->capabilities()->require(StorageDriver::class))->toBeInstanceOf(FlysystemStorageDriver::class)
        ->and(app(StorageDriver::class))->toBeInstanceOf(FlysystemStorageDriver::class);
});

it('keeps the content scanner optional: none is configured, none is available', function (): void {
    $kernel = app(Kernel::class);

    expect($kernel->compiled()->capabilities[ContentScanner::class]->cardinality)->toBe(Cardinality::OptionalSingle)
        ->and($kernel->capabilities()->has(ContentScanner::class))->toBeFalse();
});
