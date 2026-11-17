<?php

declare(strict_types=1);

namespace Vaulter\Media\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 problem definitions for all `axiomasoft/vaulter-media` error codes.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class MediaProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        yield new ProblemDefinition(
            code: MediaErrorCode::BlobUnavailable,
            type: self::URN_PREFIX.MediaErrorCode::BlobUnavailable->value,
            defaultStatus: 409,
            title: 'Blob bytes are unavailable',
            retryable: true,
            category: ErrorCategory::Conflict,
            detailKeys: [],
        );
    }
}
