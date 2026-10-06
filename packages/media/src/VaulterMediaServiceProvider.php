<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Illuminate\Contracts\Container\Container;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Console\GcBlobsCommand;
use Vaulter\Media\Console\ReconcileBlobsCommand;
use Vaulter\Media\Console\RepairBlobScopesCommand;

/**
 * Vaulter Media — the content-addressed file/blob layer (CAS), extracted from
 * vaulter-core (P2 #5, ADR decisions D2/D3). This package OWNS the concrete CAS
 * engine (ContentStore / StorageDriver / BlobStore implementations, the two-phase
 * GC + reconciler and their commands); the seam contracts, the Blob model/schema
 * and the per-file ACL stay in core (ACL-agnostic CAS — the media layer never
 * touches AccessResolver).
 *
 * Dependency direction is one-way: media requires core (for the Contracts\Cas\*
 * seam, the Blob/FileRevision models and Configuration\VaulterConfig), core does NOT require
 * media at runtime — a host that wants the CAS file layer installs this package
 * and Laravel auto-discovers this provider, which binds the concrete engine.
 */
final class VaulterMediaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter-media')
            ->hasConfigFile()
            ->hasCommand(GcBlobsCommand::class)
            ->hasCommand(ReconcileBlobsCommand::class)
            ->hasCommand(RepairBlobScopesCommand::class);
    }

    public function packageRegistered(): void
    {
        $path = __DIR__.'/../config/vaulter-media.php';
        $this->mergeConfigFrom(path: $path, key: 'vaulter-media');
        $this->app->singleton(MediaConfig::class, fn (Container $app): MediaConfig => new MediaConfig($app->make('config')));
        $this->app->singletonIf(ConfigNormalizer::class);
        $this->app->make(ConfigNormalizer::class)->registerDefaults(root: 'vaulter-media', path: $path);
        $this->app->bind('vaulter.dedup_scope', fn (Container $app): string => $app->make(MediaConfig::class)->dedupScope());

        $this->app->singleton(StorageDriver::class, static function (Container $app): StorageDriver {
            $service = $app->make($app->make(MediaConfig::class)->storageDriver());
            if (!$service instanceof StorageDriver) {
                throw new InvalidConfigurationException('[Vaulter] Configured storageDriver binding must implement StorageDriver.');
            }

            return $service;
        });
        $this->app->singleton(ContentStore::class, static function (Container $app): ContentStore {
            $service = $app->make($app->make(MediaConfig::class)->contentStore());
            if (!$service instanceof ContentStore) {
                throw new InvalidConfigurationException('[Vaulter] Configured contentStore binding must implement ContentStore.');
            }

            return $service;
        });
        $this->app->bind(BlobStore::class, static function (Container $app): BlobStore {
            $service = $app->make($app->make(MediaConfig::class)->blobStore());
            if (!$service instanceof BlobStore) {
                throw new InvalidConfigurationException('[Vaulter] Configured blobStore binding must implement BlobStore.');
            }

            return $service;
        });
    }

    public function packageBooted(): void
    {
        $config = $this->app->make(MediaConfig::class);
        $config->contentStore();
        $config->storageDriver();
        $config->blobStore();
    }
}
