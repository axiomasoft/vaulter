<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Activity\RecordsActivity;
use Vaulter\Console\GcBlobsCommand;
use Vaulter\Console\MakePanelCommand;
use Vaulter\Console\PanelMigrationsCommand;
use Vaulter\Console\PurgeTrashCommand;
use Vaulter\Console\ReconcileBlobsCommand;
use Vaulter\Console\RelayOutboxCommand;
use Vaulter\Console\RetryWebhooksCommand;
use Vaulter\Console\SweepUploadsCommand;
use Vaulter\Contracts\BlobStore;
use Vaulter\Contracts\CollaborationDriver;
use Vaulter\Contracts\ContentStore;
use Vaulter\Contracts\HostRoleDriver;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Contracts\ShareTokenGenerator;
use Vaulter\Contracts\StorageDriver;
use Vaulter\Documents\DocumentHandlerRegistry;
use Vaulter\Documents\Handlers\UniverHandler;
use Vaulter\Drive\StorageManager;
use Vaulter\Models\Node;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Policies\NodePolicy;
use Vaulter\Policies\WebhookEndpointPolicy;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;
use Vaulter\Uploads\UploadSessionManager;
use Vaulter\Webhooks\RecordsOutbox;

final class VaulterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter')
            ->hasConfigFile()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api')
            ->hasCommand(MakePanelCommand::class)
            ->hasCommand(PanelMigrationsCommand::class)
            ->hasCommand(GcBlobsCommand::class)
            ->hasCommand(ReconcileBlobsCommand::class)
            ->hasCommand(PurgeTrashCommand::class)
            ->hasCommand(SweepUploadsCommand::class)
            ->hasCommand(RelayOutboxCommand::class)
            ->hasCommand(RetryWebhooksCommand::class)
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('axioma-studio/vaulter');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(IdStrategyResolver::class);

        $this->app->singleton(
            PanelRegistry::class,
            static fn (): PanelRegistry => PanelRegistry::fromConfig(
                providerClasses: Config::panels(),
                defaultClass: Config::defaultPanel(),
            ),
        );

        $this->app->singleton(VaulterManager::class);
        $this->app->alias(VaulterManager::class, 'vaulter');

        // Drive domain (M1c): the storage get-or-create + root provisioning
        // service. Its deps (read/store repositories) are auto-resolved; binding it
        // as a singleton makes the seam explicit for swaps/tests.
        $this->app->singleton(StorageManager::class);

        // Resumable uploads (M3a / §2.8): the lifecycle coordinator. Its deps
        // (StagedUpload + the store repository) auto-resolve; binding it as a
        // singleton makes the seam explicit for swaps/tests. The two protocol
        // handlers (tus / S3-multipart) auto-resolve through the container.
        $this->app->singleton(UploadSessionManager::class);

        // The bearer share-link generator (M2c / §2.7). nodeShareTokenGeneratorClass
        // reads config('vaulter.share.token_generator') and falls back to the legacy
        // top-level key, so the one binding serves both the Drive bearer links and
        // the legacy DocDrive shares (both default to RandomShareTokenGenerator).
        $this->app->bind(ShareTokenGenerator::class, Config::nodeShareTokenGeneratorClass());

        // CAS services (H.3) — swappable via config('vaulter.cas.*'). StorageDriver
        // and ContentStore are stateless wrappers (singletons); BlobStore is the
        // race-free lifecycle orchestrator.
        $this->app->singleton(StorageDriver::class, Config::storageDriverClass());
        $this->app->singleton(ContentStore::class, Config::contentStoreClass());
        $this->app->bind(BlobStore::class, Config::blobStoreClass());

        $resolverClass = Config::permissionResolverClass();

        if ($resolverClass !== null) {
            $this->app->bind(PermissionResolver::class, $resolverClass);
        }

        // Drive ACL (M2a / H.2): the host-role driver manager resolves the
        // configured HostRoleDriver (default 'database'), keyed per driver-key, and
        // enforces the null-driver fail-loud guard in production (D6″). The
        // AccessResolver (Vaulter-owned, NON-swappable) is auto-wired from it.
        $this->app->singleton(
            HostRoleDriverManager::class,
            fn (): HostRoleDriverManager => new HostRoleDriverManager(
                container: $this->app,
                isProduction: $this->app->environment('production'),
            ),
        );

        // The contract binding resolves the active driver — so a host that injects
        // HostRoleDriver directly (or swaps it in tests) gets the configured lane.
        $this->app->bind(
            HostRoleDriver::class,
            fn (): HostRoleDriver => $this->app->make(HostRoleDriverManager::class)->driver(),
        );

        $this->app->bind(
            CollaborationDriver::class,
            fn (): CollaborationDriver => $this->app->make(VaulterManager::class)->driver(),
        );

        $this->registerDocumentHandlers();
    }

    /**
     * Wire the document-handler registry (§4.5 / §7 / H.5 / M4a). The registry is a
     * singleton (it memoises the merged handler set + resolutions). The
     * feature-flagged UniverHandler is added to the tagged 'vaulter.handlers' set
     * ONLY when the 'spreadsheets' feature is on, so a stock install never resolves
     * xlsx to it (xlsx then falls to DownloadOnly). The default handlers are loaded
     * from config (FQCN, cache-safe) inside the registry — the tag is purely the
     * conditional/host extension channel.
     */
    private function registerDocumentHandlers(): void
    {
        $this->app->singleton(DocumentHandlerRegistry::class);

        if (Config::featureEnabled('spreadsheets')) {
            $this->app->tag([UniverHandler::class], 'vaulter.handlers');
        }
    }

    public function packageBooted(): void
    {
        $this->registerRouteBindings();
        $this->registerBroadcastChannel();
        $this->registerPolicies();
        $this->registerActivitySubscriber();
        $this->registerOutboxSubscriber();
    }

    /**
     * Wire the transactional outbox (§2.11 / D14 / M3b): subscribe the RecordsOutbox
     * listener to the Drive domain events so each one stages ONE v_outbox row for
     * reliable webhook fan-out (idempotent by event_id). The events are
     * ShouldDispatchAfterCommit, so staging runs on the after-commit boundary — the
     * documented, non-invasive transactional-outbox seam (the same one the activity
     * feed uses; see RecordsOutbox for the crash-window caveat). Gated on the global
     * 'reliability' feature; when off, no listener is registered and the events fire
     * unobserved by the outbox.
     */
    private function registerOutboxSubscriber(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        Event::subscribe(RecordsOutbox::class);
    }

    /**
     * Wire the append-only activity feed (§2.9 / M2c-activity): subscribe the
     * RecordsActivity listener to the Drive domain events so each one writes EXACTLY
     * ONE row (recipients resolved at READ — no fan-out, §1.5). Gated on the global
     * 'activity' feature so a host can switch off auditing entirely; when off, no
     * listener is registered and the events fire unobserved.
     */
    private function registerActivitySubscriber(): void
    {
        if (!Config::featureEnabled('activity')) {
            return;
        }

        Event::subscribe(RecordsActivity::class);
    }

    /**
     * Register the NodePolicy against the SWAPPABLE node model (config('vaulter.
     * models.node')) so $this->authorize('view', $node) routes through the
     * AccessResolver. A host subclass of Node is governed by the same policy.
     */
    private function registerPolicies(): void
    {
        Gate::policy(Config::nodeModel(), NodePolicy::class);

        // Cover the base model too, so a host that route-binds the base Node (not a
        // subclass) still hits the policy.
        if (Config::nodeModel() !== Node::class) {
            Gate::policy(Node::class, NodePolicy::class);
        }

        // The webhook-endpoint management surface (§2.11 / D14 / M3b) is a global
        // admin resource, authorized by the WebhookEndpointPolicy (manage ability).
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);

        if (Config::webhookEndpointModel() !== WebhookEndpoint::class) {
            Gate::policy(Config::webhookEndpointModel(), WebhookEndpointPolicy::class);
        }
    }

    /**
     * Bind the Drive REST route parameters {storage} and {node} to the SWAPPABLE
     * models (config('vaulter.models.*')) so the binding keeps resolving when a
     * host points the config at a subclass. A miss 404s (findOrFail). Node uses
     * the Drive trash (a trashed_at column), not the SoftDeletes deleted_at, so a
     * trashed node still resolves by default — restore/force/download need it.
     */
    private function registerRouteBindings(): void
    {
        Route::bind('storage', static function (string $value): Model {
            $model = Config::storageModel();

            return $model::query()->findOrFail($value);
        });

        Route::bind('node', static function (string $value): Model {
            $model = Config::nodeModel();

            return $model::query()->findOrFail($value);
        });
    }

    private function registerBroadcastChannel(): void
    {
        Broadcast::channel(
            Config::channelPrefix().'.{documentId}',
            static function (Authenticatable $user, int|string $documentId): bool {
                $model = Config::documentModel();
                $document = $model::query()->find($documentId);

                if ($document === null) {
                    return false;
                }

                return app(VaulterManager::class)->driver()->authorizeChannel($user, $document);
            },
        );
    }
}
