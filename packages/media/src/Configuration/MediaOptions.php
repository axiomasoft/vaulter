<?php

declare(strict_types=1);

namespace Vaulter\Media\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Configuration\ScheduleFrequency;
use Vaulter\Media\Cas\BlobStore as DefaultBlobStore;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Models\Cas\Blob;

/**
 * Immutable compiled configuration snapshot for the `vaulter-media` CAS package.
 */
#[Api(since: '1.0')]
final readonly class MediaOptions implements OptionsFactory
{
    /**
     * @param class-string<ContentStore>  $contentStore
     * @param class-string<StorageDriver> $storageDriver
     * @param class-string<BlobStore>     $blobStore
     * @param class-string<Blob>          $blobModel
     */
    #[Internal]
    public function __construct(
        public string $disk = 'local',
        public DedupScope $dedupScope = DedupScope::Tenant,
        public int $gcGraceHours = 24,
        public int $orphanGraceHours = 24,
        public int $scrubLimit = 100,
        public ?int $scrubMaxBytes = null,
        public ?int $scrubMaxSeconds = null,
        public ?string $scheduleBlobsGc = 'dailyAt:04:00',
        public ?string $scheduleBlobsReconcile = 'weeklyOn:0,04:30',
        public string $contentStore = Sha256ContentStore::class,
        public string $storageDriver = FlysystemStorageDriver::class,
        public string $blobStore = DefaultBlobStore::class,
        #[Internal]
        public string $blobModel = Blob::class,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter-media'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys(
            $config,
            ['disk', 'dedup', 'gc', 'scrub', 'schedule', 'content_store', 'storage_driver', 'blob_store', 'models'],
            $path,
        );

        $rawDisk = $config['disk'] ?? 'local';
        $disk = is_string($rawDisk) && $rawDisk !== '' ? $rawDisk : 'local';

        $dedupScope = self::parseDedupScope($validator, $config['dedup'] ?? [], $path.'.dedup');
        [$gcGraceHours, $orphanGraceHours] = self::parseGc($validator, $config['gc'] ?? [], $path.'.gc');
        [$scrubLimit, $scrubMaxBytes, $scrubMaxSeconds] = self::parseScrub($validator, $config['scrub'] ?? [], $path.'.scrub');
        [$scheduleGc, $scheduleReconcile] = self::parseSchedule($validator, $config['schedule'] ?? [], $path.'.schedule');

        /** @var class-string<ContentStore> $contentStore */
        $contentStore = self::validateImplementation(
            $validator,
            $config['content_store'] ?? Sha256ContentStore::class,
            ContentStore::class,
            $path.'.content_store',
            Sha256ContentStore::class,
        );
        /** @var class-string<StorageDriver> $storageDriver */
        $storageDriver = self::validateImplementation(
            $validator,
            $config['storage_driver'] ?? FlysystemStorageDriver::class,
            StorageDriver::class,
            $path.'.storage_driver',
            FlysystemStorageDriver::class,
        );
        /** @var class-string<BlobStore> $blobStore */
        $blobStore = self::validateImplementation(
            $validator,
            $config['blob_store'] ?? DefaultBlobStore::class,
            BlobStore::class,
            $path.'.blob_store',
            DefaultBlobStore::class,
        );
        $blobModel = self::parseModels($validator, $config['models'] ?? [], $path.'.models');

        $validator->failIfAny();

        return new self(
            disk: $disk,
            dedupScope: $dedupScope,
            gcGraceHours: $gcGraceHours,
            orphanGraceHours: $orphanGraceHours,
            scrubLimit: $scrubLimit,
            scrubMaxBytes: $scrubMaxBytes,
            scrubMaxSeconds: $scrubMaxSeconds,
            scheduleBlobsGc: $scheduleGc,
            scheduleBlobsReconcile: $scheduleReconcile,
            contentStore: $contentStore,
            storageDriver: $storageDriver,
            blobStore: $blobStore,
            blobModel: $blobModel,
        );
    }

    public function disk(): string
    {
        return $this->disk;
    }

    public function dedupScope(): DedupScope
    {
        return $this->dedupScope;
    }

    public function gcGraceHours(int $override = 0): int
    {
        return max($override > 0 ? $override : $this->gcGraceHours, 1);
    }

    public function orphanGraceHours(int $override = 0): int
    {
        return max($override > 0 ? $override : $this->orphanGraceHours, 1);
    }

    #[Internal]
    public function scrubLimit(?int $override = null): int
    {
        return $override ?? $this->scrubLimit;
    }

    #[Internal]
    public function scrubMaxBytes(?int $override = null): ?int
    {
        return $override ?? $this->scrubMaxBytes;
    }

    #[Internal]
    public function scrubMaxSeconds(?int $override = null): ?int
    {
        return $override ?? $this->scrubMaxSeconds;
    }

    /** @return class-string<ContentStore> */
    public function contentStore(): string
    {
        return $this->contentStore;
    }

    /** @return class-string<StorageDriver> */
    public function storageDriver(): string
    {
        return $this->storageDriver;
    }

    /** @return class-string<BlobStore> */
    public function blobStore(): string
    {
        return $this->blobStore;
    }

    /** @return class-string<Blob> */
    #[Internal]
    public function blobModel(): string
    {
        return $this->blobModel;
    }

    private static function parseDedupScope(ConfigurationValidator $validator, mixed $raw, string $path): DedupScope
    {
        if (!is_array($raw)) {
            $validator->add(path: $path, message: 'must be an array');

            return DedupScope::Tenant;
        }
        $validator->rejectUnknownKeys($raw, ['scope'], $path);
        $scope = $raw['scope'] ?? 'tenant';

        if ($scope === 'global') {
            $validator->add(
                path: $path.'.scope',
                message: '"global" was removed: identical files must not be shared across tenants. Fix: set it to tenant (default), drive or off.',
            );

            return DedupScope::Tenant;
        }

        $parsed = is_string($scope) ? DedupScope::tryFrom($scope) : null;
        if ($parsed === null) {
            $validator->add(path: $path.'.scope', message: 'must be tenant, drive or off.');

            return DedupScope::Tenant;
        }

        return $parsed;
    }

    /** @return array{0: int, 1: int} */
    private static function parseGc(ConfigurationValidator $validator, mixed $raw, string $path): array
    {
        if (!is_array($raw)) {
            $validator->add(path: $path, message: 'must be an array');

            return [24, 24];
        }
        $validator->rejectUnknownKeys($raw, ['grace_hours', 'orphan_grace_hours'], $path);

        $rawGrace = $raw['grace_hours'] ?? 24;
        $grace = max(is_numeric($rawGrace) ? (int) $rawGrace : 24, 1);

        $rawOrphan = array_key_exists('orphan_grace_hours', $raw) ? $raw['orphan_grace_hours'] : $rawGrace;
        $orphan = max(is_numeric($rawOrphan) ? (int) $rawOrphan : 24, 1);

        return [$grace, $orphan];
    }

    /** @return array{0: int, 1: int|null, 2: int|null} */
    private static function parseScrub(ConfigurationValidator $validator, mixed $raw, string $path): array
    {
        if (!is_array($raw)) {
            $validator->add(path: $path, message: 'must be an array');

            return [100, null, null];
        }
        $validator->rejectUnknownKeys($raw, ['limit', 'max_bytes', 'max_seconds'], $path);

        $limit = self::parsePositiveBudget($validator, $raw['limit'] ?? 100, $path.'.limit') ?? 100;
        $maxBytes = self::parsePositiveBudget($validator, $raw['max_bytes'] ?? null, $path.'.max_bytes');
        $maxSeconds = self::parsePositiveBudget($validator, $raw['max_seconds'] ?? null, $path.'.max_seconds');

        return [$limit, $maxBytes, $maxSeconds];
    }

    private static function parsePositiveBudget(ConfigurationValidator $validator, mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) ? $value > 0 : (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)) {
            return (int) $value;
        }

        $validator->add(path: $path, message: 'must be a positive integer (or null for no budget, except limit).');

        return null;
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function parseSchedule(ConfigurationValidator $validator, mixed $raw, string $path): array
    {
        if (!is_array($raw)) {
            $validator->add(path: $path, message: 'must be an array');

            return ['dailyAt:04:00', 'weeklyOn:0,04:30'];
        }
        $validator->rejectUnknownKeys($raw, ['blobs_gc', 'blobs_reconcile'], $path);

        $gcRaw = array_key_exists('blobs_gc', $raw) ? $raw['blobs_gc'] : 'dailyAt:04:00';
        $reconcileRaw = array_key_exists('blobs_reconcile', $raw) ? $raw['blobs_reconcile'] : 'weeklyOn:0,04:30';

        return [
            self::validateFrequency($validator, $path.'.blobs_gc', $gcRaw),
            self::validateFrequency($validator, $path.'.blobs_reconcile', $reconcileRaw),
        ];
    }

    private static function validateFrequency(ConfigurationValidator $validator, string $path, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            ScheduleFrequency::parse($path, $value);
        } catch (InvalidConfiguration $exception) {
            $validator->add(path: $path, message: $exception->getMessage());

            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param  class-string $contract
     * @param  class-string $default
     * @return class-string
     */
    private static function validateImplementation(
        ConfigurationValidator $validator,
        mixed $raw,
        string $contract,
        string $path,
        string $default,
    ): string {
        if (!is_string($raw)) {
            $validator->add(path: $path, message: 'must be a class name.');

            return $default;
        }

        if ($raw === '' || !class_exists($raw) || !is_a($raw, $contract, true)) {
            $validator->add(path: $path, message: 'must name a class implementing '.$contract);

            return $default;
        }

        /** @var class-string $raw */
        return $raw;
    }

    /** @return class-string<Blob> */
    private static function parseModels(ConfigurationValidator $validator, mixed $raw, string $path): string
    {
        if (!is_array($raw)) {
            $validator->add(path: $path, message: 'must be an array');

            return Blob::class;
        }
        $validator->rejectUnknownKeys($raw, ['blob'], $path);

        $blob = $raw['blob'] ?? Blob::class;
        if (!is_string($blob)) {
            $validator->add(path: $path.'.blob', message: 'must be a class name.');

            return Blob::class;
        }

        if ($blob === '' || !class_exists($blob) || !is_a($blob, Blob::class, true)) {
            $validator->add(path: $path.'.blob', message: 'must name a class extending '.Blob::class);

            return Blob::class;
        }

        /** @var class-string<Blob> $blob */
        return $blob;
    }
}
