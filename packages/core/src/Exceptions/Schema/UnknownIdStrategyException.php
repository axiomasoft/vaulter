<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Schema;

use Vaulter\Exceptions\VaulterException;

/**
 * Thrown when config('vaulter.ids.default') (or an explicit key) names a
 * strategy that is not registered in config('vaulter.ids.strategies').
 */
final class UnknownIdStrategyException extends VaulterException
{
    /**
     * @param list<string> $available
     */
    public static function forKey(string $key, array $available): self
    {
        $known = $available === [] ? '(none)' : implode(', ', $available);

        return new self(
            "Unknown Vaulter id strategy [{$key}]. Available: {$known}.",
        );
    }
}
