<?php

declare(strict_types=1);

namespace Vaulter\Documents\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Engines\Handlers\UniverHandler;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;

final class BindingsRegistrar extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(Repository::class);

        // Own the node-comment / document-version swappable-model defaults and
        // contribute the TipTap handler + built-in exporters WITHOUT changing the
        // config paths hosts use: merge them under the existing `vaulter.models` /
        // `vaulter.handlers` / `vaulter.exporters` keys. Core ships its OWN
        // `vaulter.handlers` baseline (PreviewHandler/DownloadOnlyHandler, P0.4/D10)
        // and no defaults for models/exporters — this merge ADDS TipTapHandler
        // alongside the core baseline; a host's own config still wins on key overlap
        // (array_merge, host second) — the drive/media provider precedent.
        //
        // Cache-safety (F-P2.2-01, D19): a single config file back three DIFFERENT
        // top-level `vaulter.*` keys, so a plain `mergeConfigFrom($path, 'vaulter.models')`
        // is not viable here — it would merge the WHOLE returned array (models +
        // handlers + exporters) under `vaulter.models`, mis-nesting the latter two.
        // The merge is instead gated the same way `mergeConfigFrom` gates itself
        // (`configurationIsCached()`): when the host has run `config:cache`, the
        // merged values are already baked into the cached config, so re-reading the
        // file from disk and recomputing the merge on every request is redundant I/O
        // in `register()` (`.claude/rules/service-provider.md`).
        if (!$this->app->configurationIsCached()) {
            /** @var array{models: array<string, class-string>, handlers: list<class-string>, exporters: array<string, class-string>} $defaults */
            $defaults = require __DIR__.'/../../config/vaulter-documents.php';

            /** @var array<string, class-string> $existingModels */
            $existingModels = $config->get('vaulter.models', []);
            /** @var list<class-string> $existingHandlers */
            $existingHandlers = $config->get('vaulter.handlers', []);
            /** @var array<string, class-string> $existingExporters */
            $existingExporters = $config->get('vaulter.exporters', []);

            $config->set([
                'vaulter.models' => array_merge($defaults['models'], $existingModels),
                'vaulter.handlers' => array_merge($defaults['handlers'], $existingHandlers),
                'vaulter.exporters' => array_merge($defaults['exporters'], $existingExporters),
            ]);
        }

        // The feature-flagged UniverHandler is added to the tagged 'vaulter.handlers'
        // set ONLY when the 'spreadsheets' feature is on, so a stock install never
        // resolves xlsx to it (xlsx then falls to DownloadOnly). The format-
        // extensibility registry engine itself (`Vaulter\Handlers\FileHandlerRegistry`)
        // and the `NodePresenter` seam now bind in core (P0.4/D10) — this
        // package only ever ADDS handlers to it, never owns the engine.
        if ($config->get('vaulter.features.spreadsheets', false) === true) {
            $this->app->tag([UniverHandler::class], 'vaulter.file_handlers');
        }

        // The export filename seam (P4.5 / F-P3.1-04): NodeExportService stays
        // `final` (H.5 arch gate), so a host wanting a different export filename
        // scheme rebinds this contract to its own class instead of forking the
        // service. Bound to the pre-P4.5 hardcoded scheme (node basename + exporter
        // extension) — default behaviour is unchanged.
        $this->app->bind(ExportFilenameResolver::class, DefaultExportFilenameResolver::class);

        // Second echelon of the F-P1.11-01 stored-XSS fix (D4): TipTapRenderer takes
        // the sanitizer through its constructor (never resolved inline from the
        // container) so every HTML render — including content persisted before the
        // store-time validator existed — is sanitized on the way out. Singleton: the
        // sanitizer is stateless and config-immutable per request.
        $this->app->singleton(HtmlSanitizerInterface::class, static function (): HtmlSanitizer {
            $config = (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowMediaSchemes(['https', 'http']);

            return new HtmlSanitizer($config);
        });
    }
}
