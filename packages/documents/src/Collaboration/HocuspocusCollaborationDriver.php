<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Firebase\JWT\JWT;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Documents\Exceptions\CollaborationConfigException;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;

/**
 * The collaboration transport targeting an external Node Hocuspocus hub (see
 * hocuspocus-server/). The client connects with a signed room JWT; the hub forwards it
 * on every authenticate/load/store call, where the backend re-checks it against the
 * live document. Requires firebase/php-jwt.
 *
 * Claims (HS256, hub.jwt_secret): sub, node, drive, tenant, epoch, schema_version,
 * mode ('rw'|'ro'), room, iat, exp. The room repeats drive, node and epoch, so a token
 * is bound to exactly one generation of one document.
 */
final readonly class HocuspocusCollaborationDriver implements CollaborationDriver
{
    public function __construct(
        private DocumentsConfig $documentsConfig,
    ) {}

    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Hocuspocus;
    }

    public function issueSession(Node $node, int $epoch, int $schemaVersion, bool $writable, int|string $actorId): CollaborationSession
    {
        if (!class_exists(JWT::class)) {
            throw CollaborationConfigException::missingJwt();
        }

        $secret = $this->documentsConfig->hubJwtSecret();

        if ($secret === null) {
            throw CollaborationConfigException::missingSecret();
        }

        $nodeId = Identity::stringify($node->getRawOriginal($node->getKeyName()));
        $driveId = Identity::stringify($node->getRawOriginal('drive_id'));
        $tenant = $node->getRawOriginal('tenant_id');
        $room = $this->documentsConfig->collaborationRoom($driveId, $nodeId, $epoch);
        $mode = $writable ? 'rw' : 'ro';

        $issuedAt = time();
        $token = JWT::encode(
            payload: [
                'sub' => Identity::stringify($actorId),
                'node' => $nodeId,
                'drive' => $driveId,
                'tenant' => $tenant === null ? null : Identity::stringify($tenant),
                'epoch' => $epoch,
                'schema_version' => $schemaVersion,
                'mode' => $mode,
                'room' => $room,
                'iat' => $issuedAt,
                'exp' => $issuedAt + $this->documentsConfig->hubTokenTtl(),
            ],
            key: $secret,
            alg: 'HS256',
        );

        return new CollaborationSession(
            driver: $this->type(),
            room: $room,
            nodeId: $nodeId,
            driveId: $driveId,
            epoch: $epoch,
            schemaVersion: $schemaVersion,
            readOnly: !$writable,
            token: $token,
            url: $this->documentsConfig->hubUrl(),
        );
    }
}
