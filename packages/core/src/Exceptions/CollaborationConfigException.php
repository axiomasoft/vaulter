<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

/**
 * A realtime-collaboration operation could not proceed because its configuration is
 * incomplete (M4b): firebase/php-jwt missing, or no signing/webhook secret set. These
 * are operator-fixable setup errors, surfaced loud rather than failing open.
 */
final class CollaborationConfigException extends VaulterException
{
    public static function missingJwt(): self
    {
        return new self(
            '[Vaulter] The Hocuspocus collaboration driver requires firebase/php-jwt. '
            .'Fix: composer require firebase/php-jwt. '
            .'Docs: '.self::docs('missing-jwt'),
        );
    }

    public static function missingSecret(): self
    {
        return new self(
            '[Vaulter] Missing the Hocuspocus collaboration signing secret. '
            .'Why: minting/verifying a room JWT needs config(vaulter.collaboration.drivers.hocuspocus.secret). '
            .'Fix: set VAULTER_COLLAB_SECRET in your environment. '
            .'Docs: '.self::docs('missing-collab-secret'),
        );
    }

    public static function noVerifier(): self
    {
        return new self(
            '[Vaulter] The collaboration webhook surface has no way to authenticate callers. '
            .'Why: neither a shared webhook secret nor a JWT signing secret is configured, '
            .'so the collab/authenticate + collab/store endpoints cannot verify the Node hub. '
            .'Fix: set VAULTER_COLLAB_WEBHOOK_SECRET (shared secret) and/or VAULTER_COLLAB_SECRET (JWT key). '
            .'Docs: '.self::docs('collab-webhook-unconfigured'),
        );
    }
}
