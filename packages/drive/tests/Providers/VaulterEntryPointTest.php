<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Vaulter\Drive\Facades\Vaulter as VaulterFacade;
use Vaulter\Drive\Vaulter;
use Vaulter\Tenancy\TenancyBypass;

// D10: the typed entry point and its facade belong to drive; the Laravel alias `Vaulter` is declared by drive's composer.

it('binds the entry point as a singleton under the vaulter name', function (): void {
    expect(app('vaulter'))->toBeInstanceOf(Vaulter::class)
        ->and(app('vaulter'))->toBe(app(Vaulter::class));
});

it('resolves the entry point through the facade accessor', function (): void {
    expect(VaulterFacade::getFacadeRoot())->toBe(app(Vaulter::class))
        ->and(VaulterFacade::profiles())->toBe(app(Vaulter::class)->profiles());
});

it('runs the facade withoutTenancy callback inside the core tenancy bypass', function (): void {
    $bypass = app(TenancyBypass::class);

    expect($bypass->active())->toBeFalse()
        ->and(VaulterFacade::withoutTenancy(static fn (): bool => $bypass->active()))->toBeTrue()
        ->and($bypass->active())->toBeFalse();
});

it('keeps withoutTenancy out of the drive entry point, it is the core Tenancy::without', function (): void {
    expect(method_exists(Vaulter::class, 'withoutTenancy'))->toBeFalse();
});

it('declares the Vaulter alias in the drive composer manifest only', function (): void {
    $drive = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $core = json_decode((string) file_get_contents(InstalledVersions::getInstallPath('axioma-studio/vaulter-core').'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($drive['extra']['laravel']['aliases']['Vaulter'] ?? null)->toBe(VaulterFacade::class)
        ->and($core['extra']['laravel']['aliases'] ?? [])->not->toHaveKey('Vaulter');
});

it('has no class left under the old core entry point names', function (): void {
    expect(class_exists('Vaulter\\VaulterManager'))->toBeFalse()
        ->and(class_exists('Vaulter\\Facades\\Vaulter'))->toBeFalse();
});
