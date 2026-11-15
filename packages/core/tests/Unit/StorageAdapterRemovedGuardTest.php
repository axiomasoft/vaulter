<?php

declare(strict_types=1);
use Vaulter\Configuration\VaulterConfig;

/*
 * (Green guard, structurally no red-before — a dead-seam removal,
 * not a behaviour change): `StorageAdapter`/`DefaultStorageAdapter`/
 * `storage_adapter` were a fully-declared seam (contract + default impl +
 * config-bound FQCN resolver) that NOTHING in the package ever called — real CAS
 * persistence goes through the independent StorageDriver/ContentStore seam
 * instead. Removed as a dead artefact of an earlier milestone (its own docblock
 * called itself a "Skeleton"). This guard pins the removal so the dead seam
 * cannot silently reappear.
 */
it('removes the dead StorageAdapter seam entirely, F-P3.1-03', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/vaulter.php';

    // String literals on purpose (not ::class): both classes are DELETED — a
    // ::class reference would make phpstan fail with "class not found" on this
    // very test, which is the point of the guard.
    expect(interface_exists('Vaulter\\Contracts\\Cas\\StorageAdapter'))->toBeFalse()
        ->and(class_exists('Vaulter\\Support\\DefaultStorageAdapter'))->toBeFalse()
        ->and($config)->not->toHaveKey('storage_adapter')
        ->and(method_exists(VaulterConfig::class, 'storageAdapterClass'))->toBeFalse();
});
