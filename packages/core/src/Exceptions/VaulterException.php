<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Vaulter\Errors\VaulterErrorCode;

/**
 * Base for every Vaulter error — catch this to handle all package failures.
 */
#[Api]
class VaulterException extends CodedException
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: VaulterErrorCode::InternalError,
            category: ErrorCategory::Internal,
        );
    }

    protected static function docs(string $anchor): string
    {
        return 'https://github.com/axiomasoft/vaulter/blob/main/docs/guides/errors.md#'.$anchor;
    }

    protected static function boundedDetail(string $value, int $maxChars = 512, int $maxJsonBytes = 1024): string
    {
        $clean = mb_scrub($value, 'UTF-8');
        $escaped = preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            $clean,
        ) ?? $clean;

        $truncated = false;
        if (mb_strlen($escaped, 'UTF-8') > $maxChars) {
            $escaped = mb_substr($escaped, 0, $maxChars, 'UTF-8');
            $truncated = true;
        }

        while ($escaped !== '' && strlen(json_encode($escaped.'…', JSON_THROW_ON_ERROR)) > $maxJsonBytes) {
            $escaped = mb_substr($escaped, 0, max(1, intdiv(mb_strlen($escaped, 'UTF-8') * 3, 4)) - 1, 'UTF-8');
            $truncated = true;
        }

        return $truncated ? $escaped.'…' : $escaped;
    }
}
