<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;
use Vaulter\Support\EnumCaseAttributeResolver;
use Vaulter\VaulterManager;
use Vaulter\Webhooks\SystemHostResolver;

final class BindingsRegistrar
{
    public function register(Application $app): void
    {
        $app->singleton(VaulterDatabase::class);
        $app->singleton(IdStrategyResolver::class);

        // Container-owned, scoped (F-P2.1-05 / P4.6 review): the enum-attribute
        // reflection cache used to be a process-wide `static $cache`, a literal
        // E3.3 violation (global state outside the facade). Binding it here means
        // the cache's lifecycle follows the container instance instead of the
        // class — and scoped() (not singleton(), see DriveReadRepository right
        // below) is what actually resets it between requests/jobs on Octane/queue
        // workers, since singleton() persists for the whole worker process just
        // like the removed static cache did.
        $app->scoped(EnumCaseAttributeResolver::class);

        // Scoped (P1.3 / F-P4.2-03): DriveReadRepository memoizes find() by id in
        // an in-request identity map, so `AccessResolver::storageOf()` resolved
        // fresh per NodeResource (no explicit AccessResolver binding — see below)
        // still hits the SAME repository instance across a listing page, collapsing
        // the storage lookup from O(N) to O(1) per page. scoped() (not singleton())
        // resets the cache between requests/jobs on Octane/queue workers.
        $app->scoped(DriveReadRepository::class);

        $app->singleton(
            ProfileRegistry::class,
            static fn (): ProfileRegistry => ProfileRegistry::fromConfig(
                providerClasses: Config::panels(),
                defaultClass: Config::defaultPanel(),
            ),
        );

        $app->singleton(VaulterManager::class);
        $app->alias(VaulterManager::class, 'vaulter');

        // Drive behaviour (StorageManager, the DriveHandle factory, resumable uploads)
        // moved to axioma-studio/vaulter-drive (P2 #5 / ADR-0015, D1). Core resolves the
        // Contracts\Drive\DriveHandleFactory seam that package binds; `Vaulter::for()`
        // fails loudly when the package is absent.

        // The bearer share-link generator (M2c / §2.7). nodeShareTokenGeneratorClass
        // reads config('vaulter.share.token_generator') and falls back to the legacy
        // top-level key, so the one binding serves both the Drive bearer links and
        // the legacy Documents shares (both default to RandomShareTokenGenerator).
        $app->bind(ShareTokenGenerator::class, Config::nodeShareTokenGeneratorClass());

        // Outbound webhook SSRF guard (§2.11 / D5 / F-P1.7-01): the host resolver is
        // the injectable DNS seam (A + AAAA), and the guard is host-swappable via
        // config('vaulter.webhooks.delivery.url_guard'). The barrier runs on the
        // delivery path (DeliverWebhook) and — as an early reject — at registration.
        $app->bind(HostResolver::class, SystemHostResolver::class);
        $app->bind(OutboundUrlGuard::class, Config::webhookUrlGuardClass());

        // CAS services (H.3) — the concrete ContentStore/StorageDriver/BlobStore
        // engine now lives in axioma-studio/vaulter-media (P2 #5, D2/D3); its
        // provider binds them against the Contracts\Cas\* seam this package owns.
        // Core resolves those contracts from the container when a host installs it.

        // The host-user resolver seam (M4b) stays in axioma-studio/vaulter-documents
        // (P2 #5 / ADR-0015, D2) — collaboration is documents-only machinery.
        //
        // The format-extensibility registry (§4.5 / §7 / H.5 / M4a) — superseding H.5
        // + the T5 Option-B allocation (VLT-CANON P0.4, D10): a drive-only host (no
        // vaulter-documents installed) still needs a preview/download handler
        // descriptor, so the ENGINE (not just its contract) is core-owned. Singleton —
        // it memoises the merged handler set + resolutions. `Vaulter::handlers()->register()`
        // / `handlers()` resolve this binding unconditionally now (no MissingPackage
        // fail-loud — the engine always exists; only extra formats like TipTap need a
        // package installed).
        $app->singleton(FileHandlerRegistry::class);
        $app->bind(\Vaulter\Contracts\Handlers\FileHandlerRegistry::class, FileHandlerRegistry::class);

        // The baseline is TAGGED, not merely config-defaulted (P0.4 review, HIGH-1).
        // `hasConfigFile()` merges via Laravel's shallow, existing-wins mergeConfigFrom:
        // a host that ran `vendor:publish --tag=vaulter-config` BEFORE P0.4 carries a
        // literal `'handlers' => []` in its own config, which would WIN over core's new
        // default and silently drop PreviewHandler (exactly the drive-only host P0.4
        // exists for). The tag is a second, config-independent source the registry
        // merges and de-duplicates by FQCN, so the baseline survives a stale or emptied
        // config — and the merge stops depending on provider-registration order.
        $app->tag([PreviewHandler::class, DownloadOnlyHandler::class], 'vaulter.file_handlers');

        // NodeResource resolves this seam to embed the handler descriptor on every
        // leaf node — unconditionally bound, so Drive-only serialisation gets a real
        // preview/download descriptor instead of degrading to null (P0.4/D10).
        $app->bind(NodePresenter::class, \Vaulter\Handlers\NodePresenter::class);

        $app->singleton(
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

        $app->singleton(
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

    public function boot(Application $app): void
    {
        $app->make(ProfileRegistry::class);
    }
}
