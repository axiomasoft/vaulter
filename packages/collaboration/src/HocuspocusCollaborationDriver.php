<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Enums\CollaborationDriverType;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Drive\Models\Node;
use Vaulter\Support\Identity;

/**
 * The collaboration transport targeting an external Node Hocuspocus hub (see
 * packages/collaboration/hub/). The client connects with a signed room JWT ({@see RoomTokens}: sub, document_id,
 * drive_id, tenant, generation, schema_version, scope, permissions_version, room, iat, exp, jti); the hub forwards it
 * on every call, where the backend re-checks it against the live document and rights, and renews it before it
 * expires. Requires firebase/php-jwt.
 */
#[Internal]
final readonly class HocuspocusCollaborationDriver implements CollaborationDriver
{
    public function __construct(
        private CollaborationConfig $config,
        private RoomTokens $tokens,
    ) {}

    public function type(): string
    {
        return CollaborationDriverType::Hocuspocus->value;
    }

    public function issueSession(Node $node, int $epoch, int $schemaVersion, bool $writable, int|string $actorId): CollaborationSession
    {
        $nodeId = Identity::stringify($node->getRawOriginal($node->getKeyName()));
        $driveId = Identity::stringify($node->getRawOriginal('drive_id'));
        $tenant = $node->getRawOriginal('tenant_id');

        $minted = $this->tokens->mint(
            subject: Identity::stringify($actorId),
            documentId: $nodeId,
            driveId: $driveId,
            tenant: $tenant === null ? null : Identity::stringify($tenant),
            generation: $epoch,
            schemaVersion: $schemaVersion,
            writable: $writable,
        );

        return new CollaborationSession(
            driver: $this->type(),
            room: $minted['room'],
            nodeId: $nodeId,
            driveId: $driveId,
            epoch: $epoch,
            schemaVersion: $schemaVersion,
            readOnly: !$writable,
            token: $minted['token'],
            url: $this->config->hubUrl(),
        );
    }
}
