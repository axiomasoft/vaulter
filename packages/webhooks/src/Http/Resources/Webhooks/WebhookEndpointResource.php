<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Http\Support\RepresentationTag;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;

/**
 * A webhook subscriber endpoint, built from a {@see WebhookEndpointSnapshot} and never querying. The signing secret is
 * stored encrypted and never read back into a response: only registration and rotation return it, once, through
 * {@see self::withSecret()}. `etag` is the endpoint's tag (id and version), the If-Match of its update, deactivation,
 * rotation and deletion.
 */
#[Internal]
final class WebhookEndpointResource extends JsonResource
{
    private ?string $secret = null;

    public function __construct(WebhookEndpointSnapshot $endpoint)
    {
        parent::__construct($endpoint);
    }

    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /** The ETag header value: the same tag the body carries. */
    public function etag(): string
    {
        /** @var WebhookEndpointSnapshot $endpoint */
        $endpoint = $this->resource;

        return self::tag($endpoint);
    }

    public static function tag(WebhookEndpointSnapshot $endpoint): string
    {
        return RepresentationTag::versioned('webhook-endpoint', $endpoint->id, $endpoint->version);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpointSnapshot $endpoint */
        $endpoint = $this->resource;
        $payload = [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'drive_id' => $endpoint->driveId,
            'events' => $endpoint->events,
            'status' => $endpoint->active ? 'active' : 'disabled',
            'disabled_at' => SnapshotValue::iso($endpoint->disabledAt),
            'version' => $endpoint->version,
            'etag' => $this->etag(),
            'created_at' => SnapshotValue::iso($endpoint->createdAt),
        ];
        if ($this->secret !== null) {
            $payload['secret'] = $this->secret;
        }

        return $payload;
    }
}
