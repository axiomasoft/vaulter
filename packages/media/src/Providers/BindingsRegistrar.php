<?php

declare(strict_types=1);

namespace Vaulter\Media\Providers;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Vaulter\Configuration\CompiledOptionsCache;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\BlobPinStore;
use Vaulter\Media\Cas\BlobStore as CasBlobStore;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Encryption\KeyManagementPort;
use Vaulter\Modules\ModuleBindings;

/**
 * The container wiring of the media module: the configured CAS engine, pins, and boot configuration validation.
 */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(MediaOptions::class, static fn (Container $app): MediaOptions => CompiledOptionsCache::resolve(
            $app,
            'vaulter-media',
            static fn (array $raw): MediaOptions => MediaOptions::fromArray($raw, 'vaulter-media'),
        ));
        $app->bind('vaulter.dedup_scope', static fn (Container $app): string => $app->make(MediaOptions::class)->dedupScope->value);
        $app->when([CasBlobStore::class, Sha256ContentStore::class])->needs(Closure::class)->give(self::keyProvider(...));

        // The StorageDriver is the media module's capability, read from `vaulter-media.storage_driver` (MediaModule).
        $app->scoped(ContentStore::class, static function (Container $app): ContentStore {
            $service = $app->make($app->make(MediaOptions::class)->contentStore);
            if (!$service instanceof ContentStore) {
                throw new InvalidConfigurationException('[Vaulter] Configured contentStore binding must implement ContentStore.');
            }

            return $service;
        });
        $app->bind(BlobStore::class, static function (Container $app): BlobStore {
            $service = $app->make($app->make(MediaOptions::class)->blobStore);
            if (!$service instanceof BlobStore) {
                throw new InvalidConfigurationException('[Vaulter] Configured blobStore binding must implement BlobStore.');
            }

            return $service;
        });

        $app->bind(BlobPins::class, BlobPinStore::class);

        $app->bind('vaulter.config_validator.media', static fn (Container $app): Closure => static function () use ($app): void {
            self::buildOptions($app);
        });
        $app->tag('vaulter.config_validator.media', ConfigValidCheck::TAG);
    }

    public function boot(Application $app): void
    {
        self::buildOptions($app);
    }

    /** @return Closure(): ?KeyManagementPort */
    private static function keyProvider(Container $app): Closure
    {
        // A host can bind the provider after CAS services have been resolved.
        return static fn (): ?KeyManagementPort => $app->bound(KeyManagementPort::class) ? $app->make(KeyManagementPort::class) : null;
    }

    private static function buildOptions(Container $app): MediaOptions
    {
        $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('vaulter-media', []) : [];

        return MediaOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'vaulter-media');
    }
}
