<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Data;

use Vaulter\DocDrive\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\DocDrive\Enums\CollaborationDriverType;

/**
 * The unified realtime-collaboration session descriptor (master plan §7 / ADR-0001 /
 * M4b) a {@see NodeCollaborationDriver} issues for a document node
 * and the collab-token endpoint returns. It is the ONE shape the Vue Yjs binding and
 * the Node hub consume, regardless of transport:
 *
 *   - driver   — the chosen transport (reverb|hocuspocus).
 *   - room     — the room/channel name, ALWAYS channel_prefix.nodeId, so a session is
 *                bound to exactly one node (no cross-node access via a token).
 *   - nodeId   — the document node id the room maps to.
 *   - readOnly — encoded from the actor's ability on the node (View/Comment → true,
 *                Edit → false). The client mounts a read-only editor when true.
 *   - url      — the websocket URL the client connects to (the Hocuspocus hub); null
 *                for Reverb, which connects via Echo over `connection`.
 *   - token    — the room access handle. For Hocuspocus a signed JWT carrying
 *                {room, sub, readOnly, exp}; for Reverb the private channel name the
 *                broadcast auth endpoint gates (Reverb authenticates out-of-band).
 *   - connection — the Laravel broadcast connection name for the Reverb transport;
 *                null for Hocuspocus.
 */
final readonly class CollaborationSessionData
{
    public function __construct(
        public CollaborationDriverType $driver,
        public string $room,
        public int|string $nodeId,
        public bool $readOnly,
        public string $token,
        public ?string $url = null,
        public ?string $connection = null,
    ) {}

    /**
     * The wire shape the collab-token endpoint returns and the Vue client binds
     * against (a stable contract for the Node + Vue edge slices).
     *
     * @return array{
     *     driver: string,
     *     room: string,
     *     node_id: int|string,
     *     read_only: bool,
     *     token: string,
     *     url: string|null,
     *     connection: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'room' => $this->room,
            'node_id' => $this->nodeId,
            'read_only' => $this->readOnly,
            'token' => $this->token,
            'url' => $this->url,
            'connection' => $this->connection,
        ];
    }
}
