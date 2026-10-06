<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

final readonly class DriveConfig
{
    public function __construct(private Repository $config) {}

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

    public function nameMaxLength(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.names.max_length', default: 255);

        return is_numeric($value) ? (int) $value : 255;
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
