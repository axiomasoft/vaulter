<?php

declare(strict_types=1);

use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;

/**
 * Cache-safe config merge (P3.5 / B22 / F-P2.2-01, D19 characterization).
 *
 * `BindingsRegistrar::register()` used to `require` the
 * package config file and recompute the `vaulter.models`/`vaulter.handlers`/
 * `vaulter.exporters` merge unconditionally on every request, even under
 * `config:cache` — file I/O in `register()` the `.claude/rules/service-provider.md`
 * ban targets. The fix wraps the merge in a `configurationIsCached()` guard, the same
 * mechanism `mergeConfigFrom()` uses internally. These tests pin (1) the OUTCOME of
 * the merge is unchanged (host keys still win, package defaults still present) and
 * (2) the guard is structurally present, so a future edit cannot silently drop it
 * while keeping the outcome test green.
 */
it('merges documents package defaults into vaulter.models/handlers/exporters (F-P2.2-01)', function (): void {
    expect(config('vaulter.models.node_comment'))->toBe(NodeComment::class)
        ->and(config('vaulter.models.node_document_version'))->toBe(DocumentRevision::class)
        ->and(config('vaulter.handlers'))->toContain(TipTapHandler::class, PreviewHandler::class, DownloadOnlyHandler::class)
        ->and(config('vaulter.exporters.html'))->toBe(HtmlExporter::class)
        ->and(config('vaulter.exporters.markdown'))->toBe(MarkdownExporter::class);
});

it('lets a host-defined vaulter.models key win over the package default on re-merge (F-P2.2-01)', function (): void {
    // Simulates a host that has already overridden the swappable node_comment model
    // (e.g. its own config/vaulter.php) BEFORE the provider re-merges its defaults in —
    // array_merge(defaults, existing) keeps `existing` (host) on key overlap. The
    // guard only skips the merge when the config IS cached (not exercised here, since
    // Testbench never ships a real bootstrap cache); when uncached, the merge still
    // runs and host precedence still holds.
    config(['vaulter.models.node_comment' => 'App\\Models\\CustomNodeComment']);

    app()->register(VaulterDocumentsServiceProvider::class, force: true);

    expect(config('vaulter.models.node_comment'))->toBe('App\\Models\\CustomNodeComment')
        // The OTHER default in the same key is untouched by the host's partial override.
        ->and(config('vaulter.models.node_document_version'))->toBe(DocumentRevision::class);
});

it('guards the config file-read/merge behind configurationIsCached() in source (F-P2.2-01)', function (): void {
    // Direct structural pin: the merge (require + array_merge + config([...])) must
    // sit behind the same guard mergeConfigFrom() uses internally, so it is skipped
    // once the host has run `config:cache` — the defect this item closes was the
    // ABSENCE of this guard (unconditional file I/O in register() on every request).
    $source = file_get_contents(__DIR__.'/../../src/Providers/BindingsRegistrar.php');

    expect($source)->toContain('!$this->app->configurationIsCached()')
        ->and($source)->toMatch(
            '/if\s*\(\s*!\s*\$this->app->configurationIsCached\(\)\s*\)\s*\{.*require __DIR__/s',
        );
});
