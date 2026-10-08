<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Configuration\ScanConfig;
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
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Uploads\Inspection\FilenameInspector;
use Vaulter\Drive\Uploads\Inspection\MimeConsistencyInspector;
use Vaulter\Drive\Uploads\Inspection\ScanDispatchInspector;
use Vaulter\Drive\Uploads\Inspection\SizeInspector;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;

final class BindingsRegistrar extends ServiceProvider
{
    public function register(): void
    {
        $path = __DIR__.'/../../config/vaulter-drive.php';
        $this->mergeConfigFrom(path: $path, key: 'vaulter-drive');
        $this->app->singleton(DriveConfig::class, fn (Container $app): DriveConfig => new DriveConfig($app->make('config')));
        $this->app->bind(ScanWorkSettings::class, DriveConfig::class);
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-drive', path: $path);

        $this->app->singleton(ScanPolicyResolver::class, \Vaulter\Drive\Content\ScanPolicyResolver::class);
        $this->app->singleton(\Vaulter\Drive\Content\ScanPolicyResolver::class);
        $this->app->booted(function (): void {
            $this->validateScanner();
            (new ConsoleRegistrar)->boot($this->app);
        });
        $this->app->bind(DriveHandleFactoryContract::class, DriveHandleFactory::class);
        $this->app->singleton(DriveProvisioner::class);
        $this->app->singleton(UploadSessionManager::class);
        $this->app->tag([FilenameInspector::class, SizeInspector::class, MimeConsistencyInspector::class, ScanDispatchInspector::class], 'vaulter.upload_inspectors');
        $this->app->singleton(NodeContentLifecycleRegistry::class, function (Container $app): NodeContentLifecycleRegistry {
            $configured = $app->make('config')->get('vaulter.content_lifecycles', []);
            if (!is_array($configured) || !array_is_list($configured)) {
                throw new \LogicException('Content lifecycles configuration must be a list of lifecycle classes.');
            }
            $registry = new NodeContentLifecycleRegistry;
            $resolve = function (mixed $class) use ($app): NodeContentLifecycle {
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

    private function validateScanner(): void
    {
        $this->app->make(DriveConfig::class)->validateScanWork();
        $config = $this->app->make(ScanConfig::class);
        $policy = $this->app->make(ProfilePolicy::class);
        $enabled = false;
        foreach ($this->app->make(ProfileRegistry::class)->all() as $profile) {
            foreach ($policy->scanSpecs($profile) as $spec) {
                $enabled = $enabled || $spec->mode !== ScanMode::Off;
            }
        }
        $class = $config->scanner();
        if ($class !== null) {
            if (!class_exists($class) || !is_a($class, ContentScanner::class, true)) {
                throw new InvalidConfigurationException('Scanner must implement ContentScanner.');
            }
            if (!$this->app->bound(ContentScanner::class)) {
                $this->app->singleton(ContentScanner::class, $class);
            }
        }
        if (!$this->app->bound(ContentScanner::class)) {
            if ($enabled) {
                throw new InvalidConfigurationException('Enabled scan policy requires a configured scanner.');
            }

            return;
        }
        $this->app->make(DriveConfig::class)->validateScanWork(requireBroker: true);
        $scanner = $this->app->make(ContentScanner::class);
        $spec = $config->globalSpec();
        if (($class !== null && !$scanner instanceof $class)
            || $scanner->key() !== $spec->scannerKey || $scanner->version() !== $spec->scannerVersion) {
            throw new InvalidConfigurationException('Bound scanner identity differs from configured identity.');
        }
    }

    public function boot(): void
    {
        // Invalid or duplicate registrations fail at application boot.
        $this->app->make(NodeContentLifecycleRegistry::class);
    }
}
