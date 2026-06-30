<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\DocumentResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Resources\StorageResource;
use Vaulter\Filament\VaulterPlugin;

it('identifies the plugin', function (): void {
    expect(VaulterPlugin::make()->getId())->toBe('vaulter');
});

it('registers the Drive/permissions resources on the panel', function (): void {
    $panel = Filament::getPanel('admin');

    $resources = $panel->getResources();

    expect($resources)->toContain(StorageResource::class)
        ->toContain(NodeResource::class)
        ->toContain(NodeGrantResource::class)
        ->toContain(NodeShareResource::class)
        ->toContain(ActivityResource::class);
});

it('keeps the legacy document resources registered', function (): void {
    $resources = Filament::getPanel('admin')->getResources();

    expect($resources)->toContain(DocumentResource::class);
});

it('localizes the getPlugin cast through current()', function (): void {
    $panel = Filament::getPanel('admin');

    expect(VaulterPlugin::current($panel))->toBeInstanceOf(VaulterPlugin::class);
});

it('respects a disabled resource toggle', function (): void {
    config()->set('vaulter-filament.resources.activity', false);

    $panel = Panel::make()->id('probe');
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(ActivityResource::class)
        ->and($panel->getResources())->toContain(NodeResource::class);
});
