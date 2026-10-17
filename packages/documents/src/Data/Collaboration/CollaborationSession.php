<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Attributes\Api;

/**
 * A realtime collaboration session for one generation of a document, as
 * CollaborationOperations::session() issues it and the collab-token endpoint
 * returns it:
 *
 *   - room          — "<prefix>.<driveId>.<nodeId>.<epoch>": one document, one generation.
 *   - epoch         — the generation; a restore moves it on and closes the old room.
 *   - schemaVersion — the schema the hub seeds and stores in; a client that supports an
 *                     older one must stay read-only.
 *   - readOnly      — false only with Edit on content this installation can save back.
 *   - token         — a signed JWT carrying the same claims; the hub re-checks them.
 */
#[Api]
final readonly class CollaborationSession
{
    public function __construct(
        public string $driver,
        public string $room,
        public string $nodeId,
        public string $driveId,
        public int $epoch,
        public int $schemaVersion,
        public bool $readOnly,
        public string $token,
        public ?string $url = null,
    ) {}

    /** 'rw' or 'ro', as the token's mode claim. */
    public function mode(): string
    {
        return $this->readOnly ? 'ro' : 'rw';
    }

    /**
     * @return array{
     *     driver: string,
     *     room: string,
     *     node_id: string,
     *     drive_id: string,
     *     epoch: int,
     *     schema_version: int,
     *     mode: string,
     *     token: string,
     *     url: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'room' => $this->room,
            'node_id' => $this->nodeId,
            'drive_id' => $this->driveId,
            'epoch' => $this->epoch,
            'schema_version' => $this->schemaVersion,
            'mode' => $this->mode(),
            'token' => $this->token,
            'url' => $this->url,
        ];
    }
}
