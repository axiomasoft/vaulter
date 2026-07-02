<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Collaboration;

use Firebase\JWT\JWT;
use Vaulter\DocDrive\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\DocDrive\Data\CollaborationSessionData;
use Vaulter\DocDrive\Enums\CollaborationDriverType;
use Vaulter\DocDrive\Exceptions\CollaborationConfigException;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The optional node-collaboration transport (M4b) targeting an external Node
 * Hocuspocus hub (see hocuspocus-server/). The client connects to the hub URL with a
 * signed room JWT; the hub's onAuthenticate verifies the JWT (or calls the
 * collab/authenticate webhook) and its onStoreDocument debounces a snapshot back into
 * collab/store. Requires firebase/php-jwt to mint the room token.
 *
 * The JWT carries {room, sub, readOnly, iat, exp} signed HS256 with the shared secret:
 *   - room     — the room name (channel_prefix.nodeId), so the token is bound to
 *                exactly one node; a token for node A can never address node B.
 *   - sub      — the connecting host user's id.
 *   - readOnly — encoded from the actor's ability (View/Comment → true, Edit → false);
 *                the hub refuses writes from a read-only connection.
 *   - exp      — now + token_ttl.
 */
final readonly class HocuspocusNodeCollaborationDriver implements NodeCollaborationDriver
{
    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Hocuspocus;
    }

    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSessionData
    {
        if (!class_exists(JWT::class)) {
            throw CollaborationConfigException::missingJwt();
        }

        $secret = Config::collaborationJwtSecret();

        if ($secret === null) {
            throw CollaborationConfigException::missingSecret();
        }

        $room = Config::collaborationRoom($node->getKey());
        $readOnly = $ability !== Ability::Edit;

        $issuedAt = time();
        $token = JWT::encode(
            payload: [
                'room' => $room,
                'sub' => Identity::stringify($actorId),
                'readOnly' => $readOnly,
                'iat' => $issuedAt,
                'exp' => $issuedAt + Config::collaborationTokenTtl(),
            ],
            key: $secret,
            alg: 'HS256',
        );

        return new CollaborationSessionData(
            driver: $this->type(),
            room: $room,
            nodeId: Identity::key($node->getKey()) ?? Identity::stringify($node->getKey()),
            readOnly: $readOnly,
            token: $token,
            url: Config::collaborationHubUrl(),
            connection: null,
        );
    }
}
