<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

/**
 * The outcome of resolving a bearer share-token (§2.7 / M2c). It distinguishes the
 * denial reasons the public HTTP layer must translate to different statuses:
 *
 *  - Ok            → a ShareContext is available (the request proceeds).
 *  - NotFound      → no live share for this token (wrong / expired / revoked /
 *                    over-max_uses / item-safety failure). Indistinguishable on
 *                    purpose so a probe cannot tell "wrong token" from "trashed
 *                    target" — both 404.
 *  - PasswordRequired → the share is password-gated and no/incorrect password was
 *                    supplied (401 + a password challenge).
 *  - LoginRequired → the share's audience needs a signed-in host user and none is
 *                    present (401).
 */
enum ShareResolution: string
{
    case Ok = 'ok';
    case NotFound = 'not_found';
    case PasswordRequired = 'password_required';
    case LoginRequired = 'login_required';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }
}
