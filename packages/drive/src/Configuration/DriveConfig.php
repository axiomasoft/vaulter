<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Contracts\Content\ScanWorkSettings;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

#[Api]
final readonly class DriveConfig implements ScanWorkSettings
{
    #[Internal]
    public function __construct(private Repository $config) {}

    #[Internal]
    public function rejectMimeMismatch(): bool
    {
        return (bool) $this->config->get('vaulter-drive.uploads.reject_mime_mismatch', true);
    }

    public function scanQueue(): string
    {
        $queue = $this->config->get('vaulter-drive.uploads.scan.queue', 'default');
        if (!is_string($queue) || trim($queue) === '') {
            throw new InvalidConfigurationException('Scan queue must be nonempty.');
        }

        return $queue;
    }

    public function leaseSeconds(): int
    {
        return $this->scanWork('lease_seconds');
    }

    public function scannerTimeoutSeconds(): int
    {
        return $this->scanWork('scanner_timeout_seconds');
    }

    public function maxAttempts(): int
    {
        return $this->scanWork('max_attempts');
    }

    public function scanWork(string $key): int
    {
        $defaults = ['lease_seconds' => 120, 'scanner_timeout_seconds' => 60, 'max_attempts' => 5,
            'publish_cooldown_seconds' => 30, 'recovery_batch' => 100, 'cleanup_batch' => 100];
        if (!isset($defaults[$key])) {
            throw new InvalidConfigurationException('Unknown scan work setting.');
        }
        $value = $this->config->get('vaulter-drive.uploads.scan.'.$key, $defaults[$key]);
        if (!is_int($value) || $value < 1 || (str_ends_with($key, '_batch') && $value > 1000)) {
            throw new InvalidConfigurationException('Invalid scan work '.$key.'.');
        }

        return $value;
    }

    public function validateScanWork(bool $requireBroker = false): void
    {
        $this->scanQueue();
        foreach (['lease_seconds', 'scanner_timeout_seconds', 'max_attempts', 'publish_cooldown_seconds', 'recovery_batch', 'cleanup_batch'] as $key) {
            $this->scanWork($key);
        }
        $connection = $this->config->get('queue.default');
        if (!is_string($connection) || $connection === '') {
            throw new InvalidConfigurationException('Queue connection must be configured.');
        }
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');
        $retry = $this->config->get('queue.connections.'.$connection.'.retry_after', $requireBroker ? null : 180);
        if ($requireBroker && (!is_string($driver) || in_array($driver, ['sync', 'null'], true))) {
            throw new InvalidConfigurationException('Scanning requires an asynchronous queue connection.');
        }
        if ($this->scanWork('scanner_timeout_seconds') >= 90 || $this->scanWork('lease_seconds') <= 90
            || ($requireBroker && (!is_int($retry) || $retry < 180 || $retry <= $this->scanWork('lease_seconds')))) {
            throw new InvalidConfigurationException('Scanner deadline, job timeout, lease and broker retry_after must be ordered.');
        }
    }

    public function defaultQuota(): ?int
    {
        $value = $this->config->get('vaulter-drive.quota.default_bytes');
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidConfigurationException('Default quota must be nonnegative or null.');
        }

        return $value;
    }

    /** @return list<string> */
    public function blockedExtensions(): array
    {
        return $this->stringList($this->config->get('vaulter-drive.uploads.blocked_extensions', []), []);
    }

    public function maxFileSize(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.max_file_size', default: 5_368_709_120); // 5 GiB

        $bytes = is_numeric($value) ? (int) $value : 5_368_709_120;

        return max($bytes, 0);
    }

    public function chunkSize(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.chunk_size', default: 8_388_608); // 8 MiB

        $bytes = is_numeric($value) ? (int) $value : 8_388_608;

        return max($bytes, 1);
    }

    public function maxChunkSize(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.max_chunk_size', default: 67_108_864); // 64 MiB

        $bytes = is_numeric($value) ? (int) $value : 67_108_864;

        return max($bytes, 0);
    }

    public function sessionTtlHours(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.session_ttl_hours', default: 24);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    public function maxActiveSessionsPerActor(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.max_active_sessions_per_actor', default: 20);

        $max = is_numeric($value) ? (int) $value : 20;

        return max($max, 0);
    }

    /** @return list<string> */
    public function metadataAllowedKeys(): array
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.metadata.allowed_keys', default: ['filename', 'filetype', 'name', 'mime_type']);

        $keys = [];

        foreach (is_array($value) ? $value : [] as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function metadataMaxValueLength(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.metadata.max_value_length', default: 512);

        $length = is_numeric($value) ? (int) $value : 512;

        return max($length, 1);
    }

    public function trashRetentionDays(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.trash.retention_days', default: 30);

        $days = is_numeric($value) ? (int) $value : 30;

        return max($days, 0);
    }

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return $this->stringList($this->config->get(key: 'vaulter-drive.uploads.allowed_mime_types', default: []), []);
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    public function resumableEnabled(): bool
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.enabled', default: true);

        return (bool) $value;
    }

    /** @return ($key is 'upload_session' ? class-string<UploadSession> : class-string<UploadPart>) */
    public function model(string $key): string
    {
        if ($key === 'upload_session') {
            return $this->configuredModel(key: $key, base: UploadSession::class);
        }

        if ($key === 'upload_part') {
            return $this->configuredModel(key: $key, base: UploadPart::class);
        }

        throw new InvalidConfigurationException('[Vaulter] Unknown drive model key: '.$key);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T> $base
     * @return class-string<T>
     */
    private function configuredModel(string $key, string $base): string
    {
        $class = $this->config->get(key: 'vaulter-drive.models.'.$key, default: $base);

        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] Model vaulter-drive.models.'.$key.' must be a class name.');
        }

        ConfigValidator::assertModel(key: 'vaulter-drive.models.'.$key, class: $class, base: $base);

        return $class;
    }
}
