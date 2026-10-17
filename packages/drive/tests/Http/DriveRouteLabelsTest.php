<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Drive\Http\Support\DriveRouteLabels;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;

it('labels only routes the drive mounts, so a renamed route cannot leave a stale label', function (): void {
    $labels = new DriveRouteLabels;

    foreach ([...array_keys($labels->operations()), ...array_keys($labels->throttled())] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

it('gives every path rule an operation the named labels know, and leaves no byte route unlabelled', function (): void {
    $labels = new DriveRouteLabels;
    $known = array_unique(array_values($labels->operations()));

    foreach ($labels->operationPaths() as $rule) {
        expect($known)->toContain($rule['operation']);
    }
    foreach (['vaulter.v1.files.download', 'vaulter.v1.files.revisions.download', 'vaulter.v1.public-shares.download'] as $download) {
        expect($labels->operations()[$download])->toBe('download');
    }
});

it('is merged into the operation map of the kernel with the contributions of the other modules', function (): void {
    $map = app(VaulterHttpOperationMap::class);

    expect($map->operationFor('vaulter.v1.uploads.tus.patch'))->toBe('tus_patch')
        ->and($map->resolve('POST', 'v1/uploads/9/parts'))->toBe('s3_putpart');
});
