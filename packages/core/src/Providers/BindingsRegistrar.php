<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Configuration\ChangesConfig;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\DownloadsConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Configuration\ListingConfig;
use Vaulter\Configuration\ObservabilityConfig;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Content\OffScanPolicyResolver;
use Vaulter\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Contracts\Observability\TraceRecorder;
use Vaulter\Contracts\Profiles\CommentsFeature;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Database\WriteScope;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\Observability\NullMetricsRecorder;
use Vaulter\Observability\NullTraceRecorder;
use Vaulter\Operations\NodeLocator;
use Vaulter\Profiles\DisabledCommentsFeature;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\EnumCaseAttributeResolver;
use Vaulter\Tenancy\ShareTenantContext;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\Tenancy\TenancyBypass;
use Vaulter\VaulterManager;
use Vaulter\Webhooks\SystemHostResolver;

final class BindingsRegistrar
{
    public function register(Application $app): void
    {
        $app->singleton(VaulterConfig::class);
        $app->singleton(ScanConfig::class);
        $app->bind(ProfilePolicy::class);
        $app->bindIf(CommentsFeature::class, DisabledCommentsFeature::class);
        $app->bindIf(ScanPolicyResolver::class, OffScanPolicyResolver::class);
        $app->singleton(DatabaseConfig::class);
        $app->singleton(IdsConfig::class);
        $app->singleton(TenancyConfig::class);
        $app->scoped(TenancyBypass::class);
        $app->scoped(ShareTenantContext::class);
        $app->bind(TenancyBoundary::class);
        $app->scoped(TenantResolver::class, static function (Application $app): TenantResolver {
            $resolver = $app->make($app->make(TenancyConfig::class)->resolver());
            if (!$resolver instanceof TenantResolver) {
                throw new TenancyMisconfiguredException('TenantResolver binding returned an invalid object.');
            }

            return $resolver;
        });
        $app->singleton(AccessConfig::class);
        $app->singleton(RoutesConfig::class);
        $app->singleton(ListingConfig::class);
        $app->singleton(SharingConfig::class);
        $app->singleton(DownloadsConfig::class);
        $app->singleton(WebhooksConfig::class);
        $app->singleton(ChangesConfig::class);
        $app->singleton(ObservabilityConfig::class);
        $app->singletonIf(ConfigNormalizer::class);
        $app->singleton(ConfigValidator::class);

        $app->singleton(VaulterDatabase::class);
        $app->scoped(WriteScope::class);
        $app->bind(NodeLocator::class);
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
            static fn (Application $app): ProfileRegistry => ProfileRegistry::fromConfig(
                providerClasses: $app->make(VaulterConfig::class)->profileProviders(),
                defaultClass: $app->make(VaulterConfig::class)->defaultProfile(),
                container: $app,
            ),
        );

        $app->singleton(VaulterManager::class);
        $app->alias(VaulterManager::class, 'vaulter');

        // Drive behaviour (StorageManager, the DriveHandle factory, resumable uploads)
        // moved to axioma-studio/vaulter-drive (P2 #5 / ADR-0015, D1). Core resolves the
        // Contracts\Drive\DriveHandleFactory seam that package binds; `Vaulter::for()`
        // fails loudly when the package is absent.

        $app->bind(ShareTokenGenerator::class, static function (Application $app): ShareTokenGenerator {
            $generator = $app->make($app->make(SharingConfig::class)->tokenGenerator());
            if (!$generator instanceof ShareTokenGenerator) {
                throw new InvalidConfigurationException('[Vaulter] Sharing token generator binding must return a ShareTokenGenerator.');
            }

            return $generator;
        });

        // Outbound webhook SSRF guard (§2.11 / D5 / F-P1.7-01): the host resolver is
        // the injectable DNS seam (A + AAAA), and the guard is host-swappable via
        // delivery path (DeliverWebhook) and — as an early reject — at registration.
        $app->bind(HostResolver::class, SystemHostResolver::class);
        $app->bind(OutboundUrlGuard::class, static function (Application $app): OutboundUrlGuard {
            $guard = $app->make($app->make(WebhooksConfig::class)->urlGuard());
            if (!$guard instanceof OutboundUrlGuard) {
                throw new InvalidConfigurationException('[Vaulter] Outbound URL guard binding must return an OutboundUrlGuard.');
            }

            return $guard;
        });

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
        // fail-loud — the engine always exists; only extra formats need a package
        // installed).
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

                $settings = $app->make(ObservabilityConfig::class);

                if (!$settings->enabled()) {
                    return new NullMetricsRecorder;
                }

                $class = $settings->recorder();

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

                $settings = $app->make(ObservabilityConfig::class);

                if (!$settings->tracingEnabled()) {
                    return new NullTraceRecorder;
                }

                $class = $settings->tracingRecorder();

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

    private static function resolveContract(Application $app, string $contract): mixed
    {
        return $app->make($contract);
    }

    public function boot(Application $app): void
    {
        $app->make(ProfileRegistry::class);
        $downloads = $app->make(DownloadsConfig::class);
        $downloads->directTtlSeconds();
        if ($downloads->deliveryMode() === 'direct_signed' && (!$app->bound(DirectContentUrlSigner::class) || !self::resolveContract($app, DirectContentUrlSigner::class) instanceof DirectContentUrlSigner)) {
            throw new InvalidConfigurationException('Direct content delivery requires an exact immutable content signer.');
        }
        if (self::resolveContract($app, ScanPolicyResolver::class) instanceof OffScanPolicyResolver) {
            foreach ($app->make(ProfileRegistry::class)->all() as $profile) {
                foreach ($app->make(ProfilePolicy::class)->scanSpecs($profile) as $spec) {
                    if ($spec->mode !== ScanMode::Off) {
                        throw new InvalidConfigurationException('Enabled scan policy requires the drive scan runtime.');
                    }
                }
            }
        }
    }
}
