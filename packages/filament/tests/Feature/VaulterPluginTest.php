<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Packman\Filament\Contracts\FilamentPanelPlugin;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\NodeGrantResource;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Filament\VaulterPlugin;

it('identifies the plugin', function (): void {
    expect(VaulterPlugin::make()->getId())->toBe('vaulter');
});

it('loads the package config', function (): void {
    expect(config('vaulter-filament.navigation_group'))->toBe('Vaulter')
        ->and(config('vaulter-filament.resources.drive'))->toBeTrue()
        ->and(config('vaulter-filament.resources'))->not->toHaveKey('storage');
});

it('reads the configured navigation group instead of the hardcoded label', function (): void {
    config()->set('vaulter-filament.navigation_group', 'Files');

    expect(DriveResource::getNavigationGroup())->toBe('Files')
        ->and(NodeResource::getNavigationGroup())->toBe('Files');
});

it('registers the Drive/permissions resources on the panel', function (): void {
    $panel = Filament::getPanel('admin');

    $resources = $panel->getResources();

    expect($resources)->toContain(DriveResource::class)
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

it('keeps resources enabled for an omitted or malformed host toggle', function (mixed $value, string $panelId): void {
    if ($value === null) {
        $resources = config('vaulter-filament.resources');
        unset($resources['share']);
        config()->set('vaulter-filament.resources', $resources);
    } else {
        config()->set('vaulter-filament.resources.share', $value);
    }

    $panel = Panel::make()->id($panelId);
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->toContain(NodeShareResource::class);
})->with([
    'omitted toggle uses the default' => [null, 'probe-share-default'],
    'non-boolean toggle fails open to the documented default' => ['disabled', 'probe-share-malformed'],
]);

/*
 * ActivityResource is now ALSO gated on the
 * core 'activity' feature flag, on top of its own default-on config toggle.
 * Red-before-fix: before this item, resourceEnabled() consulted ONLY
 * config('vaulter-filament.resources.activity') (default true) — a host that
 * disabled the CORE 'activity' feature (config('vaulter-drive.features.activity',
 * false)) but never touched the Filament toggle still got ActivityResource
 * registered against a subsystem it turned off. This assertion FAILS on the
 * pre-fix code (ActivityResource stays registered).
 */
it('drops ActivityResource when the core activity feature is off, even with its own toggle left on, F-P1.10-03', function (): void {
    config()->set('vaulter-drive.features.activity', false);

    $panel = Panel::make()->id('probe-activity-off');
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(ActivityResource::class)
        ->and($panel->getResources())->toContain(NodeResource::class)
        ->and($panel->getResources())->toContain(DriveResource::class);
});

it('control: ActivityResource registers when the core activity feature is on (default), F-P1.10-03', function (): void {
    expect(config('vaulter-drive.features.activity'))->toBeTrue();

    $panel = Panel::make()->id('probe-activity-on');
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->toContain(ActivityResource::class);
});

it('respects the canonical drive resource toggle', function (): void {
    config()->set('vaulter-filament.resources.drive', false);

    $panel = Panel::make()->id('probe-drive-off');
    VaulterPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(DriveResource::class)
        ->and($panel->getResources())->toContain(NodeResource::class);
});

it('enforces shared Packman resource authorization and preserves host gate decisions', function (): void {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    expect(VaulterPlugin::current($panel))->toBeInstanceOf(FilamentPanelPlugin::class)
        ->and(DriveResource::canViewAny())->toBeFalse();

    $this->actingAs(DriveBuilder::user(901));
    expect(DriveResource::canViewAny())->toBeTrue();

    Gate::define('vaulter.drive.view_any', static fn (): bool => false);
    expect(DriveResource::canViewAny())->toBeFalse();

    Gate::define('vaulter.drive.view_any', static fn (): bool => true);
    expect(DriveResource::canViewAny())->toBeTrue();
});
