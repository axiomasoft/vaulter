<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A hub tried to seed the collaboration state of a document that already has one.
 * Seeding builds new Yjs insertions from the JSON; a second seed would duplicate the
 * whole document when the rooms merge, so only the first one is kept and the hub
 * reloads the stored state.
 */
final class CollaborationSeedConflictException extends VaulterException implements ProblemException
{
    public function __construct(public readonly string $nodeId)
    {
        parent::__construct(sprintf(
            '[Vaulter] Document %s already has a collaboration state; the seed was not written. Why: another hub instance seeded it first. Fix: reload the document state. Docs: %s',
            $nodeId,
            self::docs('collaboration-seed-conflict'),
        ));
    }

    public function problemCode(): string
    {
        return 'collaboration_seed_conflict';
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
        return ['context' => ['node_id' => $this->nodeId]];
    }
}
