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

/*
 * P4.6 review (Major): the binding must be `scoped()`, not `singleton()` — only
 * `scoped()` resets the container's cached instance between requests/jobs on
 * Octane/queue workers (see DriveReadRepository's binding in the same
 * ServiceProvider, which relies on exactly this). `singleton()` would persist
 * for the whole worker process, reproducing the same failure mode as the
 * removed process-wide static cache.
 */
it('is bound as a container scoped instance, so its lifecycle follows the container not the process, F-P2.1-05', function (): void {
    $withinRequest = app(EnumCaseAttributeResolver::class);

    expect(app(EnumCaseAttributeResolver::class))->toBe($withinRequest);

    // Octane/queue workers call this between requests/jobs — a plain singleton()
    // binding would survive it, a scoped() one must not.
    app()->forgetScopedInstances();

    expect(app(EnumCaseAttributeResolver::class))->not->toBe($withinRequest);
});
