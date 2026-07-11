<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\VaulterManager;

/*
 * P4.6 / F-P2.1-01 (Minor, D19 green guard — docblock-alignment fix, no behaviour
 * delta): the facade's `@method` docblock listed only 7 of `VaulterManager`'s 12
 * public methods (`registerHandler`, `handlers`, `registerExporter`, `exporter`,
 * `exporterFormats` were invisible to IDE autocomplete/static analysis, even
 * though the Facade's `__callStatic` delegation made them callable regardless).
 * This guard sweeps VaulterManager's public methods by REFLECTION (not a
 * hand-copied list) and pins that every one of them has a matching `@method`
 * annotation in the facade's docblock, so the next new manager method that
 * forgets its docblock line fails this test instead of silently regressing.
 */
it('lists every public VaulterManager method in the facade @method docblock, F-P2.1-01', function (): void {
    $managerMethods = collect((new ReflectionClass(VaulterManager::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isStatic() || $method->isConstructor())
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values()
        ->all();

    expect($managerMethods)->toHaveCount(12);

    $doc = (new ReflectionClass(Vaulter::class))->getDocComment();

    expect($doc)->not->toBeFalse();

    foreach ($managerMethods as $method) {
        expect($doc)->toMatch('/@method\s+static\s+\S.*\b'.preg_quote($method, '/').'\s*\(/');
    }
});
