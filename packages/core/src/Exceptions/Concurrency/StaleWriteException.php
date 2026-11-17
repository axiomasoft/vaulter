<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Concurrency;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The `If-Match` of a write names a version of the aggregate that is no longer current: someone
 * changed it since the client read it. The client refetches and decides again; the outcome is deterministic,
 * so an idempotency receipt stores it.
 */
#[Api]
final class StaleWriteException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(private readonly string $aggregate, private readonly string $id)
    {
        parent::__construct(sprintf('[Vaulter] The %s [%s] changed since it was read; refetch it before retrying.', $aggregate, $id));
    }

    /** @param string $aggregate the aggregate of the operation catalogue (`drive.share`, `webhooks.endpoint`, …) */
    public static function of(string $aggregate, int|string $id): self
    {
        return new self($aggregate, (string) $id);
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: VaulterErrorCode::StaleWrite,
            category: ErrorCategory::Validation,
            details: ['aggregate' => $this->aggregate, 'id' => $this->id],
        );
    }

    public function problemCode(): string
    {
        return 'stale_write';
    }

    public function httpStatus(): int
    {
        return 412;
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return ['aggregate' => $this->aggregate, 'id' => $this->id];
    }
}
