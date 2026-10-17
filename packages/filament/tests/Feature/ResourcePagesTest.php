<?php

declare(strict_types=1);

use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;

it('declares an index page for every Drive resource', function (string $resource): void {
    $pages = $resource::getPages();

    expect($pages)->toHaveKey('index');
})->with([
    DriveResource::class,
    NodeResource::class,
    NodeGrantResource::class,
    NodeShareResource::class,
    ActivityResource::class,
]);

it('declares no record page: every row is a snapshot with no model to open', function (): void {
    foreach ([DriveResource::class, NodeResource::class, NodeGrantResource::class, NodeShareResource::class, ActivityResource::class] as $resource) {
        expect(array_keys($resource::getPages()))->toBe(['index']);
    }
});

it('lists every Drive resource under the Vaulter navigation group', function (string $resource): void {
    expect($resource::getNavigationGroup())->toBe('Vaulter');
})->with([
    DriveResource::class,
    NodeResource::class,
    NodeGrantResource::class,
    NodeShareResource::class,
    ActivityResource::class,
]);
