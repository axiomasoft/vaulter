<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A deterministic refusal replayed from an idempotency receipt (D25): the same problem code, status, details
 * and headers the first request answered with, rendered again (title and detail come from the catalogue in the
 * current locale). Nothing ran on the replay.
 */
#[Api]
final class ReplayedProblemException extends VaulterException implements ProblemException
{
    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $problem,
        private readonly int $status,
        private readonly array $details = [],
        private readonly array $headers = [],
    ) {
        parent::__construct(sprintf('[Vaulter] Replayed the stored refusal "%s" of an idempotent request; nothing ran.', $problem));
    }

    public function problemCode(): string
    {
        return $this->problem;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function problemDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function problemHeaders(): array
    {
        return $this->headers;
    }
}
