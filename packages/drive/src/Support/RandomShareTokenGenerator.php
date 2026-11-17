<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support;

use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\Sharing\ShareTokenGenerator;

/**
 * Default share-token generator: 32 random bytes as unpadded base64url (43
 * characters, 256 bits of entropy).
 */
#[Internal]
final class RandomShareTokenGenerator implements ShareTokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
