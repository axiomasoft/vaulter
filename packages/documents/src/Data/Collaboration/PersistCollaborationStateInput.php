<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Models\Drive\Node;

/**
 * Command for {@see PersistCollaborationStateAction} — a store from the collaboration
 * hub. The binary Yjs state is the source of truth and is always present; the JSON
 * projection is derived from it (by the hub, or by the type's projectFromState()).
 *
 *   - node          — the document node the state belongs to.
 *   - yjsState      — the raw decoded binary Yjs state.
 *   - content       — the JSON projection as the hub sent it, decoded by the document's
 *                     type on the locked row; required when the type cannot project it.
 *   - actorId       — the host user the hub attributes the store to.
 *   - expectedEpoch — the room's generation; any other collab_epoch refuses the write
 *                     (409 collaboration_epoch_mismatch). Null only for internal writers
 *                     that are not a room.
 *   - seed          — the first state built from the JSON: refused unless the document
 *                     has no Yjs state yet (409 collaboration_seed_conflict).
 */
final readonly class PersistCollaborationStateInput
{
    public function __construct(
        public Node $node,
        public string $yjsState,
        /** @var array<string, mixed>|null */
        public ?array $content = null,
        public int|string|null $actorId = null,
        public ?int $expectedEpoch = null,
        public bool $seed = false,
    ) {}
}
