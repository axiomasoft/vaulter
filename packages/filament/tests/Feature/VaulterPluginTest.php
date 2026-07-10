<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Resources\StorageResource;
use Vaulter\Filament\VaulterPlugin;

it('identifies the plugin', function (): void {
    expect(VaulterPlugin::make()->getId())->toBe('vaulter');
});

it('loads the package config', function (): void {
    expect(config('vaulter-filament.navigation_group'))->toBe('Vaulter');
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

/*
 * VLT-REMED P3.1 (F-P1.10-03, D13) — ActivityResource is now ALSO gated on the
 * core 'activity' feature flag, on top of its own default-on config toggle.
 * Red-before-fix (D7): before this item, resourceEnabled() consulted ONLY
 * config('vaulter-filament.resources.activity') (default true) — a host that
 * disabled the CORE 'activity' feature (config('vaulter.features.activity',
 * false)) but never touched the Filament toggle still got ActivityResource
 * registered against a subsystem it turned off. This assertion FAILS on the
 * pre-fix code (ActivityResource stays registered).
 */
it('drops ActivityResource when the core activity feature is off, even with its own toggle left on, F-P1.10-03', function (): void {
    config()->set('vaulter.features.activity', false);

    $panel = Panel::make()->id('probe-activity-off');
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(ActivityResource::class)
        ->and($panel->getResources())->toContain(NodeResource::class)
        ->and($panel->getResources())->toContain(StorageResource::class);
});

it('control: ActivityResource registers when the core activity feature is on (default), F-P1.10-03', function (): void {
    expect(config('vaulter.features.activity'))->toBeTrue();

    $panel = Panel::make()->id('probe-activity-on');
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->toContain(ActivityResource::class);
});
