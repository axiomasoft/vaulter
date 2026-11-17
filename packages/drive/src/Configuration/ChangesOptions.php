<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled change-journal options for `vaulter-drive.changes.*`.
 */
#[Api(since: '1.0')]
final readonly class ChangesOptions
{
    private const int DEFAULT_RETENTION_DAYS = 30;

    private const int DEFAULT_MAX_PAGE_SIZE = 1000;

    private const int DEFAULT_KEY_GENERATION = 1;

    private const int DEFAULT_CURSOR_MAX_AGE_SECONDS = 86400;

    private const int MAX_CURSOR_MAX_AGE_SECONDS = 2_592_000;

    private const int MAX_PAGE_SIZE = 10_000;

    public function __construct(
        public int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        public int $maxPageSize = self::DEFAULT_MAX_PAGE_SIZE,
        public int $keyGeneration = self::DEFAULT_KEY_GENERATION,
        public int $cursorMaxAgeSeconds = self::DEFAULT_CURSOR_MAX_AGE_SECONDS,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['retention_days', 'max_page_size', 'key_generation', 'cursor_max_age_seconds'], $path);

        $retentionDays = self::positiveInteger(
            $raw['retention_days'] ?? self::DEFAULT_RETENTION_DAYS,
            $path.'.retention_days',
            self::DEFAULT_RETENTION_DAYS,
            intdiv(PHP_INT_MAX, 86400),
            $validator,
        );
        $keyGeneration = self::positiveInteger(
            $raw['key_generation'] ?? self::DEFAULT_KEY_GENERATION,
            $path.'.key_generation',
            self::DEFAULT_KEY_GENERATION,
            PHP_INT_MAX,
            $validator,
        );
        $cursorMaxAgeSeconds = self::positiveInteger(
            $raw['cursor_max_age_seconds'] ?? self::DEFAULT_CURSOR_MAX_AGE_SECONDS,
            $path.'.cursor_max_age_seconds',
            min(self::DEFAULT_CURSOR_MAX_AGE_SECONDS, min(self::MAX_CURSOR_MAX_AGE_SECONDS, $retentionDays * 86400)),
            min(self::MAX_CURSOR_MAX_AGE_SECONDS, $retentionDays * 86400),
            $validator,
        );
        $maxPageSize = self::positiveInteger(
            $raw['max_page_size'] ?? self::DEFAULT_MAX_PAGE_SIZE,
            $path.'.max_page_size',
            self::DEFAULT_MAX_PAGE_SIZE,
            self::MAX_PAGE_SIZE,
            $validator,
        );

        return new self(
            retentionDays: $retentionDays,
            maxPageSize: $maxPageSize,
            keyGeneration: $keyGeneration,
            cursorMaxAgeSeconds: $cursorMaxAgeSeconds,
        );
    }

    private static function positiveInteger(
        mixed $value,
        string $path,
        int $default,
        int $max,
        ConfigurationValidator $validator,
    ): int {
        if (!is_int($value) || $value < 1 || $value > $max) {
            $validator->add($path, sprintf('[Vaulter] Configuration %s must be an integer between 1 and %d.', $path, $max));

            return $default;
        }

        return $value;
    }
}
