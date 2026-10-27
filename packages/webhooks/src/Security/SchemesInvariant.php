<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Security;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Webhooks\Configuration\WebhooksConfig;

/** Webhook deliveries leave the process over https only. */
#[Internal]
final readonly class SchemesInvariant implements ProductionInvariant
{
    public function __construct(private WebhooksConfig $config) {}

    public function id(): string
    {
        return 'webhooks.schemes';
    }

    public function violation(): ?string
    {
        return $this->config->allowedSchemes() === ['https']
            ? null
            : 'vaulter-webhooks.delivery.allowed_schemes must be exactly ["https"].';
    }
}
