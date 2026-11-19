<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\SharingOptions;
use Vaulter\Drive\Content\OffScanPolicyResolver;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator;
use Vaulter\Drive\Handlers\FileHandlerRegistry;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Tenancy\ShareTenantContext;
use Vaulter\Drive\Vaulter;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Support\Identity;

/**
 * The drive slices, declared by DriveModule: profiles, scan policy,
 * sharing, listing and downloads options, file handlers and presentation, the drive read repository, the
 * `Vaulter` manager and the public-share and upload rate limiters.
 */
#[Internal]
final class DriveSliceRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(ProfilePolicy::class);
        $app->bindIf(ScanPolicyResolver::class, OffScanPolicyResolver::class);
        $app->scoped(ShareTenantContext::class);
        $app->bind(NodeLocator::class);

        // Scoped: DriveReadRepository memoizes find() by id in
        // an in-request identity map, so `AccessResolver::storageOf()` resolved
        // fresh per NodeResource still hits the SAME repository instance across a listing page.
        $app->scoped(DriveReadRepository::class);

        $app->singleton(
            ProfileRegistry::class,
            static function (Application $app): ProfileRegistry {
                $profiles = self::bootOptions($app)->profiles;

                return ProfileRegistry::fromConfig(
                    providerClasses: $profiles->providers,
                    defaultClass: $profiles->default,
                    container: $app,
                );
            },
        );

        $app->singleton(Vaulter::class);
        $app->alias(Vaulter::class, 'vaulter');

        $app->bind(ShareTokenGenerator::class, static function (Application $app): ShareTokenGenerator {
            $generator = $app->make($app->make(SharingOptions::class)->tokenGenerator);
            if (!$generator instanceof ShareTokenGenerator) {
                throw new InvalidConfigurationException('[Vaulter] Sharing token generator binding must return a ShareTokenGenerator.');
            }

            return $generator;
        });

        $app->singleton(FileHandlerRegistry::class);
        $app->bind(\Vaulter\Drive\Contracts\Handlers\FileHandlerRegistry::class, FileHandlerRegistry::class);
    }

    private static function resolveContract(Application $app, string $contract): mixed
    {
        return $app->make($contract);
    }

    public function boot(Application $app): void
    {
        $options = self::bootOptions($app);
        $registry = $app->make(ProfileRegistry::class);
        $downloads = $options->downloads;
        if ($downloads->deliveryMode === 'direct_signed' && (!$app->bound(DirectContentUrlSigner::class) || !self::resolveContract($app, DirectContentUrlSigner::class) instanceof DirectContentUrlSigner)) {
            throw new InvalidConfigurationException('Direct content delivery requires an exact immutable content signer.');
        }
        $policy = new ProfilePolicy($options, $app->make(Repository::class), $registry, $options->scan, $app->make(Kernel::class));
        $scanEnabled = false;
        foreach ($registry->all() as $profile) {
            foreach ($policy->scanSpecs($profile) as $spec) {
                if ($spec->mode !== ScanMode::Off) {
                    $scanEnabled = true;

                    break 2;
                }
            }
        }
        if ($scanEnabled && self::resolveContract($app, ScanPolicyResolver::class) instanceof OffScanPolicyResolver) {
            throw new InvalidConfigurationException('Enabled scan policy requires the drive scan runtime.');
        }
        $app->afterResolving(RateLimiter::class, self::registerRateLimiters(...));
        if ($app->resolved(RateLimiter::class)) {
            self::registerRateLimiters($app->make(RateLimiter::class), $app);
        }
    }

    private static function bootOptions(Application $app): DriveOptions
    {
        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter-drive', []) : [];

        return DriveOptions::fromArray(is_array($raw) ? $raw : [], 'vaulter-drive');
    }

    /** A host limiter of the same name wins: it is registered only when none exists. */
    private static function registerRateLimiters(RateLimiter $limiter, Application $app): void
    {
        if ($limiter->limiter('vaulter-public') === null) {
            $limiter->for('vaulter-public', static fn (Request $request): Limit => Limit::perMinute(
                $app->make(RoutesOptions::class)->rateLimits->public,
            )->by($request->ip() ?? 'unknown'));
        }

        if ($limiter->limiter('vaulter-uploads') === null) {
            $limiter->for('vaulter-uploads', static function (Request $request) use ($app): Limit {
                $user = $request->user();
                $key = $user instanceof Authorizable
                    ? 'actor:'.Identity::stringify($user->getAuthIdentifier())
                    : 'ip:'.($request->ip() ?? 'unknown');

                return Limit::perMinute($app->make(RoutesOptions::class)->rateLimits->uploads)->by($key);
            });
        }
    }
}
