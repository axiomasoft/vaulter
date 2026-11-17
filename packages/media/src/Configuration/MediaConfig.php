<?php

declare(strict_types=1);

namespace Vaulter\Media\Configuration;

use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Models\Cas\Blob;

#[Api]
final readonly class MediaConfig
{
    #[Internal]
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

    /** The most blobs one scrub run visits: vaulter-media.scrub.limit, a positive integer; an override wins. */
    #[Internal]
    public function scrubLimit(?int $override = null): int
    {
        return $override ?? $this->scrubSetting('limit') ?? 100;
    }

    /** The bytes after which a scrub run stops (vaulter-media.scrub.max_bytes); null: no byte budget. */
    #[Internal]
    public function scrubMaxBytes(?int $override = null): ?int
    {
        return $override ?? $this->scrubSetting('max_bytes');
    }

    /** The seconds after which a scrub run stops (vaulter-media.scrub.max_seconds); null: no time budget. */
    #[Internal]
    public function scrubMaxSeconds(?int $override = null): ?int
    {
        return $override ?? $this->scrubSetting('max_seconds');
    }

    private function scrubSetting(string $name): ?int
    {
        $value = $this->config->get(key: 'vaulter-media.scrub.'.$name);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) ? $value > 0 : (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)) {
            return (int) $value;
        }

        throw new InvalidConfigurationException('[Vaulter] vaulter-media.scrub.'.$name.' must be a positive integer (or null for no budget, except limit).');
    }

    /** @return class-string<ContentStore> */
    public function contentStore(): string
    {
        $class = $this->config->get(key: 'vaulter-media.content_store', default: Sha256ContentStore::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.content_store must be a class name.');
        }

        self::assertImplements(key: 'vaulter-media.content_store', class: $class, base: ContentStore::class);

        return $class;
    }

    /** @return class-string<StorageDriver> */
    public function storageDriver(): string
    {
        $class = $this->config->get(key: 'vaulter-media.storage_driver', default: FlysystemStorageDriver::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.storage_driver must be a class name.');
        }

        self::assertImplements(key: 'vaulter-media.storage_driver', class: $class, base: StorageDriver::class);

        return $class;
    }

    /** @return class-string<BlobStore> */
    public function blobStore(): string
    {
        $class = $this->config->get(key: 'vaulter-media.blob_store', default: \Vaulter\Media\Cas\BlobStore::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.blob_store must be a class name.');
        }

        self::assertImplements(key: 'vaulter-media.blob_store', class: $class, base: BlobStore::class);

        return $class;
    }

    /**
     * The blob model, swappable in vaulter-media.models.blob (the key moved from vaulter.models.blob with the model).
     *
     * @return class-string<Blob>
     */
    #[Internal]
    public function blobModel(): string
    {
        $class = $this->config->get(key: 'vaulter-media.models.blob', default: Blob::class);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-media.models.blob must be a class name.');
        }

        self::assertImplements(key: 'vaulter-media.models.blob', class: $class, base: Blob::class);

        return $class;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @phpstan-assert class-string<T> $class
     */
    private static function assertImplements(string $key, string $class, string $base): void
    {
        if (!class_exists($class) || !is_a($class, $base, true)) {
            throw new InvalidConfigurationException('[Vaulter] '.$key.' must name a class implementing or extending '.$base.'.');
        }
    }
}
