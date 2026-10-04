<?php

declare(strict_types=1);

namespace Vaulter;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
use Vaulter\Contracts\Handlers\HandlerRegistry;
use Vaulter\Contracts\Handlers\NodeHandlerResolver as NodeHandlerResolverContract;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Engines\DocumentHandlerRegistry;
use Vaulter\Engines\Handlers\DownloadOnlyHandler;
use Vaulter\Engines\Handlers\PreviewHandler;
use Vaulter\Engines\NodeHandlerResolver;
use Vaulter\Http\Middleware\ResolveVaulterRouteParameters;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Policies\NodePolicy;
use Vaulter\Policies\WebhookEndpointPolicy;
use Vaulter\Repositories\Drive\StorageReadRepository;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;
use Vaulter\Support\EnumCaseAttributeResolver;
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
                    ->askToStarRepoOnGitHub('axiomasoft/vaulter');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(VaulterDatabase::class);
        $this->app->singleton(IdStrategyResolver::class);

        // Container-owned, scoped (F-P2.1-05 / P4.6 review): the enum-attribute
        // reflection cache used to be a process-wide `static $cache`, a literal
        // E3.3 violation (global state outside the facade). Binding it here means
        // the cache's lifecycle follows the container instance instead of the
        // class — and scoped() (not singleton(), see StorageReadRepository right
        // below) is what actually resets it between requests/jobs on Octane/queue
        // workers, since singleton() persists for the whole worker process just
        // like the removed static cache did.
        $this->app->scoped(EnumCaseAttributeResolver::class);

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

        // The host-user resolver seam (M4b) stays in axioma-studio/vaulter-documents
        // (P2 #5 / ADR-0015, D2) — collaboration is documents-only machinery.
        //
        // The format-extensibility registry (§4.5 / §7 / H.5 / M4a) — superseding H.5
        // + the T5 Option-B allocation (VLT-CANON P0.4, D10): a drive-only host (no
        // vaulter-documents installed) still needs a preview/download handler
        // descriptor, so the ENGINE (not just its contract) is core-owned. Singleton —
        // it memoises the merged handler set + resolutions. `Vaulter::registerHandler()`
        // / `handlers()` resolve this binding unconditionally now (no MissingPackage
        // fail-loud — the engine always exists; only extra formats like TipTap need a
        // package installed).
        $this->app->singleton(DocumentHandlerRegistry::class);
        $this->app->bind(HandlerRegistry::class, DocumentHandlerRegistry::class);

        // The baseline is TAGGED, not merely config-defaulted (P0.4 review, HIGH-1).
        // `hasConfigFile()` merges via Laravel's shallow, existing-wins mergeConfigFrom:
        // a host that ran `vendor:publish --tag=vaulter-config` BEFORE P0.4 carries a
        // literal `'handlers' => []` in its own config, which would WIN over core's new
        // default and silently drop PreviewHandler (exactly the drive-only host P0.4
        // exists for). The tag is a second, config-independent source the registry
        // merges and de-duplicates by FQCN, so the baseline survives a stale or emptied
        // config — and the merge stops depending on provider-registration order.
        $this->app->tag([PreviewHandler::class, DownloadOnlyHandler::class], 'vaulter.handlers');

        // NodeResource resolves this seam to embed the handler descriptor on every
        // leaf node — unconditionally bound, so Drive-only serialisation gets a real
        // preview/download descriptor instead of degrading to null (P0.4/D10).
        $this->app->bind(NodeHandlerResolverContract::class, NodeHandlerResolver::class);

        $this->app->singleton(
            MetricsRecorder::class,
            static function (Application $app): MetricsRecorder {
                /** @var array{enabled?: bool, recorder?: string|null} $settings */
                $settings = $app->make('config')->get('vaulter.observability', []);

                if (!($settings['enabled'] ?? false)) {
                    return new NullMetricsRecorder;
                }

                $class = $settings['recorder'] ?? null;

                if (!is_string($class) || $class === '' || !class_exists($class)) {
                    self::logInvalidObservabilityRecorder(reason: 'missing_or_unknown_class', class: is_string($class) ? $class : null);

                    return new NullMetricsRecorder;
                }

                if (!is_a($class, MetricsRecorder::class, true)) {
                    self::logInvalidObservabilityRecorder(reason: 'does_not_implement_contract', class: $class);

                    return new NullMetricsRecorder;
                }

                $recorder = $app->make($class);

                if (!$recorder instanceof MetricsRecorder) {
                    self::logInvalidObservabilityRecorder(reason: 'container_returned_unexpected_type', class: $class);

                    return new NullMetricsRecorder;
                }

                return $recorder;
            },
        );

        $this->app->singleton(
            TraceRecorder::class,
            static function (Application $app): TraceRecorder {
                /** @var array{tracing?: array{enabled?: bool, recorder?: string|null}} $settings */
                $settings = $app->make('config')->get('vaulter.observability', []);
                $tracing = $settings['tracing'] ?? [];

                if (!($tracing['enabled'] ?? false)) {
                    return new NullTraceRecorder;
                }

                $class = $tracing['recorder'] ?? null;

                if (!is_string($class) || $class === '' || !class_exists($class)) {
                    self::logInvalidTraceRecorder(reason: 'missing_or_unknown_class', class: is_string($class) ? $class : null);

                    return new NullTraceRecorder;
                }

                if (!is_a($class, TraceRecorder::class, true)) {
                    self::logInvalidTraceRecorder(reason: 'does_not_implement_contract', class: $class);

                    return new NullTraceRecorder;
                }

                $recorder = $app->make($class);

                if (!$recorder instanceof TraceRecorder) {
                    self::logInvalidTraceRecorder(reason: 'container_returned_unexpected_type', class: $class);

                    return new NullTraceRecorder;
                }

                return $recorder;
            },
        );
    }

    private static function logInvalidObservabilityRecorder(string $reason, ?string $class): void
    {
        Log::warning('Vaulter observability recorder misconfigured; using NullMetricsRecorder.', [
            'reason' => $reason,
            'recorder' => $class,
        ]);
    }

    private static function logInvalidTraceRecorder(string $reason, ?string $class): void
    {
        Log::warning('Vaulter tracing recorder misconfigured; using NullTraceRecorder.', [
            'reason' => $reason,
            'recorder' => $class,
        ]);
    }

    public function packageBooted(): void
    {
        $this->registerResourceMiddlewarePriority($this->app->make(Kernel::class));
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

    private function registerResourceMiddlewarePriority(Kernel $kernel): void
    {
        if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, ResolveVaulterRouteParameters::class);
        }
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
}
