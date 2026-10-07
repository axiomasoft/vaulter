<?php

declare(strict_types=1);

use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Tests\Tenancy\MutableTenantResolver;

it('V31 strict tenancy rejects missing invalid and wrong-interface resolver in every environment', function (string $env, mixed $resolver): void {
    app()->detectEnvironment(fn (): string => $env);
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', $resolver);
    expect(fn () => app(ConfigValidator::class)->validate(app(VaulterConfig::class), app()))
        ->toThrow(TenancyMisconfiguredException::class);
})->with(['testing', 'production'])->with([null, 'MissingTenantClass', stdClass::class]);

it('V31 boot permits a valid read-only resolver with null current for maintenance CLI', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    $resolver->tenant = null;
    app()->instance(TenantResolver::class, $resolver);
    app(ConfigValidator::class)->validate(app(VaulterConfig::class), app());
    expect(app(TenantResolver::class)->current())->toBeNull();
});

it('keeps tenancy disabled by default with no resolver or model swap requirement', function (): void {
    config()->set('vaulter.tenancy.enabled', false);
    config()->set('vaulter.tenancy.resolver', null);
    app(ConfigValidator::class)->validate(app(VaulterConfig::class), app());
    expect(config('vaulter.tenancy.enabled'))->toBeFalse();
});
