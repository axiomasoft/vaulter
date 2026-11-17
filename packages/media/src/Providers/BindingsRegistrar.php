<?php

declare(strict_types=1);

namespace Vaulter\Media\Providers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\BlobPinStore;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Modules\ModuleBindings;

/**
 * The container wiring of the media module: the configured CAS engine (ContentStore, BlobStore; the
 * StorageDriver is a capability of the manifest), the pins (BlobPins, — not configurable: the GC reads the
 * same store) and the config validation, failing at boot on a bad class or value.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->singleton(MediaConfig::class, static fn (Container $app): MediaConfig => new MediaConfig($app->make('config')));
        $app->bind('vaulter.dedup_scope', static fn (Container $app): string => $app->make(MediaConfig::class)->dedupScope()->value);

        // The StorageDriver is the media module's capability, read from `vaulter-media.storage_driver` (MediaModule).
        $app->singleton(ContentStore::class, static function (Container $app): ContentStore {
            $service = $app->make($app->make(MediaConfig::class)->contentStore());
            if (!$service instanceof ContentStore) {
                throw new InvalidConfigurationException('[Vaulter] Configured contentStore binding must implement ContentStore.');
            }

            return $service;
        });
        $app->bind(BlobStore::class, static function (Container $app): BlobStore {
            $service = $app->make($app->make(MediaConfig::class)->blobStore());
            if (!$service instanceof BlobStore) {
                throw new InvalidConfigurationException('[Vaulter] Configured blobStore binding must implement BlobStore.');
            }

            return $service;
        });

        $app->bind(BlobPins::class, BlobPinStore::class);

        $app->bind('vaulter.config_validator.media', static fn (Container $app): Closure => static function () use ($app): void {
            $config = $app->make(MediaConfig::class);
            $config->dedupScope();
            $config->contentStore();
            $config->storageDriver();
            $config->blobStore();
        });
        $app->tag('vaulter.config_validator.media', ConfigValidCheck::TAG);
    }

    public function boot(Application $app): void
    {
        $config = $app->make(MediaConfig::class);
        $config->dedupScope();
        $config->contentStore();
        $config->storageDriver();
        $config->blobStore();
        // Fail at boot on a bad value; the tasks themselves read the config when the scheduler resolves.
        SchedulingRegistrar::frequencies($app->make('config'));
    }
}
