<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Document;

/**
 * A document was permanently deleted (force-deleted from the trash), as opposed
 * to the reversible {@see DocumentDeleted} soft delete.
 */
final readonly class DocumentPurged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public int|string|null $actorId = null,
    ) {}
}
