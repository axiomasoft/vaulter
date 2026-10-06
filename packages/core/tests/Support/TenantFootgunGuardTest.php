<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\TenantFootgunGuard;
use Vaulter\Tests\Fixtures\TenantAwareNode;

/*
 * F-P1.9-01 (Minor) — the boot-time fail-loud guard against the
 * `vaulter.tenancy.enabled=true` + default (non-scoping) node model
 * misconfiguration. Red-before-fix (D19/D7): before this item TenantFootgunGuard
 * did not exist and packageBooted() never checked this combination — the guard
 * tests below are red against that prior code (no class, no warning, no throw).
 */

it('warns and throws outside production when tenancy is on with the default node model, F-P1.9-01', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.models.node', Node::class);

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'vaulter.tenancy.enabled'),
    );

    expect(fn () => (app(TenantFootgunGuard::class))->check(isProduction: false))
        ->toThrow(TenancyMisconfiguredException::class);
});

it('only warns (does not throw) in production for the same misconfiguration, F-P1.9-01', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.models.node', Node::class);

    Log::shouldReceive('warning')->once();

    (app(TenantFootgunGuard::class))->check(isProduction: true);

    expect(true)->toBeTrue(); // reached without throwing
});

it('does not warn or throw when a non-default node model is configured (the ADR-0003 escape hatch, F-P1.9-01)', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.models.node', TenantAwareNode::class);

    Log::shouldReceive('warning')->never();

    (app(TenantFootgunGuard::class))->check(isProduction: false);

    expect(true)->toBeTrue();
});

it('does not warn or throw when tenancy is disabled, regardless of the node model (F-P1.9-01)', function (): void {
    config()->set('vaulter.tenancy.enabled', false);
    config()->set('vaulter.models.node', Node::class);

    Log::shouldReceive('warning')->never();

    (app(TenantFootgunGuard::class))->check(isProduction: false);

    expect(true)->toBeTrue();
});
