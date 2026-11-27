<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Vaulter\Configuration\CompiledOptionsCache;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Drive\Configuration\AccessOptions;
use Vaulter\Drive\Configuration\ChangesOptions;
use Vaulter\Drive\Configuration\ConfiguredScanWorkSettings;
use Vaulter\Drive\Configuration\DownloadsOptions;
use Vaulter\Drive\Configuration\DriveFeaturesOptions;
use Vaulter\Drive\Configuration\DriveModelsOptions;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Configuration\DriveOptionsValidator;
use Vaulter\Drive\Configuration\DriveScheduleOptions;
use Vaulter\Drive\Configuration\DrivesOptions;
use Vaulter\Drive\Configuration\ListingOptions;
use Vaulter\Drive\Configuration\ProfilesOptions;
use Vaulter\Drive\Configuration\QuotaOptions;
use Vaulter\Drive\Configuration\ResumableMetadataOptions;
use Vaulter\Drive\Configuration\ResumableUploadsOptions;
use Vaulter\Drive\Configuration\ScanOptions;
use Vaulter\Drive\Configuration\SharingOptions;
use Vaulter\Drive\Configuration\TrashOptions;
use Vaulter\Drive\Configuration\UploadsOptions;
use Vaulter\Drive\Configuration\UploadsScanOptions;
use Vaulter\Drive\Content\DocumentPurgeLifecycle;
use Vaulter\Drive\Content\NodeContentLifecycleRegistry;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Contracts\Content\ScanWorkSettings;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\Uploads\MultipartUploadPort;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Drive\Uploads\DirectMultipartUpload;
use Vaulter\Drive\Uploads\S3MultipartUploadPort;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Kernel;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Modules\ModuleBindings;
use WeakMap;

/**
 * Container wiring of {@see DriveModule}: compiled drive options, scan policy and
 * scanner validation, uploads, content lifecycles, and runtime validation.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bindIf(MultipartUploadPort::class, S3MultipartUploadPort::class);
        $app->when(DirectMultipartUpload::class)->needs(Closure::class)->give(
            static fn (Container $app): Closure => static fn (): ContentScanner => $app->make(ContentScanner::class),
        );
        $app->bind(DriveOptions::class, static fn (Container $app): DriveOptions => CompiledOptionsCache::resolve(
            $app,
            'vaulter-drive',
            static fn (array $raw): DriveOptions => DriveOptions::fromArray($raw, 'vaulter-drive'),
        ));
        $app->bind(ProfilesOptions::class, static fn (Container $app): ProfilesOptions => $app->make(DriveOptions::class)->profiles);
        $app->bind(ScanOptions::class, static fn (Container $app): ScanOptions => $app->make(DriveOptions::class)->scan);
        $app->bind(DriveFeaturesOptions::class, static fn (Container $app): DriveFeaturesOptions => $app->make(DriveOptions::class)->features);
        $app->bind(DrivesOptions::class, static fn (Container $app): DrivesOptions => $app->make(DriveOptions::class)->drives);
        $app->bind(AccessOptions::class, static fn (Container $app): AccessOptions => $app->make(DriveOptions::class)->access);
        $app->bind(ListingOptions::class, static fn (Container $app): ListingOptions => $app->make(DriveOptions::class)->listing);
        $app->bind(SharingOptions::class, static fn (Container $app): SharingOptions => $app->make(DriveOptions::class)->sharing);
        $app->bind(DownloadsOptions::class, static fn (Container $app): DownloadsOptions => $app->make(DriveOptions::class)->downloads);
        $app->bind(ChangesOptions::class, static fn (Container $app): ChangesOptions => $app->make(DriveOptions::class)->changes);
        $app->bind(UploadsOptions::class, static fn (Container $app): UploadsOptions => $app->make(DriveOptions::class)->uploads);
        $app->bind(UploadsScanOptions::class, static fn (Container $app): UploadsScanOptions => $app->make(DriveOptions::class)->uploads->scan);
        $app->bind(ResumableUploadsOptions::class, static fn (Container $app): ResumableUploadsOptions => $app->make(DriveOptions::class)->uploads->resumable);
        $app->bind(ResumableMetadataOptions::class, static fn (Container $app): ResumableMetadataOptions => $app->make(DriveOptions::class)->uploads->resumable->metadata);
        $app->bind(TrashOptions::class, static fn (Container $app): TrashOptions => $app->make(DriveOptions::class)->trash);
        $app->bind(QuotaOptions::class, static fn (Container $app): QuotaOptions => $app->make(DriveOptions::class)->quota);
        $app->bind(DriveScheduleOptions::class, static fn (Container $app): DriveScheduleOptions => $app->make(DriveOptions::class)->schedule);
        $app->bind(DriveModelsOptions::class, static fn (Container $app): DriveModelsOptions => $app->make(DriveOptions::class)->models);

        $app->bind(ConfiguredScanWorkSettings::class, static fn (Container $app): ConfiguredScanWorkSettings => new ConfiguredScanWorkSettings($app->make(DriveOptions::class)));
        $app->bind(ScanWorkSettings::class, ConfiguredScanWorkSettings::class);
        $app->bind(TenancyBoundary::class);

        $app->bind(ScanPolicyResolver::class, \Vaulter\Drive\Content\ScanPolicyResolver::class);
        $app->bind(\Vaulter\Drive\Content\ScanPolicyResolver::class);
        $app->booted(function () use ($app): void {
            $this->validateScanner($app);
        });
        $app->bind(DriveProvisioner::class, static fn (Application $app): DriveProvisioner => self::memoizeByOptions(
            $app,
            DriveProvisioner::class,
            static fn (): DriveProvisioner => $app->build(DriveProvisioner::class),
        ));
        $app->bind(UploadSessionManager::class, static fn (Application $app): UploadSessionManager => self::memoizeByOptions(
            $app,
            UploadSessionManager::class,
            static fn (): UploadSessionManager => $app->build(UploadSessionManager::class),
        ));
        $validate = static function () use ($app): void {
            $options = self::buildOptions($app);
            $validator = new DriveOptionsValidator($app->make(Repository::class));
            $validator->validate($options, $app);
            $validator->validateScanWork($options);
        };
        $app->instance('vaulter.config_validator.drive', $validate);
        $app->tag('vaulter.config_validator.drive', ConfigValidCheck::TAG);
        ConfigSectionRegistration::registerValidator($app, 'vaulter.drive', $validate);

        $app->bind(NodeContentLifecycleRegistry::class, static fn (Application $app): NodeContentLifecycleRegistry => self::memoizeByOptions(
            $app,
            NodeContentLifecycleRegistry::class,
            static function () use ($app): NodeContentLifecycleRegistry {
                $configured = $app->make('config')->get('vaulter.content_lifecycles', []);
                if (!is_array($configured) || !array_is_list($configured)) {
                    throw new LogicException('Content lifecycles configuration must be a list of lifecycle classes.');
                }
                $registry = new NodeContentLifecycleRegistry;
                $resolve = static function (mixed $class) use ($app): NodeContentLifecycle {
                    if (is_string($class) && (!class_exists($class) || !is_a($class, NodeContentLifecycle::class, true))) {
                        throw new LogicException('Content lifecycle class must implement NodeContentLifecycle.');
                    }
                    $handler = is_string($class) ? $app->make($class) : $class;
                    if (!$handler instanceof NodeContentLifecycle) {
                        throw new LogicException('Content lifecycle must implement NodeContentLifecycle.');
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
            },
        ));
    }

    private function validateScanner(Application $app): void
    {
        $options = self::buildOptions($app);
        $validator = new DriveOptionsValidator($app->make(Repository::class));
        $validator->validateScanWork($options);
        $repository = $app->make(Repository::class);
        $registry = $app->make(ProfileRegistry::class);
        $policy = new ProfilePolicy($options, $repository, $registry, $options->scan, $app->make(Kernel::class));
        $enabled = false;
        foreach ($registry->all() as $profile) {
            foreach ($policy->scanSpecs($profile) as $spec) {
                $enabled = $enabled || $spec->mode !== ScanMode::Off;
            }
        }
        $class = $options->scan->scanner;
        if ($class !== null) {
            if (!class_exists($class) || !is_a($class, ContentScanner::class, true)) {
                throw new InvalidConfigurationException('Scanner must implement ContentScanner.');
            }
            if ($repository->get('vaulter-drive.scan.scanner') === null) {
                $repository->set('vaulter-drive.scan.scanner', $class);
            }
        }
        $capabilities = $app->make(Kernel::class)->capabilities();
        if (!$capabilities->has(ContentScanner::class)) {
            if ($enabled) {
                throw new InvalidConfigurationException('Enabled scan policy requires a configured scanner.');
            }

            return;
        }
        $validator->validateScanWork($options, requireBroker: true);
        $scanner = $app->make(ContentScanner::class);
        $spec = $options->scan->globalSpec();
        if (($class !== null && !$scanner instanceof $class)
            || $scanner->key() !== $spec->scannerKey || $scanner->version() !== $spec->scannerVersion) {
            throw new InvalidConfigurationException('Bound scanner identity differs from configured identity.');
        }
    }

    public function boot(Application $app): void
    {
        $options = self::buildOptions($app);
        new DriveOptionsValidator($app->make(Repository::class))->validate($options, $app);
        // The stable morph aliases (`vaulter.node`, `vaulter.drive`) are stored in polymorphic columns, so a host's
        // morphTo() must resolve them to the configured model classes. Merged into the host's map once every
        // provider booted (never replacing it), and without enforceMorphMap().
        $app->booted(static function () use ($app): void {
            $bootOptions = self::buildOptions($app);
            $drive = $bootOptions->models->drive;
            $node = $bootOptions->models->node;
            Relation::morphMap([$drive::MORPH_ALIAS => $drive, $node::MORPH_ALIAS => $node], merge: true);
        });
        // Invalid or duplicate registrations fail at application boot.
        $app->make(NodeContentLifecycleRegistry::class);
    }

    private static function buildOptions(Container $app): DriveOptions
    {
        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter-drive', []) : [];

        return DriveOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'vaulter-drive');
    }

    /** @var WeakMap<Application, array<string, array{0: array{DriveOptions, ?MediaOptions, ?VaulterOptions, ?ProfileRegistry}, 1: object}>>|null */
    private static ?WeakMap $optionsMemo = null;

    /**
     * @template T of object
     *
     * @param  Closure(): T $build
     * @return T
     */
    private static function memoizeByOptions(Application $app, string $key, Closure $build): object
    {
        $cache = self::$optionsMemo ??= new WeakMap;

        $sig = [
            $app->make(DriveOptions::class),
            $app->bound(MediaOptions::class) ? $app->make(MediaOptions::class) : null,
            $app->bound(VaulterOptions::class) ? $app->make(VaulterOptions::class) : null,
            $app->bound(ProfileRegistry::class) ? $app->make(ProfileRegistry::class) : null,
        ];
        $entries = $cache[$app] ?? [];

        if (isset($entries[$key]) && $entries[$key][0] === $sig) {
            /** @var T $cached */
            $cached = $entries[$key][1];

            return $cached;
        }

        $instance = $build();
        $entries[$key] = [$sig, $instance];
        $cache[$app] = $entries;

        return $instance;
    }
}
