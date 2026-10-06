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

it('declares a view page for the read-mostly resources', function (): void {
    expect(DriveResource::getPages())->toHaveKey('view')
        ->and(ActivityResource::getPages())->toHaveKey('view');
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
