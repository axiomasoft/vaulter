<?php

declare(strict_types=1);

/**
 * Doc-alignment guard (P3.5 / B22 / F-P2.2-02, D19 green guard — structurally no
 * red-before, no behaviour delta).
 *
 * `VaulterServiceProvider` declares BOTH `discoversMigrations()` and
 * `runsMigrations()` — a legal combo on its own, but `vendor:publish
 * --tag=vaulter-migrations` stamps published copies with NEW timestamp prefixes;
 * Laravel tracks executed migrations by filename, so after publishing, the
 * still-active vendor auto-run tries to run the (renamed) published copies a
 * SECOND time — `SQLSTATE[42S01]: table already exists`. `installation.md` used to
 * announce the auto-discovery behaviour without warning about this consequence of
 * publishing. This test pins the presence of that warning so it cannot silently
 * regress out of the docs again.
 */
it('warns that publishing migrations switches the canonical mode (F-P2.2-02)', function (): void {
    $docs = file_get_contents(__DIR__.'/../../../../docs/getting-started/installation.md');

    expect($docs)->not->toBeFalse()
        ->and($docs)->toContain('vendor:publish --tag=vaulter-migrations')
        ->and($docs)->toContain('table already exists')
        ->and($docs)->toContain('published copies')
        ->and($docs)->toContain('second');
});
