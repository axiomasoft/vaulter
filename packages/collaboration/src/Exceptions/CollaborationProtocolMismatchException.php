<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The hub speaks another version of the hub ↔ backend protocol than this endpoint (header `X-Vaulter-Collab-Protocol`).
 * A deployment mismatch, not a race: retrying does not help until the hub and the backend run matching versions.
 */
#[Api]
final class CollaborationProtocolMismatchException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    #[Internal]
    public function __construct(public readonly ?string $presented, public readonly int $expected)
    {
        parent::__construct(sprintf(
            '[Vaulter] The hub sent collaboration protocol %s, this endpoint speaks %d; deploy matching hub and backend versions. Docs: %s',
            $presented ?? '(none)',
            $expected,
            self::docs('collaboration-protocol-mismatch'),
        ));
    }

    public function problemCode(): string
    {
        return 'collaboration_protocol_mismatch';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return ['context' => ['presented' => $this->presented, 'expected' => $this->expected]];
    }
}
