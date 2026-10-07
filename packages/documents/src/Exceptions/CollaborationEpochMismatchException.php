<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A collaboration write came from a room generation the document has left (a
 * restore or an external replace moved collab_epoch on). Accepting it would merge
 * an abandoned Yjs history back into the document, so the write is fenced off; the
 * hub treats the 409 as terminal and closes the room.
 */
final class CollaborationEpochMismatchException extends VaulterException implements ProblemException
{
    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
    ) {
        parent::__construct(sprintf(
            '[Vaulter] The collaboration room is from epoch %d, the document is at epoch %d; reopen the document. Docs: %s',
            $expected,
            $actual,
            self::docs('collaboration-epoch-mismatch'),
        ));
    }

    public function problemCode(): string
    {
        return 'collaboration_epoch_mismatch';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function renderExtras(): array
    {
        return ['context' => ['expected' => $this->expected, 'actual' => $this->actual]];
    }
}
