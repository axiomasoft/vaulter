<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Schema;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\VaulterException;

/**
 * Thrown when configuration key vaulter.ids.strategy (or an explicit key) names a
 * strategy that is not registered in configuration key vaulter.ids.strategies.
 */
#[Api]
final class UnknownIdStrategyException extends VaulterException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::UnknownIdStrategy, category: ErrorCategory::Internal);
    }

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
