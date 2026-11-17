<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled resumable upload options for `vaulter-drive.uploads.resumable.*`.
 */
#[Api(since: '1.0')]
final readonly class ResumableUploadsOptions
{
    public function __construct(
        public bool $enabled = true,
        public int $chunkSize = 8_388_608,
        public int $maxChunkSize = 67_108_864,
        public int $sessionTtlHours = 24,
        public int $completeTimeoutSeconds = 60,
        public int $maxActiveSessionsPerActor = 20,
        public ResumableMetadataOptions $metadata = new ResumableMetadataOptions,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'enabled',
            'chunk_size',
            'max_chunk_size',
            'session_ttl_hours',
            'complete_timeout_seconds',
            'max_active_sessions_per_actor',
            'metadata',
        ], $path);

        $enabled = array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true;
        $chunkSize = $raw['chunk_size'] ?? 8_388_608;
        $maxChunkSize = $raw['max_chunk_size'] ?? 67_108_864;
        $ttl = $raw['session_ttl_hours'] ?? 24;
        $timeout = $raw['complete_timeout_seconds'] ?? 60;
        $maxActive = $raw['max_active_sessions_per_actor'] ?? 20;

        return new self(
            enabled: $enabled,
            chunkSize: max(is_numeric($chunkSize) ? (int) $chunkSize : 8_388_608, 1),
            maxChunkSize: max(is_numeric($maxChunkSize) ? (int) $maxChunkSize : 67_108_864, 0),
            sessionTtlHours: max(is_numeric($ttl) ? (int) $ttl : 24, 1),
            completeTimeoutSeconds: min(max(is_numeric($timeout) ? (int) $timeout : 60, 1), 300),
            maxActiveSessionsPerActor: max(is_numeric($maxActive) ? (int) $maxActive : 20, 0),
            metadata: ResumableMetadataOptions::fromArray($raw['metadata'] ?? [], $path.'.metadata', $validator),
        );
    }
}
