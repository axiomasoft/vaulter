<?php

declare(strict_types=1);

/**
 * config:cache fails the moment any value is a closure; this guard (H.1) asserts
 * the whole vaulter config is var_export-able, i.e. `php artisan config:cache`
 * would succeed and panels/handlers/exporters/drivers carry FQCN strings, not
 * closures.
 */
it('конфиг vaulter сериализуем (config:cache не сломается)', function (): void {
    /** @var array<string, mixed> $config */
    $config = config('vaulter');

    $exported = var_export($config, return: true);

    expect($exported)->toBeString();

    expect(containsClosure($config))->toBeFalse();
});

it('ключи panels и default_panel — это FQCN, а не замыкания', function (): void {
    expect(config('vaulter.panels'))->toBeArray()
        ->and(config('vaulter.default_panel'))->toBeString();

    foreach (config('vaulter.panels') as $class) {
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
