<?php

declare(strict_types=1);

use Vaulter\Attributes\Label;
use Vaulter\Enums\Access\Effect;
use Vaulter\Support\EnumCaseAttributeResolver;

/*
 * P4.6 / F-P2.1-05 (Minor, D19 red-before): `EnumCaseAttributeResolver` used to
 * carry a `private static array $cache` — a process-wide reflection-attribute
 * cache living entirely outside `VaulterManager`/the facade, a literal E3.3
 * violation ("no global state besides facades"). This test proves the cache is
 * now owned by the INSTANCE, not the class: a fresh instance never inherits
 * another instance's cached entries. On the pre-fix `static $cache`, reading the
 * static property through a second, otherwise-untouched instance returns the
 * SAME populated array (static state has no per-instance storage) and this test
 * fails red; after the fix each instance starts with its own empty `$cache`.
 */
it('does not share its reflection-attribute cache across instances, F-P2.1-05', function (): void {
    $first = new EnumCaseAttributeResolver;
    $first->forCase(Effect::Allow, Label::class);

    $cacheProperty = new ReflectionProperty(EnumCaseAttributeResolver::class, 'cache');
    $cacheProperty->setAccessible(true);

    expect($cacheProperty->getValue($first))->not->toBe([]);

    $second = new EnumCaseAttributeResolver;

    expect($cacheProperty->getValue($second))->toBe([]);
});

it('is bound as a container singleton, so its lifecycle follows the container not the process, F-P2.1-05', function (): void {
    expect(app(EnumCaseAttributeResolver::class))->toBe(app(EnumCaseAttributeResolver::class));
});
