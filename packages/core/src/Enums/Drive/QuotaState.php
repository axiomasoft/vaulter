<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

/**
 * How full a drive is against its quota (PLAN2.D44).
 *
 * @closed
 */
enum QuotaState: string
{
    case Normal = 'normal';
    case Nearing = 'nearing';
    case Critical = 'critical';
    case Exceeded = 'exceeded';

    /** An unlimited drive is always normal. */
    public static function for(?int $limitBytes, int $usedBytes): self
    {
        if ($limitBytes === null) {
            return self::Normal;
        }
        if ($limitBytes <= 0) {
            return $usedBytes > 0 ? self::Exceeded : self::Normal;
        }
        if ($usedBytes > $limitBytes) {
            return self::Exceeded;
        }

        return match (true) {
            $usedBytes * 100 >= $limitBytes * 98 => self::Critical,
            $usedBytes * 100 >= $limitBytes * 90 => self::Nearing,
            default => self::Normal,
        };
    }
}
