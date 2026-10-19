<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Data;

use Vaulter\Attributes\Internal;

/**
 * The result of a write that mints a signing secret (register, rotate_secret): the endpoint and the raw secret, which
 * the response shows once. A receipt keeps the secret only as the application's ciphertext (ID5).
 */
#[Internal]
final readonly class MintedEndpointSecret
{
    public function __construct(
        public WebhookEndpointSnapshot $endpoint,
        public string $secret,
    ) {}
}
