<?php

declare(strict_types=1);

namespace Vaulter\Documents\Doctor;

use Firebase\JWT\JWT;
use Throwable;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Documents\Configuration\DocumentsConfig;

/** Evidence names the settings and says whether they are set; the secrets themselves never leave the config. */
final readonly class CollaborationCheck implements DoctorCheck
{
    public function __construct(private DocumentsConfig $config) {}

    public function key(): string
    {
        return 'documents.collaboration';
    }

    public function title(): string
    {
        return 'Real-time collaboration';
    }

    public function run(DoctorContext $context): array
    {
        if (!$this->config->collaborationEnabled()) {
            return [DoctorFinding::ok(code: 'documents.collaboration_disabled', message: 'Collaboration is not enabled.', evidence: ['enabled' => false])];
        }

        $jwt = $this->config->hubJwtSecret();
        $webhook = $this->config->hubWebhookSecret();
        $url = $this->config->hubUrl();
        $evidence = ['enabled' => true, 'jwt_secret_set' => $jwt !== null, 'webhook_secret_set' => $webhook !== null, 'hub_url_set' => $url !== null];

        $problems = [];
        if ($jwt === null || $webhook === null) {
            $problems[] = ['documents.collaboration_secrets_missing', 'The JWT secret and the webhook secret must both be set.', 'set VAULTER_COLLAB_SECRET and VAULTER_COLLAB_WEBHOOK_SECRET'];
        } elseif ($jwt === $webhook) {
            $problems[] = ['documents.collaboration_secrets_equal', 'The JWT secret and the webhook secret must differ: the hub holds the webhook secret and could sign room tokens with it.', 'use two different values'];
        }
        if ($url === null) {
            $problems[] = ['documents.collaboration_url_missing', 'The hub URL is not set.', 'set vaulter-documents.collaboration.hub.url'];
        } elseif ($context->production && !str_starts_with($url, 'wss://')) {
            $problems[] = ['documents.collaboration_url_insecure', 'The hub URL must use wss:// in production.', 'serve the hub over TLS and use wss://'];
        }
        try {
            $this->config->hubTokenTtl();
        } catch (Throwable) {
            $problems[] = ['documents.collaboration_token_ttl', 'The room token lifetime must be between 1 and 3600 seconds.', 'set vaulter-documents.collaboration.hub.token_ttl to at most 3600'];
        }
        if (!class_exists(JWT::class)) {
            $problems[] = ['documents.collaboration_jwt_missing', 'firebase/php-jwt is not installed.', 'composer require firebase/php-jwt'];
        }

        if ($problems === []) {
            return [DoctorFinding::ok(code: 'documents.collaboration_ok', message: 'The collaboration settings are complete.', evidence: $evidence)];
        }

        return array_map(
            static fn (array $problem): DoctorFinding => DoctorFinding::error(code: $problem[0], message: $problem[1], hint: $problem[2], evidence: $evidence),
            $problems,
        );
    }
}
