<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\VaulterManager;

it('merges the vaulter config', function (): void {
    expect(config('vaulter.routes.prefix'))->toBe('api/vaulter')
        ->and(config('vaulter.enabled_document_types'))->toContain('richtext');
});

it('binds the manager as a singleton', function (): void {
    expect(app('vaulter'))->toBeInstanceOf(VaulterManager::class)
        ->and(app('vaulter'))->toBe(app(VaulterManager::class));
});

it('registers the API routes', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    expect($uris)
        ->toContain('api/vaulter/storages/{storage}')
        ->toContain('api/vaulter/storages/{storage}/nodes')
        ->toContain('api/vaulter/nodes/{node}');
});

it('exposes the Vaulter facade', function (): void {
    Vaulter::registerDocumentType('spreadsheet', ['label' => 'Spreadsheet']);

    expect(Vaulter::documentTypes())->toHaveKey('spreadsheet');
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
