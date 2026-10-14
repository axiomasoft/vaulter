<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Configuration\ChangesConfig;
use Vaulter\Configuration\DownloadsConfig;
use Vaulter\Configuration\ListingConfig;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\OffScanPolicyResolver;
use Vaulter\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Operations\NodeLocator;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\ShareTenantContext;
use Vaulter\VaulterManager;

/**
 * The drive slices located in core until P4 moves them (D39), declared by DriveModule: profiles, scan policy,
 * sharing, listing and downloads config, file handlers and presentation, the drive read repository, the
 * `Vaulter` manager and the public-share and upload rate limiters.
 */
final class DriveSliceRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(ScanConfig::class);
        $app->bind(ProfilePolicy::class);
        $app->bindIf(ScanPolicyResolver::class, OffScanPolicyResolver::class);
        $app->scoped(ShareTenantContext::class);
        $app->singleton(AccessConfig::class);
        $app->singleton(ListingConfig::class);
        $app->singleton(SharingConfig::class);
        $app->singleton(DownloadsConfig::class);
        $app->singleton(ChangesConfig::class);
        $app->bind(NodeLocator::class);

        // Scoped: DriveReadRepository memoizes find() by id in
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

        $app->bind(ShareTokenGenerator::class, static function (Application $app): ShareTokenGenerator {
            $generator = $app->make($app->make(SharingConfig::class)->tokenGenerator());
            if (!$generator instanceof ShareTokenGenerator) {
                throw new InvalidConfigurationException('[Vaulter] Sharing token generator binding must return a ShareTokenGenerator.');
            }

            return $generator;
        });

        // The host-user resolver seam stays in axioma-studio/vaulter-documents
        // (ADR-0015) — collaboration is documents-only machinery.
        //
        // The format-extensibility registry — superseding
        // + the T5 Option-B allocation: a drive-only host (no
        // vaulter-documents installed) still needs a preview/download handler
        // descriptor, so the ENGINE (not just its contract) is core-owned. Singleton —
        // it memoises the merged handler set + resolutions. `Vaulter::handlers()->register()`
        // / `handlers()` resolve this binding unconditionally now (no MissingPackage
        // fail-loud — the engine always exists; only extra formats need a package
        // installed).
        $app->singleton(FileHandlerRegistry::class);
        $app->bind(\Vaulter\Contracts\Handlers\FileHandlerRegistry::class, FileHandlerRegistry::class);

        // The preview and download-only baseline is the drive module's contribution to the FileHandler slot,
        // so a stale or emptied host `handlers` config cannot drop it; `vaulter.file_handlers` stays the host's tag.

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
        $this->registerRateLimiters();
    }

    /** A host limiter of the same name wins: it is registered only when none exists. */
    private function registerRateLimiters(): void
    {
        if (RateLimiter::limiter('vaulter-public') === null) {
            RateLimiter::for('vaulter-public', static fn (Request $request): Limit => Limit::perMinute(
                app(RoutesConfig::class)->publicRateLimit(),
            )->by($request->ip() ?? 'unknown'));
        }

        if (RateLimiter::limiter('vaulter-uploads') === null) {
            RateLimiter::for('vaulter-uploads', static function (Request $request): Limit {
                $user = $request->user();
                $key = $user instanceof Authorizable
                    ? 'actor:'.Identity::stringify($user->getAuthIdentifier())
                    : 'ip:'.($request->ip() ?? 'unknown');

                return Limit::perMinute(app(RoutesConfig::class)->uploadsRateLimit())->by($key);
            });
        }
    }
}
