<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * Typed accessors over config('vaulter.changes.*'), the drive change journal
 * (PLAN2.D50). The journal itself is switched by vaulter.features.changes.
 */
final readonly class ChangesConfig
{
    private const int DEFAULT_RETENTION_DAYS = 30;

    private const int DEFAULT_MAX_PAGE_SIZE = 1000;

    /** A larger page would hold one request's visibility batch for too long. */
    private const int MAX_PAGE_SIZE = 10000;

    public function __construct(private Repository $config) {}

    /** Days an entry stays readable; a cursor older than that expires (410). */
    public function retentionDays(): int
    {
        return $this->positiveInteger('vaulter.changes.retention_days', self::DEFAULT_RETENTION_DAYS, PHP_INT_MAX);
    }

    /** The largest limit a client may ask one page for. */
    public function maxPageSize(): int
    {
        return $this->positiveInteger('vaulter.changes.max_page_size', self::DEFAULT_MAX_PAGE_SIZE, self::MAX_PAGE_SIZE);
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
