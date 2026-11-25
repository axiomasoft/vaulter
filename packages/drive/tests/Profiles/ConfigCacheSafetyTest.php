<?php

declare(strict_types=1);

/**
 * config:cache fails the moment any value is a closure; this guard asserts
 * the whole vaulter config is var_export-able, i.e. `php artisan config:cache`
 * would succeed and panels/handlers/exporters/drivers carry FQCN strings, not
 * closures.
 */
it('the vaulter config is serializable (config:cache keeps working)', function (): void {
    /** @var array<string, mixed> $config */
    $config = config('vaulter');

    $exported = var_export($config, return: true);

    expect($exported)->toBeString();

    expect(containsClosure($config))->toBeFalse();
});

it('the vaulter-drive config is serializable (config:cache keeps working)', function (): void {
    /** @var array<string, mixed> $config */
    $config = config('vaulter-drive');

    expect(var_export($config, return: true))->toBeString()
        ->and(containsClosure($config))->toBeFalse();
});

it('the panels and default_panel keys hold FQCNs, not closures', function (): void {
    expect(config('vaulter-drive.profiles.providers'))->toBeArray()
        ->and(config('vaulter-drive.profiles.default'))->toBeString();

    foreach (config('vaulter-drive.profiles.providers') as $class) {
        expect($class)->toBeString();
    }
});

/**
 * @param array<mixed, mixed> $value
 */
function containsClosure(array $value): bool
{
    foreach ($value as $item) {
        if ($item instanceof Closure) {
            return true;
        }

        if (is_array($item) && containsClosure($item)) {
            return true;
        }
    }

    return false;
}
