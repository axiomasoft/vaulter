<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\Data\CollaborationSession;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The default node-collaboration transport (M4b): Yjs over Laravel Reverb, no extra
 * service. The Vue client joins the PRIVATE broadcast channel for the room
 * (channel_prefix.nodeId) over the configured Echo connection; Laravel's channel-auth
 * endpoint authorizes the subscription through the AccessResolver-backed broadcast
 * callback. There is therefore no opaque room JWT — the session's `token` IS the
 * private channel name the auth endpoint gates, so a single accessor yields a usable
 * handle for both transports.
 *
 * The room is bound to exactly one node (channel_prefix.nodeId), so a session can only
 * ever address its own node — there is no cross-node access through a Reverb session.
 */
final readonly class ReverbNodeCollaborationDriver implements NodeCollaborationDriver
{
    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Reverb;
    }

    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSession
    {
        $room = Config::collaborationRoom($node->getKey());

        return new CollaborationSession(
            driver: $this->type(),
            room: $room,
            nodeId: Identity::key($node->getKey()) ?? Identity::stringify($node->getKey()),
            readOnly: $ability !== Ability::Edit,
            token: $room,
            url: null,
            connection: Config::reverbConnection(),
        );
    }
}
