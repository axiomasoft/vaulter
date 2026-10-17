<?php

declare(strict_types=1);

use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Vaulter as VaulterEntry;

it('merges the vaulter config', function (): void {
    expect(config('vaulter.routes.prefix'))->toBe('api/vaulter')
        ->and(config()->has('vaulter.enabled_document_types'))->toBeFalse();
});

it('binds the Vaulter entry point as a singleton', function (): void {
    expect(app('vaulter'))->toBeInstanceOf(VaulterEntry::class)
        ->and(app('vaulter'))->toBe(app(VaulterEntry::class));
});

it('registers the API routes', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    expect($uris)
        ->toContain('api/vaulter/v1/drives/{drive}')
        ->toContain('api/vaulter/v1/drives/{drive}/nodes')
        ->toContain('api/vaulter/v1/nodes/{node}')
        ->not->toContain('api/vaulter/storages/{storage}');
});

it('exposes the Vaulter facade', function (): void {
    expect(Vaulter::profiles())->toBe(app(ProfileRegistry::class));
});

/*
 * F-P3.2-01 — ProfileRegistry::class was a LAZY singleton (packageRegistered() only
 * binds the closure); nothing forced it during packageBooted(), so a broken panel
 * (ADR-0009's "validated on boot" claim) surfaced only on the first real request
 * that touched a panel. Red-before-fix (D7): before this item,
 * `$this->app->resolved(ProfileRegistry::class)` was FALSE right after boot — this
 * test asserts it is TRUE, without the test itself ever calling
 * app(ProfileRegistry::class) (Container::resolved() reports whether the container
 * has already built the instance, so it cannot be satisfied by the assertion's
 * own act of checking).
 */
it('eagerly resolves (and boot-validates) the panel registry on packageBooted, F-P3.2-01', function (): void {
    expect(app()->resolved(ProfileRegistry::class))->toBeTrue();
});
