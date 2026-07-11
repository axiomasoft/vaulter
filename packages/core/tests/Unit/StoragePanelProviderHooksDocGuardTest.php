<?php

declare(strict_types=1);

use Vaulter\Panels\StoragePanelProvider;

/*
 * F-P3.2-02 (D19 green guard, structurally no red-before — a docblock-alignment
 * fix, no behaviour delta): fillNode()/presentNode()/pipes() are declared and
 * boot-validated (Panel::pipesList() classes are checked to exist) but never
 * called anywhere in the package — the class docblock used to claim they "enrich
 * the node pipeline and the node representation without forking the package",
 * which was false. This guard pins that the promise is gone and the three hooks
 * are marked @unimplemented, so it cannot silently drift back.
 */
it('does not promise the fill/present/pipes hooks work, and marks them @unimplemented, F-P3.2-02', function (): void {
    $class = new ReflectionClass(StoragePanelProvider::class);

    expect($class->getDocComment())->not->toContain('without forking the package');

    foreach (['fillNode', 'presentNode', 'pipes'] as $hook) {
        $doc = $class->getMethod($hook)->getDocComment();

        expect($doc)->not->toBeFalse()
            ->and($doc)->toContain('@unimplemented')
            ->and($doc)->toContain('F-P3.2-02');
    }
});
