<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Access\HostRoleDriverManager;
use Vaulter\Activity\RecordsActivity;
use Vaulter\Console\MakePanelCommand;
use Vaulter\Console\PanelMigrationsCommand;
use Vaulter\Console\PruneWebhooksCommand;
use Vaulter\Console\RelayOutboxCommand;
use Vaulter\Console\RetryWebhooksCommand;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Policies\NodePolicy;
use Vaulter\Policies\WebhookEndpointPolicy;
use Vaulter\Repositories\Drive\StorageReadRepository;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;
use Vaulter\Support\TenantFootgunGuard;
use Vaulter\Webhooks\RecordsOutbox;
use Vaulter\Webhooks\SystemHostResolver;

final class VaulterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter')
            ->hasConfigFile()
            ->hasTranslations()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoute('api')
            ->hasCommand(MakePanelCommand::class)
            ->hasCommand(PanelMigrationsCommand::class)
            ->hasCommand(RelayOutboxCommand::class)
            ->hasCommand(RetryWebhooksCommand::class)
            ->hasCommand(PruneWebhooksCommand::class)
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

        // Scoped (P1.3 / F-P4.2-03): StorageReadRepository memoizes find() by id in
        // an in-request identity map, so `AccessResolver::storageOf()` resolved
        // fresh per NodeResource (no explicit AccessResolver binding — see below)
        // still hits the SAME repository instance across a listing page, collapsing
        // the storage lookup from O(N) to O(1) per page. scoped() (not singleton())
        // resets the cache between requests/jobs on Octane/queue workers.
        $this->app->scoped(StorageReadRepository::class);

        $this->app->singleton(
            PanelRegistry::class,
            static fn (): PanelRegistry => PanelRegistry::fromConfig(
                providerClasses: Config::panels(),
                defaultClass: Config::defaultPanel(),
            ),
        );

        $this->app->singleton(VaulterManager::class);
        $this->app->alias(VaulterManager::class, 'vaulter');

        // Drive behaviour (StorageManager, the DriveHandle factory, resumable uploads)
        // moved to axioma-studio/vaulter-drive (P2 #5 / ADR-0015, D1). Core resolves the
        // Contracts\Drive\DriveHandleFactory seam that package binds; `Vaulter::for()`
        // fails loudly when the package is absent.

        // The bearer share-link generator (M2c / §2.7). nodeShareTokenGeneratorClass
        // reads config('vaulter.share.token_generator') and falls back to the legacy
        // top-level key, so the one binding serves both the Drive bearer links and
        // the legacy Documents shares (both default to RandomShareTokenGenerator).
        $this->app->bind(ShareTokenGenerator::class, Config::nodeShareTokenGeneratorClass());

        // Outbound webhook SSRF guard (§2.11 / D5 / F-P1.7-01): the host resolver is
        // the injectable DNS seam (A + AAAA), and the guard is host-swappable via
        // config('vaulter.webhooks.delivery.url_guard'). The barrier runs on the
        // delivery path (DeliverWebhook) and — as an early reject — at registration.
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(OutboundUrlGuard::class, Config::webhookUrlGuardClass());

        // CAS services (H.3) — the concrete ContentStore/StorageDriver/BlobStore
        // engine now lives in axioma-studio/vaulter-media (P2 #5, D2/D3); its
        // provider binds them against the Contracts\Cas\* seam this package owns.
        // Core resolves those contracts from the container when a host installs it.

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

        // The host-user resolver seam (M4b) and the document-handler registry (§4.5)
        // moved to axioma-studio/vaulter-documents (P2 #5 / ADR-0015, D2). Core resolves
        // the Contracts\Documents\{HandlerRegistry,NodeHandlerResolver} seams that
        // package binds; `Vaulter::registerHandler()`/`handlers()` fail loudly when
        // the package is absent (NodeResource's embedded handler degrades to null).
    }

    public function packageBooted(): void
    {
        $this->registerRouteBindings();
        $this->registerPolicies();
        $this->registerActivitySubscriber();
        $this->registerOutboxSubscriber();
        $this->registerRateLimiters();

        // Fail-loud tenancy-footgun guard (F-P1.9-01) — see TenantFootgunGuard for
        // the full rationale; extracted so it is directly unit-testable.
        (new TenantFootgunGuard)->check(isProduction: $this->app->environment('production'));

        // Panel boot-validation (ADR-0009, H.1 / F-P3.2-01): PanelRegistry::class is a
        // LAZY singleton (packageRegistered() only binds the closure); nothing forces
        // it before this line, so a broken panel (bad model/pipe/resource FQCN, or a
        // per-panel id strategy without own tables) previously surfaced only on the
        // FIRST live request that touched a panel. Resolving it here — after config is
        // loaded and route bindings/policies are registered — makes PanelRegistry::
        // validate() run on every boot (incl. `config:cache`/`route:cache`/artisan
        // commands), matching the "fails loud at boot, not at runtime" contract the
        // ADR and InvalidPanelException's own docblock already claimed. class_exists/
        // is_a() checks only — no I/O, safe under config:cache (service-provider.md).
        $this->app->make(PanelRegistry::class);
    }

    /**
     * Register the package's own NAMED rate limiters (P1.3 / F-P1.8-01):
     * `vaulter-public` (the token-resolved public share surface + the collaboration
     * webhooks, keyed by requester IP) and `vaulter-uploads` (the resumable-upload
     * surface, keyed by actor id — falling back to IP when unauthenticated). Each
     * registration is IDEMPOTENT/OVERRIDABLE: `RateLimiter::limiter()` returns null
     * only when nothing has claimed the name yet, so a host that defines its own
     * limiter under the same name (in either direction of boot order) always wins —
     * the package never clobbers a host limiter.
     */
    private function registerRateLimiters(): void
    {
        if (RateLimiter::limiter('vaulter-public') === null) {
            RateLimiter::for('vaulter-public', static fn (Request $request): Limit => Limit::perMinute(
                Config::publicRateLimitPerMinute(),
            )->by($request->ip() ?? 'unknown'));
        }

        if (RateLimiter::limiter('vaulter-uploads') === null) {
            RateLimiter::for('vaulter-uploads', static function (Request $request): Limit {
                $user = $request->user();
                $key = $user instanceof Authorizable
                    ? 'actor:'.Identity::stringify($user->getAuthIdentifier())
                    : 'ip:'.($request->ip() ?? 'unknown');

                return Limit::perMinute(Config::uploadsRateLimitPerMinute())->by($key);
            });
        }
    }

    /**
     * Wire the transactional outbox (§2.11 / D14 / M3b) for the KERNEL events (Access
     * grants, Sharing links): subscribe the RecordsOutbox listener so each stages ONE
     * v_outbox row for reliable webhook fan-out (idempotent by event_id). The Drive
     * file/folder/upload events are staged by vaulter-drive's own RecordsDriveOutbox
     * subscriber (P2 #5) through the same OutboxRecorder seam. The events are
     * ShouldDispatchAfterCommit, so staging runs on the after-commit boundary (see
     * RecordsOutbox for the crash-window caveat). Gated on the 'reliability' feature.
     */
    private function registerOutboxSubscriber(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        Event::subscribe(RecordsOutbox::class);
    }

    /**
     * Wire the append-only activity feed (§2.9 / M2c-activity) for the KERNEL events
     * (Access grants, Sharing links): subscribe the RecordsActivity listener so each
     * writes EXACTLY ONE row (recipients resolved at READ — no fan-out, §1.5). The
     * Drive file/folder/upload events are recorded by vaulter-drive's own
     * RecordsDriveActivity subscriber (P2 #5) through the same ActivityRecorder seam.
     * Gated on the 'activity' feature so a host can switch off auditing entirely.
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
}
