<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Data;

use Vaulter\Attributes\Internal;

/**
 * The result of rotating an endpoint's signing secret: the new secret, returned to the
 * caller exactly once like at registration. The stored row never exposes it again.
 */
#[Internal]
final readonly class RotatedWebhookSecret
{
    public function __construct(
        public int|string $endpointId,
        public string $secret,
    ) {}
}
