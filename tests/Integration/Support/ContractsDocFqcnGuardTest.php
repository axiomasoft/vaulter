<?php

declare(strict_types=1);

use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;

/*
 * (green guard — docblock-alignment fix, no behaviour
 * delta): `docs/reference/php-api.md` named the FQCN
 * `Vaulter\Contracts\Uploads\UploadProtocolHandler` (core-shaped namespace), but
 * the actual interface lives at `Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler`
 * (drive package) — a `use` statement copied from the doc would not compile. This
 * guard reads the doc file directly and pins that it names the REAL FQCN
 * (sourced from the interface itself via reflection, not hand-copied) and no
 * longer the stale core-shaped one.
 */
it('documents the real FQCN of UploadProtocolHandler, not the stale core-shaped one, F-P2.1-04', function (): void {
    $realFqcn = UploadProtocolHandler::class;
    $staleFqcn = 'Vaulter\\Contracts\\Uploads\\UploadProtocolHandler';

    $doc = file_get_contents(dirname(__DIR__, 3).'/docs/reference/php-api.md');

    expect($doc)->not->toBeFalse()
        ->and($doc)->toContain($realFqcn)
        ->and($doc)->not->toContain($staleFqcn);
});
