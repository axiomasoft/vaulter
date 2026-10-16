<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use InvalidArgumentException;
use Vaulter\Attributes\Api;

/**
 * One problem code of a module (architecture#errors, D25): the HTTP status, the exception that carries it,
 * whether a client may retry and whether an idempotent operation caches it as its receipt. The default
 * receipt is `Never`: a code is cached only when its owner declares the outcome deterministic.
 */
#[Api]
final readonly class ProblemSpec
{
    /**
     * @param class-string|null $exception
     */
    public function __construct(
        public int $status,
        public ?string $exception = null,
        public Retryable $retryable = Retryable::Never,
        public ReceiptPolicy $receipt = ReceiptPolicy::Never,
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(sprintf('[Vaulter] A problem code answers with a 4xx or 5xx status, not %d.', $status));
        }
        // A server failure is never a deterministic outcome of the request (D25).
        if ($status >= 500 && $receipt === ReceiptPolicy::Cacheable) {
            throw new InvalidArgumentException(sprintf('[Vaulter] A %d problem cannot be cached as an idempotency receipt.', $status));
        }
    }
}
