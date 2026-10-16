<?php

declare(strict_types=1);

use Vaulter\Tenancy\Tenancy;
use Vaulter\Tenancy\TenancyBypass;
use Vaulter\Tests\TestCase;
use Vaulter\VaulterManager;

uses(TestCase::class);

it('runs the callback inside the tenancy bypass and returns its value', function (): void {
    $bypass = app(TenancyBypass::class);

    expect($bypass->active())->toBeFalse()
        ->and(Tenancy::without(static fn (): bool => $bypass->active()))->toBeTrue()
        ->and(Tenancy::without(static fn (): string => 'value'))->toBe('value')
        ->and($bypass->active())->toBeFalse();
});

it('nests and restores the previous state when the callback throws', function (): void {
    $bypass = app(TenancyBypass::class);

    expect(fn () => Tenancy::without(static function () use ($bypass): void {
        Tenancy::without(static fn (): null => null);
        expect($bypass->active())->toBeTrue();

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom')
        ->and($bypass->active())->toBeFalse();
});

it('is what VaulterManager::withoutTenancy delegates to', function (): void {
    $bypass = app(TenancyBypass::class);

    expect(app(VaulterManager::class)->withoutTenancy(static fn (): bool => $bypass->active()))->toBeTrue()
        ->and($bypass->active())->toBeFalse();
});
