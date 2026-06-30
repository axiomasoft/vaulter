<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Http\Middleware\ResolveCollaborationCall;

/**
 * The authenticated identity of a server-to-server collaboration webhook call (M4b),
 * resolved by {@see CollaborationCallResolver} and stashed by the
 * {@see ResolveCollaborationCall} middleware. It records HOW
 * the caller authenticated and, when a JWT was presented, the room/sub/readOnly it
 * carried — so the controller can assert the JWT's room matches the body's node (a
 * token for node A may never store to node B).
 *
 *   - viaSecret — true when the caller presented the valid shared webhook secret.
 *   - room      — the room the presented JWT was scoped to (null for a secret-only
 *                 call). The controller binds it to the request's node.
 *   - subject   — the JWT `sub` (the host user the hub attributes the call to), or
 *                 null for a secret-only call.
 *   - readOnly  — the JWT `readOnly` claim (a read-only token may NOT drive a store).
 */
final readonly class CollaborationCall
{
    public function __construct(
        public bool $viaSecret,
        public ?string $room = null,
        public ?string $subject = null,
        public bool $readOnly = false,
    ) {}

    /**
     * A call authenticated by the shared webhook secret (no per-room JWT scoping; the
     * secret is trusted server-to-server, so it may address any room and write).
     */
    public static function bySecret(): self
    {
        return new self(viaSecret: true);
    }

    /**
     * A call authenticated by a signed room JWT — scoped to its room, attributed to
     * its sub, and writable only when the token is not read-only.
     */
    public static function byJwt(string $room, ?string $subject, bool $readOnly): self
    {
        return new self(
            viaSecret: false,
            room: $room,
            subject: $subject,
            readOnly: $readOnly,
        );
    }

    /**
     * Whether this call may DRIVE A STORE. A shared-secret call always may; a JWT call
     * may only when its token is not read-only (a read-only connection cannot persist).
     */
    public function mayStore(): bool
    {
        return $this->viaSecret || !$this->readOnly;
    }

    /**
     * Whether this call is allowed to address the given room. A shared-secret call may
     * address any room; a JWT call may address ONLY the room it was minted for (node
     * binding — a token for one node can never act on another).
     */
    public function mayAddressRoom(string $room): bool
    {
        return $this->viaSecret || $this->room === $room;
    }
}
