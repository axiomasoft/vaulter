<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\VaulterException;

/**
 * A realtime-collaboration operation could not proceed because its configuration is
 * incomplete: no signing/webhook secret set. These
 * are operator-fixable setup errors, surfaced loud rather than failing open.
 */
#[Api]
final class CollaborationConfigException extends VaulterException
{
    public static function missingSecret(): self
    {
        return new self(
            '[Vaulter] Missing the Hocuspocus collaboration signing secret. '
            .'Why: minting/verifying a room JWT needs config(vaulter-collaboration.hub.jwt_secret). '
            .'Fix: set VAULTER_COLLAB_SECRET in your environment. '
            .'Docs: '.self::docs('missing-collab-secret'),
        );
    }
}
