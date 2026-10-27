<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Exceptions\CollaborationConfigException;
use Vaulter\Collaboration\Revocation\PermissionVersions;

/**
 * Mints the room JWT of a session and of its renewals (HS256, hub.jwt_secret). Claims: `sub` (the host user),
 * `document_id` (the document's node), `drive_id`, `tenant`, `generation` (the document's collab epoch),
 * `schema_version`, `scope` (`read`|`write`), `permissions_version` (`{drive, global}`, D46, the versions it was
 * issued under), `room` (`<prefix>.<drive_id>.<document_id>.<generation>`), `iat` (the application clock), `exp` =
 * iat + hub.token_ttl and a fresh `jti`. The room repeats drive, document and generation, so a token is bound to
 * exactly one generation of one document; {@see CollaborationCallResolver} refuses a token whose claims disagree.
 */
#[Internal]
final readonly class RoomTokens
{
    public function __construct(
        private CollaborationConfig $config,
        private PermissionVersions $versions,
    ) {}

    /**
     * @throws CollaborationConfigException without a JWT secret
     *
     * @return array{token: string, room: string, exp: int, jti: string, scope: string, permissions_version: array{drive: int, global: int}}
     */
    public function mint(string $subject, string $documentId, string $driveId, ?string $tenant, int $generation, int $schemaVersion, bool $writable, ?int $notAfter = null): array
    {
        $secret = $this->config->hubJwtSecret() ?? throw CollaborationConfigException::missingSecret();
        $room = $this->config->room($driveId, $documentId, $generation);
        $scope = $writable ? CollaborationCall::SCOPE_WRITE : CollaborationCall::SCOPE_READ;
        $version = $this->versions->current($driveId)->toClaim();
        $issuedAt = Carbon::now()->getTimestamp();
        $exp = min($issuedAt + $this->config->hubTokenTtl(), $notAfter ?? PHP_INT_MAX);
        $jti = (string) Str::uuid();

        $token = JWT::encode(
            payload: [
                'sub' => $subject,
                'document_id' => $documentId,
                'drive_id' => $driveId,
                'tenant' => $tenant,
                'generation' => $generation,
                'schema_version' => $schemaVersion,
                'scope' => $scope,
                'permissions_version' => $version,
                'room' => $room,
                'iat' => $issuedAt,
                'exp' => $exp,
                'jti' => $jti,
            ],
            key: $secret,
            alg: 'HS256',
        );

        return ['token' => $token, 'room' => $room, 'exp' => $exp, 'jti' => $jti, 'scope' => $scope, 'permissions_version' => $version];
    }
}
