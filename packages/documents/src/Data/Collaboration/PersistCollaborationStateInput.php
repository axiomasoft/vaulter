<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Models\Drive\Node;

/**
 * Command for {@see PersistCollaborationStateAction} (§7 / M4b) —
 * the Node Hocuspocus hub's debounced onStoreDocument snapshot. The Yjs binary CRDT
 * state is the SOURCE OF TRUTH and is always present; the TipTap projection is the
 * derived view the hub may also send (the Action NEVER reconstructs Yjs from JSON).
 *
 *   - node     — the document node the snapshot belongs to (route/webhook resolved).
 *   - yjsState — the RAW DECODED binary Yjs state (bytea), already base64-decoded by
 *                the webhook controller. The source of truth.
 *   - content  — the optional JSON projection as the hub sent it, decoded by the
 *                document's type on the locked row; null leaves the projection as it
 *                was (the Yjs state still advances).
 *   - actorId  — the host user the hub attributes the snapshot to, when known.
 */
final readonly class PersistCollaborationStateInput
{
    public function __construct(
        public Node $node,
        public string $yjsState,
        /** @var array<string, mixed>|null */
        public ?array $content = null,
        public int|string|null $actorId = null,
    ) {}
}
