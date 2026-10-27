<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Vaulter\Documents\Data\Collaboration\CollaborationGrant;

/** Shared by the fixture backend and kit; values come only from its opaque-token store. */
final class AcmeOtTokenVerifier
{
    public function __construct(private AcmeDriver $driver) {}

    public function verify(string $token): array
    {
        return self::normalize($this->driver->verify($token));
    }

    public static function normalize(CollaborationGrant $verified): array
    {
        return ['principal' => $verified->principal, 'tenant' => $verified->tenant,
            'node_id' => $verified->nodeId, 'drive_id' => $verified->driveId,
            'protocol' => $verified->protocol, 'state_format' => $verified->stateFormat,
            'generation' => $verified->generation, 'schema_version' => $verified->schemaVersion,
            'mode' => $verified->mode, 'expires_at' => $verified->notAfter];
    }
}
