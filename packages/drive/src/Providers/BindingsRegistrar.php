<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Content\NodeContentLifecycleRegistry;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Contracts\Content\ScanWorkSettings;
use Vaulter\Contracts\Drive\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Content\DocumentPurgeLifecycle;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Content\ShortcutPurgeLifecycle;
use Vaulter\Drive\DriveHandleFactory;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Uploads\Inspection\FilenameInspector;
use Vaulter\Drive\Uploads\Inspection\MimeConsistencyInspector;
use Vaulter\Drive\Uploads\Inspection\ScanDispatchInspector;
use Vaulter\Drive\Uploads\Inspection\SizeInspector;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Modules\ModuleBindings;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;

/**
 * The container wiring of the drive module ({@see \Vaulter\Drive\DriveModule}): drive config, scan policy and
 * scanner validation, the drive handle factory, uploads, content lifecycles, the drive problem codes and the
 * drive config validator.
 */
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(DriveConfig::class, static fn (Container $app): DriveConfig => new DriveConfig($app->make('config')));
        $app->bind(ScanWorkSettings::class, DriveConfig::class);

        $app->singleton(ScanPolicyResolver::class, \Vaulter\Drive\Content\ScanPolicyResolver::class);
        $app->singleton(\Vaulter\Drive\Content\ScanPolicyResolver::class);
        // After every provider booted: a host may bind its ContentScanner from its own provider.
        $app->booted(function () use ($app): void {
            $this->validateScanner($app);
        });
        $app->bind(DriveHandleFactoryContract::class, DriveHandleFactory::class);
        $app->singleton(DriveProvisioner::class);
        $app->singleton(UploadSessionManager::class);
        $app->tag([FilenameInspector::class, SizeInspector::class, MimeConsistencyInspector::class, ScanDispatchInspector::class], 'vaulter.upload_inspectors');
        $app->singleton(DriveProblemCodes::class);
        $app->tag(DriveProblemCodes::class, ProblemCatalog::TAG);
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
            // Drive owns the stand-in document purge: with the documents package installed
            // its lifecycle, collected from the tag, takes the kind over.
            $registry->registerFallback($resolve(DocumentPurgeLifecycle::class));
            foreach ([FileContentLifecycle::class, ShortcutPurgeLifecycle::class,
                ...$configured, ...$app->tagged('vaulter.content_lifecycles')] as $class) {
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
            if (!class_exists($class) || !is_a($class, ContentScanner::class, true)) {
                throw new InvalidConfigurationException('Scanner must implement ContentScanner.');
            }
            if (!$app->bound(ContentScanner::class)) {
                $app->singleton(ContentScanner::class, $class);
            }
        }
        if (!$app->bound(ContentScanner::class)) {
            if ($enabled) {
                throw new InvalidConfigurationException('Enabled scan policy requires a configured scanner.');
            }

            return;
        }
        $app->make(DriveConfig::class)->validateScanWork(requireBroker: true);
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
