<?php

declare(strict_types=1);

/**
 * Doc-alignment guard (D40, P4.5): the migrations of every package run from `vendor/` and are never published, so the
 * installation guide must not tell the reader to publish them — a published copy gets a NEW timestamp prefix and
 * Laravel, which tracks executed migrations by filename, would run it a second time (`table already exists`).
 */
it('tells the reader the migrations run from vendor and are not published (D40)', function (): void {
    $docs = file_get_contents(__DIR__.'/../../../docs/getting-started/installation.md');

    expect($docs)->not->toBeFalse()
        ->and($docs)->not->toContain('vendor:publish --tag=vaulter-migrations')
        ->and($docs)->toContain('Migrations are not published')
        ->and($docs)->toContain('straight from `vendor/`');
});
