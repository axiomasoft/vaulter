<?php

declare(strict_types=1);

use Vaulter\Enums\Handlers\Capability;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\ValueObjects\Handlers\FileDescriptor;

/*
 * VLT-CANON P0.4 / D10 review (HIGH-1) — the core handler BASELINE must survive a
 * stale published config.
 *
 * P0.4 moved the registry engine into core and gave `config('vaulter.handlers')` a
 * core-owned default of [PreviewHandler, DownloadOnlyHandler]. That default alone is
 * NOT enough: `hasConfigFile()` merges through Laravel's `mergeConfigFrom`, which is a
 * shallow, EXISTING-WINS array_merge. A host that ran `vendor:publish --tag=vaulter-config`
 * BEFORE P0.4 carries a literal `'handlers' => []` in its own config/vaulter.php — that
 * empty array wins over core's new default, and the baseline vanishes. The drive-only
 * host P0.4 exists for would silently lose `previewable` on every pdf/image.
 *
 * The fix is a container TAG (`vaulter.handlers`) in VaulterServiceProvider, a second
 * source the registry merges and de-duplicates by FQCN. These tests pin the invariant
 * from the OUTCOME side (resolution still works), not by asserting the tag exists — so
 * a future edit cannot satisfy them while dropping the guarantee.
 */
it('resolves the preview baseline even when a stale published config empties vaulter.handlers, P0.4/D10', function (): void {
    // Exactly what a pre-P0.4 published config/vaulter.php carries.
    config()->set('vaulter.handlers', []);
    app()->forgetInstance(FileHandlerRegistry::class);

    $pdf = FileDescriptor::make(nodeId: 1, filename: 'scan.pdf', detectedMime: 'application/pdf');

    $handler = app(FileHandlerRegistry::class)->resolve($pdf, Capability::Preview);

    expect($handler)->toBeInstanceOf(PreviewHandler::class)
        ->and($handler->capabilities($pdf)->previewable)->toBeTrue();
});

it('still falls back to DownloadOnly for an unhandled type under a stale config, P0.4/D10', function (): void {
    config()->set('vaulter.handlers', []);
    app()->forgetInstance(FileHandlerRegistry::class);

    $zip = FileDescriptor::make(nodeId: 1, filename: 'bundle.zip', detectedMime: 'application/zip');

    expect(app(FileHandlerRegistry::class)->resolve($zip, Capability::Download))
        ->toBeInstanceOf(DownloadOnlyHandler::class);
});

it('lists each baseline handler exactly ONCE — the tag and the config default do not double-register, P0.4/D10', function (): void {
    // Both sources name the same two FQCNs; the registry de-duplicates by class, so a
    // stock install must not resolve two PreviewHandler instances (which would make the
    // priority sort order-dependent and the introspection surface lie).
    $ids = array_map(
        static fn (object $handler): string => $handler->key(),
        app(FileHandlerRegistry::class)->all(),
    );

    expect(array_count_values($ids)['vaulter/preview'])->toBe(1)
        ->and(array_count_values($ids)['vaulter/download'])->toBe(1);
});
