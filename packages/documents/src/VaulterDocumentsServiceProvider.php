<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\Access\HostUserResolver as DefaultHostUserResolver;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Contracts\Export\ExportFilenameResolver;
use Vaulter\Documents\Engines\Handlers\UniverHandler;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Vaulter Documents — the document-node behaviour layer, extracted from vaulter-core
 * (P2 #5 / ADR-0015, D2). This package OWNS the document Actions, the TipTap/Univer
 * format drivers, the NodeComment/NodeDocumentVersion models, the real-time
 * collaboration manager/broadcast channel and the document REST routes. Core keeps
 * the schema, the Node/Storage models, Access/Sharing/Activity/Webhooks, the
 * Contracts\Handlers seam (+ its VOs — kernel-shared, T9/D2 finding) AND — since
 * VLT-CANON P0.4 (D10, superseding H.5/T5) — the format-extensibility registry engine
 * itself (`Vaulter\Engines\*`), so a drive-only host without this package still gets
 * a preview/download handler descriptor. This package's ONLY handler contribution is
 * TipTapHandler (+ UniverHandler when the 'spreadsheets' feature is on), tagged into
 * the same 'vaulter.handlers' registry core always binds.
 *
 * Dependency direction is one-way: documents requires ONLY core (T6) — never drive,
 * never media (document content is inline JSON, never CAS-committed). A host that
 * wants document behaviour installs this package and Laravel auto-discovers this
 * provider, which contributes TipTapHandler/UniverHandler to core's
 * `Vaulter::registerHandler()` seam.
 */
final class VaulterDocumentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter-documents')
            ->hasRoute('documents');
    }

    public function packageRegistered(): void
    {
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
            $defaults = require __DIR__.'/../config/vaulter-documents.php';

            /** @var array<string, class-string> $existingModels */
            $existingModels = config('vaulter.models', []);
            /** @var list<class-string> $existingHandlers */
            $existingHandlers = config('vaulter.handlers', []);
            /** @var array<string, class-string> $existingExporters */
            $existingExporters = config('vaulter.exporters', []);

            config([
                'vaulter.models' => array_merge($defaults['models'], $existingModels),
                'vaulter.handlers' => array_merge($defaults['handlers'], $existingHandlers),
                'vaulter.exporters' => array_merge($defaults['exporters'], $existingExporters),
            ]);
        }

        // The feature-flagged UniverHandler is added to the tagged 'vaulter.handlers'
        // set ONLY when the 'spreadsheets' feature is on, so a stock install never
        // resolves xlsx to it (xlsx then falls to DownloadOnly). The format-
        // extensibility registry engine itself (`Vaulter\Engines\DocumentHandlerRegistry`)
        // and the `NodeHandlerResolver` seam now bind in core (P0.4/D10) — this
        // package only ever ADDS handlers to it, never owns the engine.
        if (Config::featureEnabled('spreadsheets')) {
            $this->app->tag([UniverHandler::class], 'vaulter.handlers');
        }

        // The host-user resolver seam (M4b): the collaboration webhook resolves the
        // acting user by id through the host's auth user-provider. Bound to the
        // default DefaultHostUserResolver; a host (or a test) may swap it. Purely
        // documents machinery — core neither defines nor binds it (D2 finding).
        $this->app->bind(HostUserResolver::class, DefaultHostUserResolver::class);

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

    public function packageBooted(): void
    {
        $this->registerBroadcastChannel();
    }

    /**
     * Authorize the private collaboration room channel (channel_prefix.{id}). The
     * room id is a NODE id (M4b): a kind=document node authorizes through the
     * Vaulter-owned AccessResolver (Ability::View to connect at all; read-only vs
     * writable is encoded in the issued session, not the channel). Gated on BOTH the
     * 'document_versions' feature AND 'collaboration' (VLT-REMED P3.1 / F-P1.6-01,
     * D13/D20) — the realtime channel is a separate opt-in over base document
     * editing. The Reverb transport authenticates the socket here; there is no
     * separate room token.
     */
    private function registerBroadcastChannel(): void
    {
        Broadcast::channel(
            Config::channelPrefix().'.{roomId}',
            static fn (Authenticatable $user, int|string $roomId): bool => Config::featureEnabled('document_versions')
                && Config::collaborationEnabled()
                && self::authorizeNodeRoom($user, $roomId),
        );
    }

    /**
     * Authorize a node room: the id resolves to a kind=document node the actor may at
     * least View through the AccessResolver.
     */
    private static function authorizeNodeRoom(Authenticatable $user, int|string $roomId): bool
    {
        if (!$user instanceof Authorizable) {
            return false;
        }

        $model = Config::nodeModel();
        $node = $model::query()->find($roomId);

        if (!$node instanceof Node || !$node->kind->isDocument()) {
            return false;
        }

        return app(AccessResolver::class)->can($user, Ability::View, $node);
    }
}
