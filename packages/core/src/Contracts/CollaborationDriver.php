<?php

declare(strict_types=1);

namespace DocVault\Contracts;

use DocVault\Enums\CollaborationDriverType;
use DocVault\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Abstracts the real-time collaboration transport. The Reverb driver keeps
 * everything inside Laravel; the Hocuspocus driver targets an external Node hub.
 */
interface CollaborationDriver
{
    public function type(): CollaborationDriverType;

    /**
     * Authorize a user to join the realtime room for the given document
     * (delegates to the host PermissionResolver).
     */
    public function authorizeChannel(Authenticatable $user, Document $document): bool;

    /**
     * Front-end connection descriptor (channel/room, transport URL, token).
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(Document $document, Authenticatable $user): array;

    /**
     * Mint a room access token. May be an empty string for transports that
     * authenticate out-of-band (e.g. Reverb private-channel auth).
     */
    public function issueToken(Document $document, Authenticatable $user): string;
}
