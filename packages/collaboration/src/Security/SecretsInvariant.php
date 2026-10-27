<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Security;

use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Contracts\Security\ProductionInvariant;

/** Nonempty, different JWT and webhook secrets; the values are never part of the message. */
#[Internal]
final readonly class SecretsInvariant implements ProductionInvariant
{
    public function __construct(private CollaborationConfig $config) {}

    public function id(): string
    {
        return 'collaboration.secrets';
    }

    public function violation(): ?string
    {
        $jwt = $this->config->hubJwtSecret();
        $webhook = $this->config->hubWebhookSecret();
        if ($jwt !== null && $webhook !== null && $jwt !== $webhook) {
            return null;
        }

        return 'Collaboration requires nonempty, different vaulter-collaboration.hub.jwt_secret and webhook_secret (VAULTER_COLLAB_SECRET, VAULTER_COLLAB_WEBHOOK_SECRET).';
    }
}
