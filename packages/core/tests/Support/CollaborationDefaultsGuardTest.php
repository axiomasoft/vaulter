<?php

declare(strict_types=1);

use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;

/*
 * VLT-REMED P3.1 (W3/B11) — doc/ADR-alignment guard tests (D19: green
 * characterization, red-before is structurally impossible here — no behaviour
 * changed, only a stale fallback/doc were corrected to match the already-correct
 * code).
 */

it('the collaboration configuration accessor falls back to hocuspocus when the config key is entirely absent, F-P3.6-01', function (): void {
    config()->offsetUnset('vaulter-collaboration.driver');

    expect((new CollaborationConfig(app('config'), app(DocumentsConfig::class)))->driver())->toBe('hocuspocus');
});

it('the vaulter-collaboration config default transport is hocuspocus (unchanged by P3.1 — the ADR/docs were the drift), F-P3.6-01', function (): void {
    $defaults = require dirname(__DIR__, 3).'/collaboration/config/vaulter-collaboration.php';

    expect($defaults['driver'])->toBe('hocuspocus');
});

it('README describes canonical feature ownership (P2.5)', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 4).'/README.md');

    expect($readme)->toContain('vaulter-documents')
        ->and($readme)->not->toContain("config('vaulter-drive.features.document_versions')", "config('vaulter-drive.features.comments')");
});
