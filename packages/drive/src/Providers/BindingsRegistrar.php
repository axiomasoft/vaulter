<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\DocumentPurgeLifecycle;
use Vaulter\Drive\Content\NodeContentLifecycleRegistry;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Contracts\Content\ScanWorkSettings;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Modules\ModuleBindings;

/**
 * The container wiring of the drive module ({@see DriveModule}): drive config, scan policy and
 * scanner validation, uploads, content lifecycles, the
 * drive config validator.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(DriveConfig::class, static fn (Container $app): DriveConfig => new DriveConfig($app->make('config')));
        $app->bind(ScanWorkSettings::class, DriveConfig::class);

        $app->singleton(ScanPolicyResolver::class, \Vaulter\Drive\Content\ScanPolicyResolver::class);
        $app->singleton(\Vaulter\Drive\Content\ScanPolicyResolver::class);
        // After every provider booted: the scanner is the capability the media module provides from config.
        $app->booted(function () use ($app): void {
            $this->validateScanner($app);
        });
        $app->singleton(DriveProvisioner::class);
        $app->singleton(UploadSessionManager::class);
        $app->bind('vaulter.config_validator.drive', static fn (Container $app): Closure => static function () use ($app): void {
            $config = $app->make(DriveConfig::class);
            $config->model('upload_session');
            $config->model('upload_part');
            $config->validateScanWork();
        });
        $app->tag('vaulter.config_validator.drive', ConfigValidCheck::TAG);
        $app->singleton(NodeContentLifecycleRegistry::class, static function (Container $app): NodeContentLifecycleRegistry {
            $configured = $app->make('config')->get('vaulter.content_lifecycles', []);
            if (!is_array($configured) || !array_is_list($configured)) {
                throw new \LogicException('Content lifecycles configuration must be a list of lifecycle classes.');
            }
            $registry = new NodeContentLifecycleRegistry;
            $resolve = static function (mixed $class) use ($app): NodeContentLifecycle {
                if (is_string($class) && (!class_exists($class) || !is_a($class, NodeContentLifecycle::class, true))) {
                    throw new \LogicException('Content lifecycle class must implement NodeContentLifecycle.');
                }
                $handler = is_string($class) ? $app->make($class) : $class;
                if (!$handler instanceof NodeContentLifecycle) {
                    throw new \LogicException('Content lifecycle must implement NodeContentLifecycle.');
                }

                return $handler;
            };
            // Drive owns the stand-in document purge: with the documents module active its
            // contribution to the NodeContentLifecycle slot takes the kind over.
            $registry->registerFallback($resolve(DocumentPurgeLifecycle::class));
            foreach ($app->make(Kernel::class)->extensions()->contributions(NodeContentLifecycle::class) as $contribution) {
                $registry->register($resolve($contribution->implementation));
            }
            foreach ([...$configured, ...$app->tagged('vaulter.content_lifecycles')] as $class) {
                $registry->register($resolve($class));
            }

            return $registry;
        });
    }

    private function validateScanner(Application $app): void
    {
        $app->make(DriveConfig::class)->validateScanWork();
        $config = $app->make(ScanConfig::class);
        $policy = $app->make(ProfilePolicy::class);
        $enabled = false;
        foreach ($app->make(ProfileRegistry::class)->all() as $profile) {
            foreach ($policy->scanSpecs($profile) as $spec) {
                $enabled = $enabled || $spec->mode !== ScanMode::Off;
            }
        }
        $class = $config->scanner();
        if ($class !== null) {
            // The configured class-string is validated, never probed for activation (config-validation).
            if (!class_exists($class) || !is_a($class, ContentScanner::class, true)) {
                throw new InvalidConfigurationException('Scanner must implement ContentScanner.');
            }
            // The `vaulter-drive.uploads.scan.scanner` alias is folded into the key the capability reads.
            $repository = $app->make('config');
            if ($repository->get('vaulter.scan.scanner') === null) {
                $repository->set('vaulter.scan.scanner', $class);
            }
        }
        $capabilities = $app->make(Kernel::class)->capabilities();
        if (!$capabilities->has(ContentScanner::class)) {
            if ($enabled) {
                throw new InvalidConfigurationException('Enabled scan policy requires a configured scanner.');
            }

            return;
        }
        $app->make(DriveConfig::class)->validateScanWork(requireBroker: true);
        // The instance jobs and doctor resolve: the container singleton, not a second `Capabilities::require()` build.
        $scanner = $app->make(ContentScanner::class);
        $spec = $config->globalSpec();
        if (($class !== null && !$scanner instanceof $class)
            || $scanner->key() !== $spec->scannerKey || $scanner->version() !== $spec->scannerVersion) {
            throw new InvalidConfigurationException('Bound scanner identity differs from configured identity.');
        }
    }

    public function boot(Application $app): void
    {
        $config = $app->make(DriveConfig::class);
        $config->model('upload_session');
        $config->model('upload_part');
        // Fail at boot on a bad value; the tasks themselves read the config when the scheduler resolves.
        (new ScheduleConfig($app->make('config')))->frequencies('vaulter-drive', SchedulingRegistrar::KEYS);
        // Invalid or duplicate registrations fail at application boot.
        $app->make(NodeContentLifecycleRegistry::class);
    }
}
