<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Http;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A mutating request omitted the validator header the route requires (RFC 6585 428),
 * so the server cannot tell which representation the client saw.
 */
#[Api]
final class PreconditionRequiredException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(string $message, private readonly string $header, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function missing(string $header): self
    {
        return new self(
            sprintf('[Vaulter] The %s header is required. Why: the route changes state only against the representation the client read. Fix: send the ETag of that representation. Docs: %s', $header, self::docs('precondition-required')),
            $header,
            'missing',
        );
    }

    /**
     * `If-Match: *` matches any representation, which defeats the lost-update check.
     */
    public static function wildcard(): self
    {
        return new self(
            '[Vaulter] If-Match: * is not accepted. Why: it matches any representation, so it cannot detect a lost update. Fix: send the ETag of the representation you read. Docs: '.self::docs('precondition-required'),
            'If-Match',
            'wildcard_not_allowed',
        );
    }

    public function problemCode(): string
    {
        return 'precondition_required';
    }

    public function httpStatus(): int
    {
        return 428;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['header' => $this->header, 'reason' => $this->reason];
    }
}
