<?php

declare(strict_types=1);

use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

it('V31 strict tenancy rejects missing invalid and wrong-interface resolver in every environment', function (string $env, mixed $resolver): void {
    app()->detectEnvironment(fn (): string => $env);
    config()->set('vaulter-drive.access.permission_driver', 'database');
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', $resolver);
    app()->forgetInstance(VaulterOptions::class);
    expect(fn () => app(VaulterOptions::class)->tenancy->requireResolver())
        ->toThrow(TenancyMisconfiguredException::class);
})->with(['testing', 'production'])->with([null, 'MissingTenantClass', stdClass::class]);

it('V31 boot permits a valid read-only resolver with null current for maintenance CLI', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->forgetInstance(VaulterOptions::class);
    $resolver = new MutableTenantResolver;
    $resolver->tenant = null;
    app()->instance(TenantResolver::class, $resolver);
    app(VaulterOptions::class)->tenancy->requireResolver();
    expect(app(TenantResolver::class)->current())->toBeNull();
});

it('keeps tenancy disabled by default with no resolver or model swap requirement', function (): void {
    config()->set('vaulter.tenancy.enabled', false);
    config()->set('vaulter.tenancy.resolver');
    app()->forgetInstance(VaulterOptions::class);

    $findings = app(ConfigValidCheck::class)->run(new DoctorContext);

    expect(config('vaulter.tenancy.enabled'))->toBeFalse()
        ->and($findings[0]->status)->toBe(CheckStatus::Ok);
});
