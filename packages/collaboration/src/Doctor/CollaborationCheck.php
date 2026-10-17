<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Doctor;

use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

/**
 * The settings of an active collaboration module. Evidence names the settings and says whether they are set; the
 * secrets themselves never leave the config. (An inactive module registers no check: it is simply not collaborating.)
 */
#[Internal]
final readonly class CollaborationCheck implements DoctorCheck
{
    public function __construct(private CollaborationConfig $config) {}

    public function key(): string
    {
        return 'collaboration.hub';
    }

    public function title(): string
    {
        return 'Real-time collaboration';
    }

    public function run(DoctorContext $context): array
    {
        $jwt = $this->config->hubJwtSecret();
        $webhook = $this->config->hubWebhookSecret();
        $url = $this->config->hubUrl();
        $evidence = ['jwt_secret_set' => $jwt !== null, 'webhook_secret_set' => $webhook !== null, 'hub_url_set' => $url !== null];

        $problems = [];
        if ($jwt === null || $webhook === null) {
            $problems[] = ['collaboration.hub_secrets_missing', 'The JWT secret and the webhook secret must both be set.', 'set VAULTER_COLLAB_SECRET and VAULTER_COLLAB_WEBHOOK_SECRET'];
        } elseif ($jwt === $webhook) {
            $problems[] = ['collaboration.hub_secrets_equal', 'The JWT secret and the webhook secret must differ: the hub holds the webhook secret and could sign room tokens with it.', 'use two different values'];
        }
        if ($url === null) {
            $problems[] = ['collaboration.hub_url_missing', 'The hub URL is not set.', 'set vaulter-collaboration.hub.url'];
        } elseif ($context->production && !str_starts_with($url, 'wss://')) {
            $problems[] = ['collaboration.hub_url_insecure', 'The hub URL must use wss:// in production.', 'serve the hub over TLS and use wss://'];
        }
        try {
            $this->config->hubTokenTtl();
        } catch (Throwable) {
            $problems[] = ['collaboration.hub_token_ttl', 'The room token lifetime must be between 1 and 3600 seconds.', 'set vaulter-collaboration.hub.token_ttl to at most 3600'];
        }

        if ($problems === []) {
            return [DoctorFinding::ok(code: 'collaboration.hub_ok', message: 'The collaboration settings are complete.', evidence: $evidence)];
        }

        return array_map(
            static fn (array $problem): DoctorFinding => DoctorFinding::error(code: $problem[0], message: $problem[1], hint: $problem[2], evidence: $evidence),
            $problems,
        );
    }
}
