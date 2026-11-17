<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Media\Enums\Cas\ScanMode;

/**
 * Immutable compiled durable scan-work and legacy `vaulter-drive.uploads.scan.*` alias options.
 */
#[Api(since: '1.0')]
final readonly class UploadsScanOptions
{
    private const array DEFAULTS = [
        'lease_seconds' => 120,
        'scanner_timeout_seconds' => 60,
        'max_attempts' => 5,
        'publish_cooldown_seconds' => 30,
        'recovery_batch' => 100,
        'cleanup_batch' => 100,
    ];

    public function __construct(
        public ?bool $required = null,
        public ?ScanMode $mode = null,
        public ?string $scanner = null,
        public ?string $scannerKey = null,
        public ?string $scannerVersion = null,
        public ?string $policyVersion = null,
        public ?string $rolloutKey = null,
        public string $queue = 'default',
        public int $leaseSeconds = 120,
        public int $scannerTimeoutSeconds = 60,
        public int $maxAttempts = 5,
        public int $publishCooldownSeconds = 30,
        public int $recoveryBatch = 100,
        public int $cleanupBatch = 100,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, [
            'required',
            'mode',
            'scanner',
            'scanner_key',
            'scanner_version',
            'policy_version',
            'rollout_key',
            'queue',
            'lease_seconds',
            'scanner_timeout_seconds',
            'max_attempts',
            'publish_cooldown_seconds',
            'recovery_batch',
            'cleanup_batch',
        ], $path);

        $required = $raw['required'] ?? null;
        if ($required !== null && !is_bool($required)) {
            $validator->add($path.'.required', 'Scan required must be bool or null.');
            $required = null;
        }

        $mode = null;
        $rawMode = $raw['mode'] ?? null;
        if ($rawMode instanceof ScanMode) {
            $mode = $rawMode;
        } elseif ($rawMode !== null) {
            $parsed = is_string($rawMode) ? ScanMode::tryFrom($rawMode) : null;
            if ($parsed === null) {
                $validator->add($path.'.mode', 'Unknown scan mode.');
            } else {
                $mode = $parsed;
            }
        }

        $queueRaw = $raw['queue'] ?? 'default';
        if (!is_string($queueRaw) || $queueRaw === '') {
            $validator->add($path.'.queue', 'Scan queue must be nonempty.');
            $queue = 'default';
        } else {
            $queue = $queueRaw;
        }

        return new self(
            required: $required,
            mode: $mode,
            scanner: self::nullableString($raw['scanner'] ?? null, $path.'.scanner', $validator),
            scannerKey: self::nullableString($raw['scanner_key'] ?? null, $path.'.scanner_key', $validator),
            scannerVersion: self::nullableString($raw['scanner_version'] ?? null, $path.'.scanner_version', $validator),
            policyVersion: self::nullableString($raw['policy_version'] ?? null, $path.'.policy_version', $validator),
            rolloutKey: self::nullableString($raw['rollout_key'] ?? null, $path.'.rollout_key', $validator),
            queue: $queue,
            leaseSeconds: self::workInt($raw, 'lease_seconds', $path, $validator),
            scannerTimeoutSeconds: self::workInt($raw, 'scanner_timeout_seconds', $path, $validator),
            maxAttempts: self::workInt($raw, 'max_attempts', $path, $validator),
            publishCooldownSeconds: self::workInt($raw, 'publish_cooldown_seconds', $path, $validator),
            recoveryBatch: self::workInt($raw, 'recovery_batch', $path, $validator),
            cleanupBatch: self::workInt($raw, 'cleanup_batch', $path, $validator),
        );
    }

    public function workValue(string $key): int
    {
        return match ($key) {
            'lease_seconds' => $this->leaseSeconds,
            'scanner_timeout_seconds' => $this->scannerTimeoutSeconds,
            'max_attempts' => $this->maxAttempts,
            'publish_cooldown_seconds' => $this->publishCooldownSeconds,
            'recovery_batch' => $this->recoveryBatch,
            'cleanup_batch' => $this->cleanupBatch,
            default => throw new InvalidArgumentException('Unknown scan work setting: '.$key),
        };
    }

    private static function nullableString(mixed $value, string $path, ConfigurationValidator $validator): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $validator->add($path, 'must be a string or null.');

            return null;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $raw */
    private static function workInt(array $raw, string $key, string $path, ConfigurationValidator $validator): int
    {
        $default = self::DEFAULTS[$key];
        $value = array_key_exists($key, $raw) ? $raw[$key] : $default;
        if (!is_int($value) || $value < 1 || (str_ends_with($key, '_batch') && $value > 1000)) {
            $validator->add($path.'.'.$key, 'Invalid scan work '.$key.'.');

            return $default;
        }

        return $value;
    }
}
