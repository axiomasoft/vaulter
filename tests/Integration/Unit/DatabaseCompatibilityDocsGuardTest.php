<?php

declare(strict_types=1);

/**
 * Doc-alignment guard (P3.6 / B23 / F-P2.3-04, D19 green guard — structurally no
 * red-before, no behaviour delta: the fix IS the doc).
 *
 * Before this fix, `docs/reference/compatibility.md` described engine
 * COMPATIBILITY (Postgres/SQLite/MySQL) but never stated a policy for how the
 * shipped `v_*` schema is allowed to evolve after the package's first release —
 * grepping `docs/` for "NOT NULL without default|breaking migration|alter
 * table|schema change polic" returned 0 relevant hits (`findings/
 * P2.3-schema-embeddability.md` C12/F-P2.3-04). This test pins the presence of
 * that policy so it cannot silently regress out of the docs again.
 */
it('states the schema change policy for future releases (F-P2.3-04)', function (): void {
    $docs = file_get_contents(__DIR__.'/../../../docs/reference/compatibility.md');

    expect($docs)->not->toBeFalse()
        ->and($docs)->toContain('Schema change policy')
        ->and($docs)->toContain('nullable or carry a `default`')
        ->and($docs)->toContain('CHANGELOG.md')
        ->and($docs)->toContain('two-step change')
        ->and($docs)->toContain('blocking `ALTER TABLE`');
});

/**
 * Doc-alignment guard for the new `vaulter.database.connection` key (F-P2.3-02) — the
 * embeddability doc must tell a host how to place the schema on its own
 * connection, not just leave it discoverable only by reading `config/vaulter.php`.
 */
it('documents the vaulter.database.connection key for embedding the schema on its own connection (F-P2.3-02)', function (): void {
    $docs = file_get_contents(__DIR__.'/../../../docs/reference/compatibility.md');

    expect($docs)->not->toBeFalse()
        ->and($docs)->toContain("config('vaulter.database.connection')")
        ->and($docs)->toContain('VAULTER_DB_CONNECTION');
});
