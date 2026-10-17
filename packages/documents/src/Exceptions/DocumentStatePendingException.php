<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\State\PendingMarker;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The document carries committed collaboration edits its working state does not hold yet (the pending-state marker,
 * D26), and they could not be brought in before the deadline: no realtime transport is active, or it did not drain the
 * log in time. Reading or replacing the working state now would serve stale content or drop saved edits, so the
 * request is refused untouched; repeating it later with backoff is safe.
 */
#[Api]
final class DocumentStatePendingException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    #[Internal]
    public function __construct(public readonly PendingMarker $marker)
    {
        parent::__construct(sprintf(
            '[Vaulter] The document has saved collaboration edits that are not in its content yet. Why: the realtime transport did not bring them in before the deadline, or it is not active. Fix: retry later; make sure the collaboration module and its hub run. Docs: %s',
            self::docs('document-state-pending'),
        ));
    }

    public function problemCode(): string
    {
        return 'document_state_pending';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * Only the node: the generation and the log sequence are the transport's internals.
     *
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['node_id' => $this->marker->nodeId];
    }
}
