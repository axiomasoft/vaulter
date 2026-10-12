<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;

/**
 * The unified realtime-collaboration session descriptor (master plan §7 / ADR-0001 /
 * M4b) a {@see CollaborationDriver} issues for a document node
 * and the collab-token endpoint returns. It is the ONE shape the Vue Yjs binding and
 * the Node hub consume, regardless of transport:
 *
 *   - driver   — hocuspocus.
 *   - room     — channel_prefix.nodeId, bound to exactly one document node.
 *   - nodeId   — the document node id.
 *   - readOnly — View/Comment is read-only; Edit is writable.
 *   - url      — the Hocuspocus hub websocket URL.
 *   - token    — a signed JWT carrying room, sub, readOnly and exp.
 *   - connection — retained as null in the Hocuspocus JSON descriptor.
 */
final readonly class CollaborationSession
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
