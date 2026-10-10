<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Drive;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/** A pinned (keep_forever) revision is never deleted, by the API or by the pruning (PLAN2.D48, P4-I19). */
final class RevisionPinnedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forRevision(int|string $revisionId): self
    {
        return new self('[Vaulter] Revision ['.Identity::stringify($revisionId).'] is kept forever and cannot be deleted. '
            .'Fix: unpin it with setRevisionRetention(..., keepForever: false) first.');
    }

    public function problemCode(): string
    {
        return 'revision_pinned';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
