<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Data;

use DateTimeImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * An endpoint as a write answered it (D25, D47): everything its response shows except the signing secret, with the
 * version of the moment. It is what a receipt stores and what the response is built from, so a replay answers the
 * same body as the first request. Built from a model that was just written, which is why the version is read from the
 * attribute the write set.
 */
#[Internal]
final readonly class WebhookEndpointSnapshot
{
    /** @param list<string> $events */
    public function __construct(
        public string $id,
        public string $url,
        public ?string $driveId,
        public array $events,
        public bool $active,
        public ?DateTimeImmutable $disabledAt,
        public ?DateTimeImmutable $createdAt,
        public int $version,
        public ?string $tenantId = null,
    ) {}

    public static function of(WebhookEndpoint $endpoint): self
    {
        $key = $endpoint->getKey();
        $drive = $endpoint->drive_id;
        $tenant = $endpoint->tenant_id;

        return new self(
            id: is_int($key) || is_string($key) ? (string) $key : '',
            url: $endpoint->url,
            driveId: is_int($drive) || is_string($drive) ? (string) $drive : null,
            events: array_values($endpoint->events),
            active: $endpoint->is_active,
            disabledAt: $endpoint->disabled_at === null ? null : DateTimeImmutable::createFromInterface($endpoint->disabled_at),
            createdAt: $endpoint->created_at === null ? null : DateTimeImmutable::createFromInterface($endpoint->created_at),
            version: (int) $endpoint->version,
            tenantId: is_int($tenant) || is_string($tenant) ? (string) $tenant : null,
        );
    }
}
