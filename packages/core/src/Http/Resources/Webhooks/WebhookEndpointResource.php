<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * A webhook subscriber endpoint. The signing secret is stored encrypted and never read
 * back into a response: only registration and rotation return it, once, through
 * {@see self::withSecret()}.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends JsonResource
{
    private ?string $secret = null;

    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;
        $payload = [
            'id' => SnapshotValue::id($endpoint->getKey()),
            'url' => $endpoint->url,
            'drive_id' => SnapshotValue::nullableId($endpoint->drive_id),
            'events' => array_values($endpoint->events),
            'status' => $endpoint->is_active ? 'active' : 'disabled',
            'disabled_at' => SnapshotValue::iso($endpoint->disabled_at),
            'created_at' => SnapshotValue::iso($endpoint->created_at),
        ];
        if ($this->secret !== null) {
            $payload['secret'] = $this->secret;
        }

        return $payload;
    }
}
