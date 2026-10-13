<?php

declare(strict_types=1);

namespace Vaulter\Media\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;

final readonly class MediaConfig
{
    public function __construct(private Repository $config) {}

    public function disk(): string
    {
        $value = $this->config->get(key: 'vaulter-media.disk', default: 'local');

        return is_string($value) && $value !== '' ? $value : 'local';
    }

    /**
     * vaulter-media.dedup.scope: tenant (default), drive or off. There is no install-wide
     * 'global' scope.
     */
    public function dedupScope(): DedupScope
    {
        $value = $this->config->get(key: 'vaulter-media.dedup.scope', default: 'tenant');

        if ($value === 'global') {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.dedup.scope "global" was removed: identical files must not be shared across tenants. '
                .'Fix: set it to tenant (default), drive or off.');
        }

        return (is_string($value) ? DedupScope::tryFrom($value) : null)
            ?? throw new InvalidConfigurationException('[Vaulter] vaulter-media.dedup.scope must be tenant, drive or off.');
    }

    public function gcGraceHours(int $override = 0): int
    {
        $value = $override > 0 ? $override : $this->config->get(key: 'vaulter-media.gc.grace_hours', default: 24);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    public function orphanGraceHours(int $override = 0): int
    {
        $default = $this->config->get(key: 'vaulter-media.gc.grace_hours', default: 24);

        $value = $override > 0 ? $override : $this->config->get(key: 'vaulter-media.gc.orphan_grace_hours', default: $default);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    /** @return class-string<ContentStore> */
    public function contentStore(): string
    {
        $class = $this->config->get(key: 'vaulter-media.content_store', default: Sha256ContentStore::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.content_store must be a class name.');
        }

        ConfigValidator::assertImplements(key: 'vaulter-media.content_store', class: $class, base: ContentStore::class);

        return $class;
    }

    /** @return class-string<StorageDriver> */
    public function storageDriver(): string
    {
        $class = $this->config->get(key: 'vaulter-media.storage_driver', default: FlysystemStorageDriver::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.storage_driver must be a class name.');
        }

        ConfigValidator::assertImplements(key: 'vaulter-media.storage_driver', class: $class, base: StorageDriver::class);

        return $class;
    }

    /** @return class-string<BlobStore> */
    public function blobStore(): string
    {
        $class = $this->config->get(key: 'vaulter-media.blob_store', default: \Vaulter\Media\Cas\BlobStore::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.blob_store must be a class name.');
        }

        ConfigValidator::assertImplements(key: 'vaulter-media.blob_store', class: $class, base: BlobStore::class);

        return $class;
    }
}
