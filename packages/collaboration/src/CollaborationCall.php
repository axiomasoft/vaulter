<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Data\RoomRef;
use Vaulter\Collaboration\Http\Middleware\ResolveCollaborationCall;

/**
 * The verified room token of a hub call (collab/authenticate, collab/load,
 * collab/store), resolved by {@see CollaborationCallResolver} and stashed by
 * {@see ResolveCollaborationCall}. Every claim was signed by this application; the
 * hub only relays the token its client presented.
 *
 *   - room/ref      — the room the token was minted for and its drive/node/epoch.
 *   - subject       — the host user the hub attributes the call to.
 *   - mode          — 'rw' or 'ro' at the time the token was issued; the hub endpoints
 *                     re-check it against the live document and rights.
 *   - schemaVersion — the schema the session was issued for.
 *   - tenant        — the node's tenant when the token was issued, or null.
 */
#[Internal]
final readonly class CollaborationCall
{
    public function __construct(
        public string $room,
        public RoomRef $ref,
        public string $subject,
        public string $mode,
        public int $schemaVersion,
        public ?string $tenant,
    ) {}

    /** A read-only token never drives a store, whatever the live rights are. */
    public function mayStore(): bool
    {
        return $this->mode === 'rw';
    }
}
