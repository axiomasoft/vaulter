<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Drivers\GatePermissionDriver;

/*
 * (Green guard — docblock-alignment fix, no behaviour delta):
 * gateAllows() never had a try/catch; its docblock falsely claimed it "swallows a
 * missing-ability exception". Gate::allows() already returns false (never throws)
 * for an undefined ability, so fail-closed behaviour was never at risk — only the
 * comment was wrong. This guard pins that the false "swallow" claim is gone.
 */
it('no longer claims gateAllows() swallows an exception it never catches, F-P1.2-02', function (): void {
    $method = new ReflectionMethod(GatePermissionDriver::class, 'gateAllows');
    $doc = $method->getDocComment();

    expect($doc)->not->toBeFalse()
        ->and($doc)->not->toContain('swallow')
        ->and($doc)->not->toContain('F-P1.2-02');
});
