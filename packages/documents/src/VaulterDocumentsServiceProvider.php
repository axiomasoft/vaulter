<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Access\AccessResolver;
use Vaulter\Contracts\Documents\HandlerRegistry;
use Vaulter\Contracts\Documents\NodeHandlerResolver as NodeHandlerResolverContract;
use Vaulter\Documents\Access\HostUserResolver as DefaultHostUserResolver;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Documents\DocumentHandlerRegistry;
use Vaulter\Documents\Documents\Handlers\UniverHandler;
use Vaulter\Documents\Documents\NodeHandlerResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Vaulter Documents — the document-node behaviour layer, extracted from vaulter-core
 * (P2 #5 / ADR-0015, D2). This package OWNS the document Actions, the format-handler
 * registry, the NodeComment/NodeDocumentVersion models, the real-time collaboration
 * manager/broadcast channel and the document REST routes. Core keeps the schema, the
 * Node/Storage models, Access/Sharing/Activity/Webhooks and the Contracts\Documents
 * seam (+ its VOs — they are kernel-shared, T9/D2 finding). The Documents domain
 * events (DocumentNodeCreated, NodeCommentAdded, …) are Laravel ShouldBroadcast
 * events consumed directly by the broadcast channel below — there is no
 * Activity/outbox integration for them (none existed pre-extraction either; only the
 * Drive/Access/Sharing verbs are recorded).
 *
 * Dependency direction is one-way: documents requires ONLY core (T6) — never drive,
 * never media (document content is inline JSON, never CAS-committed). A host that
 * wants document behaviour installs this package and Laravel auto-discovers this
 * provider, which binds the HandlerRegistry/NodeHandlerResolver seams behind
 * `Vaulter::registerHandler()` / core's NodeResource.
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
        // Own the node-comment / document-version swappable-model defaults and the
        // built-in handler/exporter lists WITHOUT changing the config paths hosts use:
        // merge them under the existing `vaulter.models` / `vaulter.handlers` /
        // `vaulter.exporters` keys. Core ships no defaults for any of the three, so
        // this merge is authoritative; a host's own config still wins on key overlap
        // (array_merge, host second) — the drive/media provider precedent.
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

        // The format-extensibility registry (§4.5 / §7 / H.5 / M4a). Singleton — it
        // memoises the merged handler set + resolutions. Bound behind the core
        // HandlerRegistry seam so `Vaulter::registerHandler()`/`handlers()` resolve it
        // without core ever referencing this concrete class.
        $this->app->singleton(DocumentHandlerRegistry::class);
        $this->app->bind(HandlerRegistry::class, DocumentHandlerRegistry::class);

        // The feature-flagged UniverHandler is added to the tagged 'vaulter.handlers'
        // set ONLY when the 'spreadsheets' feature is on, so a stock install never
        // resolves xlsx to it (xlsx then falls to DownloadOnly).
        if (Config::featureEnabled('spreadsheets')) {
            $this->app->tag([UniverHandler::class], 'vaulter.handlers');
        }

        // Core's NodeResource resolves this seam to embed the handler descriptor; the
        // resolver ships here so core never references Vaulter\Documents\* directly.
        $this->app->bind(NodeHandlerResolverContract::class, NodeHandlerResolver::class);

        // The host-user resolver seam (M4b): the collaboration webhook resolves the
        // acting user by id through the host's auth user-provider. Bound to the
        // default DefaultHostUserResolver; a host (or a test) may swap it. Purely
        // documents machinery — core neither defines nor binds it (D2 finding).
        $this->app->bind(HostUserResolver::class, DefaultHostUserResolver::class);
    }

    public function packageBooted(): void
    {
        $this->registerBroadcastChannel();
    }

    /**
     * Authorize the private collaboration room channel (channel_prefix.{id}). The
     * room id is a NODE id (M4b): a kind=document node authorizes through the
     * Vaulter-owned AccessResolver (Ability::View to connect at all; read-only vs
     * writable is encoded in the issued session, not the channel). Gated on the
     * 'document_versions' feature. The Reverb transport authenticates the socket
     * here; there is no separate room token.
     */
    private function registerBroadcastChannel(): void
    {
        Broadcast::channel(
            Config::channelPrefix().'.{roomId}',
            static fn (Authenticatable $user, int|string $roomId): bool => Config::featureEnabled('document_versions')
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
