<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Typed accessors over config('vaulter-drive.changes.*'), the drive change journal
 * . The journal itself is switched by vaulter-drive.features.changes.
 */
#[Api]
final readonly class ChangesConfig
{
    private const int DEFAULT_RETENTION_DAYS = 30;

    private const int DEFAULT_MAX_PAGE_SIZE = 1000;

    private const int DEFAULT_KEY_GENERATION = 1;

    private const int DEFAULT_CURSOR_MAX_AGE_SECONDS = 86400;

    private const int MAX_CURSOR_MAX_AGE_SECONDS = 2592000;

    /** A larger page would hold one request's visibility batch for too long. */
    private const int MAX_PAGE_SIZE = 10000;

    #[Internal]
    public function __construct(private Repository $config) {}

    /** Days an entry stays readable; a cursor older than that expires (410). */
    public function retentionDays(): int
    {
        return $this->positiveInteger('vaulter-drive.changes.retention_days', self::DEFAULT_RETENTION_DAYS, intdiv(PHP_INT_MAX, 86400));
    }

    /**
     * The sync key generation (PLAN4.D20): raising it on every instance expires every
     * cursor and changes every sync_key, so clients bootstrap again.
     */
    public function keyGeneration(): int
    {
        return $this->positiveInteger('vaulter-drive.changes.key_generation', self::DEFAULT_KEY_GENERATION, PHP_INT_MAX);
    }

    /** Seconds a cursor lives after it was issued; never longer than the retention. */
    public function cursorMaxAgeSeconds(): int
    {
        return $this->positiveInteger('vaulter-drive.changes.cursor_max_age_seconds', self::DEFAULT_CURSOR_MAX_AGE_SECONDS, min(self::MAX_CURSOR_MAX_AGE_SECONDS, $this->retentionDays() * 86400));
    }

    /** The largest limit a client may ask one page for. */
    public function maxPageSize(): int
    {
        return $this->positiveInteger('vaulter-drive.changes.max_page_size', self::DEFAULT_MAX_PAGE_SIZE, self::MAX_PAGE_SIZE);
    }

    /** @throws InvalidConfigurationException */
    private function positiveInteger(string $key, int $default, int $max): int
    {
        $value = $this->config->get($key, $default);
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidConfigurationException(
                "[Vaulter] {$key} must be an integer between 1 and {$max}. "
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/reference/config.md',
            );
        }

        return $value;
    }
}
