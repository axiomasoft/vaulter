<?php

declare(strict_types=1);

namespace Vaulter\Media\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Canonical Packman error codes owned by `axiomasoft/vaulter-media`.
 */
#[Api(since: '1.0')]
enum MediaErrorCode: string implements ErrorCode
{
    case BlobUnavailable = 'media.blob_unavailable';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
